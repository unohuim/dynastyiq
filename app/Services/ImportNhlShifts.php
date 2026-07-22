<?php

namespace App\Services;

use App\Models\NhlShift;
use App\Traits\HasAPITrait;
use App\Models\NhlGameSummary;
use App\Models\Player;
use App\Classes\ImportNHLPlayer;
use App\Models\NhlGame;
use App\Models\NhlBoxscore;
use App\Models\PlayByPlay;
use Illuminate\Support\Facades\DB;

class ImportNhlShifts
{
    use HasAPITrait;

    private const SHIFT_TYPE_CODE = 517;
    private const SHORT_ARTIFACT_BASE_SECONDS = 30;
    private const SHORT_ARTIFACT_MAX_SECONDS = 45;

    /**
     * Import raw shift data from NHL API and store into nhl_shifts table,
     * including calculated fields for shift start/end seconds and duration seconds.
     *
     * @param string $nhlGameId
     * @return int
     */
    public function import(string $nhlGameId): int
    {
        $nhlGame = NhlGame::find($nhlGameId);

        if (!$nhlGame) {
            throw new \RuntimeException(sprintf(
                'Unable to import NHL shifts because game %s is not stored.',
                (string) $nhlGameId
            ));
        }

        // Fetch shifts from the special base URL
        $response = $this->getAPIDataFullUrl(
            "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={$nhlGameId}"
        );

        if (empty($response['data'])) {
            return app(ImportNhlHtmlToiShifts::class)->import((int) $nhlGameId);
        }

        $shiftsData = $response['data'];

        $shiftsCount = 0;
        $resolvedShifts = [];
        $candidateShifts = [];

        NhlShift::where('nhl_game_id', $nhlGameId)->delete();

        foreach ($shiftsData as $shift) {
            if (!$this->isShiftRow($shift) || !$this->isGameTeamShiftRow($shift, $nhlGame)) {
                continue;
            }

            // Calculate elapsed seconds from period and string times
            $shiftStartSeconds = parseElapsedSeconds($shift['startTime'] ?? null, $shift['period'] ?? 1);
            $shiftEndSeconds = parseElapsedSeconds($shift['endTime'] ?? null, $shift['period'] ?? 1);
            $durationSeconds = parseElapsedSeconds($shift['duration'] ?? null);

            if ($shiftStartSeconds === null || $shiftEndSeconds === null || $shiftEndSeconds <= $shiftStartSeconds) {
                continue;
            }

            $playerId = $shift['playerId'];
            $shiftKey = $this->shiftIntervalKey($shift, $shiftStartSeconds);
            $candidateShift = [
                'player_id' => $playerId,
                'shift_number' => (int) $shift['shiftNumber'],
                'shift_start_seconds' => $shiftStartSeconds,
                'shift_end_seconds' => $shiftEndSeconds,
                'shift_duration_seconds' => $durationSeconds ?? 0,
                'shift' => $shift,
            ];

            $candidateShifts[] = $candidateShift;

            if (
                isset($resolvedShifts[$shiftKey])
                && $resolvedShifts[$shiftKey]['shift_number'] <= (int) $shift['shiftNumber']
            ) {
                continue;
            }

            $resolvedShifts[$shiftKey] = $candidateShift;
        }

        $boxscoreTargets = $this->boxscoreShiftTargets($nhlGameId);
        $allowedPlayerIds = $this->boxscoreOrPbpPlayerIds($nhlGameId);

        if ($allowedPlayerIds !== null) {
            $resolvedShifts = $this->filterShiftchartOnlyPlayers($resolvedShifts, $allowedPlayerIds);
            $candidateShifts = $this->filterShiftchartOnlyPlayers($candidateShifts, $allowedPlayerIds);
        }

        $containedFilteredShifts = $this->removeContainedShiftIntervals($resolvedShifts);
        $normalizedShifts = $this->reconcileWithBoxscoreTargets(
            $this->removeReusedShortShiftNumbers($containedFilteredShifts, $boxscoreTargets),
            $candidateShifts,
            $boxscoreTargets
        );
        $normalizedShifts = $this->trimOvertimeShiftsAfterGameEnd($normalizedShifts, $nhlGame);
        $normalizedShifts = $this->removeGoalieEmptyNetArtifacts($normalizedShifts, $nhlGame);
        $normalizedShifts = $this->removeImpossibleOverCapSkaterArtifacts($normalizedShifts, $nhlGame, $boxscoreTargets);
        $duplicateToiCredits = $this->duplicateToiCreditsForTargets(
            $normalizedShifts,
            $candidateShifts,
            $boxscoreTargets
        );

        foreach ($normalizedShifts as $resolvedShift) {
            $shift = $resolvedShift['shift'];

            NhlShift::updateOrCreate(
                [
                    'nhl_game_id' => $nhlGameId,
                    'nhl_player_id' => $resolvedShift['player_id'],
                    'shift_start_seconds' => $resolvedShift['shift_start_seconds'],
                    'event_number' => $shift['eventNumber'] ?? null,
                    'type_code' => $shift['typeCode'] ?? null,
                ],
                [
                    'start_time' => $shift['startTime'] ?? null,
                    'end_time' => $shift['endTime'] ?? null,
                    'duration' => $shift['duration'] ?? null,
                    'period' => $shift['period'] ?? 1,
                    'shift_end_seconds' => $resolvedShift['shift_end_seconds'],
                    'shift_duration_seconds' => $resolvedShift['shift_duration_seconds'],
                    'shift_number' => $shift['shiftNumber'] ?? 0,
                    'pos_type' => null, // to be updated later from player data
                    'position' => null, // to be updated later from player data
                    'team_abbrev' => $shift['teamAbbrev'] ?? null,
                    'team_name' => $shift['teamName'] ?? null,
                    'first_name' => $shift['firstName'] ?? null,
                    'last_name' => $shift['lastName'] ?? null,
                    'detail_code' => $shift['detailCode'] ?? null,
                    'event_description' => $shift['eventDescription'] ?? null,
                    'event_details' => $shift['eventDetails'] ?? null,
                    'event_number' => $shift['eventNumber'] ?? null,
                    'type_code' => $shift['typeCode'] ?? null,
                    'hex_value' => $shift['hexValue'] ?? null,
                    'unit_id' => null, // to be assigned later
                ]
            );

            $shiftsCount++;
        }

        // Sum TOI by player for this game
        $toiSums = NhlShift::where('nhl_game_id', $nhlGameId)
            ->where('type_code', self::SHIFT_TYPE_CODE)
            ->where('shift_number', '>', 0)
            ->whereColumn('shift_end_seconds', '>', 'shift_start_seconds')
            ->selectRaw('nhl_player_id, team_abbrev, SUM(shift_duration_seconds) as total_toi, COUNT(*) as shifts')
            ->groupBy('nhl_player_id', 'team_abbrev')
            ->get();
        $summaryTotals = $this->summaryTotalsForTargets($toiSums, $duplicateToiCredits, $boxscoreTargets);

        foreach ($toiSums as $toi) {
            $player = Player::where('nhl_id', $toi->nhl_player_id)->first();
            $summaryTotal = $summaryTotals[(int) $toi->nhl_player_id] ?? [
                'toi' => (int) $toi->total_toi,
                'shifts' => (int) $toi->shifts,
            ];

            if (!isset($player)) {
                $playerImport = new ImportNHLPlayer;
                $playerImport->import($toi->nhl_player_id);
                $player = Player::where('nhl_id', $toi->nhl_player_id)->first();
            }

            $teamId = $nhlGame->getTeamIdByAbbrev($toi->team_abbrev);

            if ($teamId === null) {
                throw new \RuntimeException(sprintf(
                    'Unable to resolve NHL team id for game %s shift player %s with team abbrev %s.',
                    (string) $nhlGameId,
                    (string) $toi->nhl_player_id,
                    (string) $toi->team_abbrev
                ));
            }

            NhlGameSummary::updateOrCreate(
                [
                    'nhl_game_id' => $nhlGameId,
                    'nhl_player_id' => $toi->nhl_player_id,
                ],
                [
                    'toi' => (int) $summaryTotal['toi'],
                    'shifts' => (int) $summaryTotal['shifts'],
                    'nhl_team_id' => $teamId,
                ]
            );
        }

        return $shiftsCount;
    }

