<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\PlayByPlay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Builds deterministic shot-attempt facts from already imported NHL play-by-play.
 */
class BuildNhlShotAttemptFacts
{
    private const FACT_VERSION = 'shot_attempt_facts_v2';
    private const REBOUND_WINDOW_SECONDS = 3;

    /**
     * Upsert shot-attempt facts for one NHL game.
     */
    public function buildForGame(int $gameId): int
    {
        $game = NhlGame::query()->find($gameId);

        if (! $game instanceof NhlGame) {
            return 0;
        }

        $plays = PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->orderBy('period')
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $playerProfiles = $this->playerProfilesForPlays($plays);

        $rows = [];
        $previousPlay = null;
        $previousShotByTeam = [];
        $previousRushSequenceByTeam = [];
        $scoreboard = [
            'home_score' => 0,
            'away_score' => 0,
        ];

        foreach ($plays as $play) {
            if (! $this->isShotAttemptFactSource($play) && ! $this->isPenaltyShotAttempt($play)) {
                $this->applyGoalToScoreboard($scoreboard, $play, $game);
                $previousPlay = $play;
                continue;
            }

            $teamId = $this->nullableInt($play->event_owner_team_id);
            $previousTeamShot = $teamId ? ($previousShotByTeam[$teamId] ?? null) : null;
            $previousRushSequence = $teamId ? ($previousRushSequenceByTeam[$teamId] ?? null) : null;
            $rushContext = $this->rushContext($play, $game, $previousPlay, $previousTeamShot, $previousRushSequence);
            $rows[] = $this->rowForPlay(
                $play,
                $game,
                $previousPlay,
                $previousTeamShot,
                $scoreboard,
                $rushContext,
                $playerProfiles
            );

            if ($teamId !== null) {
                $previousShotByTeam[$teamId] = $play;
                $previousRushSequenceByTeam[$teamId] = $rushContext['is_rush_sequence']
                    ? [
                        'play' => $play,
                        'origin_play_by_play_id' => $rushContext['origin_play_by_play_id'] ?? $play->id,
                    ]
                    : null;
            }

            $this->applyGoalToScoreboard($scoreboard, $play, $game);
            $previousPlay = $play;
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('nhl_shot_attempts_facts')->upsert(
            $rows,
            ['play_by_play_id'],
            array_values(array_diff(array_keys($rows[0]), ['play_by_play_id', 'created_at']))
        );

        return count($rows);
    }

    /**
     * Build one deterministic fact row from a shot-attempt PBP event.
     *
     * @return array<string,mixed>
     */
    private function rowForPlay(
        PlayByPlay $play,
        NhlGame $game,
        ?PlayByPlay $previousPlay,
        ?PlayByPlay $previousTeamShot,
        array $scoreSnapshot,
        array $rushContext,
        array $playerProfiles
    ): array {
        $teamId = $this->nullableInt($play->event_owner_team_id);
        $opponentTeamId = $this->opponentTeamId($game, $teamId);
        $shooterPlayerId = $this->nullableInt($play->shooting_player_id ?? $play->scoring_player_id);
        $goaliePlayerId = $this->nullableInt($play->goalie_in_net_player_id);
        $shooterProfile = $shooterPlayerId ? ($playerProfiles[$shooterPlayerId] ?? []) : [];
        $goalieProfile = $goaliePlayerId ? ($playerProfiles[$goaliePlayerId] ?? []) : [];
        $shooterShoots = $this->normalizeHand($shooterProfile['shoots'] ?? null);
        $goalieCatches = $this->normalizeHand($goalieProfile['shoots'] ?? null);
        $isPenaltyShot = $this->isPenaltyShotAttempt($play);
        $isShotOnGoal = $this->isShotOnGoalFact($play);
        $isUnblocked = $this->isUnblockedShotAttemptFact($play) || $isPenaltyShot;
        $isGoal = (string) $play->type_desc_key === 'goal';
        $isEmptyNet = $this->isEmptyNetAgainst(
            $play,
            $this->nullableInt($game->home_team_id),
            $this->nullableInt($game->away_team_id)
        );
        $secondsDelta = $this->secondsDelta($play, $previousPlay);
        $previousShotDelta = $this->secondsDelta($play, $previousTeamShot);
        $isRebound = $previousShotDelta !== null && $previousShotDelta <= self::REBOUND_WINDOW_SECONDS;
        $absAngle = $this->foldedAbsoluteShotAngle($play->shot_angle);
        $shotSide = $this->shotSide($play, $game, $teamId);
        $isOffWingAttempt = $this->isOffWingAttempt($shooterShoots, $shotSide, $play->shot_type);
        $scoreDifferential = $this->scoreDifferential(
            $scoreSnapshot['home_score'],
            $scoreSnapshot['away_score'],
            $game,
            $teamId
        );
        $now = now();

        return [
            'play_by_play_id' => $play->id,
            'previous_play_by_play_id' => $previousPlay?->id,
            'nhl_game_id' => $play->nhl_game_id,
            'nhl_event_id' => $play->nhl_event_id,
            'season_id' => $game->season_id,
            'game_date' => $game->game_date?->toDateString(),
            'fact_version' => self::FACT_VERSION,
            'event_type' => $play->type_desc_key,
            'attempt_result' => $this->attemptResult($play, $isPenaltyShot, $isGoal),
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => $isUnblocked,
            'is_shot_on_goal' => $isShotOnGoal || $isPenaltyShot,
            'is_goal' => $isGoal,
            'team_id' => $teamId,
            'opponent_team_id' => $opponentTeamId,
            'shooter_player_id' => $shooterPlayerId,
            'shooter_shoots' => $shooterShoots,
            'shooter_height_inches' => $shooterProfile['height_inches'] ?? null,
            'shooter_weight_lbs' => $shooterProfile['weight_lbs'] ?? null,
            'shooter_age_years' => $this->ageYearsAtGame($shooterProfile['dob'] ?? null, $game->game_date),
            'goalie_player_id' => $goaliePlayerId,
            'goalie_catches' => $goalieCatches,
            'goalie_height_inches' => $goalieProfile['height_inches'] ?? null,
            'goalie_weight_lbs' => $goalieProfile['weight_lbs'] ?? null,
            'goalie_age_years' => $this->ageYearsAtGame($goalieProfile['dob'] ?? null, $game->game_date),
            'blocking_player_id' => $this->nullableInt($play->blocking_player_id),
            'period' => $this->nullableInt($play->period),
            'period_type' => $play->period_type,
            'period_bucket' => $this->periodBucket($play),
            'seconds_in_game' => $this->nullableInt($play->seconds_in_game),
            'seconds_since_last_event' => $this->nullableInt($play->seconds_since_last_event),
            'time_bucket' => $this->timeBucket($play),
            'situation_code' => $play->situation_code,
            'strength' => $play->strength,
            'strength_bucket' => $this->strengthBucket($play, $isPenaltyShot, $isEmptyNet),
            'home_score' => $scoreSnapshot['home_score'],
            'away_score' => $scoreSnapshot['away_score'],
            'score_differential' => $scoreDifferential,
            'score_state_bucket' => $this->scoreStateBucket($scoreDifferential),
            'x_coord' => $this->nullableInt($play->x_coord),
            'y_coord' => $this->nullableInt($play->y_coord),
            'shot_distance' => $play->shot_distance,
            'shot_angle' => $play->shot_angle,
            'abs_shot_angle' => $absAngle,
            'shot_side' => $shotSide,
            'is_off_wing_attempt' => $isOffWingAttempt,
            'goalie_hand_matchup_bucket' => $this->goalieHandMatchupBucket($shooterShoots, $goalieCatches),
            'distance_bucket' => $this->distanceBucket($play->shot_distance),
            'angle_bucket' => $this->angleBucket($absAngle),
            'zone_code' => $play->zone_code,
            'zone_bucket' => $this->zoneBucket($play->zone_code),
            'shot_type' => $play->shot_type,
            'shot_type_bucket' => $this->shotTypeBucket($play->shot_type),
            'is_rebound' => $isRebound,
            'rebound_window_seconds' => self::REBOUND_WINDOW_SECONDS,
            'rebound_bucket' => $isRebound ? 'rebound' : 'not_rebound',
            'is_rush' => $rushContext['is_rush_sequence'],
            'is_rush_attempt' => $rushContext['is_rush_attempt'],
            'is_rush_sequence' => $rushContext['is_rush_sequence'],
            'rush_sequence_origin_play_by_play_id' => $rushContext['origin_play_by_play_id'],
            'rush_bucket' => $this->rushBucket($rushContext, $isRebound),
            'is_empty_net' => $isEmptyNet,
            'net_state_bucket' => $isEmptyNet ? 'empty_net' : 'goalie_in_net',
            'previous_event_type' => $previousPlay?->type_desc_key,
            'previous_event_team_id' => $this->nullableInt($previousPlay?->event_owner_team_id),
            'previous_event_seconds_delta' => $secondsDelta,
            'facts_payload' => json_encode([
                'previous_team_shot_play_by_play_id' => $previousTeamShot?->id,
                'previous_team_shot_seconds_delta' => $previousShotDelta,
                'rush_reason' => $rushContext['reason'],
                'source' => self::FACT_VERSION,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Return player context snapshots keyed by NHL player id.
     *
     * @param \Illuminate\Support\Collection<int, PlayByPlay> $plays
     * @return array<int,array{shoots:?string,height_inches:?int,weight_lbs:?int,dob:mixed}>
     */
    private function playerProfilesForPlays($plays): array
    {
        $playerIds = $plays
            ->flatMap(fn (PlayByPlay $play): array => [
                $this->nullableInt($play->shooting_player_id ?? $play->scoring_player_id),
                $this->nullableInt($play->goalie_in_net_player_id),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($playerIds === []) {
            return [];
        }

        return DB::table('players')
            ->whereIn('nhl_id', $playerIds)
            ->select(['nhl_id', 'shoots', 'height', 'weight', 'dob'])
            ->get()
            ->mapWithKeys(fn (object $player): array => [
                (int) $player->nhl_id => [
                    'shoots' => $this->normalizeHand($player->shoots),
                    'height_inches' => $this->heightInches($player->height),
                    'weight_lbs' => $this->nullableInt($player->weight),
                    'dob' => $player->dob,
                ],
            ])
            ->all();
    }

    private function isShootout(PlayByPlay $play): bool
    {
        return $play->period_type === 'SO';
    }

    private function isShotAttemptFactSource(PlayByPlay $play): bool
    {
        if ($this->isShootout($play)) {
            return false;
        }

        return in_array((string) $play->type_desc_key, [
            'shot-on-goal',
            'missed-shot',
            'blocked-shot',
            'goal',
        ], true);
    }

    private function isShotOnGoalFact(PlayByPlay $play): bool
    {
        if ($this->isShootout($play)) {
            return false;
        }

        return in_array((string) $play->type_desc_key, ['shot-on-goal', 'goal'], true);
    }

    private function isUnblockedShotAttemptFact(PlayByPlay $play): bool
    {
        if ($this->isShootout($play)) {
            return false;
        }

        return $this->isShotOnGoalFact($play) || (string) $play->type_desc_key === 'missed-shot';
    }

    private function isPenaltyShotAttempt(PlayByPlay $play): bool
    {
        $metadata = $play->metadata ?? [];

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        if (is_array($metadata) && ($metadata['is_penalty_shot_attempt'] ?? false) === true) {
            return true;
        }

        $details = is_array($metadata) ? ($metadata['details'] ?? []) : [];
        $description = strtolower((string) ($play->html_description ?? ''));

        return str_starts_with(strtolower((string) ($play->desc_key ?? $details['descKey'] ?? '')), 'ps-')
            || str_contains($description, 'penalty shot');
    }

    private function isEmptyNetAgainst(PlayByPlay $play, ?int $homeTeamId, ?int $awayTeamId): bool
    {
        if ($this->isShootout($play)) {
            return false;
        }

        $ownerTeamId = $this->nullableInt($play->event_owner_team_id);

        if ($ownerTeamId === null || $homeTeamId === null || $awayTeamId === null) {
            return false;
        }

        $situationCode = substr((string) ($play->situation_code ?? ''), 0, 4);

        if (strlen($situationCode) < 4) {
            return false;
        }

        if ($ownerTeamId === $homeTeamId) {
            return $situationCode[0] === '0';
        }

        if ($ownerTeamId === $awayTeamId) {
            return $situationCode[3] === '0';
        }

        return false;
    }

    private function attemptResult(PlayByPlay $play, bool $isPenaltyShot, bool $isGoal): string
    {
        if ($isPenaltyShot) {
            return 'penalty_shot';
        }

        if ($isGoal) {
            return 'goal';
        }

        return match ((string) $play->type_desc_key) {
            'shot-on-goal' => 'saved_shot',
            'missed-shot' => 'missed_shot',
            'blocked-shot' => 'blocked_shot',
            default => 'unknown',
        };
    }

    private function opponentTeamId(NhlGame $game, ?int $teamId): ?int
    {
        $homeTeamId = $this->nullableInt($game->home_team_id);
        $awayTeamId = $this->nullableInt($game->away_team_id);

        if ($teamId === $homeTeamId) {
            return $awayTeamId;
        }

        if ($teamId === $awayTeamId) {
            return $homeTeamId;
        }

        return null;
    }

    private function scoreDifferential(?int $homeScore, ?int $awayScore, NhlGame $game, ?int $teamId): ?int
    {
        if ($homeScore === null || $awayScore === null || $teamId === null) {
            return null;
        }

        if ($teamId === $this->nullableInt($game->home_team_id)) {
            return $homeScore - $awayScore;
        }

        if ($teamId === $this->nullableInt($game->away_team_id)) {
            return $awayScore - $homeScore;
        }

        return null;
    }

    /**
     * Derive conservative rush context from transition evidence and inherit it
     * only for same-team rebounds inside the rebound window.
     *
     * @param array{play:PlayByPlay,origin_play_by_play_id:int}|null $previousRushSequence
     *
     * @return array{is_rush_attempt:bool,is_rush_sequence:bool,origin_play_by_play_id:?int,reason:string}
     */
    private function rushContext(
        PlayByPlay $play,
        NhlGame $game,
        ?PlayByPlay $previousPlay,
        ?PlayByPlay $previousTeamShot,
        ?array $previousRushSequence
    ): array {
        if ($this->isEmptyNetAgainst(
            $play,
            $this->nullableInt($game->home_team_id),
            $this->nullableInt($game->away_team_id)
        )) {
            return [
                'is_rush_attempt' => false,
                'is_rush_sequence' => false,
                'origin_play_by_play_id' => null,
                'reason' => 'empty_net_excluded',
            ];
        }

        $isRushAttempt = $this->isDirectRushAttempt($play, $previousPlay);

        if ($isRushAttempt) {
            return [
                'is_rush_attempt' => true,
                'is_rush_sequence' => true,
                'origin_play_by_play_id' => $play->id,
                'reason' => 'direct_transition',
            ];
        }

        $previousRushPlay = $previousRushSequence['play'] ?? null;
        $previousRushDelta = $previousRushPlay instanceof PlayByPlay
            ? $this->secondsDelta($play, $previousRushPlay)
            : null;
        $previousShotDelta = $this->secondsDelta($play, $previousTeamShot);
        $isRushRebound = $previousRushDelta !== null
            && $previousRushDelta <= self::REBOUND_WINDOW_SECONDS
            && $previousShotDelta !== null
            && $previousShotDelta <= self::REBOUND_WINDOW_SECONDS;

        if ($isRushRebound) {
            return [
                'is_rush_attempt' => false,
                'is_rush_sequence' => true,
                'origin_play_by_play_id' => $previousRushSequence['origin_play_by_play_id'] ?? $previousRushPlay->id,
                'reason' => 'rebound_from_rush_attempt',
            ];
        }

        return [
            'is_rush_attempt' => false,
            'is_rush_sequence' => false,
            'origin_play_by_play_id' => null,
            'reason' => 'not_rush',
        ];
    }

    private function isDirectRushAttempt(PlayByPlay $play, ?PlayByPlay $previousPlay): bool
    {
        if ($previousPlay === null || $this->zoneBucket($play->zone_code) !== 'offensive') {
            return false;
        }

        $delta = $this->secondsDelta($play, $previousPlay);

        if ($delta === null || $delta > 8 || $this->isRushBoundaryEvent($previousPlay)) {
            return false;
        }

        $previousZone = $this->zoneBucket($previousPlay->zone_code);
        $previousType = (string) $previousPlay->type_desc_key;

        return in_array($previousZone, ['neutral', 'defensive'], true)
            || in_array($previousType, ['takeaway', 'giveaway'], true);
    }

    private function isRushBoundaryEvent(PlayByPlay $play): bool
    {
        return in_array((string) $play->type_desc_key, [
            'faceoff',
            'stoppage',
            'penalty',
            'goal',
            'period-end',
            'game-end',
        ], true);
    }

    /**
     * @param array{is_rush_attempt:bool,is_rush_sequence:bool,origin_play_by_play_id:?int,reason:string} $rushContext
     */
    private function rushBucket(array $rushContext, bool $isRebound): string
    {
        if ($rushContext['is_rush_attempt']) {
            return 'rush_attempt';
        }

        if ($rushContext['is_rush_sequence'] && $isRebound) {
            return 'rush_rebound';
        }

        return 'not_rush';
    }

    /**
     * Advance the in-game scoreboard after the current event has been projected.
     *
     * @param array{home_score:int,away_score:int} $scoreboard
     */
    private function applyGoalToScoreboard(array &$scoreboard, PlayByPlay $play, NhlGame $game): void
    {
        if ((string) $play->type_desc_key !== 'goal' || (string) ($play->period_type ?? '') === 'SO') {
            return;
        }

        $teamId = $this->nullableInt($play->event_owner_team_id);

        if ($teamId === $this->nullableInt($game->home_team_id)) {
            $scoreboard['home_score']++;
        }

        if ($teamId === $this->nullableInt($game->away_team_id)) {
            $scoreboard['away_score']++;
        }
    }

    private function scoreStateBucket(?int $scoreDifferential): string
    {
        return match (true) {
            $scoreDifferential === null => 'unknown',
            $scoreDifferential >= 2 => 'leading_by_2_plus',
            $scoreDifferential === 1 => 'leading_by_1',
            $scoreDifferential === 0 => 'tied',
            $scoreDifferential === -1 => 'trailing_by_1',
            default => 'trailing_by_2_plus',
        };
    }

    private function periodBucket(PlayByPlay $play): string
    {
        if ($play->period_type === 'OT') {
            return 'ot';
        }

        return match ((int) ($play->period ?? 0)) {
            1 => 'p1',
            2 => 'p2',
            3 => 'p3',
            0 => 'unknown',
            default => 'other',
        };
    }

    private function timeBucket(PlayByPlay $play): string
    {
        $seconds = $this->nullableInt($play->seconds_in_period);

        if ($seconds === null) {
            return 'unknown';
        }

        $period = (int) ($play->period ?? 0);
        $secondsRemaining = $this->nullableInt($play->seconds_remaining);

        if ($period >= 3 && $secondsRemaining !== null && $secondsRemaining <= 300) {
            return 'final_5';
        }

        if ($seconds < 400) {
            return 'early_period';
        }

        if ($seconds < 800) {
            return 'middle_period';
        }

        return 'late_period';
    }

    private function strengthBucket(PlayByPlay $play, bool $isPenaltyShot, bool $isEmptyNet): string
    {
        if ($isPenaltyShot) {
            return 'ps';
        }

        if ($isEmptyNet) {
            return 'en';
        }

        return match (strtoupper((string) $play->strength)) {
            'EV' => 'ev',
            'PP' => 'pp',
            'PK' => 'pk',
            default => 'unknown',
        };
    }

    private function distanceBucket(mixed $distance): string
    {
        if ($distance === null) {
            return 'unknown';
        }

        $distance = max(0.0, (float) $distance);

        if ($distance >= 60.0) {
            return 'd_060_plus';
        }

        $lower = (int) (floor($distance / 5) * 5);

        return sprintf('d_%03d_%03d', $lower, $lower + 5);
    }

    private function angleBucket(?float $angle): string
    {
        if ($angle === null) {
            return 'unknown';
        }

        $angle = max(0.0, $angle);

        if ($angle >= 90.0) {
            return 'a_080_090';
        }

        $lower = (int) (floor($angle / 10) * 10);

        return sprintf('a_%03d_%03d', $lower, $lower + 10);
    }

    private function foldedAbsoluteShotAngle(mixed $angle): ?float
    {
        if ($angle === null) {
            return null;
        }

        $absoluteAngle = abs((float) $angle);

        return min($absoluteAngle, abs(180.0 - $absoluteAngle));
    }

    private function zoneBucket(?string $zoneCode): string
    {
        return match (strtoupper(trim((string) $zoneCode))) {
            'O', 'OZ' => 'offensive',
            'N', 'NZ' => 'neutral',
            'D', 'DZ' => 'defensive',
            default => 'unknown',
        };
    }

    private function shotTypeBucket(?string $shotType): string
    {
        $normalized = strtolower(trim((string) $shotType));

        if ($normalized === '') {
            return 'unknown';
        }

        foreach ([
            'wrist' => 'wrist',
            'snap' => 'snap',
            'slap' => 'slap',
            'backhand' => 'backhand',
            'tip' => 'tip',
            'deflect' => 'deflection',
            'wrap' => 'wrap',
            'poke' => 'poke',
        ] as $needle => $bucket) {
            if (str_contains($normalized, $needle)) {
                return $bucket;
            }
        }

        return 'other';
    }

    private function shotSide(PlayByPlay $play, NhlGame $game, ?int $teamId): string
    {
        $yCoord = $this->nullableInt($play->y_coord);

        if ($yCoord === null) {
            return 'unknown';
        }

        if (abs($yCoord) < 10) {
            return 'center';
        }

        $attackingNetSide = $this->attackingNetSide($play, $game, $teamId);

        if ($attackingNetSide === null) {
            return 'unknown';
        }

        if ($attackingNetSide === 'right') {
            return $yCoord < 0 ? 'left' : 'right';
        }

        return $yCoord > 0 ? 'left' : 'right';
    }

    private function attackingNetSide(PlayByPlay $play, NhlGame $game, ?int $teamId): ?string
    {
        $homeDefendingSide = strtolower(trim((string) $play->home_team_defending_side));

        if (! in_array($homeDefendingSide, ['left', 'right'], true) || $teamId === null) {
            return null;
        }

        $homeTeamId = $this->nullableInt($game->home_team_id);
        $awayTeamId = $this->nullableInt($game->away_team_id);

        if ($teamId === $homeTeamId) {
            return $homeDefendingSide === 'left' ? 'right' : 'left';
        }

        if ($teamId === $awayTeamId) {
            return $homeDefendingSide;
        }

        return null;
    }

    private function isOffWingAttempt(?string $shooterShoots, string $shotSide, ?string $shotType): ?bool
    {
        if ($this->shotTypeBucket($shotType) === 'unknown') {
            return null;
        }

        if ($shooterShoots === null || ! in_array($shotSide, ['left', 'right'], true)) {
            return null;
        }

        return ($shooterShoots === 'R' && $shotSide === 'left')
            || ($shooterShoots === 'L' && $shotSide === 'right');
    }

    private function normalizeHand(mixed $hand): ?string
    {
        $normalized = strtoupper(trim((string) $hand));

        return in_array($normalized, ['L', 'R'], true) ? $normalized : null;
    }

    private function goalieHandMatchupBucket(?string $shooterShoots, ?string $goalieCatches): string
    {
        if ($shooterShoots === null || $goalieCatches === null) {
            return 'unknown';
        }

        return 'shooter_' . strtolower($shooterShoots) . '_vs_goalie_' . strtolower($goalieCatches);
    }

    private function heightInches(mixed $height): ?int
    {
        $height = trim((string) $height);

        if ($height === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*[\'-]\s*(\d+)/', $height, $matches) === 1) {
            return ((int) $matches[1] * 12) + (int) $matches[2];
        }

        if (ctype_digit($height)) {
            $inches = (int) $height;

            return $inches > 0 ? $inches : null;
        }

        return null;
    }

    private function ageYearsAtGame(mixed $dob, mixed $gameDate): ?float
    {
        if ($dob === null || $gameDate === null) {
            return null;
        }

        try {
            $birthDate = $dob instanceof CarbonInterface
                ? CarbonImmutable::instance($dob)
                : CarbonImmutable::parse((string) $dob);
            $playedOn = $gameDate instanceof CarbonInterface
                ? CarbonImmutable::instance($gameDate)
                : CarbonImmutable::parse((string) $gameDate);
        } catch (\Throwable) {
            return null;
        }

        if ($birthDate->greaterThan($playedOn)) {
            return null;
        }

        return round($birthDate->diffInDays($playedOn) / 365.2425, 2);
    }

    private function secondsDelta(PlayByPlay $play, ?PlayByPlay $previousPlay): ?int
    {
        if ($previousPlay === null || $play->seconds_in_game === null || $previousPlay->seconds_in_game === null) {
            return null;
        }

        return max(0, (int) $play->seconds_in_game - (int) $previousPlay->seconds_in_game);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
