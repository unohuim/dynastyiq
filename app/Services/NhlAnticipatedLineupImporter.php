<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceSource;
use App\Models\NhlCurrentLineup;
use App\Models\NhlLineupObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists lineup evidence and projects the latest source consensus. */
class NhlAnticipatedLineupImporter
{
    public function __construct(
        private readonly OpenAiNhlLineupDiscovery $discovery,
        private readonly NhlLineupPlayerResolver $players,
    ) {
    }

    /** @return array{observed:int,skipped:int} */
    public function import(object $game, string $teamAbbrev, int $teamId): array
    {
        $known = DB::table('source_scopes as scopes')
            ->join('sources', 'sources.id', '=', 'scopes.source_id')
            ->where('scopes.sport', 'hockey')->where('scopes.league', 'NHL')
            ->where(fn ($query) => $query->where('scopes.team_id', $teamId)->orWhereNull('scopes.team_id'))
            ->orderByDesc('sources.last_seen_at')->limit(10)
            ->get(['sources.name', 'sources.handle', 'sources.canonical_url'])->map(fn (object $source): array => (array) $source)->all();

        $observed = 0;
        $skipped = 0;
        foreach ($this->discovery->discover($game, $teamAbbrev, $known) as $candidate) {
            if (! $this->hasLineupText($candidate)) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($candidate, $game, $teamAbbrev, $teamId, &$observed): void {
                $source = $this->source($candidate, $teamId, $teamAbbrev);
                $this->recordEngagement($source, $candidate);
                $normalized = $this->normalizePlayers($candidate['players'] ?? [], $teamAbbrev);
                if ($normalized === []) {
                    return;
                }
                $structureHash = hash('sha256', json_encode(array_map(
                    fn (array $player): array => [
                        $player['line_key'],
                        $player['slot_index'],
                        $player['nhl_player_id'] ?? Str::slug($player['player_name']),
                    ],
                    $normalized
                )));
                $publishedAt = filled($candidate['published_at'] ?? null)
                    ? Carbon::parse($candidate['published_at'])
                    : null;
                $engagement = $candidate['engagement'] ?? [];
                $observation = NhlLineupObservation::query()->firstOrCreate(
                    [
                        'nhl_game_id' => (int) $game->nhl_game_id,
                        'team_id' => $teamId,
                        'post_url' => (string) $candidate['post_url'],
                    ],
                    [
                        'team_abbrev' => $teamAbbrev,
                        'source_id' => $source->id,
                        'post_text' => (string) $candidate['post_text'],
                        'provider_published_at' => $publishedAt,
                        'observed_at' => now(),
                        'like_count' => $engagement['likes'] ?? null,
                        'reply_count' => $engagement['replies'] ?? null,
                        'repost_count' => $engagement['reposts'] ?? null,
                        'view_count' => $engagement['views'] ?? null,
                        'completeness' => $this->completeness($normalized),
                        'structure_hash' => $structureHash,
                        'raw_evidence' => $candidate,
                    ]
                );
                if (! $observation->wasRecentlyCreated) {
                    return;
                }
                $observation->players()->createMany($normalized);
                $observed++;
                $this->refreshCurrent((int) $game->nhl_game_id, $teamId, $teamAbbrev);
            });
        }