    /**
     * Determine whether a shift chart feed row is an actual player shift interval.
     *
     * @param array<string, mixed> $shift
     * @return bool
     */
    private function isShiftRow(array $shift): bool
    {
        return !empty($shift['playerId'])
            && (int) ($shift['typeCode'] ?? 0) === self::SHIFT_TYPE_CODE
            && (int) ($shift['shiftNumber'] ?? 0) > 0
            && !empty($shift['startTime'])
            && !empty($shift['endTime'])
            && !empty($shift['duration']);
    }

    /**
     * Determine whether a shift row belongs to one of the teams in the stored game.
     *
     * @param array<string, mixed> $shift
     * @return bool
     */
    private function isGameTeamShiftRow(array $shift, NhlGame $game): bool
    {
        $teamAbbrev = (string) ($shift['teamAbbrev'] ?? '');

        return $teamAbbrev !== ''
            && $game->getTeamIdByAbbrev($teamAbbrev) !== null;
    }

    /**
     * Build summary totals after applying tightly-scoped boxscore-backed corrections.
     *
     * @param \Illuminate\Support\Collection<int,object> $toiSums
     * @param array<int,int> $duplicateToiCredits
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<int,array{team_abbrev:string,toi:int,shifts:int}>
     */
    private function summaryTotalsForTargets(
        \Illuminate\Support\Collection $toiSums,
        array $duplicateToiCredits,
        array $boxscoreTargets
    ): array {
        $totals = [];

        foreach ($toiSums as $toi) {
            $playerId = (int) $toi->nhl_player_id;
            $derivedToi = (int) $toi->total_toi + (int) ($duplicateToiCredits[$playerId] ?? 0);
            $derivedShifts = (int) $toi->shifts;

            $totals[$playerId] = [
                'team_abbrev' => (string) $toi->team_abbrev,
                'toi' => $derivedToi,
                'shifts' => $this->summaryShiftCountForTarget(
                    $playerId,
                    $derivedShifts,
                    $derivedToi,
                    $boxscoreTargets,
                ),
            ];
        }

        return $this->reconcileSummaryOnlyToiTransfers($totals, $boxscoreTargets);
    }

    /**
     * Transfer summary-only TOI when paired player totals prove a source misallocation.
     *
     * @param array<int,array{team_abbrev:string,toi:int,shifts:int}> $totals
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<int,array{team_abbrev:string,toi:int,shifts:int}>
     */
    private function reconcileSummaryOnlyToiTransfers(array $totals, array $boxscoreTargets): array
    {
        $underByTeamAndDelta = [];
        $overByTeamAndDelta = [];

        foreach ($totals as $playerId => $total) {
            $target = $boxscoreTargets[$playerId] ?? null;

            if (!$target || $target['is_goalie']) {
                continue;
            }

            $toiDelta = (int) $total['toi'] - (int) $target['toi'];
            $shiftDelta = (int) $total['shifts'] - (int) $target['shifts'];

            if ($toiDelta > 0 && $toiDelta <= self::SHORT_ARTIFACT_MAX_SECONDS && $shiftDelta === 0) {
                $overByTeamAndDelta[$total['team_abbrev'] . '|' . $toiDelta][] = $playerId;
                continue;
            }

            if ($toiDelta < 0 && abs($toiDelta) <= self::SHORT_ARTIFACT_MAX_SECONDS && $shiftDelta === -1) {
                $underByTeamAndDelta[$total['team_abbrev'] . '|' . abs($toiDelta)][] = $playerId;
            }
        }

        $usedOverPlayers = [];

        foreach ($underByTeamAndDelta as $key => $underPlayers) {
            $overPlayers = array_values(array_filter(
                $overByTeamAndDelta[$key] ?? [],
                fn (int $playerId): bool => !isset($usedOverPlayers[$playerId])
            ));

            if (count($underPlayers) !== 1 || count($overPlayers) !== 1) {
                continue;
            }

            [, $delta] = explode('|', $key, 2);
            $deltaSeconds = (int) $delta;
            $underPlayerId = (int) $underPlayers[0];
            $overPlayerId = (int) $overPlayers[0];

            $totals[$overPlayerId]['toi'] -= $deltaSeconds;
            $totals[$underPlayerId]['toi'] += $deltaSeconds;
            $totals[$underPlayerId]['shifts'] += 1;
            $usedOverPlayers[$overPlayerId] = true;
        }

        return $totals;
    }

    /**
     * Borrow official shift count when the feed is missing one count-only skater shift.
     *
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     */
    private function summaryShiftCountForTarget(
        int $playerId,
        int $derivedShifts,
        int $derivedToi,
        array $boxscoreTargets
    ): int {
        $target = $boxscoreTargets[$playerId] ?? null;

        if (
            !$target
            || $target['is_goalie']
            || $target['shifts'] !== $derivedShifts + 1
            || abs($target['toi'] - $derivedToi) > 1
        ) {
            return $derivedShifts;
        }

        return $target['shifts'];
    }

