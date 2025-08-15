<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use App\Repositories\NhlImportProgressRepo;
use App\Jobs\NhlDiscoverRangeJob;


class NhlDiscovery
{
    use HasAPITrait;

    /** @var array<string> */
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events'];

    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /** Full backfill: dispatch jobs per season in chunks up to max_weeks_discovery_job (Sep 1 → Aug 31). */
    public function init(): void
    {
        $min_season_id = config('apiImportNhl.min_season_id', '20192020');
        \Log::warning("min_season_id:  {$min_season_id}");
        [$start, $end] = $this->seasonWindowFromLowerBound((string) config('apiImportNhl.min_season_id', '20192020'));
        $this->dispatchSeasonChunkJobs($start, $end);
    }

    /** Nightly sync: discover recent games. */
    public function sync(int $daysBack = 14): void
    {
        $end   = Carbon::today()->endOfDay();
        $start = $end->copy()->subDays(max(0, $daysBack))->startOfDay();

        $seasonIds = $this->dispatchWindowChunkJobs($start, $end); // returns array of season_ids
    }

    /* -------------------- internals -------------------- */

    /**
     * Dispatch 1 job per chunk within each season; batch them.
     */
    private function dispatchSeasonChunkJobs(Carbon $globalStart, Carbon $globalEnd): void
    {
        $maxWeeks = (int) config('nhlimport.max_weeks_discovery_job', 13);
        $maxWeeks = max(1, $maxWeeks);

        $cursorStart = $globalStart->copy()->startOfDay();
        $globalEnd   = $globalEnd->copy()->endOfDay();

        while ($cursorStart->lte($globalEnd)) {
            $seasonStartYear = $cursorStart->month >= 9 ? $cursorStart->year : $cursorStart->year - 1;
            $seasonId = sprintf('%04d%04d', $seasonStartYear, $seasonStartYear + 1);

            $seasonStart = Carbon::create($seasonStartYear, 9, 1)->startOfDay();
            $seasonEnd   = Carbon::create($seasonStartYear + 1, 8, 31)->endOfDay();

            $rangeStart = $seasonStart->lt($cursorStart) ? $cursorStart->copy() : $seasonStart->copy();
            $rangeEnd   = $seasonEnd->gt($globalEnd) ? $globalEnd->copy() : $seasonEnd->copy();

            $jobs = [];
            $chunkStart = $rangeStart->copy();

            while ($chunkStart->lte($rangeEnd)) {
                $chunkEnd = $chunkStart->copy()->addWeeks($maxWeeks)->subSecond();
                if ($chunkEnd->gt($rangeEnd)) {
                    $chunkEnd = $rangeEnd->copy();
                }

                $rows = $this->collectChunkRows($chunkStart, $chunkEnd);
                if (!empty($rows)) {
                    $jobs[] = new NhlDiscoverRangeJob($chunkStart->copy(), $chunkEnd->copy(), $rows);
                }

                $chunkStart = $chunkStart->copy()->addWeeks($maxWeeks);
            }

            if ($jobs) {
                Bus::batch($jobs)
                    ->name("nhl-discovery:{$seasonId}")                    
                    ->dispatch();
            }

            $cursorStart = $seasonEnd->copy()->addSecond();
        }
    }

    /**
     * Dispatch chunked jobs over an arbitrary window and return touched season_ids.
     *
     * @return array<int,string>
     */
    private function dispatchWindowChunkJobs(Carbon $from, Carbon $to): array
    {
        $maxWeeks = (int) config('nhlimport.max_weeks_discovery_job', 13);
        $maxWeeks = max(1, $maxWeeks);

        $touched = [];
        $chunkStart = $from->copy();

        while ($chunkStart->lte($to)) {
            $chunkEnd = $chunkStart->copy()->addWeeks($maxWeeks)->subSecond();
            if ($chunkEnd->gt($to)) {
                $chunkEnd = $to->copy();
            }

            $rows = $this->collectChunkRows($chunkStart, $chunkEnd);
            if (!empty($rows)) {
                NhlDiscoverRangeJob::dispatch($chunkStart->copy(), $chunkEnd->copy(), $rows);
                foreach ($rows as $r) {
                    $touched[$r['season_id']] = true;
                }
            }

            $chunkStart = $chunkStart->copy()->addWeeks($maxWeeks);
        }

        return array_keys($touched);
    }

    /**
     * Build all scheduled rows for every game between $from and $to (inclusive).
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectChunkRows(Carbon $from, Carbon $to): array
    {
        $rows   = [];
        $now    = now();
        $cursor = $to->copy()->startOfDay();
        $stop   = $from->copy()->startOfDay();

        while ($cursor->gte($stop)) {
            $payload = $this->getAPIData('nhl', 'dailyscores', ['date' => $cursor->toDateString()]);
            $games   = is_array($payload) ? ($payload['games'] ?? []) : [];

            foreach ($games as $g) {
                $gameId   = (string) ($g['id'] ?? '');
                $seasonId = (string) ($g['season'] ?? '');
                if ($gameId === '' || $seasonId === '') {
                    continue;
                }

                $gameDate = (string) ($g['gameDate'] ?? $cursor->toDateString());
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

            $cursor->subDay();
        }

        return $rows;
    }

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
        $payload = $this->getAPIData('nhl', 'dailyscores', ['date' => $yyyy_mm_dd]);
        $games   = is_array($payload) ? ($payload['games'] ?? []) : [];
        if (empty($games)) {
            return;
        }

        $now  = now();
        $rows = [];

        foreach ($games as $g) {
            $gameId    = (string) ($g['id'] ?? '');
            $seasonId  = (string) ($g['season'] ?? '');
            $gameDate  = (string) ($g['gameDate'] ?? $yyyy_mm_dd);
            $gameType  = (int) ($g['gameType'] ?? 0);

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
            $this->repo->insertScheduledRows($rows);
        }
    }

    private function seasonWindowFromLowerBound(string $minSeasonId): array
    {
        [$minStart, ] = $this->seasonDatesSepToAug($minSeasonId);
        return [$minStart, Carbon::today()];
    }

    /** Season bounds: Sep 1 (startYear) → Aug 31 (startYear+1). */
    private function seasonDatesSepToAug(string $seasonId): array
    {
        $startYear = (int) substr($seasonId, 0, 4);
        $start = Carbon::create($startYear, 9, 1)->startOfDay();
        $end   = Carbon::create($startYear + 1, 8, 31)->endOfDay();
        return [$start, $end];
    }
}
