<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\Player;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Supplies short-lived NHL gamecenter state for today's unprocessed games. */
class NhlGameLiveContext
{
    use HasAPITrait;

    /** Create the provider context reader with canonical goalie selection. */
    public function __construct(private readonly NhlStartingGoalieSelector $goalies)
    {
    }

    /** Repair missing historical final scores without loading player or goalie context. */
    public function refreshFinalScore(NhlGame $game): void
    {
        $response = Cache::remember(
            'nhl:gamecenter:boxscore:' . $game->nhl_game_id,
            15,
            function () use ($game): array|string {
                try {
                    return $this->getAPIData('nhl', 'boxscore', ['gameId' => $game->nhl_game_id]);
                } catch (Throwable $exception) {
                    report($exception);

                    return [];
                }
            }
        );
        if (! is_array($response)
            || (int) ($response['id'] ?? 0) !== (int) $game->nhl_game_id
            || ! in_array(mb_strtoupper((string) ($response['gameState'] ?? '')), ['FINAL', 'OFF'], true)) {
            return;
        }

        $away = data_get($response, 'awayTeam.score');
        $home = data_get($response, 'homeTeam.score');
        if (! is_int($away) || ! is_int($home) || $away < 0 || $home < 0) {
            return;
        }

        $game->forceFill([
            'game_state' => mb_strtoupper((string) $response['gameState']),
            'away_team_score' => $away,
            'home_team_score' => $home,
        ])->save();
    }

