<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameSourceStatus;
use App\Models\NhlGameValidation;
use App\Models\NhlPbpSourceMismatch;
use App\Models\PlayByPlay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verify imported API PBP against NHL's official HTML play-by-play report.
 */
class VerifyNhlHtmlPlayByPlay
{
    public function __construct(
        private readonly NhlHtmlPbpReportLocator $locator,
        private readonly NhlHtmlPbpReportParser $parser,
        private readonly NhlSourceOnlyPbpReview $sourceOnlyPbpReview
    ) {
    }

    /**
     * Verify one game and return the number of parsed HTML events.
     */
    public function verify(int $gameId): int
    {
        $reportUrls = $this->locator->reportUrls($gameId);
        $sourceUrl = $reportUrls['playByPlay'] ?? null;

        if ($sourceUrl === null) {
            $validation = $this->persistValidation($gameId, NhlGameValidation::STATUS_INCOMPLETE, [[
                'mismatch_type' => 'source_unavailable',
                'severity' => NhlPbpSourceMismatch::SEVERITY_INFO,
                'source_url' => null,
                'html_event' => ['reason' => 'HTML play-by-play report URL was not available from right-rail.'],
            ]]);
            $this->exportTroubleshooting($validation, null);

            return 0;
        }

        try {
            $html = Http::timeout(30)->accept('text/html')->get($sourceUrl)->throw()->body();
        } catch (\Throwable $throwable) {
            $this->storeHtmlSourceStatus(
                $gameId,
                NhlGameSourceStatus::STATUS_UNAVAILABLE,
                $sourceUrl,
                'html_pbp_fetch_failed',
                ['message' => $throwable->getMessage()]
            );

            $validation = $this->persistValidation($gameId, NhlGameValidation::STATUS_INCOMPLETE, [[
                'mismatch_type' => 'source_unavailable',
                'severity' => NhlPbpSourceMismatch::SEVERITY_INFO,
                'source_url' => $sourceUrl,
                'html_event' => ['reason' => $throwable->getMessage()],
            ]]);
            $this->exportTroubleshooting($validation, $sourceUrl);

            return 0;
        }

        try {
            $htmlEvents = $this->parser->parse($html);
        } catch (\Throwable $throwable) {
            $this->storeHtmlSourceStatus(
                $gameId,
                NhlGameSourceStatus::STATUS_UNAVAILABLE,
                $sourceUrl,
                'html_pbp_parse_failed',
                ['message' => $throwable->getMessage()]
            );

            $validation = $this->persistValidation($gameId, NhlGameValidation::STATUS_INCOMPLETE, [[
                'mismatch_type' => 'parser_failure',
                'severity' => NhlPbpSourceMismatch::SEVERITY_INFO,
                'source_url' => $sourceUrl,
                'html_event' => ['reason' => $throwable->getMessage()],
            ]]);
            $this->exportTroubleshooting($validation, $sourceUrl);

            return 0;
        }

        $this->storeHtmlSourceStatus(
            $gameId,
            $htmlEvents === [] ? NhlGameSourceStatus::STATUS_EMPTY : NhlGameSourceStatus::STATUS_AVAILABLE,
            $sourceUrl,
            $htmlEvents === [] ? 'html_pbp_no_events' : null,
            ['events_count' => count($htmlEvents)]
        );

        if ($htmlEvents === []) {
            $validation = $this->persistValidation($gameId, NhlGameValidation::STATUS_INCOMPLETE, [[
                'mismatch_type' => 'parser_coverage_gap',
                'severity' => NhlPbpSourceMismatch::SEVERITY_INFO,
                'source_url' => $sourceUrl,
                'html_event' => ['reason' => 'HTML play-by-play report did not produce parseable events.'],
            ]]);
            $this->exportTroubleshooting($validation, $sourceUrl);

            return 0;
        }

        $apiEvents = PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->get();

        $htmlToiShifts = $this->htmlToiShifts($gameId, $reportUrls);
        $mismatches = $this->compareAndEnrich($gameId, $apiEvents, $htmlEvents, $sourceUrl, $htmlToiShifts);
        $status = $mismatches === []
            ? NhlGameValidation::STATUS_APPROVED
            : NhlGameValidation::STATUS_FAILED;

        $validation = $this->persistValidation($gameId, $status, $mismatches);

        if ((int) $validation->mismatch_count > 0) {
            $this->exportTroubleshooting($validation, $sourceUrl);
        }

        return count($htmlEvents);
    }

