<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameSourceStatus;
use Illuminate\Support\Facades\Http;

/**
 * Discover official NHL HTML report URLs from the gamecenter right-rail payload.
 */
class NhlHtmlPbpReportLocator
{
    /**
     * Return the official HTML play-by-play report URL for a game, when present.
     */
    public function playByPlayUrl(int $gameId): ?string
    {
        return $this->reportUrls($gameId)['playByPlay'] ?? null;
    }

    /**
     * Return official HTML report URLs keyed by NHL right-rail report name.
     *
     * @return array<string,string>
     */
    public function reportUrls(int $gameId): array
    {
        $url = $this->rightRailUrl($gameId);

        try {
            $payload = Http::timeout(30)->acceptJson()->get($url)->throw()->json();
        } catch (\Throwable $throwable) {
            $this->storeSourceStatus(
                $gameId,
                NhlGameSourceStatus::SOURCE_RIGHT_RAIL,
                NhlGameSourceStatus::STATUS_UNAVAILABLE,
                $url,
                'right_rail_fetch_failed',
                ['message' => $throwable->getMessage()]
            );

            return [];
        }

        $reports = is_array($payload) ? ($payload['gameReports'] ?? []) : [];
        $reportUrls = collect(is_array($reports) ? $reports : [])
            ->filter(fn (mixed $reportUrl): bool => is_string($reportUrl) && $reportUrl !== '')
            ->map(fn (string $reportUrl): string => $reportUrl)
            ->all();
        $playByPlayUrl = $reportUrls['playByPlay'] ?? null;

        $this->storeSourceStatus(
            $gameId,
            NhlGameSourceStatus::SOURCE_RIGHT_RAIL,
            $playByPlayUrl ? NhlGameSourceStatus::STATUS_AVAILABLE : NhlGameSourceStatus::STATUS_EMPTY,
            $url,
            $playByPlayUrl ? null : 'missing_play_by_play_report',
            [
                'play_by_play_url' => $playByPlayUrl,
                'toi_away_url' => $reportUrls['toiAway'] ?? null,
                'toi_home_url' => $reportUrls['toiHome'] ?? null,
            ]
        );

        return $reportUrls;
    }

    /**
     * Build the gamecenter right-rail URL.
     */
    private function rightRailUrl(int $gameId): string
    {
        return "https://api-web.nhle.com/v1/gamecenter/{$gameId}/right-rail";
    }

    /**
     * Persist source availability for later audit.
     *
     * @param array<string,mixed> $details
     */
    private function storeSourceStatus(
        int $gameId,
        string $source,
        string $status,
        string $url,
        ?string $reason = null,
        array $details = []
    ): void {
        NhlGameSourceStatus::query()->updateOrCreate(
            [
                'nhl_game_id' => $gameId,
                'source' => $source,
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
}
