<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use App\Repositories\NhlImportProgressRepo;
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\RequestException;


class NhlDiscoverGames
{
    use HasAPITrait;

    /** @var array<string> */
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events'];

    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /**
     * Discover and persist scheduled rows for a single calendar day (YYYY-MM-DD).
     */
    public function discoverDay(string $yyyy_mm_dd): void
    {
        try {
            $payload = $this->getAPIData('nhl', 'dailyscores', ['date' => $yyyy_mm_dd]);
        } catch (RequestException $e) {
            $status = $e->response?->status();

            if ($status === 404) {
                // No schedule for this date — treat as empty and exit.
                return;
            }

            if (in_array($status, [400, 401, 403, 422], true)) {
                \Log::warning('NHL dailyscores client error', [
                    'date' => $yyyy_mm_dd,
                    'status' => $status,
                    'body' => $e->response?->body(),
                ]);
                return;
            }

            // 5xx / network etc. → let the job retry/fail as configured
            throw $e;
        }

        $games = is_array($payload) ? ($payload['games'] ?? []) : [];
        if (empty($games)) {
            return;
        }

        // ... existing row-build + insert logic ...
    }
}