        return compact('observed', 'skipped');
    }

    /** @param array<string,mixed> $candidate */
    private function source(array $candidate, int $teamId, string $teamAbbrev): EvidenceSource
    {
        $platform = mb_strtolower((string) ($candidate['platform'] ?? 'web'));
        $handle = filled($candidate['source_handle'] ?? null) ? (string) $candidate['source_handle'] : null;
        $source = EvidenceSource::query()
            ->when($handle, fn ($query) => $query->where('platform', $platform)->where('handle', $handle))
            ->when(! $handle, fn ($query) => $query->where('canonical_url', (string) $candidate['source_url']))
            ->first();
        if ($source === null) {
            $source = EvidenceSource::query()->create([
                'platform' => $platform,
                'name' => (string) $candidate['source_name'],
                'handle' => $handle,
                'canonical_url' => (string) $candidate['source_url'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        } else {
            $source->update(['last_seen_at' => now()]);
        }
        DB::table('source_scopes')->updateOrInsert(
            ['source_id' => $source->id, 'sport' => 'hockey', 'league' => 'NHL', 'team_id' => $teamId],
            ['team_abbrev' => $teamAbbrev, 'created_at' => now(), 'updated_at' => now()]
        );
        if (collect([$candidate['followers'] ?? null, $candidate['following'] ?? null, $candidate['post_count'] ?? null])->contains(
            fn (mixed $value): bool => $value !== null
        )) {
            DB::table('source_metric_snapshots')->insert([
                'source_id' => $source->id,
                'followers' => isset($candidate['followers']) ? (int) $candidate['followers'] : null,
                'following' => isset($candidate['following']) ? (int) $candidate['following'] : null,
                'posts' => isset($candidate['post_count']) ? (int) $candidate['post_count'] : null,
                'observed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $source;
    }

    /** @param array<string,mixed> $candidate */
    private function recordEngagement(EvidenceSource $source, array $candidate): void
    {
        $engagement = $candidate['engagement'] ?? [];
        if (collect($engagement)->filter(fn (mixed $value): bool => $value !== null)->isEmpty()) {
            return;
        }

        DB::table('source_engagement_snapshots')->insert([
            'source_id' => $source->id,
            'evidence_url' => (string) $candidate['post_url'],
            'likes' => $engagement['likes'] ?? null,
            'replies' => $engagement['replies'] ?? null,
            'reposts' => $engagement['reposts'] ?? null,
            'views' => $engagement['views'] ?? null,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int,mixed> $players @return array<int,array<string,mixed>> */
    private function normalizePlayers(array $players, string $teamAbbrev): array
    {
        $allowed = ['F1', 'F2', 'F3', 'F4', 'D1', 'D2', 'D3', 'G', 'SCR'];

        return collect($players)->filter(function (mixed $row) use ($allowed): bool {
            return is_array($row)
                && trim((string) ($row['name'] ?? '')) !== ''
                && in_array($row['line_key'] ?? null, $allowed, true)
                && (int) ($row['slot_index'] ?? 0) > 0;
        })->unique(fn (array $row): string => $row['line_key'] . ':' . $row['slot_index'])
            ->map(function (array $row) use ($teamAbbrev): array {
                $player = $this->players->resolve((string) $row['name'], $teamAbbrev);

                return [
                    'player_id' => $player?->id,
                    'nhl_player_id' => $player?->nhl_id,
                    'player_name' => (string) $row['name'],
                    'lineup_role' => (string) $row['lineup_role'],
                    'line_key' => (string) $row['line_key'],
                    'slot_index' => (int) $row['slot_index'],
                    'resolution_status' => $player ? 'resolved' : 'unresolved',
                ];
            })->sortBy(fn (array $row): string => sprintf('%s:%02d', $row['line_key'], $row['slot_index']))
            ->values()->all();
    }

    /** @param array<int,array<string,mixed>> $players */
    private function completeness(array $players): string
    {
        $counts = collect($players)->countBy('lineup_role');

        return ($counts['forward'] ?? 0) >= 12 && ($counts['defense'] ?? 0) >= 6 && ($counts['goalie'] ?? 0) >= 2
            ? 'full'
            : 'partial';
    }

    /** @param array<string,mixed> $candidate */
    private function hasLineupText(array $candidate): bool
    {
        return trim((string) ($candidate['post_text'] ?? '')) !== ''
            && count($candidate['players'] ?? []) >= 6;
    }

    private function refreshCurrent(int $gameId, int $teamId, string $teamAbbrev): void
    {
        $latest = NhlLineupObservation::query()->where('nhl_game_id', $gameId)->where('team_id', $teamId)
            ->where('completeness', 'full')
            ->orderByRaw('COALESCE(provider_published_at, observed_at) DESC')->latest('id')->first();
        if ($latest === null) {
            return;
        }
        $cutoff = ($latest->provider_published_at ?? $latest->observed_at)->copy()->subHours(6);
        $matching = NhlLineupObservation::query()->where('nhl_game_id', $gameId)->where('team_id', $teamId)
            ->where('structure_hash', $latest->structure_hash)
            ->where(fn ($query) => $query->where('provider_published_at', '>=', $cutoff)
                ->orWhere(fn ($fallback) => $fallback->whereNull('provider_published_at')->where('observed_at', '>=', $cutoff)))
            ->get();
        $sourceCount = $matching->pluck('source_id')->unique()->count();
        $status = $sourceCount >= 3 ? 'strongly_corroborated' : ($sourceCount >= 2 ? 'corroborated' : 'reported');

        NhlCurrentLineup::query()->updateOrCreate(
            ['nhl_game_id' => $gameId, 'team_id' => $teamId],
            [
                'team_abbrev' => $teamAbbrev,
                'nhl_lineup_observation_id' => $latest->id,
                'structure_hash' => $latest->structure_hash,
                'evidence_status' => $status,
                'source_count' => $sourceCount,
                'first_observed_at' => $matching->min('observed_at'),
                'last_observed_at' => $matching->max('observed_at'),
            ]
        );
    }
}
