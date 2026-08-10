<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameSourceStatus;
use App\Traits\HasAPITrait;

/**
 * Fetches NHL gamecenter right-rail payloads and records non-blocking source status.
 */
class NhlRightRailService
{
    use HasAPITrait;

    /**
     * Fetch the right-rail payload for one NHL game.
     *
     * @return array<string,mixed>|null
     */
    public function payload(int $gameId): ?array
    {
        $url = $this->getApiUrl('nhl', 'right_rail', ['gameId' => $gameId]);

        try {
            $payload = $this->getAPIData('nhl', 'right_rail', ['gameId' => $gameId]);
        } catch (\Throwable $throwable) {
            $this->storeSourceStatus(
                $gameId,
                NhlGameSourceStatus::STATUS_UNAVAILABLE,
                $url,
                'right_rail_fetch_failed',
                ['message' => $throwable->getMessage()]
            );

            return null;
        }

        if (! is_array($payload)) {
            $this->storeSourceStatus(
                $gameId,
                NhlGameSourceStatus::STATUS_EMPTY,
                $url,
                'right_rail_invalid_payload',
                []
            );

            return null;
        }

        $details = $this->sourceDetails($payload);
        $hasPayload = ($details['report_count'] ?? 0) > 0 || (bool) ($details['has_game_info'] ?? false);

        $this->storeSourceStatus(
            $gameId,
            $hasPayload ? NhlGameSourceStatus::STATUS_AVAILABLE : NhlGameSourceStatus::STATUS_EMPTY,
            $url,
            $hasPayload ? null : 'right_rail_missing_context',
            $details
        );

        return $payload;
    }

    /**
     * Return official HTML report URLs from a right-rail payload.
     *
     * @return array<string,string>
     */
    public function reportUrlsFromPayload(array $payload): array
    {
        $reports = $payload['gameReports'] ?? [];

        return collect(is_array($reports) ? $reports : [])
            ->filter(fn (mixed $reportUrl): bool => is_string($reportUrl) && $reportUrl !== '')
            ->map(fn (string $reportUrl): string => $reportUrl)
            ->all();
    }

    /**
     * Build compact source details for status/debug display.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sourceDetails(array $payload): array
    {
        $reportUrls = $this->reportUrlsFromPayload($payload);
        $gameInfo = $payload['gameInfo'] ?? [];
        $gameInfo = is_array($gameInfo) ? $gameInfo : [];

        return [
            'report_count' => count($reportUrls),
            'play_by_play_url' => $reportUrls['playByPlay'] ?? null,
            'toi_away_url' => $reportUrls['toiAway'] ?? null,
            'toi_home_url' => $reportUrls['toiHome'] ?? null,
            'has_game_info' => $gameInfo !== [],
            'referee_count' => count(is_array($gameInfo['referees'] ?? null) ? $gameInfo['referees'] : []),
            'linesman_count' => count(is_array($gameInfo['linesmen'] ?? null) ? $gameInfo['linesmen'] : []),
            'away_head_coach' => $gameInfo['awayTeam']['headCoach']['default'] ?? null,
            'home_head_coach' => $gameInfo['homeTeam']['headCoach']['default'] ?? null,
        ];
    }

    /**
     * Persist source availability for later audit.
     *
     * @param array<string,mixed> $details
     */
    private function storeSourceStatus(
        int $gameId,
        string $status,
        string $url,
        ?string $reason = null,
        array $details = []
    ): void {
        NhlGameSourceStatus::query()->updateOrCreate(
            [
                'nhl_game_id' => $gameId,
                'source' => NhlGameSourceStatus::SOURCE_RIGHT_RAIL,
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