    /**
     * Compare source rows and write contextual player positions.
     *
     * @param \Illuminate\Support\Collection<int,PlayByPlay> $apiEvents
     * @param array<int,array<string,mixed>> $htmlEvents
     * @param array<int,array<string,mixed>> $htmlToiShifts
     * @return array<int,array<string,mixed>>
     */
    private function compareAndEnrich(
        int $gameId,
        \Illuminate\Support\Collection $apiEvents,
        array $htmlEvents,
        string $sourceUrl,
        array $htmlToiShifts
    ): array {
        $mismatches = [];
        $matchedApiEventKeys = [];

        foreach ($htmlEvents as $htmlEvent) {
            $apiEvent = $this->matchApiEvent($apiEvents, $htmlEvent, $matchedApiEventKeys);

            if (! $apiEvent) {
                $mismatches[] = $this->mismatch('event_missing_in_api', NhlPbpSourceMismatch::SEVERITY_HIGH, null, $htmlEvent, $sourceUrl);
                continue;
            }

            $matchedApiEventKeys[$this->apiEventKey($apiEvent)] = true;

            $eventMismatches = $this->fieldMismatches($apiEvent, $htmlEvent, $sourceUrl);
            $mismatches = [...$mismatches, ...$eventMismatches];

            if ($this->isPenaltyShotAttemptEvent($apiEvent, $htmlEvent)) {
                continue;
            }

            $resolvedHtmlPlayers = $this->resolvedHtmlOnIcePlayers($gameId, $htmlEvent);

            $this->upsertPositions($apiEvent, $resolvedHtmlPlayers);
            $onIceMismatch = $this->onIceMismatch(
                $apiEvent,
                $htmlEvent,
                $resolvedHtmlPlayers,
                $sourceUrl,
                $htmlToiShifts
            );

            if ($onIceMismatch !== null) {
                $mismatches[] = $onIceMismatch;
            }
        }

        return $mismatches;
    }

