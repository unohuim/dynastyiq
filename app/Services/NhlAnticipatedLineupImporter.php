<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceSource;
use App\Models\NhlCurrentLineup;
use App\Models\NhlLineupObservation;
use App\Models\NhlStartingGoalieObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists lineup evidence and projects the latest source consensus. */
class NhlAnticipatedLineupImporter
{
    public function __construct(
        private readonly NhlOfficialGameRosterDiscovery $officialRosters,
        private readonly XNhlLineupDiscovery $discovery,
        private readonly NhlLineupPlayerResolver $players,
    ) {
    }

    /** @return array{observed:int,skipped:int} */
    public function import(
        object $game,
        string $teamAbbrev,
        int $teamId,
        bool $opponentReported = true
    ): array
    {
        $official = $this->importOfficial($game, $teamAbbrev, $teamId);
        if ($official['available']) {
            return ['observed' => $official['observed'], 'skipped' => 0];
        }

        return $this->importFromX($game, $teamAbbrev, $teamId);
    }

    /** @return array{available:bool,observed:int} */
    public function importOfficial(object $game, string $teamAbbrev, int $teamId): array
    {
        $candidate = $this->officialRosters->discover($game, $teamAbbrev);
        if ($candidate === null) {
            return ['available' => false, 'observed' => 0];
        }

        $observed = 0;
        $skipped = 0;
        $this->persistCandidates([$candidate], $game, $teamAbbrev, $teamId, $observed, $skipped);

        return ['available' => true, 'observed' => $observed];
    }

