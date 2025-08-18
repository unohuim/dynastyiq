<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;

/**
 * Thin service wrapper around CapWages "players" endpoint.
 * Responsible only for fetching pages (no dispatching).
 */
class ImportCapWages
{
    use HasAPITrait;

    /**
     * Fetch a single page of CapWages players.
     *
     * @param int $page
     * @param int $perPage
     * @return array<string,mixed> Raw API response (expects ['data'=>[], 'meta'=>['pagination'=>...]])
     */
    public function fetchPlayersPage(int $page, int $perPage = 100): array
    {
        return $this->getAPIData(
            'capwages',
            'players',
            [],
            ['page' => max(1, $page), 'limit' => max(1, $perPage)]
        );
    }
}
