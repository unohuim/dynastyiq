<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrackGamesImportProgressNhl
{
    use HasAPITrait;

    /** @var array<string> */
    private array $importTypes = ['pbp', 'shifts', 'boxscore', 'summary'];

    /** Full backfill from config('nhlimport.min_season_id') up to today (iterate from most recent backwards). */
    public function init(): void
    {
        [$start, $end] = $this->seasonWindowFromLowerBound((string) config('nhlimport.min_season_id', '20092010'));
        $this->discoverRangeBackward($start, $end);
    }

    /** Rolling sync window (default 14 days back → today), iterating from most recent backwards. */
    public function sync(int $daysBack = 14): void
    {
        $end   = Carbon::today();
        $start = $end->copy()->subDays(max(0, $daysBack));
        $this->discoverRangeBackward($start, $end);
    }

    /* -------------------- internals -------------------- */

    private function discoverRangeBackward(Carbon $startDate, Carbon $endDate): void
    {
        $cursor = $endDate->copy()->startOfDay();
        $stop   = $startDate->copy()->startOfDay();

        while ($cursor->gte($stop)) {
            $this->discoverDay($cursor->toDateString());
            $cursor->subDay();
        }
    }

    private function discoverDay(string $yyyy_mm_dd): void
    {
        // Expects config('apiurls.nhl.endpoints.dailyscores') with {date}
        $payload = $this->getAPIData('nhl', 'dailyscores', ['date' => $yyyy_mm_dd]);
        $games   = is_array($payload) ? ($payload['games'] ?? []) : [];

        if (empty($games)) {
            return;
        }

        $now  = now();
        $rows = [];

        foreach ($games as $g) {
            $gameId    = (string)($g['id'] ?? '');
            $seasonId  = (string)($g['season'] ?? '');
            $gameDate  = (string)($g['gameDate'] ?? $yyyy_mm_dd);
            $gameType  = (int)($g['gameType'] ?? 0); // 1=pre, 2=reg, 3=playoffs, etc.

            if ($gameId === '' || $seasonId === '') {
                continue;
            }

            $dateOnly = Carbon::parse($gameDate)->toDateString();

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

        if ($rows) {
            DB::table('nhl_import_progress')->insertOrIgnore($rows);
        }
    }

    /** Build date window from lower-bound season_id (YYYYYYYY) to today. */
    private function seasonWindowFromLowerBound(string $minSeasonId): array
    {
        [$minStart, ] = $this->seasonDatesSepToAug($minSeasonId);
        return [$minStart, Carbon::today()];
    }

    /** Season bounds: Sep 1 (startYear) → Aug 31 (startYear+1). */
    private function seasonDatesSepToAug(string $seasonId): array
    {
        $startYear = (int) substr($seasonId, 0, 4);
        $start = Carbon::create($startYear, 9, 1)->startOfDay();   // Sep 1
        $end   = Carbon::create($startYear + 1, 8, 31)->endOfDay(); // Aug 31
        return [$start, $end];
    }
}
