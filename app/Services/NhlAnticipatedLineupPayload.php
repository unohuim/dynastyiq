<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlCurrentLineup;
use App\Models\NhlGame;
use App\Models\NhlLineupObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds public and partner-facing anticipated-lineup payloads from current projections. */
class NhlAnticipatedLineupPayload
{
    /** Create the payload builder with official live-game enrichment. */
    public function __construct(
        private readonly NhlGameLiveContext $liveContext,
        private readonly NhlGameLineupProjectionBuilder $projections,
        private readonly NhlAvailabilityPayload $availability,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(Carbon $date): array
    {
        $lineups = collect($this->build($date)['anticipated_lineups'])
            ->keyBy(fn (array $lineup): string => $lineup['nhl_game_id'] . ':' . $lineup['team_abbrev']);
        $games = NhlGame::query()
            ->whereDate('game_date', $date->toDateString())
            ->orderBy('start_time_utc')
            ->orderBy('nhl_game_id')
            ->get();
        $scoreGameIds = $this->processedScoreGameIds($games);
        $games = $games->map(function (NhlGame $game) use ($lineups, $scoreGameIds): array {
            $live = $this->recentGameContext($game);

            return $this->game(
                $game,
                $lineups,
                $scoreGameIds->contains((int) $game->nhl_game_id) || ($live['show_score'] ?? false),
                $live
            );
        });

        return [
            'games' => $games,
            'meta' => [
                'date' => $date->toDateString(),
                'count' => $games->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function gamePage(int $nhlGameId): array
    {
        $game = NhlGame::query()->findOrFail($nhlGameId);
        $lineups = collect($this->build($game->game_date, $nhlGameId)['anticipated_lineups'])
            ->keyBy(fn (array $lineup): string => $lineup['nhl_game_id'] . ':' . $lineup['team_abbrev']);

        $live = $this->recentGameContext($game);

        $detail = $this->game($game, $lineups, (bool) ($live['show_score'] ?? false), $live);
        if (! ($detail['live_mode'] ?? false)) {
            $season = (string) $game->season_id;
            $modelId = $this->projections->latestUsableSatModelId($season);
            foreach (['away', 'home'] as $side) {
                $team = (string) $detail[$side]['team_abbrev'];
                $detail[$side] = [...$detail[$side], ...$this->projections->teamPreview(
                    $detail[$side]['lineup'], $team, $season, (int) $game->game_type, $modelId
                )];
                $detail[$side]['injuries'] = $team === '' ? [] : $this->availability->injuries($team)['injuries'];
            }
        }

        return ['game' => $detail];
    }

    /** @return array<string,mixed> */
    public function build(Carbon $date, ?int $nhlGameId = null): array
    {
        $gameIds = DB::table('nhl_games')->whereDate('game_date', $date->toDateString())
            ->when($nhlGameId, fn ($query) => $query->where('nhl_game_id', $nhlGameId))
            ->pluck('nhl_game_id');
        $rows = NhlCurrentLineup::query()->with([
            'observation.players',
            'observation.source',
            'components.observation.players',
            'components.observation.source',
        ])
            ->whereIn('nhl_game_id', $gameIds)->orderBy('nhl_game_id')->orderBy('team_abbrev')->get()
            ->filter(fn (NhlCurrentLineup $row): bool => $this->isEligible($row, $date));

        return [
            'anticipated_lineups' => $rows->map(fn (NhlCurrentLineup $row): array => $this->lineup($row))->values(),
            'meta' => [
                'date' => $date->toDateString(),
                'nhl_game_id' => $nhlGameId,
                'count' => $rows->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function forGameTeam(int $nhlGameId, string $teamAbbrev, bool $requireCorroboration = true): ?array
    {
        $row = NhlCurrentLineup::query()->with([
            'observation.players',
            'observation.source',
            'components.observation.players',
            'components.observation.source',
        ])
            ->where('nhl_game_id', $nhlGameId)->where('team_abbrev', mb_strtoupper($teamAbbrev))
            ->when($requireCorroboration, fn ($query) => $query->whereIn('evidence_status', ['official', 'corroborated', 'strongly_corroborated']))
            ->first();

        $gameDate = NhlGame::query()->where('nhl_game_id', $nhlGameId)->value('game_date');
        if ($row !== null && ($gameDate === null || ! $this->isEligible($row, (string) $gameDate))) {
            return null;
        }

        return $row ? $this->lineup($row) : null;
    }

    /** @return array<string,mixed> */
    private function lineup(NhlCurrentLineup $row): array
    {
        $observation = $row->observation;
        $componentObservations = $row->components->pluck('observation')->filter();
        $representativeComponents = $row->components->where('is_representative', true);
        if ($representativeComponents->isNotEmpty()) {
            $forwardObservation = $representativeComponents
                ->firstWhere('component_type', 'forwards')?->observation;
            $defenseObservation = $representativeComponents
                ->firstWhere('component_type', 'defense')?->observation;
            $supplementalObservation = collect([$forwardObservation, $defenseObservation])->filter()
                ->sortByDesc(fn (NhlLineupObservation $item): int =>
                    ($item->provider_published_at ?? $item->observed_at)?->timestamp ?? 0)
                ->first();
            $players = collect($forwardObservation?->players ?? [])->where('lineup_role', 'forward')
                ->concat(collect($defenseObservation?->players ?? [])->where('lineup_role', 'defense'))
                ->concat(collect($supplementalObservation?->players ?? [])->whereIn('lineup_role', ['goalie', 'scratch']));
            $sources = $componentObservations->unique('source_id');
        } else {
            $players = $observation->players;
            $sources = $this->legacySources($row, $observation);
        }

        return [
            'nhl_game_id' => $row->nhl_game_id,
            'team_id' => $row->team_id,
            'team_abbrev' => $row->team_abbrev,
            'evidence_status' => $row->evidence_status,
            'manual_override' => (bool) data_get($observation->raw_evidence, 'manual_override', false),
            'source_count' => $row->source_count,
            'first_observed_at' => $row->first_observed_at?->toIso8601String(),
            'last_observed_at' => $row->last_observed_at?->toIso8601String(),
            'players' => $players->sortBy(fn ($player): string => sprintf('%s:%02d', $player->line_key, $player->slot_index))
                ->map(fn ($player): array => [
                    'player_id' => $player->player_id,
                    'nhl_player_id' => $player->nhl_player_id,
                    'player_name' => $player->player_name,
                    'lineup_role' => $player->lineup_role,
                    'line_key' => $player->line_key,
                    'slot_index' => $player->slot_index,
                    'power_play_unit' => $player->power_play_unit,
                    'penalty_kill_unit' => $player->penalty_kill_unit,
                    'resolution_status' => $player->resolution_status,
                ])->values(),
            'sources' => $sources->map(fn (NhlLineupObservation $sourceObservation): array => [
                'source_id' => $sourceObservation->source_id,
                'name' => $sourceObservation->source->name,
                'handle' => $sourceObservation->source->handle,
                'platform' => $sourceObservation->source->platform,
                'post_url' => $sourceObservation->post_url,
                'published_at' => $sourceObservation->provider_published_at?->toIso8601String(),
                'observed_at' => $sourceObservation->observed_at?->toIso8601String(),
                'engagement' => [
                    'likes' => $sourceObservation->like_count,
                    'replies' => $sourceObservation->reply_count,
                    'reposts' => $sourceObservation->repost_count,
                    'views' => $sourceObservation->view_count,
                ],
            ])->values(),
        ];
    }

    private function isEligible(NhlCurrentLineup $row, string|Carbon $gameDate): bool
    {
        if (! $row->hasVerifiedPlayers()) {
            return false;
        }
        $representatives = $row->components->where('is_representative', true)->pluck('observation')->filter();
        if ($representatives->isEmpty()) {
            return $row->observation !== null && $row->observation->isEligibleForGameDate($gameDate);
        }

        return $representatives->count() === 2
            && $representatives->every(fn (NhlLineupObservation $observation): bool =>
                $observation->isEligibleForGameDate($gameDate));
    }

    /** @return Collection<int,NhlLineupObservation> */
    private function legacySources(NhlCurrentLineup $row, NhlLineupObservation $observation): Collection
    {
        $cutoff = ($observation->provider_published_at ?? $observation->observed_at)->copy()->subHours(6);
        $gameDate = NhlGame::query()->where('nhl_game_id', $row->nhl_game_id)->value('game_date');
        $eligibleFrom = NhlLineupObservation::evidenceCutoff((string) $gameDate);
        if ($cutoff->lt($eligibleFrom)) {
            $cutoff = $eligibleFrom;
        }

        return NhlLineupObservation::query()->with('source')
            ->where('nhl_game_id', $row->nhl_game_id)->where('team_id', $row->team_id)
            ->where('structure_hash', $row->structure_hash)
            ->where(fn ($query) => $query->where('provider_published_at', '>=', $cutoff)
                ->orWhere(fn ($fallback) => $fallback->whereNull('provider_published_at')
                    ->where('observed_at', '>=', $cutoff)))
            ->get()->unique('source_id');
    }

    /**
     * @param Collection<string,array<string,mixed>> $lineups
     * @return array<string,mixed>
     */
    private function game(
        NhlGame $game,
        Collection $lineups,
        bool $includeScore = false,
        ?array $live = null
    ): array
    {
        $pregame = in_array($live['state'] ?? null, ['FUT', 'PRE'], true);
        $state = $live['state'] ?? $game->game_state;
        if (filled($state) && ! in_array($state, ['FUT', 'PRE', 'FINAL'], true)) {
            $emptyTeam = ['team_id' => null, 'team_abbrev' => null, 'team_name' => null,
                'team_logo' => null, 'score' => null, 'sog' => null, 'goalies' => [],
                'starting_goalie' => null, 'lineup' => null];

            return ['nhl_game_id' => $game->nhl_game_id, ...($live['live_game'] ?? [
                'game_date' => null, 'start_time_utc' => null, 'game_type' => null,
                'game_state' => null, 'game_state_label' => null,
                'live_mode' => true, 'live_data_unavailable' => true,
                'away' => $emptyTeam, 'home' => $emptyTeam,
            ])];
        }
        $showGoalie = $pregame || ($state !== null && $state !== '' && ! in_array($state, ['FUT', 'PRE', 'FINAL'], true));

        return [
            'nhl_game_id' => $game->nhl_game_id,
            'game_date' => $game->game_date->toDateString(),
            'start_time_utc' => $game->start_time_utc?->toIso8601String(),
            'game_type' => $game->game_type,
            'game_state' => $live['state'] ?? $game->game_state,
            'game_state_label' => $live['label'] ?? null,
            'away' => [
                'team_id' => $game->away_team_id,
                'team_abbrev' => $game->away_team_abbrev,
                'team_name' => $game->away_team_common_name,
                'team_logo' => $game->away_team_logo,
                'score' => $includeScore ? $game->away_team_score : null,
                'sog' => $includeScore ? $game->away_team_sog : null,
                'starting_goalie' => $showGoalie ? ($live['away_goalie'] ?? null) : null,
                'lineup' => $lineups->get($game->nhl_game_id . ':' . $game->away_team_abbrev),
            ],
            'home' => [
                'team_id' => $game->home_team_id,
                'team_abbrev' => $game->home_team_abbrev,
                'team_name' => $game->home_team_common_name,
                'team_logo' => $game->home_team_logo,
                'score' => $includeScore ? $game->home_team_score : null,
                'sog' => $includeScore ? $game->home_team_sog : null,
                'starting_goalie' => $showGoalie ? ($live['home_goalie'] ?? null) : null,
                'lineup' => $lineups->get($game->nhl_game_id . ':' . $game->home_team_abbrev),
            ],
        ];
    }

    /**
     * Return games whose completed boxscore import contains rows for both teams and final scores.
     *
     * @param Collection<int,NhlGame> $games
     * @return Collection<int,int>
     */
    private function processedScoreGameIds(Collection $games): Collection
    {
        $gamesWithScores = $games
            ->filter(fn (NhlGame $game): bool =>
                $game->away_team_score !== null && $game->home_team_score !== null)
            ->pluck('nhl_game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->values();
        if ($gamesWithScores->isEmpty()) {
            return collect();
        }

        $completedGameIds = DB::table('nhl_import_progress')
            ->where('import_type', 'boxscore')
            ->where('status', 'completed')
            ->whereIn('game_id', $gamesWithScores->map(fn (int $gameId): string => (string) $gameId))
            ->pluck('game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->values();
        if ($completedGameIds->isEmpty()) {
            return collect();
        }

        return DB::table('nhl_boxscores')
            ->whereIn('nhl_game_id', $completedGameIds)
            ->select('nhl_game_id')
            ->groupBy('nhl_game_id')
            ->havingRaw('COUNT(DISTINCT nhl_team_id) >= 2')
            ->pluck('nhl_game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->values();
    }

    /** @return array<string,mixed>|null */
    private function recentGameContext(NhlGame $game): ?array
    {
        $today = Carbon::now('UTC')->startOfDay();
        if (! in_array($game->game_date->toDateString(), [
            $today->toDateString(), $today->copy()->subDay()->toDateString(),
        ], true)) {
            return null;
        }

        if ($game->game_state !== 'FINAL') {
            $context = $this->liveContext->forGame($game);
            if ($context !== null && filled($context['state'] ?? null)) {
                return $context;
            }
        }

        $state = mb_strtoupper((string) $game->game_state);
        return [
            'state' => $state,
            'label' => match ($state) {
                'FUT' => 'Pregame',
                'PRE' => 'Starting soon',
                'FINAL' => 'Final',
                default => $state === '' ? null : 'Live',
            },
            'show_score' => $state === 'FINAL'
                && $game->away_team_score !== null && $game->home_team_score !== null,
        ];
    }
}
