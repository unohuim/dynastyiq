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
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events', 'sum-game-units'];

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
                return; // no schedule for this date
            }

            if (in_array($status, [400, 401, 403, 422], true)) {
                \Log::warning('NHL dailyscores client error', [
                    'date'   => $yyyy_mm_dd,
                    'status' => $status,
                    'body'   => $e->response?->body(),
                ]);
                return; // don’t retry
            }

            throw $e; // transient/server errors → let the job retry/fail
        }

        $games = is_array($payload) ? ($payload['games'] ?? []) : [];
        if (empty($games)) {
            return;
        }

        $now  = now();
        $rows = [];

        foreach ($games as $g) {
            $gameId   = (string) ($g['id'] ?? '');
            $seasonId = (string) ($g['season'] ?? '');
            if ($gameId === '' || $seasonId === '') {
                continue;
            }

            $gameDate = (string) ($g['gameDate'] ?? $yyyy_mm_dd);
            $dateOnly = Carbon::parse($gameDate)->toDateString();
            $gameType = (int) ($g['gameType'] ?? 0);

            foreach ($this->importTypes as $type) {
                $rows[] = [
                    'season_id'     => $seasonId,
                    'game_date'     => $dateOnly,
                    'game_id'       => $gameId,
                    'game_type'     => $gameType,
                    'import_type'   => $type,
                    'items_count'   => 0,
                    'status'        => 'scheduled',
                    'discovered_at' => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        if (!empty($rows)) {
            $this->repo->insertScheduledRows($rows);
        }
    }
}
