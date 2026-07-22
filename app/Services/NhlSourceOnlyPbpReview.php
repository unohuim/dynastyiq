<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Builds a source-only PBP on-ice review from NHL API PBP, HTML PBP, and raw shiftcharts.
 */
class NhlSourceOnlyPbpReview
{
    public function __construct(
        private readonly NhlHtmlPbpReportLocator $locator,
        private readonly NhlHtmlPbpReportParser $parser
    ) {
    }

    /**
     * Build one review page and write source-only player-on-ice mismatches to full_pbp_errors.txt.
     *
     * @return array<string,mixed>
     */
    public function review(int $gameId, int $index = 0): array
    {
        $review = $this->sourceReview($gameId);
        $events = $review['events'];
        $mismatches = $review['mismatches'];

        $this->writeErrors(
            $gameId,
            $review['api_url'],
            $review['html_url'],
            $review['shift_url'],
            $mismatches,
            'full_pbp_errors.txt'
        );

        $firstMismatchIndex = $mismatches[0]['index'] ?? null;
        $index = $index > 0 || $firstMismatchIndex === null
            ? $index
            : (int) $firstMismatchIndex;
        $index = max(0, min($index, max(count($events) - 1, 0)));
        $nextMismatchIndex = collect($mismatches)
            ->pluck('index')
            ->first(fn (int $mismatchIndex): bool => $mismatchIndex > $index);

        return [
            'game_id' => $gameId,
            'api_url' => $review['api_url'],
            'html_url' => $review['html_url'],
            'shift_url' => $review['shift_url'],
            'toi_away_url' => $review['toi_away_url'],
            'toi_home_url' => $review['toi_home_url'],
            'event_count' => count($events),
            'mismatch_count' => count($mismatches),
            'current_index' => $index,
            'next_mismatch_index' => $nextMismatchIndex,
            'event' => $events[$index] ?? null,
        ];
    }

