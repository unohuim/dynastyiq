<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceSource;
use App\Models\NhlCurrentLineup;
use App\Models\NhlCurrentLineupComponent;
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
        $this->reconcileUnresolved((int) $game->nhl_game_id, $teamId, $teamAbbrev);
        $candidate = $this->officialRosters->discover($game, $teamAbbrev);
        if ($candidate === null) {
            return ['available' => false, 'observed' => 0];
        }

        $observed = 0;
        $skipped = 0;
        $this->persistCandidates([$candidate], $game, $teamAbbrev, $teamId, $observed, $skipped);

        $current = NhlCurrentLineup::query()->where('nhl_game_id', $game->nhl_game_id)
            ->where('team_id', $teamId)->first();

        return ['available' => $current?->hasVerifiedPlayers() ?? false, 'observed' => $observed];
    }

    /** @return array{observed:int,skipped:int} */
    public function importFromX(
        object $game,
        string $teamAbbrev,
        int $teamId,
        ?string $streamBatchId = null
    ): array
    {
        $this->reconcileUnresolved((int) $game->nhl_game_id, $teamId, $teamAbbrev);
        $observed = 0;
        $skipped = 0;
        $this->persistCandidates(
            $this->discovery->discover($game, $teamAbbrev, $streamBatchId),
            $game,
            $teamAbbrev,
            $teamId,
            $observed,
            $skipped
        );

        return compact('observed', 'skipped');
    }

    /** Re-evaluate identity fields while preserving the immutable reported evidence. */
    private function reconcileUnresolved(int $gameId, int $teamId, string $teamAbbrev): void
    {
        $rows = \App\Models\NhlLineupObservationPlayer::query()
            ->where('team_id', $teamId)
            ->where('team_abbrev', $teamAbbrev)
            ->where('resolution_status', 'unresolved')
            ->whereHas('observation', fn ($query) => $query->where('nhl_game_id', $gameId))
            ->get();

        foreach ($rows as $row) {
            $player = $this->players->resolve((string) $row->player_name, $teamAbbrev);
            if ($player === null) {
                continue;
            }

            $row->update([
                'player_id' => $player->id,
                'nhl_player_id' => $player->nhl_id,
                'resolution_status' => 'resolved',
            ]);
        }

        $this->refreshCurrent($gameId, $teamId, $teamAbbrev);
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
            $normalized = $this->normalizePlayers($candidate['players'] ?? [], $teamId, $teamAbbrev);
            $completeness = $this->completeness($normalized);
            if ($completeness === 'partial') {
                $skipped++;
                continue;
            }

            DB::transaction(function () use (
                $candidate,
                $game,
                $teamAbbrev,
                $teamId,
                $normalized,
                $completeness,
                &$observed
            ): void {
                $source = $this->source($candidate, $teamId, $teamAbbrev);
                $this->recordEngagement($source, $candidate);
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
                        'completeness' => $completeness,
                        'structure_hash' => $structureHash,
                        'raw_evidence' => $candidate,
                    ]
                );
                if (! $observation->wasRecentlyCreated) {
                    return;
                }
                $observation->players()->createMany($normalized);
                $this->recordStartingGoalie($observation, $normalized, $candidate, $game, $teamAbbrev);
                $observed++;
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
        DB::table('source_scopes')->insertOrIgnore([
            'source_id' => $source->id,
            'sport' => 'hockey',
            'league' => 'NHL',
            'team_id' => $teamId,
            'team_abbrev' => $teamAbbrev,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('source_scopes')
            ->where('source_id', $source->id)
            ->where('sport', 'hockey')
            ->where('league', 'NHL')
            ->where('team_id', $teamId)
            ->update(['team_abbrev' => $teamAbbrev, 'updated_at' => now()]);
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
                $nhlPlayerId = $player?->nhl_id;

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
        $forwardsComplete = $this->players->verifiedLineupIds($players, 'forward') !== null;
        $defenseComplete = $this->players->verifiedLineupIds($players, 'defense') !== null;

        return match (true) {
            $forwardsComplete && $defenseComplete => 'full',
            $forwardsComplete => 'forwards',
            $defenseComplete => 'defense',
            default => 'partial',
        };
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
        $gameDate = DB::table('nhl_games')->where('nhl_game_id', $gameId)->value('game_date');
        if ($gameDate === null) {
            return;
        }
        $eligibleFrom = NhlLineupObservation::evidenceCutoff((string) $gameDate);
        $observations = NhlLineupObservation::query()->with('players')
            ->where('nhl_game_id', $gameId)->where('team_id', $teamId)
            ->whereIn('completeness', ['full', 'forwards', 'defense'])
            ->where(fn ($query) => $query->where('provider_published_at', '>=', $eligibleFrom)
                ->orWhere(fn ($fallback) => $fallback->whereNull('provider_published_at')
                    ->where('observed_at', '>=', $eligibleFrom)))
            ->orderByRaw('COALESCE(provider_published_at, observed_at) DESC')->latest('id')->get();
        $forward = $observations->first(fn (NhlLineupObservation $observation): bool =>
            in_array($observation->completeness, ['full', 'forwards'], true)
            && $this->players->verifiedLineupIds($observation->players->toArray(), 'forward') !== null);
        $defense = $observations->first(fn (NhlLineupObservation $observation): bool =>
            in_array($observation->completeness, ['full', 'defense'], true)
            && $this->players->verifiedLineupIds($observation->players->toArray(), 'defense') !== null);
        if ($forward === null || $defense === null) {
            NhlCurrentLineup::query()->where('nhl_game_id', $gameId)->where('team_id', $teamId)->delete();
            return;
        }
        $forwardHash = $this->componentHash($forward, 'forward');
        $defenseHash = $this->componentHash($defense, 'defense');
        $forwardSupport = $observations->filter(fn (NhlLineupObservation $observation): bool =>
            $this->componentHash($observation, 'forward') === $forwardHash);
        $defenseSupport = $observations->filter(fn (NhlLineupObservation $observation): bool =>
            $this->componentHash($observation, 'defense') === $defenseHash);
        $supporting = $forwardSupport->concat($defenseSupport)->unique('id');
        $sourceCount = $supporting->pluck('source_id')->unique()->count();
        $componentSourceCount = min(
            $forwardSupport->pluck('source_id')->unique()->count(),
            $defenseSupport->pluck('source_id')->unique()->count()
        );
        $isOfficial = (bool) data_get($forward->raw_evidence, 'official', false)
            && (bool) data_get($defense->raw_evidence, 'official', false);
        $status = $isOfficial
            ? 'official'
            : ($componentSourceCount >= 3
                ? 'strongly_corroborated'
                : ($componentSourceCount >= 2 ? 'corroborated' : 'reported'));
        $representative = $observations->first(fn (NhlLineupObservation $observation): bool =>
            in_array($observation->id, [$forward->id, $defense->id], true));
        $current = NhlCurrentLineup::query()->updateOrCreate(
            ['nhl_game_id' => $gameId, 'team_id' => $teamId],
            [
                'team_abbrev' => $teamAbbrev,
                'nhl_lineup_observation_id' => $representative->id,
                'structure_hash' => hash('sha256', $forwardHash . ':' . $defenseHash),
                'evidence_status' => $status,
                'source_count' => $sourceCount,
                'first_observed_at' => $supporting->min('observed_at'),
                'last_observed_at' => $supporting->max('observed_at'),
            ]
        );
        $current->components()->delete();
        foreach ([
            NhlCurrentLineupComponent::TYPE_FORWARDS => [$forward, $forwardSupport],
            NhlCurrentLineupComponent::TYPE_DEFENSE => [$defense, $defenseSupport],
        ] as $componentType => [$representativeObservation, $componentSupport]) {
            foreach ($componentSupport as $observation) {
                $current->components()->create([
                    'component_type' => $componentType,
                    'nhl_lineup_observation_id' => $observation->id,
                    'is_representative' => $observation->id === $representativeObservation->id,
                ]);
            }
        }
    }

    private function componentHash(NhlLineupObservation $observation, string $role): ?string
    {
        $expected = $role === 'forward' ? 12 : 6;
        $players = $observation->players->where('lineup_role', $role)
            ->sortBy(fn ($player): string => sprintf('%s:%02d', $player->line_key, $player->slot_index));
        if ($players->count() !== $expected
            || $this->players->verifiedLineupIds($players->values()->toArray(), $role) === null) {
            return null;
        }

        return hash('sha256', $players->map(fn ($player): array => [
            $player->line_key,
            $player->slot_index,
            $player->nhl_player_id ?? Str::slug($player->player_name),
        ])->values()->toJson());
    }
}
