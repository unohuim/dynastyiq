<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\NhlGameSourceStatus;
use App\Models\NhlShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports fallback shift windows from NHL TV/TH HTML time-on-ice reports.
 */
class ImportNhlHtmlToiShifts
{
    private const SHIFT_TYPE_CODE = 517;

    public function __construct(private readonly NhlHtmlPbpReportLocator $locator)
    {
    }

    /**
     * Import TV/TH shift windows into the derived shift table.
     */
    public function import(int $gameId): int
    {
        $game = NhlGame::query()->where('nhl_game_id', $gameId)->first();

        if (! $game) {
            return 0;
        }

        $reportUrls = $this->locator->reportUrls($gameId);
        $awayUrl = $reportUrls['toiAway'] ?? null;
        $homeUrl = $reportUrls['toiHome'] ?? null;
        $rows = [
            ...$this->parseReport($this->fetchHtml($awayUrl), strtoupper((string) $game->away_team_abbrev), $gameId),
            ...$this->parseReport($this->fetchHtml($homeUrl), strtoupper((string) $game->home_team_abbrev), $gameId),
        ];

        if ($rows === []) {
        $this->storeStatus($gameId, NhlGameSourceStatus::STATUS_EMPTY, 'missing_or_empty_toi_reports', [
            'url' => $awayUrl ?? $homeUrl ?? 'unavailable',
            'toi_away_url' => $awayUrl,
            'toi_home_url' => $homeUrl,
            'imported_rows' => 0,
            ]);

            return 0;
        }

        NhlShift::query()->where('nhl_game_id', $gameId)->delete();

        foreach ($rows as $row) {
            NhlShift::query()->create($row);
        }

        $this->updateSummaryToi($game, $rows);
        $this->storeStatus($gameId, NhlGameSourceStatus::STATUS_AVAILABLE, 'tv_th_fallback', [
            'url' => $awayUrl ?? $homeUrl ?? 'unavailable',
            'toi_away_url' => $awayUrl,
            'toi_home_url' => $homeUrl,
            'imported_rows' => count($rows),
        ]);

        return count($rows);
    }

    private function fetchHtml(?string $url): string
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
     * @return array<int,array<string,mixed>>
     */
    private function parseReport(string $html, string $teamAbbrev, int $gameId): array
    {
        if ($html === '' || $teamAbbrev === '') {
            return [];
        }

        $teamIdsByAbbrev = DB::table('nhl_games')
            ->where('nhl_game_id', $gameId)
            ->first(['home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);

        if ($teamIdsByAbbrev === null) {
            return [];
        }

        $teamIds = [
            strtoupper((string) $teamIdsByAbbrev->home_team_abbrev) => (int) $teamIdsByAbbrev->home_team_id,
            strtoupper((string) $teamIdsByAbbrev->away_team_abbrev) => (int) $teamIdsByAbbrev->away_team_id,
        ];
        $teamId = $teamIds[$teamAbbrev] ?? null;

        if ($teamId === null) {
            return [];
        }

        $playerIdsBySweater = DB::table('nhl_boxscores')
            ->where('nhl_game_id', $gameId)
            ->where('nhl_team_id', $teamId)
            ->where('sweater_number', '>', 0)
            ->pluck('nhl_player_id', 'sweater_number')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $document = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $currentPlayer = null;
        $rows = [];
        $now = now();

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
                    'nhl_player_id' => $playerIdsBySweater[$sweater] ?? null,
                    'first_name' => trim($matches[3]),
                    'last_name' => trim($matches[2]),
                    'sweater_number' => $sweater,
                ];

                continue;
            }

            if ($currentPlayer === null || ! $this->isShiftCells($cells)) {
                continue;
            }

            $period = $this->period($cells[1]);
            $start = $this->gameSeconds($cells[1], $cells[2]);
            $end = $this->gameSeconds($cells[1], $cells[3]);

            if (($currentPlayer['nhl_player_id'] ?? null) === null || $period === null || $start === null || $end === null || $end <= $start) {
                continue;
            }

            $rows[] = [
                'nhl_game_id' => $gameId,
                'nhl_player_id' => (int) $currentPlayer['nhl_player_id'],
                'shift_number' => (int) $cells[0],
                'period' => $period,
                'start_time' => $this->elapsedClock($cells[2]),
                'end_time' => $this->elapsedClock($cells[3]),
                'duration' => $cells[4],
                'shift_start_seconds' => $start,
                'shift_end_seconds' => $end,
                'shift_duration_seconds' => $end - $start,
                'team_abbrev' => $teamAbbrev,
                'team_name' => $teamAbbrev,
                'first_name' => $currentPlayer['first_name'],
                'last_name' => $currentPlayer['last_name'],
                'event_number' => null,
                'type_code' => self::SHIFT_TYPE_CODE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,string> $cells
     */
    private function isShiftCells(array $cells): bool
    {
        return count($cells) >= 5
            && preg_match('/^\d+$/', $cells[0]) === 1
            && preg_match('/^(?:\d+|OT)$/', $cells[1]) === 1
            && str_contains($cells[2], '/')
            && str_contains($cells[3], '/')
            && preg_match('/^\d{1,3}:\d{2}$/', $cells[4]) === 1;
    }

    private function period(string $period): ?int
    {
        $period = strtoupper(trim($period));

        if ($period === 'OT') {
            return 4;
        }

        return ctype_digit($period) ? (int) $period : null;
    }

    private function elapsedClock(string $clockPair): string
    {
        $clock = trim(explode('/', $clockPair)[0] ?? '');

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            return $clock;
        }

        return sprintf('%02d:%s', (int) $matches[1], $matches[2]);
    }

    private function gameSeconds(string $period, string $clockPair): ?int
    {
        $periodNumber = $this->period($period);
        $clock = $this->elapsedClock($clockPair);

        if ($periodNumber === null || ! preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            return null;
        }

        $offset = $periodNumber <= 1
            ? 0
            : ($periodNumber <= 3 ? ($periodNumber - 1) * 1200 : 3600 + (($periodNumber - 4) * 300));

        return $offset + ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function updateSummaryToi(NhlGame $game, array $rows): void
    {
        foreach (collect($rows)->groupBy('nhl_player_id') as $playerId => $playerRows) {
            $teamAbbrev = (string) ($playerRows->first()['team_abbrev'] ?? '');
            $teamId = $game->getTeamIdByAbbrev($teamAbbrev);

            if ($teamId === null) {
                continue;
            }

            DB::table('nhl_game_summaries')->updateOrInsert(
                [
                    'nhl_game_id' => $game->nhl_game_id,
                    'nhl_player_id' => (string) $playerId,
                ],
                [
                    'nhl_team_id' => $teamId,
                    'toi' => $playerRows->sum('shift_duration_seconds'),
                    'shifts' => $playerRows->count(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $details
     */
    private function storeStatus(int $gameId, string $status, string $reason, array $details): void
    {
        NhlGameSourceStatus::query()->updateOrCreate(
            [
                'nhl_game_id' => $gameId,
                'source' => NhlGameSourceStatus::SOURCE_HTML_TOI,
            ],
            [
                'status' => $status,
                'reason' => $reason,
                'url' => (string) ($details['url'] ?? 'unavailable'),
                'details' => $details,
                'checked_at' => now(),
            ]
        );
    }
}