    /**
     * Write source-only troubleshooting output for failed enrichment.
     */
    public function writeTroubleshootingErrors(int $gameId): void
    {
        $review = $this->sourceReview($gameId);
        $directory = $this->troubleshootingDirectory($gameId);

        if ($directory === null) {
            return;
        }

        $this->writeRawPayloadIfMissing($directory, "raw_api_pbp_{$gameId}.json", $review['raw_api_payload']);
        $this->writeRawPayloadIfMissing($directory, "raw_html_pbp_{$gameId}.html", $review['raw_html_payload']);
        $this->writeRawPayloadIfMissing($directory, "raw_toi_away_{$gameId}.html", $review['raw_toi_away_payload']);
        $this->writeRawPayloadIfMissing($directory, "raw_toi_home_{$gameId}.html", $review['raw_toi_home_payload']);
        $this->writeRawPayloadIfMissing($directory, "raw_shiftcharts_{$gameId}.json", $review['raw_shift_payload']);
        $this->writeBoxscoreDiagnostics($directory, $gameId, $review['html_toi_rows'], $review['shifts']);
        $this->writeErrors(
            $gameId,
            $review['api_url'],
            $review['html_url'],
            $review['shift_url'],
            $review['mismatches'],
            'errors.txt',
            5,
            true
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceReview(int $gameId): array
    {
        $apiUrl = "https://api-web.nhle.com/v1/gamecenter/{$gameId}/play-by-play";
        $shiftUrl = "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={$gameId}";
        $reportUrls = $this->locator->reportUrls($gameId);
        $htmlUrl = $reportUrls['playByPlay'] ?? null;
        $toiAwayUrl = $reportUrls['toiAway'] ?? null;
        $toiHomeUrl = $reportUrls['toiHome'] ?? null;

        $apiResponse = Http::timeout(30)->acceptJson()->get($apiUrl)->throw();
        $shiftResponse = Http::timeout(30)->acceptJson()->get($shiftUrl)->throw();
        $apiPayload = $apiResponse->json();
        $shiftPayload = $shiftResponse->json();
        $html = $htmlUrl !== null
            ? Http::timeout(30)->accept('text/html')->get($htmlUrl)->throw()->body()
            : '';
        $toiAwayHtml = $this->fetchOptionalHtmlReport($toiAwayUrl);
        $toiHomeHtml = $this->fetchOptionalHtmlReport($toiHomeUrl);

        $apiEvents = $this->apiEvents(is_array($apiPayload) ? $apiPayload : []);
        $roster = $this->rosterByTeamSweater(is_array($apiPayload) ? $apiPayload : []);
        $awayTeamAbbrev = strtoupper((string) ($apiPayload['awayTeam']['abbrev'] ?? ''));
        $homeTeamAbbrev = strtoupper((string) ($apiPayload['homeTeam']['abbrev'] ?? ''));
        $htmlToiRows = [
            ...$this->htmlToiRows($toiAwayHtml, $awayTeamAbbrev, $roster),
            ...$this->htmlToiRows($toiHomeHtml, $homeTeamAbbrev, $roster),
        ];
        $htmlToiShifts = $this->htmlToiShiftRows($htmlToiRows);
        $htmlEvents = $this->parser->parse($html);
        $shifts = $this->shiftRows(is_array($shiftPayload) ? $shiftPayload : []);
        $htmlMatches = $this->matchedHtmlRows($apiEvents, $htmlEvents);

        $events = [];
        $mismatches = [];

        foreach ($apiEvents as $eventIndex => $apiEvent) {
            $htmlEvent = $htmlMatches[$apiEvent['key']] ?? null;
            $shiftPlayerPayloads = $this->shiftPlayerPayloadsForEvent($shifts, $apiEvent);
            $htmlPlayerPayloads = $this->htmlPlayerPayloads($htmlEvent, $roster);
            $shiftPlayers = collect($shiftPlayerPayloads)->pluck('player_id')->filter()->unique()->sort()->values()->all();
            $htmlPlayers = collect($htmlPlayerPayloads)->pluck('nhl_player_id')->filter()->unique()->sort()->values()->all();
            $missingFromShift = array_values(array_diff($htmlPlayers, $shiftPlayers));
            $extraInShift = array_values(array_diff($shiftPlayers, $htmlPlayers));
            $canCompare = $htmlEvent !== null
                && ($apiEvent['period_type'] ?? null) !== 'SO'
                && ! $this->skipsOnIceComparison($apiEvent, $htmlEvent);
            $isBoundaryResolved = $canCompare
                && $this->isExactBoundaryResolved($shifts, $apiEvent, $missingFromShift, $extraInShift);

            if ($isBoundaryResolved) {
                $missingFromShift = [];
                $extraInShift = [];
            }

            $toiResolution = null;

            if ($canCompare && ($missingFromShift !== [] || $extraInShift !== [])) {
                $toiResolution = $this->htmlToiResolvesMismatch($htmlToiShifts, $apiEvent, $missingFromShift, $extraInShift);

                if ($toiResolution !== null) {
                    $missingFromShift = [];
                    $extraInShift = [];
                }
            }

            $hasMismatch = $canCompare && ($missingFromShift !== [] || $extraInShift !== []);

            $row = [
                'index' => $eventIndex,
                'api' => $apiEvent,
                'html' => $htmlEvent,
                'shift_players' => $shiftPlayers,
                'html_players' => $htmlPlayers,
                'shift_player_payloads' => $shiftPlayerPayloads,
                'html_player_payloads' => $htmlPlayerPayloads,
                'all_shifts' => $shifts,
                'missing_from_shiftcharts' => $missingFromShift,
                'extra_in_shiftcharts' => $extraInShift,
                'boundary_resolution' => $isBoundaryResolved ? 'html_exact_boundary_resolved' : null,
                'toi_resolution' => $toiResolution,
                'comparison_skipped' => ! $canCompare,
                'has_mismatch' => $hasMismatch,
            ];

            $events[] = $row;

            if ($hasMismatch) {
                $mismatches[] = $row;
            }
        }

        return [
            'game_id' => $gameId,
            'api_url' => $apiUrl,
            'html_url' => $htmlUrl,
            'shift_url' => $shiftUrl,
            'toi_away_url' => $toiAwayUrl,
            'toi_home_url' => $toiHomeUrl,
            'raw_api_payload' => $apiResponse->body(),
            'raw_html_payload' => $html,
            'raw_toi_away_payload' => $toiAwayHtml,
            'raw_toi_home_payload' => $toiHomeHtml,
            'raw_shift_payload' => $shiftResponse->body(),
            'html_toi_rows' => $htmlToiRows,
            'shifts' => $shifts,
            'events' => $events,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * Period/game-end rows can carry presentation-only on-ice groups after the meaningful event at the same clock.
     *
     * @param array<string,mixed> $event
     */
    private function skipsOnIceComparison(array $event, ?array $htmlEvent = null): bool
    {
        return in_array((string) ($event['type'] ?? ''), ['period-end', 'game-end'], true)
            || $this->isPenaltyShotAttempt($event, $htmlEvent);
    }

    /**
     * Penalty-shot attempts are tracked as events, but not as normal on-ice unit events.
     *
     * @param array<string,mixed> $event
     */
    private function isPenaltyShotAttempt(array $event, ?array $htmlEvent = null): bool
    {
        if (! in_array((string) ($event['type'] ?? ''), ['shot-on-goal', 'goal', 'missed-shot'], true)) {
            return false;
        }

        $description = strtolower((string) ($event['description'] ?? ''));
        $htmlDescription = strtolower((string) ($htmlEvent['description'] ?? ''));
        $details = is_array($event['details'] ?? null) ? $event['details'] : [];

        return str_starts_with(strtolower((string) ($details['descKey'] ?? '')), 'ps-')
            || str_contains($description, 'penalty shot')
            || str_contains($htmlDescription, 'penalty shot');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function apiEvents(array $payload): array
    {
        return collect($payload['plays'] ?? [])
            ->values()
            ->map(function (array $event, int $index): array {
                $period = (int) ($event['periodDescriptor']['number'] ?? 0);
                $time = (string) ($event['timeInPeriod'] ?? '0:00');

                return [
                    'key' => (string) ($event['eventId'] ?? $index) . ':' . (string) ($event['sortOrder'] ?? $index),
                    'event_id' => $event['eventId'] ?? null,
                    'sort_order' => $event['sortOrder'] ?? null,
                    'period' => $period,
                    'period_type' => $event['periodDescriptor']['periodType'] ?? null,
                    'time' => $this->normalizeClock($time),
                    'seconds' => $this->periodElapsedSeconds($period, $time),
                    'type' => (string) ($event['typeDescKey'] ?? ''),
                    'description' => $this->apiDescription($event),
                    'details' => $event['details'] ?? [],
                ];
            })
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $apiEvents
     * @param array<int,array<string,mixed>> $htmlEvents
     * @return array<string,array<string,mixed>>
     */
    private function matchedHtmlRows(array $apiEvents, array $htmlEvents): array
    {
        $availableHtmlRows = array_values($htmlEvents);
        $matches = [];

        foreach ($apiEvents as $apiEvent) {
            $matchIndex = null;

            foreach ($availableHtmlRows as $index => $htmlEvent) {
                if (
                    (int) ($htmlEvent['period'] ?? 0) === (int) $apiEvent['period']
                    && $this->normalizeClock((string) ($htmlEvent['time_in_period'] ?? '')) === $apiEvent['time']
                    && (string) ($htmlEvent['type'] ?? '') === $apiEvent['type']
                ) {
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex === null) {
                continue;
            }

            $matches[$apiEvent['key']] = $availableHtmlRows[$matchIndex];
            unset($availableHtmlRows[$matchIndex]);
            $availableHtmlRows = array_values($availableHtmlRows);
        }

        return $matches;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function shiftRows(array $payload): array
    {
        return collect($payload['data'] ?? [])
            ->filter(fn (array $shift): bool => (int) ($shift['typeCode'] ?? 0) === 517)
            ->map(function (array $shift): ?array {
                $period = (int) ($shift['period'] ?? 0);
                $start = $this->periodElapsedSeconds($period, (string) ($shift['startTime'] ?? ''));
                $end = $this->periodElapsedSeconds($period, (string) ($shift['endTime'] ?? ''));

                if ($period <= 0 || $start === null || $end === null || $end <= $start) {
                    return null;
                }

                return [
                    'raw' => $shift,
                    'player_id' => (int) ($shift['playerId'] ?? 0),
                    'team_abbrev' => $shift['teamAbbrev'] ?? null,
                    'period' => $period,
                    'start' => $start,
                    'end' => $end,
                    'start_time' => $shift['startTime'] ?? null,
                    'end_time' => $shift['endTime'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $shifts
     * @param array<string,mixed> $event
     * @return array<int,array<string,mixed>>
     */
    private function shiftPlayerPayloadsForEvent(array $shifts, array $event): array
    {
        $eventSecond = $event['seconds'];

        if ($eventSecond === null) {
            return [];
        }

        return collect($shifts)
            ->filter(fn (array $shift): bool => (
                (int) $shift['period'] === (int) $event['period']
                && $this->shiftContainsEvent($shift, $event, (int) $eventSecond)
            ))
            ->sortBy('player_id')
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $shift
     * @param array<string,mixed> $event
     */
    private function shiftContainsEvent(array $shift, array $event, int $eventSecond): bool
    {
        $start = (int) $shift['start'];
        $end = (int) $shift['end'];
        $type = (string) ($event['type'] ?? '');

        if ($start <= $eventSecond && $end > $eventSecond) {
            return true;
        }

        if (in_array($type, ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'], true) && $end === $eventSecond) {
            return true;
        }

        return false;
    }

    /**
     * Exact shiftchart boundaries cannot prove whether the line change happened before or after the event.
     *
     * @param array<int,array<string,mixed>> $shifts
     * @param array<string,mixed> $event
     * @param array<int,int> $missingFromShift
     * @param array<int,int> $extraInShift
     */
    private function isExactBoundaryResolved(
        array $shifts,
        array $event,
        array $missingFromShift,
        array $extraInShift
    ): bool {
        if ($missingFromShift === [] && $extraInShift === []) {
            return false;
        }

        $eventSecond = $event['seconds'] ?? null;

        if ($eventSecond === null) {
            return false;
        }

        $boundaryPlayerIds = collect($shifts)
            ->filter(fn (array $shift): bool => (
                (int) $shift['period'] === (int) $event['period']
                && ((int) $shift['start'] === (int) $eventSecond || (int) $shift['end'] === (int) $eventSecond)
            ))
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($boundaryPlayerIds === []) {
            return false;
        }

        $differentPlayerIds = array_values(array_unique([
            ...$missingFromShift,
            ...$extraInShift,
        ]));

        return array_diff($differentPlayerIds, $boundaryPlayerIds) === [];
    }

    /**
     * @param array<string,mixed>|null $htmlEvent
     * @return array<int,array<string,mixed>>
     */
    private function htmlPlayerPayloads(?array $htmlEvent, array $roster): array
    {
        if ($htmlEvent === null) {
            return [];
        }

        return collect($htmlEvent['on_ice_players'] ?? [])
            ->map(function (array $player) use ($roster): ?int {
                if (! empty($player['nhl_player_id'])) {
                    return (int) $player['nhl_player_id'];
                }

                $team = strtoupper((string) ($player['team_abbrev'] ?? ''));
                $sweater = (int) ($player['sweater_number'] ?? 0);

                return $roster[$team . ':' . $sweater] ?? null;
            })
            ->filter()
            ->map(function (mixed $id) use ($htmlEvent, $roster): ?array {
                foreach ($htmlEvent['on_ice_players'] ?? [] as $player) {
                    $team = strtoupper((string) ($player['team_abbrev'] ?? ''));
                    $sweater = (int) ($player['sweater_number'] ?? 0);
                    $playerId = ! empty($player['nhl_player_id'])
                        ? (int) $player['nhl_player_id']
                        : ($roster[$team . ':' . $sweater] ?? null);

                    if ((int) $playerId !== (int) $id) {
                        continue;
                    }

                    return [
                        ...$player,
                        'nhl_player_id' => (int) $id,
                    ];
                }

                return null;
            })
            ->filter()
            ->sortBy('nhl_player_id')
            ->values()
            ->all();
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

    private function normalizeClock(string $clock): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $matches)) {
            return trim($clock);
        }

        return ((int) $matches[1]) . ':' . $matches[2];
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
     * @param array<string,mixed> $event
     */
    private function apiDescription(array $event): string
    {
        $details = $event['details'] ?? [];

        return trim(json_encode($details, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,int>
     */
    private function rosterByTeamSweater(array $payload): array
    {
        $teamsById = [];

        foreach (['awayTeam', 'homeTeam'] as $teamKey) {
            $team = $payload[$teamKey] ?? [];

            if (! is_array($team) || empty($team['id']) || empty($team['abbrev'])) {
                continue;
            }

            $teamsById[(int) $team['id']] = strtoupper((string) $team['abbrev']);
        }

        return collect($payload['rosterSpots'] ?? [])
            ->mapWithKeys(function (array $player) use ($teamsById): array {
                $team = $teamsById[(int) ($player['teamId'] ?? 0)] ?? null;
                $sweater = (int) ($player['sweaterNumber'] ?? 0);
                $playerId = (int) ($player['playerId'] ?? 0);

                if ($team === null || $sweater <= 0 || $playerId <= 0) {
                    return [];
                }

                return [$team . ':' . $sweater => $playerId];
            })
            ->all();
    }

    /**
     * Parse NHL TV/TH TOI report totals.
     *
     * @param array<string,int> $roster
     * @return array<int,array<string,mixed>>
     */
    private function htmlToiRows(string $html, string $teamAbbrev, array $roster): array
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
        $shiftRows = [];
        $rows = [];

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
                $lastName = trim($matches[2]);
                $firstName = trim($matches[3]);
                $currentPlayer = [
                    'nhl_player_id' => $roster[$teamAbbrev . ':' . $sweater] ?? null,
                    'team_abbrev' => $teamAbbrev,
                    'sweater_number' => $sweater,
                    'player_name' => trim($firstName . ' ' . $lastName),
                    'raw_player_name' => trim($lastName . ', ' . $firstName),
                ];
                $shiftRows = [];

                continue;
            }

            if ($currentPlayer === null) {
                continue;
            }

            if ($this->isHtmlToiShiftCells($cells)) {
                $shiftRows[] = [
                    'shift_number' => (int) $cells[0],
                    'period' => $this->htmlToiPeriod($cells[1]),
                    'start_time' => $this->htmlToiElapsedClock($cells[2]),
                    'end_time' => $this->htmlToiElapsedClock($cells[3]),
                    'start' => $this->htmlToiGameSeconds($cells[1], $cells[2]),
                    'end' => $this->htmlToiGameSeconds($cells[1], $cells[3]),
                    'duration' => $cells[4],
                    'event_marker' => $cells[5] ?? '',
                ];

                continue;
            }

            if (!$this->isHtmlToiTotalCells($cells)) {
                continue;
            }

            $rows[] = [
                ...$currentPlayer,
                'shifts' => (int) $cells[1],
                'average_toi' => $cells[2],
                'toi' => $cells[3],
                'toi_seconds' => $this->clockToSeconds($cells[3]),
                'ev_toi' => $cells[4],
                'ev_toi_seconds' => $this->clockToSeconds($cells[4]),
                'pp_toi' => $cells[5],
                'pp_toi_seconds' => $this->clockToSeconds($cells[5]),
                'sh_toi' => $cells[6],
                'sh_toi_seconds' => $this->clockToSeconds($cells[6]),
                'shift_rows' => array_values(array_filter(
                    $shiftRows,
                    fn (array $shift): bool => $shift['period'] !== null && $shift['start'] !== null && $shift['end'] !== null
                )),
            ];
            $currentPlayer = null;
            $shiftRows = [];
        }

        return $rows;
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

    /**
     * @param array<int,string> $cells
     */
    private function isHtmlToiTotalCells(array $cells): bool
    {
        return count($cells) >= 7
            && $cells[0] === 'TOT'
            && preg_match('/^\d+$/', $cells[1]) === 1
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[2]) === 1
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[3]) === 1
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[4]) === 1
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[5]) === 1
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[6]) === 1;
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

    /**
     * @param array<int,array<string,mixed>> $htmlToiRows
     * @return array<int,array<string,mixed>>
     */
    private function htmlToiShiftRows(array $htmlToiRows): array
    {
        return collect($htmlToiRows)
            ->flatMap(function (array $player): array {
                return collect($player['shift_rows'] ?? [])
                    ->map(fn (array $shift): array => [
                        ...$shift,
                        'player_id' => (int) ($player['nhl_player_id'] ?? 0),
                        'team_abbrev' => $player['team_abbrev'] ?? null,
                        'sweater_number' => $player['sweater_number'] ?? null,
                        'player_name' => $player['player_name'] ?? null,
                    ])
                    ->filter(fn (array $shift): bool => (int) ($shift['player_id'] ?? 0) > 0)
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $htmlToiShifts
     * @param array<string,mixed> $event
     * @param array<int,int> $missingFromShift
     * @param array<int,int> $extraInShift
     */
    private function htmlToiResolvesMismatch(
        array $htmlToiShifts,
        array $event,
        array $missingFromShift,
        array $extraInShift
    ): ?array {
        if ($htmlToiShifts === [] || ($missingFromShift === [] && $extraInShift === [])) {
            return null;
        }

        $eventSecond = $event['seconds'] ?? null;

        if ($eventSecond === null) {
            return null;
        }

        $activeToiPlayers = collect($htmlToiShifts)
            ->filter(fn (array $shift): bool => (
                (int) $shift['period'] === (int) $event['period']
                && $this->shiftContainsEvent($shift, $event, (int) $eventSecond)
            ));

        if ($activeToiPlayers->isEmpty()) {
            return null;
        }

        $activePlayerIds = $activeToiPlayers
            ->pluck('player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (array_diff($missingFromShift, $activePlayerIds) !== []) {
            return null;
        }

        if (array_intersect($extraInShift, $activePlayerIds) !== []) {
            return null;
        }

        return [
            'source' => 'html_toi',
            'active_player_ids' => $activePlayerIds,
            'missing_supported' => $missingFromShift,
            'extras_rejected' => $extraInShift,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $shifts
     * @return array<int,array<string,mixed>>
     */
    private function shiftAggregateRows(array $shifts): array
    {
        return collect($shifts)
            ->groupBy(fn (array $shift): int => (int) $shift['player_id'])
            ->map(function (\Illuminate\Support\Collection $playerShifts, int $playerId): array {
                $firstShift = $playerShifts->first();
                $seconds = $playerShifts->sum(
                    fn (array $shift): int => max(0, (int) $shift['end'] - (int) $shift['start'])
                );

                return [
                    'nhl_player_id' => $playerId,
                    'team_abbrev' => $firstShift['team_abbrev'] ?? '',
                    'shifts' => $playerShifts->count(),
                    'toi_seconds' => $seconds,
                    'toi' => $this->secondsToClock($seconds),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $mismatches
     */
    private function writeErrors(
        int $gameId,
        string $apiUrl,
        ?string $htmlUrl,
        string $shiftUrl,
        array $mismatches,
        string $filename,
        ?int $limit = null,
        bool $includePayloads = false
    ): void {
        $directory = $this->troubleshootingDirectory($gameId);

        if ($directory === null) {
            return;
        }

        $visibleMismatches = $limit === null ? $mismatches : array_slice($mismatches, 0, $limit);
        $title = $filename === 'full_pbp_errors.txt' ? 'Full PBP Source Review' : 'Source-Only PBP Review';
        $lines = [
            "{$title} {$gameId}",
            '',
            'API: ' . $apiUrl,
            'HTML: ' . ($htmlUrl ?: 'N/A'),
            'Shiftcharts: ' . $shiftUrl,
            'Mismatch count: ' . count($mismatches),
            'Displayed mismatch count: ' . count($visibleMismatches),
            '',
        ];

        foreach ($visibleMismatches as $index => $mismatch) {
            $event = $mismatch['api'];

            $lines[] = '---';
            $lines[] = 'Mismatch ' . ($index + 1);
            $lines[] = 'Event: ' . ($event['event_id'] ?? 'N/A');
            $lines[] = 'Sort: ' . ($event['sort_order'] ?? 'N/A');
            $lines[] = 'Time: P' . ($event['period'] ?? 'N/A') . ' ' . ($event['time'] ?? 'N/A');
            $lines[] = 'Type: ' . ($event['type'] ?? 'N/A');
            $lines[] = 'HTML players: ' . implode(', ', $mismatch['html_players']);
            $lines[] = 'Shiftchart players: ' . implode(', ', $mismatch['shift_players']);
            $lines[] = 'Missing from shiftcharts: ' . implode(', ', $mismatch['missing_from_shiftcharts']);
            $lines[] = 'Extra in shiftcharts: ' . implode(', ', $mismatch['extra_in_shiftcharts']);

            if ($includePayloads) {
                $lines[] = 'API event payload:';
                $lines[] = $this->jsonBlock($mismatch['api']);
                $lines[] = 'HTML PBP event payload:';
                $lines[] = $this->jsonBlock($mismatch['html']);
                $lines[] = 'Shift context for missing/extra players:';
                $lines[] = $this->jsonBlock($this->mismatchPlayerPayloads($mismatch));
            }

            $lines[] = '';
        }

        File::put($directory . '/' . $filename, implode("\n", $lines));
    }

    /**
     * @param array<int,array<string,mixed>> $htmlToiRows
     * @param array<int,array<string,mixed>> $shifts
     */
    private function writeBoxscoreDiagnostics(string $directory, int $gameId, array $htmlToiRows, array $shifts): void
    {
        $officialRows = $this->officialBoxscoreRows($gameId);
        $summaryRows = $this->summaryBoxscoreRows($gameId);
        $shiftRows = $this->shiftAggregateRows($shifts);

        File::put($directory . '/boxscore.txt', $this->boxscoreText(
            "Official Boxscore {$gameId}",
            $officialRows,
            'official'
        ));
        File::put($directory . '/our_boxscore.txt', $this->boxscoreText(
            "DynastyIQ Summary Boxscore {$gameId}",
            $summaryRows,
            'summary'
        ));
        File::put($directory . '/html_toi.txt', $this->htmlToiText($gameId, $htmlToiRows));
        File::put($directory . '/shifts_box_gaps.txt', $this->shiftBoxGapsText(
            $gameId,
            $officialRows,
            $summaryRows,
            $htmlToiRows,
            $shiftRows
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function officialBoxscoreRows(int $gameId): \Illuminate\Support\Collection
    {
        return DB::table('nhl_boxscores')
            ->where('nhl_game_id', $gameId)
            ->orderBy('nhl_team_id')
            ->orderBy('player_name')
            ->get([
                'nhl_player_id',
                'nhl_team_id',
                'sweater_number',
                'position',
                'player_name',
                'goals',
                'assists',
                'points',
                'plus_minus',
                'penalty_minutes',
                'sog',
                'hits',
                'blocks',
                'toi',
                'toi_seconds',
                'shifts',
                'saves',
                'shots_against',
                'goals_against',
                'ev_saves',
                'ev_shots_against',
                'pp_saves',
                'pp_shots_against',
                'pk_saves',
                'pk_shots_against',
            ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function summaryBoxscoreRows(int $gameId): \Illuminate\Support\Collection
    {
        return DB::table('nhl_game_summaries as summaries')
            ->leftJoin('players', 'players.nhl_id', '=', 'summaries.nhl_player_id')
            ->where('summaries.nhl_game_id', $gameId)
            ->orderBy('summaries.nhl_team_id')
            ->orderBy('players.full_name')
            ->get([
                'summaries.nhl_player_id',
                'summaries.nhl_team_id',
                'players.full_name as player_name',
                'players.position',
                'summaries.g',
                'summaries.a',
                'summaries.pts',
                'summaries.plus_minus',
                'summaries.pim',
                'summaries.sog',
                'summaries.h',
                'summaries.b',
                'summaries.toi',
                'summaries.shifts',
                'summaries.ps',
                'summaries.psg',
                'summaries.shog',
                'summaries.sv',
                'summaries.sa',
                'summaries.ga',
                'summaries.evsv',
                'summaries.evsa',
                'summaries.ppsv',
                'summaries.ppsa',
                'summaries.pksv',
                'summaries.pksa',
                'summaries.sv_pct',
                'summaries.gaa',
            ]);
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     */
    private function boxscoreText(string $title, \Illuminate\Support\Collection $rows, string $source): string
    {
        $lines = [
            $title,
            '',
            'Rows: ' . $rows->count(),
            '',
            implode("\t", [
                'player_id',
                'team_id',
                'name',
                'pos',
                'sweater',
                'toi',
                'toi_seconds',
                'shifts',
                'g',
                'a',
                'pts',
                '+/-',
                'pim',
                'sog',
                'hits',
                'blocks',
                'saves',
                'sa',
                'ga',
                'ev_sv/sa',
                'pp_sv/sa',
                'pk_sv/sa',
                'ps/psg/shog',
            ]),
        ];

        foreach ($rows as $row) {
            $lines[] = implode("\t", [
                (string) ($row->nhl_player_id ?? ''),
                (string) ($row->nhl_team_id ?? ''),
                (string) ($row->player_name ?? ''),
                (string) ($row->position ?? ''),
                $source === 'official' ? (string) ($row->sweater_number ?? '') : '',
                $source === 'official'
                    ? (string) ($row->toi ?? $this->secondsToClock($row->toi_seconds ?? null))
                    : $this->secondsToClock($row->toi ?? null),
                (string) ($source === 'official' ? ($row->toi_seconds ?? '') : ($row->toi ?? '')),
                (string) ($row->shifts ?? 0),
                (string) ($source === 'official' ? ($row->goals ?? 0) : ($row->g ?? 0)),
                (string) ($source === 'official' ? ($row->assists ?? 0) : ($row->a ?? 0)),
                (string) ($source === 'official' ? ($row->points ?? 0) : ($row->pts ?? 0)),
                (string) ($row->plus_minus ?? 0),
                (string) ($source === 'official' ? ($row->penalty_minutes ?? 0) : ($row->pim ?? 0)),
                (string) ($row->sog ?? 0),
                (string) ($source === 'official' ? ($row->hits ?? 0) : ($row->h ?? 0)),
                (string) ($source === 'official' ? ($row->blocks ?? 0) : ($row->b ?? 0)),
                (string) ($source === 'official' ? ($row->saves ?? 0) : ($row->sv ?? 0)),
                (string) ($source === 'official' ? ($row->shots_against ?? 0) : ($row->sa ?? 0)),
                (string) ($source === 'official' ? ($row->goals_against ?? 0) : ($row->ga ?? 0)),
                (string) ($source === 'official' ? ($row->ev_saves ?? 0) : ($row->evsv ?? 0)) . '/'
                    . (string) ($source === 'official' ? ($row->ev_shots_against ?? 0) : ($row->evsa ?? 0)),
                (string) ($source === 'official' ? ($row->pp_saves ?? 0) : ($row->ppsv ?? 0)) . '/'
                    . (string) ($source === 'official' ? ($row->pp_shots_against ?? 0) : ($row->ppsa ?? 0)),
                (string) ($source === 'official' ? ($row->pk_saves ?? 0) : ($row->pksv ?? 0)) . '/'
                    . (string) ($source === 'official' ? ($row->pk_shots_against ?? 0) : ($row->pksa ?? 0)),
                $source === 'official' ? '' : (string) ($row->ps ?? 0) . '/' . (string) ($row->psg ?? 0) . '/' . (string) ($row->shog ?? 0),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function htmlToiText(int $gameId, array $rows): string
    {
        $lines = [
            "HTML TV/TH TOI {$gameId}",
            '',
            'Rows: ' . count($rows),
            '',
            implode("\t", [
                'player_id',
                'team',
                'sweater',
                'name',
                'shifts',
                'avg_toi',
                'toi',
                'toi_seconds',
                'ev_toi',
                'pp_toi',
                'sh_toi',
            ]),
        ];

        foreach ($rows as $row) {
            $lines[] = implode("\t", [
                (string) ($row['nhl_player_id'] ?? ''),
                (string) ($row['team_abbrev'] ?? ''),
                (string) ($row['sweater_number'] ?? ''),
                (string) ($row['player_name'] ?? ''),
                (string) ($row['shifts'] ?? 0),
                (string) ($row['average_toi'] ?? ''),
                (string) ($row['toi'] ?? ''),
                (string) ($row['toi_seconds'] ?? ''),
                (string) ($row['ev_toi'] ?? ''),
                (string) ($row['pp_toi'] ?? ''),
                (string) ($row['sh_toi'] ?? ''),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $officialRows
     * @param \Illuminate\Support\Collection<int,object> $summaryRows
     * @param array<int,array<string,mixed>> $htmlToiRows
     * @param array<int,array<string,mixed>> $shiftRows
     */
    private function shiftBoxGapsText(
        int $gameId,
        \Illuminate\Support\Collection $officialRows,
        \Illuminate\Support\Collection $summaryRows,
        array $htmlToiRows,
        array $shiftRows
    ): string {
        $officialByPlayer = $officialRows->keyBy(fn (object $row): int => (int) $row->nhl_player_id);
        $summaryByPlayer = $summaryRows->keyBy(fn (object $row): int => (int) $row->nhl_player_id);
        $htmlByPlayer = collect($htmlToiRows)->keyBy(fn (array $row): int => (int) ($row['nhl_player_id'] ?? 0));
        $rawShiftByPlayer = collect($shiftRows)->keyBy(fn (array $row): int => (int) ($row['nhl_player_id'] ?? 0));
        $playerIds = $officialByPlayer->keys()
            ->merge($summaryByPlayer->keys())
            ->merge($htmlByPlayer->keys())
            ->merge($rawShiftByPlayer->keys())
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $gaps = $playerIds
            ->map(function (int $playerId) use ($officialByPlayer, $summaryByPlayer, $htmlByPlayer, $rawShiftByPlayer): ?array {
                $official = $officialByPlayer->get($playerId);
                $summary = $summaryByPlayer->get($playerId);
                $htmlToi = $htmlByPlayer->get($playerId);
                $rawShift = $rawShiftByPlayer->get($playerId);
                $officialToi = $official !== null ? (int) ($official->toi_seconds ?? 0) : 0;
                $summaryToi = $summary !== null ? (int) ($summary->toi ?? 0) : 0;
                $htmlToiSeconds = $htmlToi !== null ? (int) ($htmlToi['toi_seconds'] ?? 0) : 0;
                $rawShiftToi = $rawShift !== null ? (int) ($rawShift['toi_seconds'] ?? 0) : 0;
                $officialShifts = $official !== null ? (int) ($official->shifts ?? 0) : 0;
                $summaryShifts = $summary !== null ? (int) ($summary->shifts ?? 0) : 0;
                $htmlShifts = $htmlToi !== null ? (int) ($htmlToi['shifts'] ?? 0) : 0;
                $rawShiftCount = $rawShift !== null ? (int) ($rawShift['shifts'] ?? 0) : 0;
                $summaryToiDelta = $summaryToi - $officialToi;
                $summaryShiftDelta = $summaryShifts - $officialShifts;
                $htmlToiDelta = $htmlToiSeconds - $officialToi;
                $htmlShiftDelta = $htmlShifts - $officialShifts;
                $rawToiDelta = $rawShiftToi - $officialToi;
                $rawShiftDelta = $rawShiftCount - $officialShifts;

                if (
                    $summaryToiDelta === 0
                    && $summaryShiftDelta === 0
                    && $htmlToiDelta === 0
                    && $htmlShiftDelta === 0
                    && $rawToiDelta === 0
                    && $rawShiftDelta === 0
                ) {
                    return null;
                }

                return [
                    'player_id' => $playerId,
                    'team_id' => (int) ($official->nhl_team_id ?? $summary->nhl_team_id ?? 0),
                    'name' => (string) ($official->player_name ?? $summary->player_name ?? $htmlToi['player_name'] ?? ''),
                    'position' => (string) ($official->position ?? $summary->position ?? ''),
                    'official_toi' => $officialToi,
                    'html_toi' => $htmlToiSeconds,
                    'raw_shift_toi' => $rawShiftToi,
                    'summary_toi' => $summaryToi,
                    'html_toi_delta' => $htmlToiDelta,
                    'raw_toi_delta' => $rawToiDelta,
                    'summary_toi_delta' => $summaryToiDelta,
                    'official_shifts' => $officialShifts,
                    'html_shifts' => $htmlShifts,
                    'raw_shifts' => $rawShiftCount,
                    'summary_shifts' => $summaryShifts,
                    'html_shift_delta' => $htmlShiftDelta,
                    'raw_shift_delta' => $rawShiftDelta,
                    'summary_shift_delta' => $summaryShiftDelta,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $gap): int => max(
                abs($gap['html_toi_delta']),
                abs($gap['raw_toi_delta']),
                abs($gap['summary_toi_delta'])
            ) * 1000 + max(
                abs($gap['html_shift_delta']),
                abs($gap['raw_shift_delta']),
                abs($gap['summary_shift_delta'])
            ))
            ->values();

        $lines = [
            "Shift/Boxscore Gaps {$gameId}",
            '',
            'Rows: ' . $gaps->count(),
            '',
            implode("\t", [
                'player_id',
                'team_id',
                'name',
                'pos',
                'official_toi',
                'html_toi',
                'raw_shift_toi',
                'our_toi',
                'html_toi_delta',
                'raw_toi_delta',
                'our_toi_delta',
                'official_shifts',
                'html_shifts',
                'raw_shifts',
                'our_shifts',
                'html_shift_delta',
                'raw_shift_delta',
                'our_shift_delta',
            ]),
        ];

        foreach ($gaps as $gap) {
            $lines[] = implode("\t", [
                (string) $gap['player_id'],
                (string) $gap['team_id'],
                $gap['name'],
                $gap['position'],
                $this->secondsToClock($gap['official_toi']),
                $this->secondsToClock($gap['html_toi']),
                $this->secondsToClock($gap['raw_shift_toi']),
                $this->secondsToClock($gap['summary_toi']),
                $this->signedSeconds($gap['html_toi_delta']),
                $this->signedSeconds($gap['raw_toi_delta']),
                $this->signedSeconds($gap['summary_toi_delta']),
                (string) $gap['official_shifts'],
                (string) $gap['html_shifts'],
                (string) $gap['raw_shifts'],
                (string) $gap['summary_shifts'],
                (string) $gap['html_shift_delta'],
                (string) $gap['raw_shift_delta'],
                (string) $gap['summary_shift_delta'],
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function secondsToClock(mixed $seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return '';
        }

        $seconds = max(0, (int) $seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function clockToSeconds(string $clock): int
    {
        if (! preg_match('/^(\d{1,3}):(\d{2})$/', trim($clock), $matches)) {
            return 0;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function signedSeconds(int $seconds): string
    {
        $sign = $seconds > 0 ? '+' : ($seconds < 0 ? '-' : '');

        return $sign . $this->secondsToClock(abs($seconds));
    }

    private function troubleshootingDirectory(int $gameId): ?string
    {
        $root = (string) config('apiImportNhl.validation_troubleshooting_path');

        if ($root === '') {
            return null;
        }

        $directory = $root . '/' . $gameId;
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    private function writeRawPayloadIfMissing(string $directory, string $filename, string $payload): void
    {
        if (File::exists($directory . '/' . $filename)) {
            return;
        }

        File::put($directory . '/' . $filename, $payload);
    }

    /**
     * @param array<string,mixed> $mismatch
     * @return array<int,array<string,mixed>>
     */
    private function mismatchPlayerPayloads(array $mismatch): array
    {
        $playerIds = array_values(array_unique([
            ...$mismatch['missing_from_shiftcharts'],
            ...$mismatch['extra_in_shiftcharts'],
        ]));

        return collect($playerIds)
            ->map(fn (int $playerId): array => [
                'nhl_player_id' => $playerId,
                'classification' => in_array($playerId, $mismatch['missing_from_shiftcharts'], true)
                    ? 'missing_from_shiftcharts'
                    : 'extra_in_shiftcharts',
                'html_player_payloads' => collect($mismatch['html_player_payloads'])
                    ->where('nhl_player_id', $playerId)
                    ->values()
                    ->all(),
                'shiftchart_player_payloads_at_event' => collect($mismatch['shift_player_payloads'])
                    ->where('player_id', $playerId)
                    ->values()
                    ->all(),
                'shiftchart_shift_context' => $this->shiftContextForPlayer(
                    $playerId,
                    $mismatch['api']['seconds'],
                    $mismatch['api']['period'],
                    $mismatch['all_shifts'] ?? []
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $shifts
     * @return array<string,mixed>
     */
    private function shiftContextForPlayer(int $playerId, mixed $eventSecond, mixed $period, array $shifts): array
    {
        if ($eventSecond === null) {
            return [
                'previous_shift' => null,
                'active_shifts' => [],
                'next_shift' => null,
            ];
        }

        $playerShifts = collect($shifts)
            ->filter(fn (array $shift): bool => (int) $shift['player_id'] === $playerId && (int) $shift['period'] === (int) $period);

        return [
            'previous_shift' => $playerShifts
                ->filter(fn (array $shift): bool => (int) $shift['end'] <= (int) $eventSecond)
                ->sortByDesc('end')
                ->first(),
            'active_shifts' => $playerShifts
                ->filter(fn (array $shift): bool => (int) $shift['start'] <= (int) $eventSecond && (int) $shift['end'] > (int) $eventSecond)
                ->sortBy('start')
                ->values()
                ->all(),
            'next_shift' => $playerShifts
                ->filter(fn (array $shift): bool => (int) $shift['start'] > (int) $eventSecond)
                ->sortBy('start')
                ->first(),
        ];
    }

    /**
     * @param mixed $payload
     */
    private function jsonBlock(mixed $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? 'null' : $json;
    }
}