    /**
     * Build the provider interval identity for a real shift row.
     *
     * @param array<string, mixed> $shift
     * @param int $shiftStartSeconds
     * @return string
     */
    private function shiftIntervalKey(array $shift, int $shiftStartSeconds): string
    {
        return implode('|', [
            (string) ($shift['playerId'] ?? ''),
            (string) ($shift['period'] ?? ''),
            (string) $shiftStartSeconds,
            (string) ($shift['eventNumber'] ?? ''),
            (string) ($shift['typeCode'] ?? ''),
        ]);
    }

    /**
     * Remove provider artifacts where a shift is fully contained inside another shift.
     *
     * @param array<string,array<string,mixed>> $resolvedShifts
     * @return array<int,array<string,mixed>>
     */
    private function removeContainedShiftIntervals(array $resolvedShifts): array
    {
        $kept = [];
        $sortedShifts = collect($resolvedShifts)
            ->sortBy([
                ['shift_start_seconds', 'asc'],
                ['shift_end_seconds', 'desc'],
            ]);

        foreach ($sortedShifts as $resolvedShift) {
            $groupKey = implode('|', [
                (string) $resolvedShift['player_id'],
                (string) ($resolvedShift['shift']['period'] ?? ''),
            ]);

            $kept[$groupKey] ??= [];
            $contained = false;

            foreach ($kept[$groupKey] as $keptShift) {
                if (
                    $resolvedShift['shift_start_seconds'] >= $keptShift['shift_start_seconds']
                    && $resolvedShift['shift_end_seconds'] <= $keptShift['shift_end_seconds']
                ) {
                    $contained = true;
                    break;
                }
            }

            if (!$contained) {
                $kept[$groupKey][] = $resolvedShift;
            }
        }

        return collect($kept)
            ->flatten(1)
            ->sortBy([
                ['shift_start_seconds', 'asc'],
                ['shift_end_seconds', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Remove tiny provider artifacts when the same shift number is reused later.
     *
     * @param array<int,array<string,mixed>> $resolvedShifts
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<int,array<string,mixed>>
     */
    private function removeReusedShortShiftNumbers(array $resolvedShifts, array $boxscoreTargets): array
    {
        $discardKeys = [];
        $byPlayer = collect($resolvedShifts)->groupBy(fn (array $resolvedShift): int => (int) $resolvedShift['player_id']);
        $byPlayerShiftNumber = collect($resolvedShifts)->groupBy(
            fn (array $resolvedShift): string => implode('|', [
                (string) $resolvedShift['player_id'],
                (string) $resolvedShift['shift_number'],
            ])
        );

        foreach ($byPlayerShiftNumber as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $sortedGroup = $group->sortBy('shift_start_seconds')->values();
            $firstShift = $sortedGroup->first();
            $firstPeriod = (int) ($firstShift['shift']['period'] ?? 0);
            $hasLaterPeriodReuse = $sortedGroup->contains(
                fn (array $resolvedShift): bool => (int) ($resolvedShift['shift']['period'] ?? 0) > $firstPeriod
            );

            if (!$hasLaterPeriodReuse || (int) ($firstShift['shift_duration_seconds'] ?? 0) > 5) {
                continue;
            }

            if ($this->shortShiftDropWouldMissTarget(
                $byPlayer->get((int) $firstShift['player_id'], collect())->values()->all(),
                $firstShift,
                $boxscoreTargets
            )) {
                continue;
            }

            $discardKeys[$this->resolvedShiftIdentity($firstShift)] = true;
        }

        return collect($resolvedShifts)
            ->reject(fn (array $resolvedShift): bool => isset($discardKeys[$this->resolvedShiftIdentity($resolvedShift)]))
            ->values()
            ->all();
    }

    /**
     * Remove goalie shiftchart rows that PBP proves are empty-net artifacts.
     *
     * @param array<int,array<string,mixed>> $resolvedShifts
     * @return array<int,array<string,mixed>>
     */
    private function removeGoalieEmptyNetArtifacts(array $resolvedShifts, NhlGame $game): array
    {
        $artifactKeys = $this->goalieEmptyNetArtifactKeys($game);

        if ($artifactKeys === []) {
            return $resolvedShifts;
        }

        return collect($resolvedShifts)
            ->reject(fn (array $resolvedShift): bool => isset($artifactKeys[$this->goalieEmptyNetShiftKey($resolvedShift)]))
            ->values()
            ->all();
    }

    /**
     * Build shift identities for goalies who were in net for a goal, then empty net immediately after it.
     *
     * @return array<string,bool>
     */
    private function goalieEmptyNetArtifactKeys(NhlGame $game): array
    {
        $plays = PlayByPlay::query()
            ->where('nhl_game_id', $game->nhl_game_id)
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'event_owner_team_id',
                'period',
                'seconds_in_game',
                'type_desc_key',
                'goalie_in_net_player_id',
                'situation_code',
            ])
            ->values();

        if ($plays->isEmpty()) {
            return [];
        }

        $artifactKeys = [];

        foreach ($plays as $index => $play) {
            if (
                $play->type_desc_key !== 'goal'
                || empty($play->goalie_in_net_player_id)
                || $play->seconds_in_game === null
                || $play->period === null
            ) {
                continue;
            }

            $goalieTeamId = $this->goalieTeamIdForGoal($play, $game);

            if ($goalieTeamId === null) {
                continue;
            }

            $emptyNetPlay = $plays
                ->slice($index + 1)
                ->first(fn (PlayByPlay $nextPlay): bool => $nextPlay->seconds_in_game !== null
                    && (int) $nextPlay->seconds_in_game === (int) $play->seconds_in_game
                    && $this->teamHasEmptyNet($nextPlay->situation_code, $goalieTeamId, $game));

            if (!$emptyNetPlay) {
                continue;
            }

            $teamAbbrev = $this->teamAbbrevForGameTeam($goalieTeamId, $game);

            if ($teamAbbrev === null) {
                continue;
            }

            $artifactKeys[implode('|', [
                (string) $play->goalie_in_net_player_id,
                (string) $play->period,
                (string) $play->seconds_in_game,
                $teamAbbrev,
            ])] = true;
        }

        return $artifactKeys;
    }

    /**
     * Determine the team of the goalie who allowed the goal.
     *
     * @param PlayByPlay $play
     * @param NhlGame $game
     * @return int|null
     */
    private function goalieTeamIdForGoal(PlayByPlay $play, NhlGame $game): ?int
    {
        $eventOwnerTeamId = (int) ($play->event_owner_team_id ?? 0);

        if ($eventOwnerTeamId === (int) $game->home_team_id) {
            return (int) $game->away_team_id;
        }

        if ($eventOwnerTeamId === (int) $game->away_team_id) {
            return (int) $game->home_team_id;
        }

        return null;
    }

    /**
     * Determine whether a situation code has no goalie for the given game team.
     *
     * @param string|null $situationCode
     * @param int $teamId
     * @param NhlGame $game
     * @return bool
     */
    private function teamHasEmptyNet(?string $situationCode, int $teamId, NhlGame $game): bool
    {
        $code = substr((string) $situationCode, 0, 4);

        if (strlen($code) !== 4) {
            return false;
        }

        if ($teamId === (int) $game->away_team_id) {
            return $code[0] === '0';
        }

        if ($teamId === (int) $game->home_team_id) {
            return $code[3] === '0';
        }

        return false;
    }

    /**
     * Resolve the stored abbreviation for a game team id.
     *
     * @param int $teamId
     * @param NhlGame $game
     * @return string|null
     */
    private function teamAbbrevForGameTeam(int $teamId, NhlGame $game): ?string
    {
        if ($teamId === (int) $game->home_team_id) {
            return $game->home_team_abbrev;
        }

        if ($teamId === (int) $game->away_team_id) {
            return $game->away_team_abbrev;
        }

        return null;
    }

    /**
     * Trim shiftchart rows after PBP proves an overtime game ended before the period limit.
     *
     * @param array<int,array<string,mixed>> $resolvedShifts
     * @return array<int,array<string,mixed>>
     */
    private function trimOvertimeShiftsAfterGameEnd(array $resolvedShifts, NhlGame $game): array
    {
        $boundaries = $this->overtimeFinalBoundaries($game);

        if ($boundaries === []) {
            return $resolvedShifts;
        }

        return collect($resolvedShifts)
            ->map(fn (array $resolvedShift): ?array => $this->trimShiftToOvertimeBoundary($resolvedShift, $boundaries))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    private function overtimeFinalBoundaries(NhlGame $game): array
    {
        return PlayByPlay::query()
            ->where('nhl_game_id', $game->nhl_game_id)
            ->where('period_type', 'OT')
            ->whereIn('type_desc_key', ['game-end', 'period-end'])
            ->whereNotNull('period')
            ->whereNotNull('seconds_in_game')
            ->where('seconds_remaining', '>', 0)
            ->orderBy('seconds_in_game')
            ->get(['period', 'seconds_in_game'])
            ->groupBy(fn (PlayByPlay $play): int => (int) $play->period)
            ->map(fn ($plays): int => (int) $plays->min('seconds_in_game'))
            ->all();
    }

    /**
     * @param array<string,mixed> $resolvedShift
     * @param array<int,int> $boundaries
     * @return array<string,mixed>|null
     */
    private function trimShiftToOvertimeBoundary(array $resolvedShift, array $boundaries): ?array
    {
        $period = (int) ($resolvedShift['shift']['period'] ?? 0);
        $boundary = $boundaries[$period] ?? null;

        if ($boundary === null || (int) $resolvedShift['shift_end_seconds'] <= $boundary) {
            return $resolvedShift;
        }

        if ((int) $resolvedShift['shift_start_seconds'] >= $boundary) {
            return null;
        }

        $resolvedShift['shift_end_seconds'] = $boundary;
        $resolvedShift['shift_duration_seconds'] = $boundary - (int) $resolvedShift['shift_start_seconds'];
        $resolvedShift['shift']['endTime'] = $this->formatPeriodSeconds(
            $boundary - (($period - 1) * 1200)
        );
        $resolvedShift['shift']['duration'] = $this->formatPeriodSeconds(
            (int) $resolvedShift['shift_duration_seconds']
        );

        return $resolvedShift;
    }

    /**
     * Format elapsed seconds within a period as mm:ss.
     */
    private function formatPeriodSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Build the identity used to compare a shift row against empty-net artifact timestamps.
     *
     * @param array<string,mixed> $resolvedShift
     * @return string
     */
    private function goalieEmptyNetShiftKey(array $resolvedShift): string
    {
        $shift = $resolvedShift['shift'];

        return implode('|', [
            (string) $resolvedShift['player_id'],
            (string) ($shift['period'] ?? ''),
            (string) $resolvedShift['shift_start_seconds'],
            (string) ($shift['teamAbbrev'] ?? ''),
        ]);
    }

    /**
     * Remove skater rows that make a team's on-ice count impossible under PBP manpower.
     *
     * @param array<int,array<string,mixed>> $resolvedShifts
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<int,array<string,mixed>>
     */
    private function removeImpossibleOverCapSkaterArtifacts(
        array $resolvedShifts,
        NhlGame $game,
        array $boxscoreTargets
    ): array {
        if ($boxscoreTargets === [] || $resolvedShifts === []) {
            return $resolvedShifts;
        }

        $artifactKeys = $this->impossibleOverCapSkaterArtifactKeys($resolvedShifts, $game, $boxscoreTargets);

        if ($artifactKeys === []) {
            return $resolvedShifts;
        }

        return collect($resolvedShifts)
            ->reject(fn (array $resolvedShift): bool => isset($artifactKeys[$this->resolvedShiftIdentity($resolvedShift)]))
            ->values()
            ->all();
    }

    /**
     * Build exact artifact identities for impossible team skater counts.
     *
     * @param array<int,array<string,mixed>> $resolvedShifts
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<string,bool>
     */
    private function impossibleOverCapSkaterArtifactKeys(
        array $resolvedShifts,
        NhlGame $game,
        array $boxscoreTargets
    ): array {
        $plays = PlayByPlay::query()
            ->where('nhl_game_id', $game->nhl_game_id)
            ->whereNotNull('seconds_in_game')
            ->whereNotNull('situation_code')
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'period', 'seconds_in_game', 'situation_code'])
            ->values();

        if ($plays->isEmpty()) {
            return [];
        }

        $playerTotals = collect($resolvedShifts)
            ->groupBy(fn (array $shift): int => (int) $shift['player_id'])
            ->map(fn ($playerShifts): array => [
                'shifts' => $playerShifts->count(),
                'toi' => $this->shiftDurationTotal($playerShifts->values()->all()),
            ]);
        $artifactKeys = [];

        foreach ($plays as $play) {
            $playSecond = (int) $play->seconds_in_game;

            foreach ([(int) $game->away_team_id, (int) $game->home_team_id] as $teamId) {
                $teamAbbrev = $this->teamAbbrevForGameTeam($teamId, $game);
                $maxSkaters = $this->maxSkatersForSituation((string) $play->situation_code, $teamId, $game);

                if ($teamAbbrev === null || $maxSkaters === null) {
                    continue;
                }

                $activeSkaters = collect($resolvedShifts)
                    ->filter(fn (array $shift): bool => (string) ($shift['shift']['teamAbbrev'] ?? '') === $teamAbbrev)
                    ->filter(fn (array $shift): bool => !($boxscoreTargets[(int) $shift['player_id']]['is_goalie'] ?? false))
                    ->filter(fn (array $shift): bool => (int) $shift['shift_start_seconds'] <= $playSecond
                        && (int) $shift['shift_end_seconds'] > $playSecond)
                    ->values();
                $excessSkaters = $activeSkaters->count() - $maxSkaters;

                if ($excessSkaters <= 0) {
                    continue;
                }

                $exactArtifactRows = $activeSkaters
                    ->filter(function (array $shift) use ($boxscoreTargets, $playerTotals): bool {
                        $playerId = (int) $shift['player_id'];
                        $target = $boxscoreTargets[$playerId] ?? null;
                        $total = $playerTotals->get($playerId);

                        if (!$target || !$total) {
                            return false;
                        }

                        return ((int) $total['shifts'] - 1) === (int) $target['shifts']
                            && ((int) $total['toi'] - (int) $shift['shift_duration_seconds']) === (int) $target['toi'];
                    })
                    ->values();

                if ($exactArtifactRows->count() !== $excessSkaters) {
                    continue;
                }

                foreach ($exactArtifactRows as $artifactRow) {
                    $artifactKeys[$this->resolvedShiftIdentity($artifactRow)] = true;
                }
            }
        }

        return $artifactKeys;
    }

    /**
     * Return the max skater count for a team from NHL situation code.
     */
    private function maxSkatersForSituation(string $situationCode, int $teamId, NhlGame $game): ?int
    {
        $code = substr($situationCode, 0, 4);

        if (strlen($code) !== 4) {
            return null;
        }

        if ($teamId === (int) $game->away_team_id) {
            return (int) $code[1];
        }

        if ($teamId === (int) $game->home_team_id) {
            return (int) $code[2];
        }

        return null;
    }

    /**
     * Determine whether dropping a short reused-number shift moves away from official totals.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array<string,mixed> $dropShift
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return bool
     */
    private function shortShiftDropWouldMissTarget(array $playerShifts, array $dropShift, array $boxscoreTargets): bool
    {
        $target = $boxscoreTargets[(int) $dropShift['player_id']] ?? null;

        if (!$target) {
            return false;
        }

        $currentDistance = $this->targetDistance(
            count($playerShifts),
            $this->shiftDurationTotal($playerShifts),
            $target
        );
        $afterDistance = $this->targetDistance(
            max(0, count($playerShifts) - 1),
            max(0, $this->shiftDurationTotal($playerShifts) - (int) $dropShift['shift_duration_seconds']),
            $target
        );

        return $afterDistance >= $currentDistance;
    }

    /**
     * Load official boxscore shift and TOI targets when they are already available.
     *
     * @param string $nhlGameId
     * @return array<int,array{shifts:int,toi:int,is_goalie:bool}>
     */
    private function boxscoreShiftTargets(string $nhlGameId): array
    {
        return NhlBoxscore::query()
            ->where('nhl_game_id', $nhlGameId)
            ->whereNotNull('nhl_player_id')
            ->whereNotNull('toi_seconds')
            ->where('toi_seconds', '>', 0)
            ->get(['nhl_player_id', 'shifts', 'toi_seconds', 'position'])
            ->filter(fn (NhlBoxscore $boxscore): bool => (int) ($boxscore->shifts ?? 0) > 0
                || strtoupper((string) ($boxscore->position ?? '')) === 'G')
            ->mapWithKeys(fn (NhlBoxscore $boxscore): array => [
                (int) $boxscore->nhl_player_id => [
                    'shifts' => (int) ($boxscore->shifts ?? 0),
                    'toi' => (int) $boxscore->toi_seconds,
                    'is_goalie' => strtoupper((string) ($boxscore->position ?? '')) === 'G',
                ],
            ])
            ->all();
    }

    /**
     * Return player ids proven by boxscore or PBP, or null when either source is unavailable.
     *
     * @return array<int,bool>|null
     */
    private function boxscoreOrPbpPlayerIds(string $nhlGameId): ?array
    {
        $boxscorePlayerIds = NhlBoxscore::query()
            ->where('nhl_game_id', $nhlGameId)
            ->whereNotNull('nhl_player_id')
            ->pluck('nhl_player_id')
            ->map(fn ($playerId): int => (int) $playerId)
            ->filter(fn (int $playerId): bool => $playerId > 0)
            ->values();

        if ($boxscorePlayerIds->isEmpty()) {
            return null;
        }

        $pbpRows = PlayByPlay::query()
            ->where('nhl_game_id', $nhlGameId)
            ->get([
                'nhl_player_id',
                'fo_winning_player_id',
                'fo_losing_player_id',
                'scoring_player_id',
                'assist1_player_id',
                'assist2_player_id',
                'committed_by_player_id',
                'drawn_by_player_id',
                'shooting_player_id',
                'goalie_in_net_player_id',
                'blocking_player_id',
                'hitting_player_id',
                'hittee_player_id',
            ]);

        if ($pbpRows->isEmpty()) {
            return null;
        }

        $pbpPlayerIds = $pbpRows
            ->flatMap(fn (PlayByPlay $play): array => [
                $play->nhl_player_id,
                $play->fo_winning_player_id,
                $play->fo_losing_player_id,
                $play->scoring_player_id,
                $play->assist1_player_id,
                $play->assist2_player_id,
                $play->committed_by_player_id,
                $play->drawn_by_player_id,
                $play->shooting_player_id,
                $play->goalie_in_net_player_id,
                $play->blocking_player_id,
                $play->hitting_player_id,
                $play->hittee_player_id,
            ])
            ->map(fn ($playerId): int => (int) $playerId)
            ->filter(fn (int $playerId): bool => $playerId > 0);

        return $boxscorePlayerIds
            ->merge($pbpPlayerIds)
            ->unique()
            ->mapWithKeys(fn (int $playerId): array => [$playerId => true])
            ->all();
    }

    /**
     * Remove shiftchart rows for players that neither boxscore nor PBP recognizes for the game.
     *
     * @param array<string|int,array<string,mixed>> $resolvedShifts
     * @param array<int,bool> $allowedPlayerIds
     * @return array<string|int,array<string,mixed>>
     */
    private function filterShiftchartOnlyPlayers(array $resolvedShifts, array $allowedPlayerIds): array
    {
        return collect($resolvedShifts)
            ->filter(fn (array $resolvedShift): bool => isset($allowedPlayerIds[(int) $resolvedShift['player_id']]))
            ->all();
    }

    /**
     * Reconcile shiftchart artifacts against official boxscore totals when available.
     *
     * @param array<int,array<string,mixed>> $filteredShifts
     * @param array<int,array<string,mixed>> $candidateShifts
     * @param array<int,array{shifts:int,toi:int,is_goalie:bool}> $boxscoreTargets
     * @return array<int,array<string,mixed>>
     */
    private function reconcileWithBoxscoreTargets(
        array $filteredShifts,
        array $candidateShifts,
        array $boxscoreTargets
    ): array {
        if ($boxscoreTargets === []) {
            return $filteredShifts;
        }

        $candidateByPlayer = collect($candidateShifts)->groupBy(fn (array $shift): int => (int) $shift['player_id']);

        return collect($filteredShifts)
            ->groupBy(fn (array $shift): int => (int) $shift['player_id'])
            ->flatMap(function ($playerShifts, int $playerId) use ($boxscoreTargets, $candidateByPlayer) {
                $target = $boxscoreTargets[$playerId] ?? null;

                if (!$target || $target['toi'] <= 0) {
                    return $playerShifts;
                }

                $normalized = $playerShifts->values()->all();

                if ($target['is_goalie']) {
                    return $this->reconcileGoalieWithBoxscoreToi($normalized, $target);
                }

                $normalized = $this->replaceContainedIntervalsForTarget(
                    $normalized,
                    $candidateByPlayer->get($playerId, collect())->values()->all(),
                    $target
                );
                $normalized = $this->dropShortRowsForTarget($normalized, $target);
                $normalized = $this->dropPenaltyWindowRowsForTarget($normalized, $target);

                return $this->replaceDuplicateAlternativesForTarget(
                    $normalized,
                    $candidateByPlayer->get($playerId, collect())->values()->all(),
                    $target
                );
            })
            ->sortBy([
                ['player_id', 'asc'],
                ['shift_start_seconds', 'asc'],
                ['shift_end_seconds', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Drop exact goalie TOI overage rows when official goalie TOI proves shiftchart artifacts.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array{shifts:int,toi:int,is_goalie:bool} $target
     * @return array<int,array<string,mixed>>
     */
    private function reconcileGoalieWithBoxscoreToi(array $playerShifts, array $target): array
    {
        $excessToi = $this->shiftDurationTotal($playerShifts) - $target['toi'];

        if ($excessToi <= 0) {
            return $playerShifts;
        }

        $artifactRows = $this->goalieArtifactCandidateRows($playerShifts);
        $dropRows = $this->findAnyExactDurationSubset($artifactRows, $excessToi);

        if ($dropRows === []) {
            return $playerShifts;
        }

        $dropKeys = collect($dropRows)
            ->mapWithKeys(fn (array $shift): array => [$this->resolvedShiftIdentity($shift) => true])
            ->all();

        return collect($playerShifts)
            ->reject(fn (array $shift): bool => isset($dropKeys[$this->resolvedShiftIdentity($shift)]))
            ->values()
            ->all();
    }

    /**
     * Return goalie rows that look like provider artifacts before attempting exact-duration removal.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @return array<int,array<string,mixed>>
     */
    private function goalieArtifactCandidateRows(array $playerShifts): array
    {
        return collect($playerShifts)
            ->filter(fn (array $shift): bool => $this->goalieArtifactScore($shift, $playerShifts) >= 2)
            ->sortByDesc(fn (array $shift): int => $this->goalieArtifactScore($shift, $playerShifts))
            ->values()
            ->all();
    }

    /**
     * Swap duplicate correction rows only when official boxscore totals prove the replacement.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array<int,array<string,mixed>> $candidateShifts
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>
     */
    private function replaceDuplicateAlternativesForTarget(
        array $playerShifts,
        array $candidateShifts,
        array $target
    ): array {
        if (
            count($playerShifts) !== $target['shifts']
            || $this->shiftDurationTotal($playerShifts) === $target['toi']
        ) {
            return $playerShifts;
        }

        $candidateGroups = collect($candidateShifts)
            ->groupBy(fn (array $shift): string => $this->duplicateCorrectionKey($shift))
            ->filter(fn ($group): bool => $group->count() > 1);

        if ($candidateGroups->isEmpty()) {
            return $playerShifts;
        }

        $optionSets = [];
        foreach ($playerShifts as $index => $selectedShift) {
            $group = $candidateGroups->get($this->duplicateCorrectionKey($selectedShift));

            if (!$group) {
                continue;
            }

            $options = $group
                ->unique(fn (array $shift): string => $this->resolvedShiftIdentity($shift))
                ->values()
                ->all();

            if (count($options) < 2) {
                continue;
            }

            $optionSets[] = [
                'index' => $index,
                'options' => $options,
            ];
        }

        if ($optionSets === []) {
            return $playerShifts;
        }

        return $this->findExactDuplicateReplacement($playerShifts, $optionSets, $target) ?? $playerShifts;
    }

    /**
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array<int,array{index:int,options:array<int,array<string,mixed>>}> $optionSets
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>|null
     */
    private function findExactDuplicateReplacement(array $playerShifts, array $optionSets, array $target): ?array
    {
        return $this->findExactDuplicateReplacementRecursive($playerShifts, $optionSets, $target, 0);
    }

    /**
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array<int,array{index:int,options:array<int,array<string,mixed>>}> $optionSets
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>|null
     */
    private function findExactDuplicateReplacementRecursive(
        array $playerShifts,
        array $optionSets,
        array $target,
        int $offset
    ): ?array {
        if ($offset >= count($optionSets)) {
            return $this->shiftDurationTotal($playerShifts) === $target['toi'] ? $playerShifts : null;
        }

        $optionSet = $optionSets[$offset];

        foreach ($optionSet['options'] as $option) {
            $candidate = $playerShifts;
            $candidate[$optionSet['index']] = $option;

            $result = $this->findExactDuplicateReplacementRecursive(
                $candidate,
                $optionSets,
                $target,
                $offset + 1
            );

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Swap a kept enclosing interval for a contained official interval when that exactly fixes TOI.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array<int,array<string,mixed>> $candidateShifts
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>
     */
    private function replaceContainedIntervalsForTarget(array $playerShifts, array $candidateShifts, array $target): array
    {
        if (count($playerShifts) !== $target['shifts']) {
            return $playerShifts;
        }

        $currentToi = $this->shiftDurationTotal($playerShifts);
        $excessToi = $currentToi - $target['toi'];

        if ($excessToi <= 0) {
            return $playerShifts;
        }

        foreach ($playerShifts as $keptIndex => $keptShift) {
            foreach ($candidateShifts as $candidateShift) {
                if ($this->resolvedShiftIdentity($keptShift) === $this->resolvedShiftIdentity($candidateShift)) {
                    continue;
                }

                if (!$this->isContainedReplacementCandidate($keptShift, $candidateShift)) {
                    continue;
                }

                $replacementDiff = (int) $keptShift['shift_duration_seconds']
                    - (int) $candidateShift['shift_duration_seconds'];

                if ($replacementDiff !== $excessToi) {
                    continue;
                }

                $playerShifts[$keptIndex] = $candidateShift;

                return $playerShifts;
            }
        }

        return $playerShifts;
    }

    /**
     * Drop a subset of short rows when their count and duration exactly reconcile to boxscore.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>
     */
    private function dropShortRowsForTarget(array $playerShifts, array $target): array
    {
        $excessShifts = count($playerShifts) - $target['shifts'];
        $excessToi = $this->shiftDurationTotal($playerShifts) - $target['toi'];

        if ($excessShifts <= 0 || $excessToi <= 0 || $excessShifts > 5) {
            return $playerShifts;
        }

        $artifactMaxSeconds = max(
            self::SHORT_ARTIFACT_BASE_SECONDS,
            min(self::SHORT_ARTIFACT_MAX_SECONDS, $excessToi)
        );

        $shortRows = collect($playerShifts)
            ->filter(fn (array $shift): bool => (int) $shift['shift_duration_seconds'] <= $artifactMaxSeconds)
            ->values()
            ->all();

        $dropRows = $this->findExactDurationSubset($shortRows, $excessShifts, $excessToi);

        if ($dropRows === []) {
            return $playerShifts;
        }

        $dropKeys = collect($dropRows)
            ->mapWithKeys(fn (array $shift): array => [$this->resolvedShiftIdentity($shift) => true])
            ->all();

        return collect($playerShifts)
            ->reject(fn (array $shift): bool => isset($dropKeys[$this->resolvedShiftIdentity($shift)]))
            ->values()
            ->all();
    }

    /**
     * Drop exact overage rows that are fully inside the player's own major penalty window.
     *
     * @param array<int,array<string,mixed>> $playerShifts
     * @param array{shifts:int,toi:int} $target
     * @return array<int,array<string,mixed>>
     */
    private function dropPenaltyWindowRowsForTarget(array $playerShifts, array $target): array
    {
        $excessShifts = count($playerShifts) - $target['shifts'];
        $excessToi = $this->shiftDurationTotal($playerShifts) - $target['toi'];

        if ($excessShifts <= 0 || $excessToi <= 0 || $excessShifts > 5 || $playerShifts === []) {
            return $playerShifts;
        }

        $playerId = (int) ($playerShifts[0]['player_id'] ?? 0);
        $gameId = (int) ($playerShifts[0]['shift']['gameId'] ?? 0);

        if ($playerId <= 0 || $gameId <= 0) {
            return $playerShifts;
        }

        $windows = $this->majorPenaltyWindows($gameId, $playerId);

        if ($windows === []) {
            return $playerShifts;
        }

        $candidateRows = collect($playerShifts)
            ->filter(fn (array $shift): bool => $this->shiftInsideAnyWindow($shift, $windows))
            ->values()
            ->all();
        $dropRows = $this->findExactDurationSubset($candidateRows, $excessShifts, $excessToi);

        if ($dropRows === []) {
            return $playerShifts;
        }

        $dropKeys = collect($dropRows)
            ->mapWithKeys(fn (array $shift): array => [$this->resolvedShiftIdentity($shift) => true])
            ->all();

        return collect($playerShifts)
            ->reject(fn (array $shift): bool => isset($dropKeys[$this->resolvedShiftIdentity($shift)]))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{start:int,end:int}>
     */
    private function majorPenaltyWindows(int $gameId, int $playerId): array
    {
        return PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->where('type_desc_key', 'penalty')
            ->where('committed_by_player_id', $playerId)
            ->where(function ($query): void {
                $query->where('duration', '>=', 5)
                    ->orWhereIn(DB::raw("UPPER(COALESCE(penalty_type_code, ''))"), ['MAJ', 'MIS', 'GAM', 'MAT']);
            })
            ->get(['seconds_in_game', 'duration'])
            ->map(function (PlayByPlay $penalty): ?array {
                $start = $penalty->seconds_in_game;
                $duration = $penalty->duration;

                if ($start === null || $duration === null || (int) $duration <= 0) {
                    return null;
                }

                return [
                    'start' => (int) $start,
                    'end' => (int) $start + ((int) $duration * 60),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $shift
     * @param array<int,array{start:int,end:int}> $windows
     */
    private function shiftInsideAnyWindow(array $shift, array $windows): bool
    {
        $start = (int) ($shift['shift_start_seconds'] ?? 0);
        $end = (int) ($shift['shift_end_seconds'] ?? 0);

        if ($end <= $start) {
            return false;
        }

        foreach ($windows as $window) {
            if ($start >= $window['start'] && $end <= $window['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Credit duplicate provider intervals as TOI-only when they exactly reconcile official TOI.
     *
     * @param array<int,array<string,mixed>> $normalizedShifts
     * @param array<int,array<string,mixed>> $candidateShifts
     * @param array<int,array{shifts:int,toi:int}> $boxscoreTargets
     * @return array<int,int>
     */
    private function duplicateToiCreditsForTargets(
        array $normalizedShifts,
        array $candidateShifts,
        array $boxscoreTargets
    ): array {
        if ($boxscoreTargets === []) {
            return [];
        }

        $credits = [];
        $candidateByPlayer = collect($candidateShifts)->groupBy(fn (array $shift): int => (int) $shift['player_id']);

        foreach (collect($normalizedShifts)->groupBy(fn (array $shift): int => (int) $shift['player_id']) as $playerId => $playerShifts) {
            $target = $boxscoreTargets[(int) $playerId] ?? null;

            if (!$target || $playerShifts->count() !== $target['shifts']) {
                continue;
            }

            $missingToi = $target['toi'] - $this->shiftDurationTotal($playerShifts->values()->all());

            if ($missingToi <= 0 || $missingToi > self::SHORT_ARTIFACT_MAX_SECONDS) {
                continue;
            }

            $duplicateRows = $this->duplicateProviderRows(
                $candidateByPlayer->get((int) $playerId, collect())->values()->all()
            );
            $creditRows = [];

            for ($size = 1; $size <= min(5, count($duplicateRows)); $size++) {
                $creditRows = $this->findExactDurationSubset($duplicateRows, $size, $missingToi);

                if ($creditRows !== []) {
                    break;
                }
            }

            if ($creditRows !== []) {
                $credits[(int) $playerId] = $missingToi;
            }
        }

        return $credits;
    }

    /**
     * Return extra copies of identical provider shift intervals.
     *
     * @param array<int,array<string,mixed>> $candidateShifts
     * @return array<int,array<string,mixed>>
     */
    private function duplicateProviderRows(array $candidateShifts): array
    {
        return collect($candidateShifts)
            ->groupBy(fn (array $shift): string => $this->duplicateProviderIntervalKey($shift))
            ->flatMap(function ($group) {
                return $group->sortBy('shift.id')->skip(1);
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $keptShift
     * @param array<string,mixed> $candidateShift
     * @return bool
     */
    private function isContainedReplacementCandidate(array $keptShift, array $candidateShift): bool
    {
        return (int) $keptShift['player_id'] === (int) $candidateShift['player_id']
            && (int) ($keptShift['shift']['period'] ?? 0) === (int) ($candidateShift['shift']['period'] ?? 0)
            && (int) $keptShift['shift_number'] === (int) $candidateShift['shift_number']
            && (int) $candidateShift['shift_start_seconds'] >= (int) $keptShift['shift_start_seconds']
            && (int) $candidateShift['shift_end_seconds'] <= (int) $keptShift['shift_end_seconds']
            && (int) $candidateShift['shift_duration_seconds'] < (int) $keptShift['shift_duration_seconds'];
    }

    /**
     * Score goalie artifact likelihood without replacing the exact-duration proof requirement.
     *
     * @param array<string,mixed> $shift
     * @param array<int,array<string,mixed>> $playerShifts
     */
    private function goalieArtifactScore(array $shift, array $playerShifts): int
    {
        $score = 0;

        if ($this->hasDurationMismatch($shift)) {
            $score += 4;
        }

        if (empty($shift['shift']['eventNumber'])) {
            $score += 2;
        }

        if ($this->overlapsAnotherShift($shift, $playerShifts)) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Determine whether a shift row duration conflicts with its start/end interval.
     *
     * @param array<string,mixed> $shift
     */
    private function hasDurationMismatch(array $shift): bool
    {
        $intervalSeconds = (int) $shift['shift_end_seconds'] - (int) $shift['shift_start_seconds'];

        return $intervalSeconds > 0
            && $intervalSeconds !== (int) $shift['shift_duration_seconds'];
    }

    /**
     * Determine whether this row overlaps another row for the same goalie and period.
     *
     * @param array<string,mixed> $shift
     * @param array<int,array<string,mixed>> $playerShifts
     */
    private function overlapsAnotherShift(array $shift, array $playerShifts): bool
    {
        foreach ($playerShifts as $otherShift) {
            if ($this->resolvedShiftIdentity($shift) === $this->resolvedShiftIdentity($otherShift)) {
                continue;
            }

            if (
                (int) $shift['player_id'] !== (int) $otherShift['player_id']
                || (int) ($shift['shift']['period'] ?? 0) !== (int) ($otherShift['shift']['period'] ?? 0)
            ) {
                continue;
            }

            if (
                (int) $shift['shift_start_seconds'] < (int) $otherShift['shift_end_seconds']
                && (int) $shift['shift_end_seconds'] > (int) $otherShift['shift_start_seconds']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find any subset of candidate rows whose durations exactly equal the target.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function findAnyExactDurationSubset(array $rows, int $duration): array
    {
        for ($size = 1; $size <= count($rows); $size++) {
            $subset = $this->findExactDurationSubset($rows, $size, $duration);

            if ($subset !== []) {
                return $subset;
            }
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param int $size
     * @param int $duration
     * @return array<int,array<string,mixed>>
     */
    private function findExactDurationSubset(array $rows, int $size, int $duration): array
    {
        if ($size > count($rows)) {
            return [];
        }

        return $this->findExactDurationSubsetRecursive($rows, $size, $duration);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param int $size
     * @param int $duration
     * @param int $offset
     * @param array<int,array<string,mixed>> $selected
     * @return array<int,array<string,mixed>>
     */
    private function findExactDurationSubsetRecursive(
        array $rows,
        int $size,
        int $duration,
        int $offset = 0,
        array $selected = []
    ): array {
        if (count($selected) === $size) {
            return $duration === 0 ? $selected : [];
        }

        for ($index = $offset; $index < count($rows); $index++) {
            $row = $rows[$index];
            $remainingDuration = $duration - (int) $row['shift_duration_seconds'];

            if ($remainingDuration < 0) {
                continue;
            }

            $result = $this->findExactDurationSubsetRecursive(
                $rows,
                $size,
                $remainingDuration,
                $index + 1,
                [...$selected, $row]
            );

            if ($result !== []) {
                return $result;
            }
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $shifts
     * @return int
     */
    private function shiftDurationTotal(array $shifts): int
    {
        return (int) collect($shifts)->sum(fn (array $shift): int => (int) $shift['shift_duration_seconds']);
    }

    /**
     * Calculate distance from official boxscore shift and TOI targets.
     *
     * @param array{shifts:int,toi:int} $target
     */
    private function targetDistance(int $shifts, int $toi, array $target): int
    {
        return abs($shifts - $target['shifts']) + abs($toi - $target['toi']);
    }

    /**
     * Build an in-memory identity for a resolved shift row.
     *
     * @param array<string,mixed> $resolvedShift
     * @return string
     */
    private function resolvedShiftIdentity(array $resolvedShift): string
    {
        $shift = $resolvedShift['shift'];

        return implode('|', [
            (string) $resolvedShift['player_id'],
            (string) $resolvedShift['shift_number'],
            (string) ($shift['period'] ?? ''),
            (string) $resolvedShift['shift_start_seconds'],
            (string) $resolvedShift['shift_end_seconds'],
            (string) ($shift['eventNumber'] ?? ''),
        ]);
    }

    /**
     * Build the correction identity NHL reuses when publishing alternate end/duration rows.
     *
     * @param array<string,mixed> $resolvedShift
     * @return string
     */
    private function duplicateCorrectionKey(array $resolvedShift): string
    {
        $shift = $resolvedShift['shift'];

        return implode('|', [
            (string) $resolvedShift['player_id'],
            (string) ($shift['period'] ?? ''),
            (string) $resolvedShift['shift_start_seconds'],
            (string) ($shift['eventNumber'] ?? ''),
            (string) ($shift['typeCode'] ?? ''),
        ]);
    }

    /**
     * Build the identity for exact duplicate provider rows.
     *
     * @param array<string,mixed> $resolvedShift
     * @return string
     */
    private function duplicateProviderIntervalKey(array $resolvedShift): string
    {
        $shift = $resolvedShift['shift'];

        return implode('|', [
            (string) $resolvedShift['player_id'],
            (string) $resolvedShift['shift_number'],
            (string) ($shift['period'] ?? ''),
            (string) $resolvedShift['shift_start_seconds'],
            (string) $resolvedShift['shift_end_seconds'],
            (string) $resolvedShift['shift_duration_seconds'],
            (string) ($shift['eventNumber'] ?? ''),
            (string) ($shift['typeCode'] ?? ''),
        ]);
    }
}
