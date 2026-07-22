<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlUnitShift;
use App\Models\NhlUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectEventsToUnitShifts
{
    protected int $gameId;

    public function __construct(
        int $gameId,
        private readonly NhlHtmlPbpReportLocator $htmlReportLocator,
        private readonly NhlHtmlPbpReportParser $htmlReportParser,
        private readonly ResolveNhlUnit $unitResolver
    ) {
        $this->gameId = $gameId;
    }

    public function connect(): int
    {
        $unitShifts = NhlUnitShift::where('nhl_game_id', $this->gameId)
            ->orderBy('start_game_seconds')
            ->get();

        $events = PlayByPlay::where('nhl_game_id', $this->gameId)
            ->orderBy('seconds_in_game')
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        DB::table('event_unit_shifts')
            ->whereIn('event_id', $events->pluck('id'))
            ->delete();

        $criticalStoppage = ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'];
        $criticalStart = ['period-start', 'faceoff'];

        $startsBySecond = [];
        $endsBySecond = [];
        $unitShiftRows = $unitShifts->all();

        foreach ($unitShiftRows as $shift) {
            $startsBySecond[(int) $shift->start_game_seconds][] = (int) $shift->id;
            $endsBySecond[(int) $shift->end_game_seconds][] = (int) $shift->id;
        }

        $eventsCount = 0;
        $startIndex = 0;
        $activeShifts = [];
        $pivotRowsByKey = [];
        $now = now();
        $htmlBoundaryResolver = null;

        foreach ($events as $event) {
            $isCriticalStoppage = in_array($event->type_desc_key, $criticalStoppage);
            $isCriticalStart = in_array($event->type_desc_key, $criticalStart);
            $eventTime = (int) $event->seconds_in_game;

            // 1. Core shifts: start < event time AND end > event time
            while (
                isset($unitShiftRows[$startIndex])
                && (int) $unitShiftRows[$startIndex]->start_game_seconds < $eventTime
            ) {
                $shift = $unitShiftRows[$startIndex];
                $activeShifts[(int) $shift->id] = $shift;
                $startIndex++;
            }

            foreach ($activeShifts as $shiftId => $shift) {
                if ((int) $shift->end_game_seconds <= $eventTime) {
                    unset($activeShifts[$shiftId]);
                    continue;
                }

                $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
            }

            // 2. If critical stoppage event, assign to shifts ending exactly at event time
            if ($isCriticalStoppage) {
                foreach ($endsBySecond[$eventTime] ?? [] as $shiftId) {
                    $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
                }
            }


            // 3. If critical start event, assign to shifts starting exactly at event time
            if ($isCriticalStart) {
                foreach ($startsBySecond[$eventTime] ?? [] as $shiftId) {
                    $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
                }
            }

            $htmlBoundaryResolver ??= $this->htmlBoundaryResolver($events);

            if ($this->isPenaltyShotAttemptEvent($event, $htmlBoundaryResolver)) {
                $this->replaceQueuedEventRows($pivotRowsByKey, (int) $event->id);
                $eventsCount++;

                continue;
            }

            if ($this->hasExactBoundary($eventTime, $startsBySecond, $endsBySecond)) {
                $htmlShiftIds = $htmlBoundaryResolver !== null
                    ? $this->htmlResolvedShiftIds($event, $eventTime, $htmlBoundaryResolver)
                    : [];

                if ($htmlShiftIds !== []) {
                    $this->replaceQueuedEventRows($pivotRowsByKey, (int) $event->id);

                    foreach ($htmlShiftIds as $shiftId) {
                        $this->queuePivotRow($pivotRowsByKey, (int) $event->id, $shiftId, $now);
                    }
                }
            }

            $htmlShiftIds = $htmlBoundaryResolver !== null
                ? $this->htmlToiCorrectedShiftIds($event, $eventTime, $htmlBoundaryResolver, $pivotRowsByKey)
                : [];

            if ($htmlShiftIds !== []) {
                $this->replaceQueuedEventRows($pivotRowsByKey, (int) $event->id);

                foreach ($htmlShiftIds as $shiftId) {
                    $this->queuePivotRow($pivotRowsByKey, (int) $event->id, $shiftId, $now);
                }
            }

            $eventsCount++;
        }

        foreach (array_chunk(array_values($pivotRowsByKey), 1000) as $rows) {
            DB::table('event_unit_shifts')->insert($rows);
        }

        return $eventsCount;
    }

    /**
     * Queue a unique event/unit-shift pivot row for batched insertion.
     *
     * @param array<string,array<string,mixed>> $rows
     */
    private function queuePivotRow(array &$rows, int $eventId, int $shiftId, \DateTimeInterface $now): void
    {
        $key = $eventId . ':' . $shiftId;

        if (isset($rows[$key])) {
            return;
        }

        $rows[$key] = [
            'event_id' => $eventId,
            'unit_shift_id' => $shiftId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Remove any queued unit links for one event before replacing them with HTML-resolved links.
     *
     * @param array<string,array<string,mixed>> $rows
     */
    private function replaceQueuedEventRows(array &$rows, int $eventId): void
    {
        foreach (array_keys($rows) as $key) {
            if (str_starts_with($key, $eventId . ':')) {
                unset($rows[$key]);
            }
        }
    }

    /**
     * Determine whether shiftchart boundaries make this event's exact on-ice state ambiguous.
     *
     * @param array<int,array<int,int>> $startsBySecond
     * @param array<int,array<int,int>> $endsBySecond
     */
    private function hasExactBoundary(int $eventTime, array $startsBySecond, array $endsBySecond): bool
    {
        return ! empty($startsBySecond[$eventTime]) || ! empty($endsBySecond[$eventTime]);
    }

    /**
     * Build the HTML event and player lookup needed for exact-boundary resolution.
     *
     * @param \Illuminate\Support\Collection<int,PlayByPlay> $events
     * @return array{events:array<string,array<string,mixed>>,players:array<string,array<int,int>>,toi_shifts:array<int,array<string,mixed>>,team_ids:array<string,int>}|null
     */
    private function htmlBoundaryResolver(\Illuminate\Support\Collection $events): ?array
    {
        $reportUrls = $this->htmlReportLocator->reportUrls($this->gameId);
        $url = $reportUrls['playByPlay'] ?? null;

        if ($url === null) {
            return null;
        }

        try {
            $html = Http::timeout(30)->accept('text/html')->get($url)->throw()->body();
            $htmlEvents = $this->htmlReportParser->parse($html);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to resolve NHL HTML PBP boundary event links.', [
                'game_id' => $this->gameId,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if ($htmlEvents === []) {
            return null;
        }

        return [
            'events' => $this->matchedHtmlRows($events, $htmlEvents),
            'players' => $this->nhlPlayerIdsByTeamSweater(),
            'toi_shifts' => $this->htmlToiShifts($reportUrls),
            'team_ids' => $this->teamIdsByAbbrev(),
        ];
    }

    /**
     * Match imported API events to parsed HTML rows in source order.
     *
     * @param \Illuminate\Support\Collection<int,PlayByPlay> $events
     * @param array<int,array<string,mixed>> $htmlEvents
     * @return array<string,array<string,mixed>>
     */
    private function matchedHtmlRows(\Illuminate\Support\Collection $events, array $htmlEvents): array
    {
        $availableHtmlRows = array_values($htmlEvents);
        $matches = [];

        foreach ($events as $event) {
            $matchIndex = null;

            foreach ($availableHtmlRows as $index => $htmlEvent) {
                if (
                    (int) ($htmlEvent['period'] ?? 0) === (int) $event->period
                    && $this->normalizeClock((string) ($htmlEvent['time_in_period'] ?? '')) === $this->normalizeClock((string) $event->time_in_period)
                    && (string) ($htmlEvent['type'] ?? '') === (string) $event->type_desc_key
                ) {
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex === null) {
                continue;
            }

            $matches[$this->eventKey($event)] = $availableHtmlRows[$matchIndex];
            unset($availableHtmlRows[$matchIndex]);
            $availableHtmlRows = array_values($availableHtmlRows);
        }

        return $matches;
    }

    /**
     * Resolve HTML player sweater rows to existing local unit shifts for the exact event.
     *
     * @param array{events:array<string,array<string,mixed>>,players:array<string,array<int,int>>,toi_shifts:array<int,array<string,mixed>>,team_ids:array<string,int>} $resolver
     * @return array<int,int>
     */
    private function htmlResolvedShiftIds(PlayByPlay $event, int $eventTime, array $resolver): array
    {
        $htmlEvent = $resolver['events'][$this->eventKey($event)] ?? null;

        if ($htmlEvent === null) {
            return [];
        }

        $htmlPlayerIdsByTeamType = $this->htmlPlayerIdsByTeamType($htmlEvent, $resolver['players']);

        if ($htmlPlayerIdsByTeamType === []) {
            return [];
        }

        $candidates = DB::table('nhl_unit_shifts as us')
            ->join('nhl_units as units', 'units.id', '=', 'us.unit_id')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'units.id')
            ->join('players', 'players.id', '=', 'up.player_id')
            ->where('us.nhl_game_id', $this->gameId)
            ->where('us.start_game_seconds', '<=', $eventTime)
            ->where('us.end_game_seconds', '>=', $eventTime)
            ->get([
                'us.id as unit_shift_id',
                'us.team_abbrev',
                'units.unit_type',
                'players.nhl_id as nhl_player_id',
            ])
            ->groupBy('unit_shift_id');

        $shiftIds = [];

        foreach ($candidates as $unitShiftId => $rows) {
            $first = $rows->first();
            $team = strtoupper((string) ($first->team_abbrev ?? ''));
            $unitType = (string) ($first->unit_type ?? '');
            $expectedIds = $htmlPlayerIdsByTeamType[$team][$unitType] ?? [];

            if ($expectedIds === []) {
                continue;
            }

            $candidateIds = $rows
                ->pluck('nhl_player_id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($candidateIds === $expectedIds) {
                $shiftIds[] = (int) $unitShiftId;
            }
        }

        sort($shiftIds, SORT_NUMERIC);

        return $this->candidateShiftPlayerIds($shiftIds) === $this->expectedHtmlPlayerIds($htmlPlayerIdsByTeamType)
            ? $shiftIds
            : [];
    }

    /**
     * Build source-corrected unit shifts when HTML PBP and TV/TH TOI agree against shiftchart links.
     *
     * @param array{events:array<string,array<string,mixed>>,players:array<string,array<int,int>>,toi_shifts:array<int,array<string,mixed>>,team_ids:array<string,int>} $resolver
     * @param array<string,array<string,mixed>> $queuedRows
     * @return array<int,int>
     */
    private function htmlToiCorrectedShiftIds(PlayByPlay $event, int $eventTime, array $resolver, array $queuedRows): array
    {
        if (($event->period_type ?? null) === 'SO') {
            return [];
        }

        $htmlEvent = $resolver['events'][$this->eventKey($event)] ?? null;

        if ($htmlEvent === null || ($resolver['toi_shifts'] ?? []) === []) {
            return [];
        }

        $htmlPlayerIdsByTeamType = $this->htmlPlayerIdsByTeamType($htmlEvent, $resolver['players']);
        $expectedHtmlIds = $this->expectedHtmlPlayerIds($htmlPlayerIdsByTeamType);

        if ($expectedHtmlIds === []) {
            return [];
        }

        $currentShiftIds = $this->queuedShiftIdsForEvent($queuedRows, (int) $event->id);
        $currentIds = $this->candidateShiftPlayerIds($currentShiftIds);

        if ($currentIds === [] || $currentIds === $expectedHtmlIds) {
            return [];
        }

        $missingFromCurrent = array_values(array_diff($expectedHtmlIds, $currentIds));
        $extraInCurrent = array_values(array_diff($currentIds, $expectedHtmlIds));

        if (! $this->htmlToiSupportsCorrection($resolver['toi_shifts'], $event, $eventTime, $missingFromCurrent, $extraInCurrent)) {
            return [];
        }

        $shiftIds = [];

        foreach ($this->htmlUnitGroups($htmlPlayerIdsByTeamType) as $group) {
            $window = $this->htmlToiCommonWindow($resolver['toi_shifts'], $group['player_ids'], $event, $eventTime);

            if ($window === null) {
                return [];
            }

            $team = $group['team_abbrev'];
            $unit = $this->unitResolver->resolve($group['unit_type'], $group['player_ids'], $team);
            $teamId = $resolver['team_ids'][$team] ?? null;
            $start = (int) $window['start'];
            $end = (int) $window['end'];

            $unitShift = NhlUnitShift::updateOrCreate(
                [
                    'unit_id' => $unit->id,
                    'nhl_game_id' => $this->gameId,
                    'start_game_seconds' => $start,
                ],
                [
                    'period' => $this->periodFromSeconds($start),
                    'start_time' => $this->secondsToTimeString($start),
                    'end_time' => $this->secondsToTimeString($end),
                    'end_game_seconds' => $end,
                    'seconds' => max(0, $end - $start),
                    'team_id' => $teamId,
                    'team_abbrev' => $team,
                ]
            );

            $shiftIds[] = (int) $unitShift->id;
        }

        sort($shiftIds, SORT_NUMERIC);

        return $this->candidateShiftPlayerIds($shiftIds) === $expectedHtmlIds ? $shiftIds : [];
    }

    /**
     * Penalty-shot attempts are tracked as events, but they do not have normal on-ice units.
     *
     * @param array{events:array<string,array<string,mixed>>,players:array<string,array<int,int>>,toi_shifts:array<int,array<string,mixed>>,team_ids:array<string,int>}|null $resolver
     */
    private function isPenaltyShotAttemptEvent(PlayByPlay $event, ?array $resolver): bool
    {
        if (! in_array((string) $event->type_desc_key, ['shot-on-goal', 'goal', 'missed-shot'], true)) {
            return false;
        }

        $metadata = is_array($event->metadata) ? $event->metadata : [];

        if (($metadata['is_penalty_shot_attempt'] ?? false) === true) {
            return true;
        }

        if ((string) $event->desc_key === 'penalty-shot-attempt') {
            return true;
        }

        $htmlEvent = $resolver['events'][$this->eventKey($event)] ?? null;

        return $htmlEvent !== null
            && str_contains(strtolower((string) ($htmlEvent['description'] ?? '')), 'penalty shot');
    }

    /**
     * Build HTML on-ice player sets by team and unit type.
     *
     * @param array<string,mixed> $htmlEvent
     * @param array<string,array<int,int>> $nhlPlayerIdsByTeamSweater
     * @return array<string,array<string,array<int,int>>>
     */
    private function htmlPlayerIdsByTeamType(array $htmlEvent, array $nhlPlayerIdsByTeamSweater): array
    {
        $playersByTeamType = [];

        foreach ($htmlEvent['on_ice_players'] ?? [] as $player) {
            $team = strtoupper((string) ($player['team_abbrev'] ?? ''));
            $sweater = (int) ($player['sweater_number'] ?? 0);
            $position = (string) ($player['position_code'] ?? '');
            $nhlPlayerId = ! empty($player['nhl_player_id'])
                ? (int) $player['nhl_player_id']
                : ($nhlPlayerIdsByTeamSweater[$team][$sweater] ?? null);

            if ($team === '' || $nhlPlayerId === null) {
                continue;
            }

            $unitType = match ($position) {
                'LW', 'C', 'RW' => 'F',
                'D', 'LD', 'RD' => 'D',
                'G' => 'G',
                default => null,
            };

            if ($unitType === null) {
                continue;
            }

            $playersByTeamType[$team][$unitType][] = $nhlPlayerId;

            if ($unitType !== 'G') {
                $playersByTeamType[$team]['SK'][] = $nhlPlayerId;
            }
        }

        foreach ($playersByTeamType as $team => $types) {
            foreach ($types as $unitType => $ids) {
                $ids = array_values(array_unique(array_map('intval', $ids)));
                sort($ids, SORT_NUMERIC);

                $playersByTeamType[$team][$unitType === 'SK' ? 'PP' : $unitType] = $ids;
                $playersByTeamType[$team][$unitType === 'SK' ? 'PK' : $unitType] = $ids;

                if ($unitType === 'SK') {
                    unset($playersByTeamType[$team]['SK']);
                }
            }
        }

        return $playersByTeamType;
    }

    /**
     * Return all unique NHL player IDs represented by candidate unit shifts.
     *
     * @param array<int,int> $shiftIds
     * @return array<int,int>
     */
    private function candidateShiftPlayerIds(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return DB::table('nhl_unit_shifts as us')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'us.unit_id')
            ->join('players', 'players.id', '=', 'up.player_id')
            ->whereIn('us.id', $shiftIds)
            ->pluck('players.nhl_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Return all unique NHL player IDs from the parsed HTML event.
     *
     * @param array<string,array<string,array<int,int>>> $htmlPlayerIdsByTeamType
     * @return array<int,int>
     */
    private function expectedHtmlPlayerIds(array $htmlPlayerIdsByTeamType): array
    {
        $ids = [];

        foreach ($htmlPlayerIdsByTeamType as $types) {
            foreach ($types as $unitType => $playerIds) {
                if (in_array($unitType, ['PP', 'PK'], true)) {
                    continue;
                }

                $ids = [...$ids, ...$playerIds];
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Return the actual unit groups represented by one HTML PBP on-ice row.
     *
     * @param array<string,array<string,array<int,int>>> $htmlPlayerIdsByTeamType
     * @return array<int,array{team_abbrev:string,unit_type:string,player_ids:array<int,int>}>
     */
    private function htmlUnitGroups(array $htmlPlayerIdsByTeamType): array
    {
        $teams = array_keys($htmlPlayerIdsByTeamType);
        $skaterCounts = [];

        foreach ($teams as $team) {
            $skaterCounts[$team] = count($htmlPlayerIdsByTeamType[$team]['F'] ?? [])
                + count($htmlPlayerIdsByTeamType[$team]['D'] ?? []);
        }

        $specialTeams = count($skaterCounts) === 2
            && count(array_unique(array_values($skaterCounts))) > 1
            && min($skaterCounts) <= 4;
        $groups = [];

        foreach ($htmlPlayerIdsByTeamType as $team => $types) {
            $goalies = $types['G'] ?? [];

            if ($specialTeams) {
                $skaters = array_values(array_unique([
                    ...($types['F'] ?? []),
                    ...($types['D'] ?? []),
                ]));
                sort($skaters, SORT_NUMERIC);

                if ($skaters !== []) {
                    $groups[] = [
                        'team_abbrev' => $team,
                        'unit_type' => $skaterCounts[$team] === max($skaterCounts) ? 'PP' : 'PK',
                        'player_ids' => $skaters,
                    ];
                }
            } else {
                foreach (['F', 'D'] as $unitType) {
                    $playerIds = $types[$unitType] ?? [];

                    if ($playerIds !== []) {
                        $groups[] = [
                            'team_abbrev' => $team,
                            'unit_type' => $unitType,
                            'player_ids' => $playerIds,
                        ];
                    }
                }
            }

            if ($goalies !== []) {
                $groups[] = [
                    'team_abbrev' => $team,
                    'unit_type' => 'G',
                    'player_ids' => $goalies,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param array<string,array<string,mixed>> $queuedRows
     * @return array<int,int>
     */
    private function queuedShiftIdsForEvent(array $queuedRows, int $eventId): array
    {
        $shiftIds = [];

        foreach ($queuedRows as $key => $row) {
            if (! str_starts_with($key, $eventId . ':')) {
                continue;
            }

            $shiftIds[] = (int) $row['unit_shift_id'];
        }

        $shiftIds = array_values(array_unique($shiftIds));
        sort($shiftIds, SORT_NUMERIC);

        return $shiftIds;
    }

    /**
     * TV/TH must support every HTML-only player and reject every shiftchart-only player.
     *
     * @param array<int,array<string,mixed>> $toiShifts
     * @param array<int,int> $missingFromCurrent
     * @param array<int,int> $extraInCurrent
     */
    private function htmlToiSupportsCorrection(
        array $toiShifts,
        PlayByPlay $event,
        int $eventTime,
        array $missingFromCurrent,
        array $extraInCurrent
    ): bool {
        $differentIds = array_values(array_unique([
            ...$missingFromCurrent,
            ...$extraInCurrent,
        ]));

        if ($differentIds === []) {
            return false;
        }

        $knownToiIds = collect($toiShifts)
            ->pluck('nhl_player_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (array_diff($differentIds, $knownToiIds) !== []) {
            return false;
        }

        $activeToiIds = $this->activeHtmlToiIds($toiShifts, $event, $eventTime);

        if (array_diff($missingFromCurrent, $activeToiIds) !== []) {
            return false;
        }

        return array_intersect($extraInCurrent, $activeToiIds) === [];
    }

    /**
     * @param array<int,array<string,mixed>> $toiShifts
     * @return array<int,int>
     */
    private function activeHtmlToiIds(array $toiShifts, PlayByPlay $event, int $eventTime): array
    {
        return collect($toiShifts)
            ->filter(fn (array $shift): bool => $this->htmlToiShiftContainsEvent($shift, $event, $eventTime))
            ->pluck('nhl_player_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Find the shared TV/TH window for a group of HTML on-ice players.
     *
     * @param array<int,array<string,mixed>> $toiShifts
     * @param array<int,int> $playerIds
     * @return array{start:int,end:int}|null
     */
    private function htmlToiCommonWindow(array $toiShifts, array $playerIds, PlayByPlay $event, int $eventTime): ?array
    {
        $activeRows = collect($toiShifts)
            ->filter(fn (array $shift): bool => (
                in_array((int) ($shift['nhl_player_id'] ?? 0), $playerIds, true)
                && $this->htmlToiShiftContainsEvent($shift, $event, $eventTime)
            ))
            ->groupBy(fn (array $shift): int => (int) $shift['nhl_player_id']);

        if ($activeRows->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all() !== array_values($playerIds)) {
            return null;
        }

        $starts = [];
        $ends = [];

        foreach ($activeRows as $rows) {
            $row = $rows->first();
            $starts[] = (int) $row['start_game_seconds'];
            $ends[] = (int) $row['end_game_seconds'];
        }

        $start = max($starts);
        $end = min($ends);

        return $start <= $eventTime && $end >= $eventTime && $end > $start
            ? ['start' => $start, 'end' => $end]
            : null;
    }

    /**
     * @param array<string,mixed> $shift
     */
    private function htmlToiShiftContainsEvent(array $shift, PlayByPlay $event, int $eventTime): bool
    {
        if ((int) ($shift['period'] ?? 0) !== (int) $event->period) {
            return false;
        }

        $start = (int) ($shift['start_game_seconds'] ?? 0);
        $end = (int) ($shift['end_game_seconds'] ?? 0);

        if ($start <= $eventTime && $end > $eventTime) {
            return true;
        }

        return in_array((string) $event->type_desc_key, ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'], true)
            && $end === $eventTime;
    }

    /**
     * Resolve NHL IDs by team abbreviation and sweater number from imported boxscore rows.
     *
     * @return array<string,array<int,int>>
     */
    private function nhlPlayerIdsByTeamSweater(): array
    {
        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $this->gameId)
            ->first(['home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);

        if ($game === null) {
            return [];
        }

        $teamAbbrevsById = [
            (int) $game->home_team_id => strtoupper((string) $game->home_team_abbrev),
            (int) $game->away_team_id => strtoupper((string) $game->away_team_abbrev),
        ];

        $lookup = [];

        DB::table('nhl_boxscores')
            ->where('nhl_game_id', $this->gameId)
            ->whereIn('nhl_team_id', array_keys($teamAbbrevsById))
            ->whereNotNull('sweater_number')
            ->get(['nhl_team_id', 'sweater_number', 'nhl_player_id'])
            ->each(function (object $row) use (&$lookup, $teamAbbrevsById): void {
                $team = $teamAbbrevsById[(int) $row->nhl_team_id] ?? null;

                if ($team === null) {
                    return;
                }

                $lookup[$team][(int) $row->sweater_number] = (int) $row->nhl_player_id;
            });

        return $lookup;
    }

    /**
     * Resolve NHL team IDs by abbreviation for corrected unit-shift rows.
     *
     * @return array<string,int>
     */
    private function teamIdsByAbbrev(): array
    {
        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $this->gameId)
            ->first(['home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);

        if ($game === null) {
            return [];
        }

        return [
            strtoupper((string) $game->home_team_abbrev) => (int) $game->home_team_id,
            strtoupper((string) $game->away_team_abbrev) => (int) $game->away_team_id,
        ];
    }

    /**
     * Parse TV/TH HTML TOI reports into source shift windows.
     *
     * @param array<string,string> $reportUrls
     * @return array<int,array<string,mixed>>
     */
    private function htmlToiShifts(array $reportUrls): array
    {
        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $this->gameId)
            ->first(['home_team_abbrev', 'away_team_abbrev']);

        if ($game === null) {
            return [];
        }

        $playerIds = $this->nhlPlayerIdsByTeamSweater();

        return [
            ...$this->parseHtmlToiShifts(
                $this->fetchOptionalHtmlReport($reportUrls['toiAway'] ?? null),
                strtoupper((string) $game->away_team_abbrev),
                $playerIds
            ),
            ...$this->parseHtmlToiShifts(
                $this->fetchOptionalHtmlReport($reportUrls['toiHome'] ?? null),
                strtoupper((string) $game->home_team_abbrev),
                $playerIds
            ),
        ];
    }

    /**
     * @param array<string,array<int,int>> $playerIds
     * @return array<int,array<string,mixed>>
     */
    private function parseHtmlToiShifts(string $html, string $teamAbbrev, array $playerIds): array
    {
        if ($html === '' || $teamAbbrev === '') {
            return [];
        }

        $document = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $currentPlayer = null;
        $shifts = [];

        foreach ($document->getElementsByTagName('tr') as $tableRow) {
            $cells = [];

            foreach ($tableRow->getElementsByTagName('td') as $cell) {
                $cells[] = trim(preg_replace('/\s+/', ' ', html_entity_decode($cell->textContent, ENT_QUOTES | ENT_HTML5)) ?? '');
            }

            $cells = array_values(array_filter($cells, fn (string $cell): bool => $cell !== ''));

            if ($cells === []) {
                continue;
            }

            if (preg_match('/^(\d{1,2})\s+(.+),\s+(.+)$/u', $cells[0], $matches)) {
                $sweater = (int) $matches[1];
                $currentPlayer = [
                    'nhl_player_id' => $playerIds[$teamAbbrev][$sweater] ?? null,
                    'team_abbrev' => $teamAbbrev,
                    'sweater_number' => $sweater,
                ];

                continue;
            }

            if ($currentPlayer === null || ! $this->isHtmlToiShiftCells($cells)) {
                continue;
            }

            $period = $this->htmlToiPeriod($cells[1]);
            $start = $this->htmlToiGameSeconds($cells[1], $cells[2]);
            $end = $this->htmlToiGameSeconds($cells[1], $cells[3]);

            if (($currentPlayer['nhl_player_id'] ?? null) === null || $period === null || $start === null || $end === null) {
                continue;
            }

            $shifts[] = [
                ...$currentPlayer,
                'shift_number' => (int) $cells[0],
                'period' => $period,
                'start_game_seconds' => $start,
                'end_game_seconds' => $end,
                'start_time' => $this->htmlToiElapsedClock($cells[2]),
                'end_time' => $this->htmlToiElapsedClock($cells[3]),
                'duration' => $cells[4],
            ];
        }

        return $shifts;
    }

    /**
     * @param array<int,string> $cells
     */
    private function isHtmlToiShiftCells(array $cells): bool
    {
        return count($cells) >= 5
            && preg_match('/^\d+$/', $cells[0]) === 1
            && preg_match('/^(?:\d+|OT)$/', $cells[1]) === 1
            && str_contains($cells[2], '/')
            && str_contains($cells[3], '/')
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[4]) === 1;
    }

    private function htmlToiPeriod(string $period): ?int
    {
        $period = strtoupper(trim($period));

        if ($period === 'OT') {
            return 4;
        }

        return ctype_digit($period) ? (int) $period : null;
    }

    private function htmlToiElapsedClock(string $clockPair): ?string
    {
        $parts = array_map('trim', explode('/', $clockPair));
        $clock = $parts[0] ?? null;

        return is_string($clock) && preg_match('/^\d{1,2}:\d{2}$/', $clock) === 1 ? $this->normalizeClock($clock) : null;
    }

    private function htmlToiGameSeconds(string $period, string $clockPair): ?int
    {
        $normalizedPeriod = $this->htmlToiPeriod($period);
        $clock = $this->htmlToiElapsedClock($clockPair);

        if ($normalizedPeriod === null || $clock === null) {
            return null;
        }

        return $this->periodElapsedSeconds($normalizedPeriod, $clock);
    }

    private function periodElapsedSeconds(int $period, string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches)) {
            return null;
        }

        return $this->periodOffset($period) + ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function periodOffset(int $period): int
    {
        if ($period <= 1) {
            return 0;
        }

        if ($period <= 3) {
            return ($period - 1) * 1200;
        }

        return 3600 + (($period - 4) * 300);
    }

    private function periodFromSeconds(int $seconds): int
    {
        if ($seconds < 3600) {
            return intdiv($seconds, 1200) + 1;
        }

        return intdiv($seconds - 3600, 300) + 4;
    }

    private function secondsToTimeString(int $seconds): string
    {
        $period = $this->periodFromSeconds($seconds);
        $periodSeconds = $seconds - $this->periodOffset($period);

        return sprintf('%02d:%02d', intdiv($periodSeconds, 60), $periodSeconds % 60);
    }

    private function fetchOptionalHtmlReport(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        try {
            return Http::timeout(30)->accept('text/html')->get($url)->throw()->body();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Stable key for matching one API event to one parsed HTML row.
     */
    private function eventKey(PlayByPlay $event): string
    {
        return implode(':', [
            (string) $event->id,
            (string) $event->nhl_event_id,
            (string) $event->sort_order,
        ]);
    }

    /**
     * Normalize report/API clocks to the same display shape.
     */
    private function normalizeClock(string $clock): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $matches)) {
            return trim($clock);
        }

        return ((int) $matches[1]) . ':' . $matches[2];
    }




    protected function normalizeZoneCode($event, int $referenceTeamId): ?string
    {
        $zone = $event->zone_code;

        if (is_null($zone)) {
            return null;
        }

        // If event_owner_team_id matches reference team, zone is correct
        if ($event->event_owner_team_id == $referenceTeamId) {
            return $zone;
        }

        // Otherwise, flip zone: offensive <-> defensive, neutral stays the same
        return match ($zone) {
            'O' => 'D',
            'D' => 'O',
            default => $zone,
        };
    }


    public function printEventUnitShiftCounts(): void
    {
        $events = PlayByPlay::where('nhl_game_id', $this->gameId)
            ->orderBy('seconds_in_game')
            ->get();

        $unitShifts = NhlUnitShift::where('nhl_game_id', $this->gameId)
            ->get();

        $maxEventTime = $events->max('seconds_in_game');

        foreach ($events as $event) {
            $count = $unitShifts->filter(function ($shift) use ($event, $maxEventTime) {
                return $shift->start_game_seconds <= $event->seconds_in_game
                    && (
                        ($event->seconds_in_game == $maxEventTime && $shift->end_game_seconds >= $event->seconds_in_game) ||
                        ($event->seconds_in_game != $maxEventTime && $shift->end_game_seconds > $event->seconds_in_game)
                    );
            })->count();

            if ($count != 6) {
                echo "<p>Event ID: {$event->id} - Unit Shifts Count: {$count} </p>";
            }
        }
    }


    public function showTopLine(?int $gameId = null, ?string $posType = null)
    {
        $query = NhlUnitShift::query()
            ->selectRaw('unit_id, SUM(seconds) as total_seconds')
            ->groupBy('unit_id');

        if ($gameId) {
            $query->where('nhl_game_id', $gameId);
        }

        if ($posType) {
            $query->whereHas('unit', fn($q) => $q->where('unit_type', $posType));
        }

        $unitSeconds = $query->orderByDesc('total_seconds')->get();

        // Load units with players and attach total_seconds
        $units = NhlUnit::with('players', 'events')
            ->whereIn('id', $unitSeconds->pluck('unit_id'))
            ->get()
            ->keyBy('id');

        return $unitSeconds->map(fn($item) => [
            'unit' => $units->get($item->unit_id),
            'total_seconds' => $item->total_seconds,
        ])->values();
    }

}