    /**
     * Match a parsed HTML event to one imported API PBP row.
     *
     * @param \Illuminate\Support\Collection<int,PlayByPlay> $apiEvents
     * @param array<string,mixed> $htmlEvent
     * @param array<string,bool> $matchedApiEventKeys
     */
    private function matchApiEvent(\Illuminate\Support\Collection $apiEvents, array $htmlEvent, array $matchedApiEventKeys = []): ?PlayByPlay
    {
        $period = (int) ($htmlEvent['period'] ?? 0);
        $time = $this->normalizeClock((string) ($htmlEvent['time_in_period'] ?? ''));
        $type = (string) ($htmlEvent['type'] ?? '');

        $availableApiEvents = $apiEvents->reject(
            fn (PlayByPlay $event): bool => isset($matchedApiEventKeys[$this->apiEventKey($event)])
        );

        $matches = $availableApiEvents->filter(fn (PlayByPlay $event): bool => (
            (int) $event->period === $period
            && $this->normalizeClock((string) $event->time_in_period) === $time
        ));

        $typeMatches = $matches->filter(
            fn (PlayByPlay $event): bool => $this->eventTypesMatch($event, $type)
        );

        if ($typeMatches->count() === 1) {
            return $typeMatches->first();
        }

        if ($typeMatches->count() > 1) {
            $challengeMatch = $this->sameClockStoppageMatch($typeMatches, $htmlEvent);

            if ($challengeMatch instanceof PlayByPlay) {
                return $challengeMatch;
            }

            return $typeMatches->first();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Stable identity for marking one API event as consumed by one HTML row.
     */
    private function apiEventKey(PlayByPlay $event): string
    {
        if ($event->getKey() !== null) {
            return 'id:' . $event->getKey();
        }

        return implode(':', [
            'event',
            (string) $event->nhl_event_id,
            (string) $event->sort_order,
            (string) $event->period,
            (string) $event->time_in_period,
            (string) $event->type_desc_key,
        ]);
    }

    /**
     * Return field-level mismatches for a matched event.
     *
     * @param array<string,mixed> $htmlEvent
     * @return array<int,array<string,mixed>>
     */
    private function fieldMismatches(PlayByPlay $apiEvent, array $htmlEvent, string $sourceUrl): array
    {
        $mismatches = [];

        foreach (['period', 'time_in_period', 'type_desc_key'] as $field) {
            $htmlField = $field === 'type_desc_key' ? 'type' : $field;

            if ($field === 'time_in_period') {
                $apiValue = $this->normalizeClock((string) $apiEvent->{$field});
                $htmlValue = $this->normalizeClock((string) ($htmlEvent[$htmlField] ?? ''));
            } else {
                $apiValue = (string) $apiEvent->{$field};
                $htmlValue = (string) ($htmlEvent[$htmlField] ?? '');
            }

            if ($field === 'type_desc_key' && $this->eventTypesMatch($apiEvent, $htmlValue)) {
                continue;
            }

            if ($apiValue !== $htmlValue) {
                $mismatches[] = $this->mismatch(
                    $field === 'type_desc_key' ? 'event_type_mismatch' : 'event_clock_mismatch',
                    NhlPbpSourceMismatch::SEVERITY_HIGH,
                    $apiEvent,
                    $htmlEvent,
                    $sourceUrl
                );
            }
        }

        return $mismatches;
    }

    private function eventTypesMatch(PlayByPlay $event, string $htmlType): bool
    {
        $apiType = (string) $event->type_desc_key;

        if ($apiType === $htmlType) {
            return true;
        }

        return $this->isShootoutAttempt($event)
            && $apiType === 'failed-shot-attempt'
            && $htmlType === 'missed-shot';
    }

    private function isShootoutAttempt(PlayByPlay $event): bool
    {
        return (string) $event->period_type === 'SO' || (int) $event->period >= 5;
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

    /**
     * Pick the right same-clock stoppage when NHL records a challenge as multiple presentation rows.
     *
     * @param \Illuminate\Support\Collection<int,PlayByPlay> $matches
     * @param array<string,mixed> $htmlEvent
     */
    private function sameClockStoppageMatch(\Illuminate\Support\Collection $matches, array $htmlEvent): ?PlayByPlay
    {
        if (($htmlEvent['type'] ?? null) !== 'stoppage') {
            return null;
        }

        $description = strtolower((string) ($htmlEvent['description'] ?? ''));
        $wantsChallenge = str_contains($description, 'chlg') || str_contains($description, 'challenge');
        $wantsTvTimeout = str_contains($description, 'tv timeout');

        if ($wantsChallenge) {
            return $matches->first(function (PlayByPlay $event): bool {
                $details = $event->metadata['details'] ?? [];
                $reason = strtolower((string) ($details['reason'] ?? ''));
                $secondaryReason = strtolower((string) ($details['secondaryReason'] ?? ''));

                return str_contains($reason, 'chlg') || str_contains($secondaryReason, 'chlg');
            });
        }

        if ($wantsTvTimeout) {
            return $matches->first(function (PlayByPlay $event): bool {
                $details = $event->metadata['details'] ?? [];
                $reason = strtolower((string) ($details['reason'] ?? ''));
                $secondaryReason = strtolower((string) ($details['secondaryReason'] ?? ''));

                return str_contains($reason, 'tv-timeout') || str_contains($secondaryReason, 'tv-timeout');
            });
        }

        if (! str_contains($description, 'official')) {
            return null;
        }

        return $matches->first(function (PlayByPlay $event): bool {
            $details = $event->metadata['details'] ?? [];
            $reason = strtolower((string) ($details['reason'] ?? ''));

            return str_contains($reason, 'referee') || str_contains($reason, 'linesman') || str_contains($reason, 'official');
        });
    }

    /**
     * Penalty-shot attempts are tracked as events, but not as normal on-ice unit events.
     *
     * @param array<string,mixed> $htmlEvent
     */
    private function isPenaltyShotAttemptEvent(PlayByPlay $event, array $htmlEvent): bool
    {
        if (! in_array((string) $event->type_desc_key, ['shot-on-goal', 'goal', 'missed-shot'], true)) {
            return false;
        }

        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return ($metadata['is_penalty_shot_attempt'] ?? false) === true
            || (string) $event->desc_key === 'penalty-shot-attempt'
            || str_contains(strtolower((string) ($htmlEvent['description'] ?? '')), 'penalty shot');
    }

    /**
     * Store player-position rows for linked unit shifts when HTML exposes player identity.
     *
     * @param \Illuminate\Support\Collection<int,array{player_id:int,nhl_player_id:int,position_code:string|null,sweater_number:int|null,team_abbrev:string|null,side:string|null,text:string|null}> $players
     */
    private function upsertPositions(PlayByPlay $apiEvent, \Illuminate\Support\Collection $players): void
    {
        if ($players->isEmpty()) {
            return;
        }

        foreach ($players as $player) {
            if (empty($player['position_code'])) {
                continue;
            }

            $playerId = (int) $player['player_id'];

            $unitShiftId = DB::table('event_unit_shifts as eus')
                ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
                ->join('nhl_unit_players as up', 'up.unit_id', '=', 'us.unit_id')
                ->where('eus.event_id', $apiEvent->id)
                ->where('up.player_id', $playerId)
                ->value('us.id');

            if (! $unitShiftId) {
                continue;
            }

            DB::table('nhl_unit_shift_players')->updateOrInsert(
                [
                    'unit_shift_id' => (int) $unitShiftId,
                    'player_id' => (int) $playerId,
                ],
                [
                    'position_code' => $player['position_code'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * Compare HTML on-ice IDs to linked unit-shift player IDs.
     *
     * @param array<string,mixed> $htmlEvent
     * @param \Illuminate\Support\Collection<int,array{player_id:int,nhl_player_id:int,position_code:string|null,sweater_number:int|null,team_abbrev:string|null,side:string|null,text:string|null}> $resolvedHtmlPlayers
     * @param array<int,array<string,mixed>> $htmlToiShifts
     * @return array<string,mixed>|null
     */
    private function onIceMismatch(
        PlayByPlay $apiEvent,
        array $htmlEvent,
        \Illuminate\Support\Collection $resolvedHtmlPlayers,
        string $sourceUrl,
        array $htmlToiShifts
    ): ?array {
        if ($this->skipsOnIceComparison($apiEvent)) {
            return null;
        }

        $unresolvedPlayers = $this->htmlIdentityCandidates($htmlEvent)->count() - $resolvedHtmlPlayers->count();

        if ($unresolvedPlayers > 0) {
            return $this->mismatch(
                'on_ice_player_unresolved',
                NhlPbpSourceMismatch::SEVERITY_MEDIUM,
                $apiEvent,
                [
                    ...$htmlEvent,
                    'unresolved_on_ice_players' => $unresolvedPlayers,
                ],
                $sourceUrl
            );
        }

        $htmlIds = $resolvedHtmlPlayers
            ->pluck('nhl_player_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($htmlIds === []) {
            return null;
        }

        $linkedPlayers = $this->linkedOnIcePlayers($apiEvent);
        $linkedIds = $linkedPlayers
            ->pluck('nhl_player_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($linkedIds === [] || $linkedIds === $htmlIds) {
            return null;
        }

        if ($this->isExactBoundaryOnIceMismatch($apiEvent, $htmlIds, $linkedIds)) {
            return null;
        }

        if ($this->htmlToiResolvesOnIceMismatch($apiEvent, $htmlIds, $linkedIds, $htmlToiShifts)) {
            return null;
        }

        return $this->mismatch(
            'on_ice_player_mismatch',
            NhlPbpSourceMismatch::SEVERITY_MEDIUM,
            $apiEvent,
            [
                ...$htmlEvent,
                'resolved_html_on_ice_player_ids' => $htmlIds,
                'resolved_html_on_ice_players' => $resolvedHtmlPlayers->values()->all(),
                'linked_on_ice_player_ids' => $linkedIds,
                'linked_on_ice_players' => $linkedPlayers->values()->all(),
            ],
            $sourceUrl
        );
    }

    private function skipsOnIceComparison(PlayByPlay $event): bool
    {
        return in_array((string) $event->type_desc_key, ['period-end', 'game-end'], true);
    }

    /**
     * Shiftcharts do not consistently tell whether an exact-second line change happened before or after the event.
     *
     * @param array<int,int> $htmlIds
     * @param array<int,int> $linkedIds
     */
    private function isExactBoundaryOnIceMismatch(PlayByPlay $apiEvent, array $htmlIds, array $linkedIds): bool
    {
        $eventSecond = $apiEvent->seconds_in_game;

        if ($eventSecond === null) {
            return false;
        }

        $differentPlayerIds = array_values(array_unique([
            ...array_diff($htmlIds, $linkedIds),
            ...array_diff($linkedIds, $htmlIds),
        ]));

        if ($differentPlayerIds === []) {
            return false;
        }

        $boundaryPlayerIds = DB::table('nhl_unit_shifts as us')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'us.unit_id')
            ->join('players', 'players.id', '=', 'up.player_id')
            ->where('us.nhl_game_id', $apiEvent->nhl_game_id)
            ->where(function ($query) use ($eventSecond): void {
                $query->where('us.start_game_seconds', (int) $eventSecond)
                    ->orWhere('us.end_game_seconds', (int) $eventSecond);
            })
            ->pluck('players.nhl_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $boundaryPlayerIds !== [] && array_diff($differentPlayerIds, $boundaryPlayerIds) === [];
    }

    /**
     * TV/TH TOI can arbitrate source disagreements when it independently supports HTML PBP.
     *
     * @param array<int,int> $htmlIds
     * @param array<int,int> $linkedIds
     * @param array<int,array<string,mixed>> $htmlToiShifts
     */
    private function htmlToiResolvesOnIceMismatch(
        PlayByPlay $apiEvent,
        array $htmlIds,
        array $linkedIds,
        array $htmlToiShifts
    ): bool {
        if ($htmlToiShifts === [] || $apiEvent->seconds_in_game === null) {
            return false;
        }

        $missingFromLinked = array_values(array_diff($htmlIds, $linkedIds));
        $extraInLinked = array_values(array_diff($linkedIds, $htmlIds));

        if ($missingFromLinked === [] && $extraInLinked === []) {
            return false;
        }

        $knownToiIds = collect($htmlToiShifts)
            ->pluck('nhl_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (array_diff([...$missingFromLinked, ...$extraInLinked], $knownToiIds) !== []) {
            return false;
        }

        $activeToiIds = collect($htmlToiShifts)
            ->filter(fn (array $shift): bool => (
                (int) ($shift['period'] ?? 0) === (int) $apiEvent->period
                && $this->htmlToiShiftContainsEvent($shift, $apiEvent, (int) $apiEvent->seconds_in_game)
            ))
            ->pluck('nhl_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (array_diff($missingFromLinked, $activeToiIds) !== []) {
            return false;
        }

        return array_intersect($extraInLinked, $activeToiIds) === [];
    }

    /**
     * @param array<string,mixed> $shift
     */
    private function htmlToiShiftContainsEvent(array $shift, PlayByPlay $apiEvent, int $eventSecond): bool
    {
        $start = (int) ($shift['start_game_seconds'] ?? 0);
        $end = (int) ($shift['end_game_seconds'] ?? 0);

        if ($start <= $eventSecond && $end > $eventSecond) {
            return true;
        }

        if (
            in_array((string) $apiEvent->type_desc_key, ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'], true)
            && $end === $eventSecond
        ) {
            return true;
        }

        return false;
    }

    /**
     * Return linked shiftchart/unit players for one imported API event.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function linkedOnIcePlayers(PlayByPlay $apiEvent): \Illuminate\Support\Collection
    {
        return DB::table('event_unit_shifts as eus')
            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->join('nhl_units as units', 'units.id', '=', 'us.unit_id')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'units.id')
            ->join('players', 'players.id', '=', 'up.player_id')
            ->where('eus.event_id', $apiEvent->id)
            ->orderBy('us.team_id')
            ->orderBy('units.unit_type')
            ->orderBy('players.nhl_id')
            ->get([
                'eus.event_id',
                'eus.unit_shift_id',
                'us.nhl_game_id',
                'us.period',
                'us.start_time',
                'us.end_time',
                'us.start_game_seconds',
                'us.end_game_seconds',
                'us.seconds',
                'us.team_id',
                'us.team_abbrev',
                'units.id as unit_id',
                'units.unit_type',
                'up.player_id',
                'players.nhl_id as nhl_player_id',
                'players.full_name',
                'players.position',
                'players.team_abbrev as player_team_abbrev',
            ])
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * Resolve HTML on-ice jersey/position rows to local player IDs.
     *
     * @param array<string,mixed> $htmlEvent
     * @return \Illuminate\Support\Collection<int,array{player_id:int,nhl_player_id:int,position_code:string|null,sweater_number:int|null,team_abbrev:string|null,side:string|null,text:string|null}>
     */
    private function resolvedHtmlOnIcePlayers(int $gameId, array $htmlEvent): \Illuminate\Support\Collection
    {
        $htmlPlayers = $this->htmlIdentityCandidates($htmlEvent);

        if ($htmlPlayers->isEmpty()) {
            return collect();
        }

        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $gameId)
            ->first(['home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);

        $teamIdsByAbbrev = [];

        if ($game !== null) {
            $teamIdsByAbbrev[strtoupper((string) $game->home_team_abbrev)] = (int) $game->home_team_id;
            $teamIdsByAbbrev[strtoupper((string) $game->away_team_abbrev)] = (int) $game->away_team_id;
        }

        $jerseyKeys = $htmlPlayers
            ->filter(fn (array $player): bool => empty($player['nhl_player_id']) && ! empty($player['team_abbrev']) && ! empty($player['sweater_number']))
            ->map(function (array $player) use ($teamIdsByAbbrev): ?array {
                $teamId = $teamIdsByAbbrev[strtoupper((string) $player['team_abbrev'])] ?? null;

                return $teamId !== null ? [
                    'team_id' => $teamId,
                    'sweater_number' => (int) $player['sweater_number'],
                ] : null;
            })
            ->filter()
            ->values();

        $nhlIdsByJersey = collect();

        if ($jerseyKeys->isNotEmpty()) {
            $nhlIdsByJersey = DB::table('nhl_boxscores')
                ->where('nhl_game_id', $gameId)
                ->whereIn('nhl_team_id', $jerseyKeys->pluck('team_id')->unique()->all())
                ->whereIn('sweater_number', $jerseyKeys->pluck('sweater_number')->unique()->all())
                ->get(['nhl_team_id', 'sweater_number', 'nhl_player_id'])
                ->keyBy(fn (object $row): string => ((int) $row->nhl_team_id).':'.((int) $row->sweater_number));
        }

        $nhlIds = $htmlPlayers
            ->map(function (array $player) use ($teamIdsByAbbrev, $nhlIdsByJersey): ?int {
                if (! empty($player['nhl_player_id'])) {
                    return (int) $player['nhl_player_id'];
                }

                $teamId = $teamIdsByAbbrev[strtoupper((string) ($player['team_abbrev'] ?? ''))] ?? null;

                if ($teamId === null || empty($player['sweater_number'])) {
                    return null;
                }

                $row = $nhlIdsByJersey->get($teamId.':'.((int) $player['sweater_number']));

                return $row !== null ? (int) $row->nhl_player_id : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($nhlIds->isEmpty()) {
            return collect();
        }

        $playerIdsByNhlId = DB::table('players')
            ->whereIn('nhl_id', $nhlIds->all())
            ->pluck('id', 'nhl_id');

        return $htmlPlayers
            ->map(function (array $player) use ($teamIdsByAbbrev, $nhlIdsByJersey, $playerIdsByNhlId): ?array {
                $nhlPlayerId = ! empty($player['nhl_player_id']) ? (int) $player['nhl_player_id'] : null;
                $teamId = $teamIdsByAbbrev[strtoupper((string) ($player['team_abbrev'] ?? ''))] ?? null;

                if ($nhlPlayerId === null && $teamId !== null && ! empty($player['sweater_number'])) {
                    $row = $nhlIdsByJersey->get($teamId.':'.((int) $player['sweater_number']));
                    $nhlPlayerId = $row !== null ? (int) $row->nhl_player_id : null;
                }

                $playerId = $nhlPlayerId !== null ? ($playerIdsByNhlId[(string) $nhlPlayerId] ?? null) : null;

                if (! $playerId) {
                    return null;
                }

                return [
                    'player_id' => (int) $playerId,
                    'nhl_player_id' => $nhlPlayerId,
                    'position_code' => isset($player['position_code']) ? (string) $player['position_code'] : null,
                    'sweater_number' => isset($player['sweater_number']) ? (int) $player['sweater_number'] : null,
                    'team_abbrev' => isset($player['team_abbrev']) ? (string) $player['team_abbrev'] : null,
                    'side' => isset($player['side']) ? (string) $player['side'] : null,
                    'text' => isset($player['text']) ? (string) $player['text'] : null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Return HTML players with enough information to identify a player.
     *
     * @param array<string,mixed> $htmlEvent
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function htmlIdentityCandidates(array $htmlEvent): \Illuminate\Support\Collection
    {
        return collect($htmlEvent['on_ice_players'] ?? [])
            ->filter(fn (array $player): bool => ! empty($player['nhl_player_id']) || (
                ! empty($player['team_abbrev']) && ! empty($player['sweater_number'])
            ))
            ->values();
    }

    /**
     * Parse TV/TH report shift windows for the game.
     *
     * @param array<string,string> $reportUrls
     * @return array<int,array<string,mixed>>
     */
    private function htmlToiShifts(int $gameId, array $reportUrls): array
    {
        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $gameId)
            ->first(['home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);

        if ($game === null) {
            return [];
        }

        $teamAbbrevsById = [
            (int) $game->away_team_id => strtoupper((string) $game->away_team_abbrev),
            (int) $game->home_team_id => strtoupper((string) $game->home_team_abbrev),
        ];
        $nhlIdsByTeamSweater = DB::table('nhl_boxscores')
            ->where('nhl_game_id', $gameId)
            ->get(['nhl_team_id', 'sweater_number', 'nhl_player_id'])
            ->mapWithKeys(function (object $row) use ($teamAbbrevsById): array {
                $team = $teamAbbrevsById[(int) $row->nhl_team_id] ?? null;
                $sweater = (int) $row->sweater_number;
                $nhlPlayerId = (int) $row->nhl_player_id;

                if ($team === null || $sweater <= 0 || $nhlPlayerId <= 0) {
                    return [];
                }

                return [$team . ':' . $sweater => $nhlPlayerId];
            })
            ->all();

        return [
            ...$this->parseHtmlToiShifts(
                $this->fetchOptionalHtmlReport($reportUrls['toiAway'] ?? null),
                strtoupper((string) $game->away_team_abbrev),
                $nhlIdsByTeamSweater
            ),
            ...$this->parseHtmlToiShifts(
                $this->fetchOptionalHtmlReport($reportUrls['toiHome'] ?? null),
                strtoupper((string) $game->home_team_abbrev),
                $nhlIdsByTeamSweater
            ),
        ];
    }

    /**
     * @param array<string,int> $nhlIdsByTeamSweater
     * @return array<int,array<string,mixed>>
     */
    private function parseHtmlToiShifts(string $html, string $teamAbbrev, array $nhlIdsByTeamSweater): array
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
                    'nhl_player_id' => $nhlIdsByTeamSweater[$teamAbbrev . ':' . $sweater] ?? null,
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
     * Build a persisted mismatch row payload.
     *
     * @param array<string,mixed>|null $htmlEvent
     * @return array<string,mixed>
     */
    private function mismatch(
        string $type,
        string $severity,
        ?PlayByPlay $apiEvent,
        ?array $htmlEvent,
        string $sourceUrl
    ): array {
        return [
            'play_by_play_id' => $apiEvent?->id,
            'nhl_event_id' => $apiEvent?->nhl_event_id ?? ($htmlEvent['event_number'] ?? null),
            'mismatch_type' => $type,
            'severity' => $severity,
            'period' => $apiEvent?->period ?? ($htmlEvent['period'] ?? null),
            'time_in_period' => $apiEvent?->time_in_period ?? ($htmlEvent['time_in_period'] ?? null),
            'source_url' => $sourceUrl,
            'api_event' => $apiEvent ? $this->apiSnapshot($apiEvent) : null,
            'html_event' => $htmlEvent,
        ];
    }

    /**
     * Persist one validation header and replace its mismatch rows.
     *
     * @param array<int,array<string,mixed>> $mismatches
     */
    private function persistValidation(int $gameId, string $status, array $mismatches): NhlGameValidation
    {
        return DB::transaction(function () use ($gameId, $status, $mismatches): NhlGameValidation {
            $validation = NhlGameValidation::query()->updateOrCreate(
                [
                    'nhl_game_id' => $gameId,
                    'validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT,
                ],
                [
                    'status' => $status,
                    'mismatch_count' => count($mismatches),
                    'checked_at' => now(),
                    'approved_at' => $status === NhlGameValidation::STATUS_APPROVED ? now() : null,
                    'approved_by' => null,
                    'resolution' => null,
                    'resolution_note' => null,
                ]
            );

            $validation->pbpSourceMismatches()->delete();

            foreach ($mismatches as $mismatch) {
                $validation->pbpSourceMismatches()->create($mismatch);
            }

            return $validation->refresh();
        });
    }

    /**
     * Export offline review payloads without affecting verifier result.
     */
    private function exportTroubleshooting(NhlGameValidation $validation, ?string $htmlUrl): void
    {
        try {
            $gameId = (int) $validation->nhl_game_id;
            $this->sourceOnlyPbpReview->writeTroubleshootingErrors($gameId);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to export NHL HTML PBP troubleshooting payloads.', [
                'game_id' => $validation->nhl_game_id,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Store HTML PBP source status.
     *
     * @param array<string,mixed> $details
     */
    private function storeHtmlSourceStatus(
        int $gameId,
        string $status,
        string $url,
        ?string $reason,
        array $details
    ): void {
        NhlGameSourceStatus::query()->updateOrCreate(
            [
                'nhl_game_id' => $gameId,
                'source' => NhlGameSourceStatus::SOURCE_HTML_PBP,
            ],
            [
                'status' => $status,
                'reason' => $reason,
                'url' => $url,
                'details' => $details,
                'checked_at' => now(),
            ]
        );
    }

    /**
     * Snapshot comparable API PBP fields.
     *
     * @return array<string,mixed>
     */
    private function apiSnapshot(PlayByPlay $event): array
    {
        return [
            'id' => $event->id,
            'nhl_event_id' => $event->nhl_event_id,
            'sort_order' => $event->sort_order,
            'period' => $event->period,
            'time_in_period' => $event->time_in_period,
            'type_desc_key' => $event->type_desc_key,
            'event_owner_team_id' => $event->event_owner_team_id,
            'scoring_player_id' => $event->scoring_player_id,
            'committed_by_player_id' => $event->committed_by_player_id,
            'shooting_player_id' => $event->shooting_player_id,
        ];
    }
}
