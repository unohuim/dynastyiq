<?php

declare(strict_types=1);

namespace App\Classes;

use App\Services\ImportNHLPlayer as ImportNHLPlayerService;

/**
 * Backward-compatible wrapper for the NHL player import service.
 */
class ImportNHLPlayer
{
    /**
     * Import a player from the NHL API and persist their data.
     *
     * @param string $playerId NHL.com player ID
     * @param bool $isProspect Whether this player is a prospect
     */
    public function import(string $playerId, bool $isProspect = false): \App\Models\Player
    {
        return app(ImportNHLPlayerService::class)->import($playerId, $isProspect);
    }

    /**
     * Check if a player already exists by NHL player ID.
     *
     * @param int|string $nhlPlayerId
     */
    public function playerExists(int|string $nhlPlayerId): bool
    {
        return ImportNHLPlayerService::playerExists($nhlPlayerId);
    }
}
