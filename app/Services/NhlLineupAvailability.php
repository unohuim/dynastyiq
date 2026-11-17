<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Apply the same game-date injury boundary to submitted, calculated and saved lineups. */
final class NhlLineupAvailability
{
    /** Current injuries never rewrite actual participants in a started or finished game. */
    public function applies(object $game): bool
    {
        return in_array(strtoupper((string) ($game->game_state ?? 'FUT')), ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true);
    }

    /** Return supported out evidence known by the target/request cutoff; return estimates are not clearance. */
    public function blocked(object $game, string $team): Collection
    {
        if (! $this->applies($game)) {
            return collect();
        }
        $cutoff = $this->cutoff($game);

        return $this->outQuery()->where('team_abbrev', strtoupper($team))
            ->where(fn ($query) => $query->whereNull('first_observed_at')->orWhere('first_observed_at', '<=', $cutoff))
            ->orderBy('nhl_player_id')->get(['nhl_player_id', 'player_name', 'status_text', 'availability', 'evidence_level', 'first_observed_at']);
    }

    /** Compare eligibility meaning across a completed injury import, excluding heartbeat changes. */
    public function currentOutByTeam(): array
    {
        return $this->outQuery()->orderBy('nhl_player_id')->get(['team_abbrev', 'nhl_player_id'])
            ->groupBy('team_abbrev')->map(fn ($rows): array => $rows->pluck('nhl_player_id')->map(fn ($id): int => (int) $id)->all())->all();
    }

    /** The supported-out rule is shared by admission, fallback, fingerprints and injury refreshes. */
    private function outQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('nhl_player_injuries')->where('availability', 'out')
            ->whereIn('evidence_level', ['reported', 'corroborated', 'confirmed_unavailable'])->whereNotNull('nhl_player_id');
    }

    /** Check a revision using identity-only database projection, never its full bucket trees. */
    public function revisionErrors(object $game, int $revisionId): array
    {
        if (! $this->applies($game)) {
            return [];
        }
        if (DB::getDriverName() !== 'pgsql') {
            $payload = DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->value('payload');

            return $payload === null ? [] : $this->predictionErrors($game, json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        }
        $rows = DB::select(<<<'SQL'
            SELECT jsonb_path_query_array(payload::jsonb, '$.teams.away.roster[*].nhl_player_id') AS away_ids,
                jsonb_path_query_array(payload::jsonb, '$.teams.home.roster[*].nhl_player_id') AS home_ids,
                COALESCE(payload::jsonb #>> '{inputs,away_goalie_id}', payload::jsonb #>> '{goalies,away,nhl_player_id}') AS away_goalie,
                COALESCE(payload::jsonb #>> '{inputs,home_goalie_id}', payload::jsonb #>> '{goalies,home,nhl_player_id}') AS home_goalie
            FROM nhl_game_prediction_revisions WHERE id = ?
            SQL, [$revisionId]);
        if ($rows === []) {
            return [];
        }
        $payload = [];
        foreach (['away', 'home'] as $side) {
            $payload['teams'][$side]['roster'] = array_map(fn ($id): array => ['nhl_player_id' => $id],
                json_decode($rows[0]->{$side.'_ids'}, true, 512, JSON_THROW_ON_ERROR));
            $payload['inputs'][$side.'_goalie_id'] = $rows[0]->{$side.'_goalie'};
        }

        return $this->predictionErrors($game, $payload);
    }

    /** Identify dressed-player conflicts without changing identity, membership, counts or scratch rules.
     * @param iterable<array<string,mixed>> $players
     * @return array<int,string>
     */
    public function errors(object $game, string $team, iterable $players): array
    {
        $ids = collect($players)->reject(fn ($player): bool => ($player['lineup_role'] ?? '') === 'scratch')
            ->pluck('nhl_player_id')->filter()->map(fn ($id): int => (int) $id)->all();

        return $this->blocked($game, $team)->whereIn('nhl_player_id', $ids)->map(fn ($row): string =>
            $row->player_name.' is marked out for '.CarbonImmutable::parse($game->game_date)->toDateString()
            .': '.($row->status_text ?: 'Reported unavailable.'))->values()->all();
    }

    /** Fingerprint eligibility meaning for one game, never heartbeat timestamps. */
    public function evidence(object $game): array
    {
        return $this->evidenceForGames([$game])[(int) ($game->nhl_game_id ?? 0)];
    }

    /** One source read keeps a shared date's preparation identity stable across its game callers. */
    public function evidenceForGames(iterable $games): array
    {
        $games = collect($games);
        $teams = $games->flatMap(fn ($g): array => [$g->away_team_abbrev ?? '', $g->home_team_abbrev ?? ''])->filter()->unique();
        $rows = $this->outQuery()->whereIn('team_abbrev', $teams)->orderBy('nhl_player_id')
            ->get(['nhl_player_id', 'team_abbrev', 'first_observed_at']);
        $result = [];
        foreach ($games as $game) {
            $sides = [];
            $cutoff = $this->cutoff($game);
            foreach (['away', 'home'] as $side) {
                $team = (string) ($game->{$side.'_team_abbrev'} ?? '');
                $sides[$side] = $this->applies($game) ? $rows->where('team_abbrev', $team)
                    ->filter(fn ($row): bool => $row->first_observed_at === null || CarbonImmutable::parse($row->first_observed_at, 'UTC')->lte($cutoff))
                    ->map(fn ($row): array => ['nhl_player_id' => (int) $row->nhl_player_id])->values()->all() : [];
            }
            $result[(int) ($game->nhl_game_id ?? 0)] = ['version' => 1, 'excluded' => $sides];
        }

        return $result;
    }

    /** Bound eligibility evidence by the request and target start without implying medical clearance. */
    private function cutoff(object $game): CarbonImmutable
    {
        $cutoff = CarbonImmutable::now('UTC');
        if (! empty($game->start_time_utc)) {
            return $cutoff->min(CarbonImmutable::parse($game->start_time_utc, 'UTC'));
        }

        return empty($game->game_date) ? $cutoff : $cutoff->min(CarbonImmutable::parse($game->game_date, 'America/Toronto')->endOfDay()->utc());
    }

    /** A saved forecast with newly unavailable participants must be refreshed, not rewritten. */
    public function predictionErrors(object $game, array $payload): array
    {
        $errors = [];
        foreach (['away', 'home'] as $side) {
            $players = $payload['teams'][$side]['roster'] ?? [];
            $goalieId = $payload['inputs'][$side.'_goalie_id'] ?? $payload['goalies'][$side]['nhl_player_id'] ?? null;
            if ($goalieId !== null) {
                $players[] = ['nhl_player_id' => $goalieId, 'lineup_role' => 'goalie'];
            }
            $errors = [...$errors, ...$this->errors($game, (string) ($game->{$side.'_team_abbrev'} ?? ''), $players)];
        }

        return $errors;
    }
}