    /**
     * Read shared provider evidence; compact mode omits goalie selection and roster assembly.
     *
     * @return array<string,mixed>|null
     */
    public function forGame(NhlGame $game, bool $compact = false): ?array
    {
        try {
            $response = Cache::remember(
                'nhl:gamecenter:boxscore:' . $game->nhl_game_id,
                15,
                fn (): array|string => $this->getAPIData('nhl', 'boxscore', ['gameId' => $game->nhl_game_id])
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($response)) {
            return null;
        }

        return $this->contextFromProvider($game, $response, $compact);
    }

    /** Normalize trusted provider evidence using the existing public presentation contract. */
    private function contextFromProvider(NhlGame $game, array $response, bool $compact): ?array
    {
        $this->goalies->lockBoxscoreStarters((int) $game->nhl_game_id, $response);

        $state = mb_strtoupper((string) ($response['gameState'] ?? ''));
        $showScore = $state !== '' && ! in_array($state, ['FUT', 'PRE'], true);
        if ($showScore) {
            $game->forceFill([
                'game_state' => $state,
                'away_team_score' => data_get($response, 'awayTeam.score', $game->away_team_score),
                'home_team_score' => data_get($response, 'homeTeam.score', $game->home_team_score),
                'away_team_sog' => data_get($response, 'awayTeam.sog', $game->away_team_sog),
                'home_team_sog' => data_get($response, 'homeTeam.sog', $game->home_team_sog),
            ])->save();
        } elseif ($state !== '' && $game->game_state !== $state) {
            $game->forceFill(['game_state' => $state])->save();
        }

        if ($compact) {
            $teams = [];
            foreach (['away', 'home'] as $side) {
                $team = $response[$side.'Team'] ?? [];
                $teams[$side] = ['team_abbrev' => $team['abbrev'] ?? null,
                    'team_logo' => $team['logo'] ?? null, 'score' => $showScore ? ($team['score'] ?? null) : null];
            }

            return ['state' => $state, 'show_score' => $showScore,
                'live_game' => $showScore ? ['game_state' => $state,
                    'time_remaining' => data_get($response, 'clock.timeRemaining'),
                    'in_intermission' => data_get($response, 'clock.inIntermission'),
                    'period_number' => data_get($response, 'periodDescriptor.number'),
                    'period_type' => data_get($response, 'periodDescriptor.periodType'),
                    'start_time_utc' => $response['startTimeUTC'] ?? null, ...$teams] : null];
        }

        if ($showScore && $state !== 'FINAL') {
            return [
                'state' => $state, 'label' => 'Live', 'show_score' => true,
                'live_game' => $this->livePresentation($response),
            ];
        }

        return [
            'state' => $state,
            'label' => match ($state) {
                'FUT' => 'Pregame',
                'PRE' => 'Starting soon',
                'FINAL' => 'Final',
                default => $state === '' ? null : 'Live',
            },
            'show_score' => $showScore,
            'away_score' => $showScore ? data_get($response, 'awayTeam.score') : null,
            'home_score' => $showScore ? data_get($response, 'homeTeam.score') : null,
            'away_goalie' => $this->goalie($response, 'awayTeam', $game, (string) $game->away_team_abbrev),
            'home_goalie' => $this->goalie($response, 'homeTeam', $game, (string) $game->home_team_abbrev),
        ];
    }

    /** Public reads consume shared producer snapshots and never initiate NHL requests. */
    public function sharedForGame(NhlGame $game, bool $compact = false): ?array
    {
        $feed = app(NhlLiveGameFeed::class);
        $snapshot = $feed->snapshot((int) $game->nhl_game_id);
        if ($feed->fresh($snapshot)) {
            return $this->contextFromProvider($game, $snapshot['boxscore'], $compact);
        }
        $state = strtoupper((string) $game->game_state);
        if (in_array($state, ['FUT', 'PRE'], true)) {
            $context = ['state' => $state, 'label' => $state === 'PRE' ? 'Starting soon' : 'Pregame', 'show_score' => false];
            if (! $compact) {
                foreach (['away', 'home'] as $side) {
                    $context[$side.'_goalie'] = $this->goalies->select((int) $game->nhl_game_id,
                        (string) $game->{$side.'_team_abbrev'}, (string) $game->season_id);
                }
            }

            return $context;
        }

        return null;
    }

    /** Read live detail only from the shared feed, with explicit freshness. */
    public function publicDetail(NhlGame $game): ?array
    {
        $feed = app(NhlLiveGameFeed::class);
        $snapshot = $feed->snapshot((int) $game->nhl_game_id);
        if (! $feed->fresh($snapshot) || in_array($snapshot['boxscore']['gameState'] ?? '', ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true)) {
            return null;
        }

        return $this->detailFromProvider($snapshot['boxscore'], $snapshot['pbp']);
    }

    /** Persist source state centrally before notifying subscribers. */
    public function persistShared(NhlGame $game, array $box): void
    {
        $this->goalies->lockBoxscoreStarters((int) $game->nhl_game_id, $box);
        $values = ['game_state' => $box['gameState'] ?? $game->game_state];
        foreach (['away', 'home'] as $side) {
            foreach (['score', 'sog'] as $field) {
                if (isset($box[$side.'Team'][$field])) {
                    $values[$side.'_team_'.$field] = $box[$side.'Team'][$field];
                }
            }
        }
        $game->forceFill($values)->save();
    }

    /** Assemble public detail from already validated shared provider responses. */
    public function detailFromProvider(array $box, array $pbp): array
    {
        return ['game' => $this->livePresentation($box), 'boxscore' => $box,
            'plays' => $pbp['plays'] ?? [], 'roster' => $pbp['rosterSpots'] ?? [], 'events_available' => isset($pbp['id'])];
    }

    /** Build live presentation exclusively from provider JSON, except canonical goalie avatars. */
    private function livePresentation(array $response): array
    {
        $result = [
            'provider_game_id' => $response['id'] ?? null,
            'game_date' => $response['gameDate'] ?? null,
            'start_time_utc' => $response['startTimeUTC'] ?? null,
            'game_type' => $response['gameType'] ?? null,
            'game_state' => $response['gameState'], 'game_state_label' => 'Live',
            'live_mode' => true, 'live_data_unavailable' => false,
        ];
        foreach (['away', 'home'] as $side) {
            $team = $response[$side . 'Team'] ?? [];
            $rows = collect(data_get($response, "playerByGameStats.{$side}Team.goalies", []))
                ->filter(fn ($row): bool => is_array($row) && isset($row['playerId']))
                ->filter(fn (array $row): bool => ($row['starter'] ?? false) === true
                    || preg_match('/[1-9]/', (string) ($row['toi'] ?? '')) === 1
                    || (int) ($row['shotsAgainst'] ?? 0) > 0
                    || preg_match('/[1-9]/', (string) ($row['saveShotsAgainst'] ?? '')) === 1
                    || (int) ($row['saves'] ?? 0) > 0 || (int) ($row['goalsAgainst'] ?? 0) > 0);
            $avatars = Player::query()->whereIn('nhl_id', $rows->pluck('playerId'))
                ->pluck('head_shot_url', 'nhl_id');
            $goalies = $rows->map(function (array $row) use ($avatars): array {
                $saves = isset($row['saves']) ? (int) $row['saves'] : null;
                if ($saves === null && preg_match('/^(\d+)\/(\d+)$/', (string) ($row['saveShotsAgainst'] ?? ''), $match) === 1
                    && (int) $match[1] <= (int) $match[2]) {
                    $saves = (int) $match[1];
                }
                if ($saves === null && isset($row['shotsAgainst'], $row['goalsAgainst'])
                    && (int) $row['shotsAgainst'] >= (int) $row['goalsAgainst']) {
                    $saves = (int) $row['shotsAgainst'] - (int) $row['goalsAgainst'];
                }

                return [
                    'nhl_player_id' => (int) $row['playerId'],
                    'name' => data_get($row, 'name.default'),
                    'is_starter' => ($row['starter'] ?? false) === true,
                    'status' => 'confirmed', 'selection_source' => 'nhl_boxscore',
                    'avatar_url' => $avatars->get((int) $row['playerId']),
                    'goals_against' => isset($row['goalsAgainst']) ? (int) $row['goalsAgainst'] : null,
                    'saves' => $saves,
                ];
            })->values()->all();
            $roster = [];
            foreach (['forwards' => 'forward', 'defense' => 'defense', 'goalies' => 'goalie'] as $group => $role) {
                foreach (data_get($response, "playerByGameStats.{$side}Team.{$group}", []) as $row) {
                    if (! is_array($row) || empty($row['playerId'])) {
                        continue;
                    }
                    $roster[] = [
                        'nhl_player_id' => (int) $row['playerId'],
                        'player_name' => data_get($row, 'name.default'),
                        'position' => $row['position'] ?? null, 'lineup_role' => $role,
                        'sweater_number' => $row['sweaterNumber'] ?? null,
                        'is_starter' => $role === 'goalie' && ($row['starter'] ?? false) === true,
                    ];
                }
            }
            $starters = collect($goalies)->where('is_starter', true)->values();
            $result[$side] = [
                'team_id' => $team['id'] ?? null, 'team_abbrev' => $team['abbrev'] ?? null,
                'team_name' => data_get($team, 'commonName.default'), 'team_logo' => $team['logo'] ?? null,
                'score' => $team['score'] ?? null, 'sog' => $team['sog'] ?? null,
                'goalies' => $goalies,
                'starting_goalie' => $starters->count() === 1 ? $starters->first() : null,
                'lineup' => $roster === [] ? null : ['evidence_status' => 'official',
                    'manual_override' => false, 'team_abbrev' => $team['abbrev'] ?? null,
                    'players' => $roster],
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $response @return array<string,mixed>|null */
    private function goalie(array $response, string $side, NhlGame $game, string $teamAbbrev): ?array
    {
        $rows = collect(data_get($response, "playerByGameStats.{$side}.goalies", []));
        $starter = $rows->first(fn (mixed $goalie): bool => is_array($goalie) && ($goalie['starter'] ?? false) === true);
        if (is_array($starter) && isset($starter['playerId'])) {
            $selected = $this->goalies->select(
                (int) $game->nhl_game_id,
                $teamAbbrev,
                providedGoalieId: (int) $starter['playerId']
            );

            return [
                ...($selected ?? []),
                'nhl_player_id' => (int) $starter['playerId'],
                'name' => $selected['name'] ?? data_get($starter, 'name.default') ?? (string) $starter['playerId'],
                'status' => 'confirmed',
                'selection_source' => 'nhl_boxscore',
                'goals_against' => isset($starter['goalsAgainst']) ? (int) $starter['goalsAgainst'] : null,
                'saves' => isset($starter['saves']) ? (int) $starter['saves'] : null,
            ];
        }

        $selected = $this->goalies->select((int) $game->nhl_game_id, $teamAbbrev);
        if ($selected === null) {
            return null;
        }
        $row = $rows->first(fn (mixed $goalie): bool => is_array($goalie)
            && (int) ($goalie['playerId'] ?? 0) === (int) $selected['nhl_player_id']);

        return [
            ...$selected,
            'goals_against' => isset($row['goalsAgainst']) ? (int) $row['goalsAgainst'] : null,
            'saves' => isset($row['saves']) ? (int) $row['saves'] : null,
        ];
    }
}
