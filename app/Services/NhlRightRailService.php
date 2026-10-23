<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameSourceStatus;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

        $this->recordPayload($gameId, $url, $payload);

        return $payload;
    }

    /**
     * Fetch missing Teams inputs in bounded parallel groups without importing refs or player rows.
     *
     * @param array<int,int> $gameIds
     * @return iterable<int,array<string,mixed>|null>
     */
    public function payloads(array $gameIds): iterable
    {
        $deadline = now()->addSeconds(90);
        foreach (array_chunk(array_values(array_unique($gameIds)), 8) as $chunk) {
            // Keep slow providers inside the existing preparation request/job budget.
            if (now()->greaterThanOrEqualTo($deadline)) {
                return;
            }
            $responses = Http::pool(function (Pool $pool) use ($chunk): array {
                $requests = [];
                foreach ($chunk as $gameId) {
                    $requests[] = $pool->as((string) $gameId)->acceptJson()->connectTimeout(3)->timeout(10)
                        ->get($this->getApiUrl('nhl', 'right_rail', ['gameId' => $gameId]));
                }
                return $requests;
            });
            foreach ($chunk as $gameId) {
                $url = $this->getApiUrl('nhl', 'right_rail', ['gameId' => $gameId]);
                $response = $responses[(string) $gameId] ?? null;
                if (! $response instanceof Response || ! $response->successful()) {
                    $this->storeSourceStatus($gameId, NhlGameSourceStatus::STATUS_UNAVAILABLE, $url,
                        'right_rail_fetch_failed', ['http_status' => $response instanceof Response ? $response->status() : null]);
                    yield $gameId => null;
                    continue;
                }
                $payload = $response->json();
                if (! is_array($payload)) {
                    $this->storeSourceStatus($gameId, NhlGameSourceStatus::STATUS_EMPTY, $url, 'right_rail_invalid_payload');
                    yield $gameId => null;
                    continue;
                }
                $this->recordPayload($gameId, $url, $payload);
                yield $gameId => $payload;
            }
        }
    }

    /** Record the same source audit for single-game imports and pooled Teams preparation. */
    private function recordPayload(int $gameId, string $url, array $payload): void
    {
        $details = $this->sourceDetails($payload);
        $hasPayload = ($details['report_count'] ?? 0) > 0 || (bool) ($details['has_game_info'] ?? false);

        $this->storeSourceStatus(
            $gameId,
            $hasPayload ? NhlGameSourceStatus::STATUS_AVAILABLE : NhlGameSourceStatus::STATUS_EMPTY,
            $url,
            $hasPayload ? null : 'right_rail_missing_context',
            $details
        );
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
