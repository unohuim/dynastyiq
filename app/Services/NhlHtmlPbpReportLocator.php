<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Discover official NHL HTML report URLs from the gamecenter right-rail payload.
 */
class NhlHtmlPbpReportLocator
{
    public function __construct(private readonly NhlRightRailService $rightRail)
    {
    }

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
        $payload = $this->rightRail->payload($gameId);

        if ($payload === null) {
            return [];
        }

        return $this->rightRail->reportUrlsFromPayload($payload);
    }
}
