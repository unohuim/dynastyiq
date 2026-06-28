<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;

/**
 * Imports NHL team reference data from the NHL stats API.
 */
class ImportNhlTeams
{
    use HasAPITrait;

    public function __construct(
        private readonly NhlTeamReference $teams,
    ) {
    }

    /**
     * Sync NHL team reference rows.
     */
    public function sync(): int
    {
        $payload = $this->getAPIData('nhl_stats', 'teams');
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->teams->upsertFromStatsPayload($row) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