    /** @return array{observed:int,skipped:int} */
    public function importFromX(object $game, string $teamAbbrev, int $teamId): array
    {
        $observed = 0;
        $skipped = 0;
        $this->persistCandidates(
            $this->discovery->discover($game, $teamAbbrev),
            $game,
            $teamAbbrev,
            $teamId,
            $observed,
            $skipped
        );

        return compact('observed', 'skipped');
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function persistCandidates(
        array $candidates,
        object $game,
        string $teamAbbrev,
        int $teamId,
        int &$observed,
        int &$skipped
    ): void {
        foreach ($candidates as $candidate) {
            if (! $this->hasLineupText($candidate)) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($candidate, $game, $teamAbbrev, $teamId, &$observed): void {
                $source = $this->source($candidate, $teamId, $teamAbbrev);
                $this->recordEngagement($source, $candidate);
                $normalized = $this->normalizePlayers($candidate['players'] ?? [], $teamId, $teamAbbrev);
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
                $this->recordStartingGoalie($observation, $normalized, $candidate, $game, $teamAbbrev);
                if ($normalized !== []) {
                    $observed++;
                }
                $this->refreshCurrent((int) $game->nhl_game_id, $teamId, $teamAbbrev);
            });
        }
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
    private function normalizePlayers(array $players, int $teamId, string $teamAbbrev): array
    {
        $allowed = ['F1', 'F2', 'F3', 'F4', 'D1', 'D2', 'D3', 'G', 'SCR'];

        return collect($players)->filter(function (mixed $row) use ($allowed): bool {
            return is_array($row)
                && trim((string) ($row['name'] ?? '')) !== ''
                && in_array($row['line_key'] ?? null, $allowed, true)
                && (int) ($row['slot_index'] ?? 0) > 0;
        })->unique(fn (array $row): string => $row['line_key'] . ':' . $row['slot_index'])
            ->map(function (array $row) use ($teamId, $teamAbbrev): array {
                $player = isset($row['nhl_player_id'])
                    ? \App\Models\Player::query()->where('nhl_id', (int) $row['nhl_player_id'])->first()
                    : $this->players->resolve((string) $row['name'], $teamAbbrev);
                $nhlPlayerId = isset($row['nhl_player_id'])
                    ? (int) $row['nhl_player_id']
                    : $player?->nhl_id;

                return [
                    'team_id' => $teamId,
                    'team_abbrev' => $teamAbbrev,
                    'player_id' => $player?->id,
                    'nhl_player_id' => $nhlPlayerId,
                    'player_name' => (string) ($player?->full_name ?: $row['name']),
                    'lineup_role' => (string) $row['lineup_role'],
                    'line_key' => (string) $row['line_key'],
                    'slot_index' => (int) $row['slot_index'],
                    'power_play_unit' => in_array((int) ($row['power_play_unit'] ?? 0), [1, 2], true)
                        ? (int) $row['power_play_unit'] : null,
                    'penalty_kill_unit' => in_array((int) ($row['penalty_kill_unit'] ?? 0), [1, 2], true)
                        ? (int) $row['penalty_kill_unit'] : null,
                    'resolution_status' => $nhlPlayerId ? 'resolved' : 'unresolved',
                ];
            })->sortBy(fn (array $row): string => sprintf('%s:%02d', $row['line_key'], $row['slot_index']))
            ->values()->all();
    }

    /** @param array<int,array<string,mixed>> $players */
    private function completeness(array $players): string
    {
        $counts = collect($players)->countBy('lineup_role');

        return ($counts['forward'] ?? 0) >= 12 && ($counts['defense'] ?? 0) >= 6
            ? 'full'
            : 'partial';
    }

    /** @param array<string,mixed> $candidate */
    private function hasLineupText(array $candidate): bool
    {
        return trim((string) ($candidate['post_text'] ?? '')) !== '';
    }

    /**
     * Record an ordered G1 as expected starter evidence while retaining G2 in the lineup observation.
     *
     * @param array<int,array<string,mixed>> $players
     * @param array<string,mixed> $candidate
     */
    private function recordStartingGoalie(
        NhlLineupObservation $observation,
        array $players,
        array $candidate,
        object $game,
        string $teamAbbrev
    ): void {
        $starter = collect($players)->first(fn (array $player): bool => $player['line_key'] === 'G'
            && $player['lineup_role'] === 'goalie'
            && $player['slot_index'] === 1);
        if ($starter === null) {
            return;
        }

        $backup = collect($players)->first(fn (array $player): bool => $player['line_key'] === 'G'
            && $player['lineup_role'] === 'goalie'
            && $player['slot_index'] === 2);
        $isHome = mb_strtoupper((string) $game->home_team_abbrev) === $teamAbbrev;
        $opponent = $isHome ? $game->away_team_abbrev : $game->home_team_abbrev;
        $isOfficial = (bool) ($candidate['official'] ?? false);

        NhlStartingGoalieObservation::query()->create([
            'nhl_game_id' => (int) $game->nhl_game_id,
            'game_date' => Carbon::parse((string) $game->game_date)->toDateString(),
            'team_abbrev' => $teamAbbrev,
            'opponent_abbrev' => mb_strtoupper((string) $opponent),
            'is_home' => $isHome,
            'player_id' => $starter['player_id'],
            'nhl_player_id' => $starter['nhl_player_id'],
            'player_name' => $starter['player_name'],
            'provider' => $isOfficial ? 'nhl_boxscore' : 'public_lineup',
            'provider_player_key' => null,
            'status' => $isOfficial ? 'confirmed' : 'expected',
            'provider_published_at' => $observation->provider_published_at,
            'fetched_at' => $observation->observed_at,
            'source_url' => (string) $candidate['post_url'],
            'raw_evidence' => [
                'lineup_observation_id' => $observation->id,
                'source_id' => $observation->source_id,
                'line_key' => 'G',
                'slot_index' => 1,
                'backup' => $backup ? [
                    'player_id' => $backup['player_id'],
                    'nhl_player_id' => $backup['nhl_player_id'],
                    'player_name' => $backup['player_name'],
                ] : null,
            ],
        ]);
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
        $isOfficial = (bool) data_get($latest->raw_evidence, 'official', false);
        $status = $isOfficial
            ? 'official'
            : ($sourceCount >= 3 ? 'strongly_corroborated' : ($sourceCount >= 2 ? 'corroborated' : 'reported'));

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
