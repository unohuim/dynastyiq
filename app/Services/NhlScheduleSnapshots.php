<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlScheduleSnapshotsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Publish durable schedule views; public reads never aggregate source games. */
class NhlScheduleSnapshots
{
    /** Resolve the September–August NHL season for an Eastern calendar date. */
    public function seasonId(CarbonImmutable $date): string
    {
        $year = $date->month >= 9 ? $date->year : $date->year - 1;
        return (string) $year.($year + 1);
    }

    /** Read only prepared rows; queue missing data without doing builder work inline. */
    public function read(CarbonImmutable $start): array
    {
        $today = CarbonImmutable::now('America/Toronto')->startOfDay();
        $season = $this->seasonId($today);
        $weekKey = 'week:'.$start->toDateString();
        $offKey = 'off:'.$season.':'.$today->toDateString();
        $rows = DB::table('nhl_schedule_snapshots')->whereIn('snapshot_key', [$weekKey, $offKey])->get()->keyBy('snapshot_key');
        $week = $rows->get($weekKey);
        $off = $rows->get($offKey);
        $weekSeason = $this->seasonId($start);
        $hasSeason = $week !== null || $weekSeason === $season || DB::table('nhl_games')->where('season_id', $weekSeason)->exists();
        $prepared = ($week !== null || ! $hasSeason) && $off !== null;
        if ($week === null && $hasSeason) {
            $this->queueSeason($weekSeason);
        }
        if ($off === null) {
            $this->queueSeason($season);
            $off = DB::table('nhl_schedule_snapshots')->where('season_id', $season)
                ->where('snapshot_key', 'like', 'off:'.$season.':%')->where('snapshot_key', '<=', $offKey)->orderByDesc('snapshot_key')->first();
        }
        $end = $start->addDays(6);
        $payload = $week ? json_decode($week->payload, true, 512, JSON_THROW_ON_ERROR) : [
            'week_start' => $start->toDateString(), 'week_end' => $end->toDateString(),
            'label' => $start->format('M j').' – '.$end->format('M j, Y'), 'timezone' => 'America/Toronto',
            'teams' => [], 'game_count' => 0,
            'days' => collect(range(0, 6))->map(fn (int $offset): array => [
                'date' => $start->addDays($offset)->toDateString(), 'label' => $start->addDays($offset)->format('D'),
                'display_date' => $start->addDays($offset)->format('M j'), 'is_off_night' => false,
            ])->all(),
        ];
        $payload['off_nights'] = $off ? json_decode($off->payload, true, 512, JSON_THROW_ON_ERROR) : [
            'season_id' => $season, 'from_date' => $today->toDateString(),
            'label' => 'Remaining '.substr($season, 0, 4).'–'.substr($season, 6, 2).' season · From '.$today->format('M j, Y'),
            'teams' => [], 'game_count' => 0,
        ];
        $payload['off_nights']['built_at'] = $off?->built_at;
        $payload['is_current_week'] = $start->isSameDay($today->startOfWeek(CarbonImmutable::MONDAY));
        foreach ($payload['days'] as &$day) {
            $day['is_today'] = $day['date'] === $today->toDateString();
        }
        unset($day);
        $payload['previous_url'] = route('tools.schedule', ['week' => $start->subWeek()->toDateString()]);
        $payload['next_url'] = route('tools.schedule', ['week' => $start->addWeek()->toDateString()]);
        $payload['current_url'] = route('tools.schedule');
        $payload['prepared'] = $prepared;
        $payload['built_at'] = $week?->built_at;
        return $payload;
    }

    /** Build all calendar weeks for one season and atomically publish only after every calculation succeeds. */
    public function build(string $season, ?callable $progress = null): int
    {
        if (! preg_match('/^(\d{4})(\d{4})$/', $season, $match) || (int) $match[2] !== (int) $match[1] + 1) {
            throw new \InvalidArgumentException('Use an eight-digit consecutive NHL season.');
        }
        return Cache::store('database')->lock('nhl-schedule-snapshots:publish', 180)->block(10, function () use ($season, $progress): int {
            $today = CarbonImmutable::now('America/Toronto')->startOfDay();
            $calculator = app(NhlScheduleCalculator::class);
            $start = CarbonImmutable::create((int) substr($season, 0, 4), 9, 1, 0, 0, 0, 'America/Toronto')->startOfWeek(CarbonImmutable::MONDAY);
            $end = CarbonImmutable::create((int) substr($season, 4, 4), 8, 31, 0, 0, 0, 'America/Toronto')->startOfWeek(CarbonImmutable::MONDAY);
            $rows = [];
            $built = now()->toIso8601String();
            for ($date = $start; $date->lte($end); $date = $date->addWeek()) {
                $payload = $calculator->week($date, $today);
                unset($payload['off_nights']);
                foreach ($payload['teams'] as &$team) {
                    unset($team['logo']);
                }
                unset($team);
                $rows[] = ['snapshot_key' => 'week:'.$date->toDateString(), 'season_id' => $this->seasonId($date),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'built_at' => $built];
                if ($progress !== null) {
                    $progress();
                }
            }
            if ($season === $this->seasonId($today)) {
                foreach ([$today, $today->addDay()] as $asOf) {
                    if ($this->seasonId($asOf) !== $season) {
                        continue;
                    }
                    $off = $calculator->week($asOf->startOfWeek(CarbonImmutable::MONDAY), $asOf, true)['off_nights'];
                    foreach ($off['teams'] as &$team) {
                        unset($team['logo']);
                    }
                    unset($team);
                    $rows[] = ['snapshot_key' => 'off:'.$season.':'.$asOf->toDateString(), 'season_id' => $season,
                        'payload' => json_encode($off, JSON_THROW_ON_ERROR), 'built_at' => $built];
                }
            }
            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 10) as $chunk) {
                    DB::table('nhl_schedule_snapshots')->upsert($chunk, ['snapshot_key'], ['season_id', 'payload', 'built_at']);
                }
            }, 3);
            return count($rows);
        });
    }

    /** Preparation outages must not fail a source import or discard a published view. */
    public function queueSeason(string $season): void
    {
        try {
            BuildNhlScheduleSnapshotsJob::dispatch($season)->afterCommit();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Queue relevant seasons after schedule writes, including cross-season boundary weeks. */
    public function changedDate(string $date): void
    {
        $day = CarbonImmutable::parse($date, 'America/Toronto');
        $seasons = array_unique([$this->seasonId($day), $this->seasonId($day->startOfWeek()), $this->seasonId($day->endOfWeek())]);
        foreach ($seasons as $season) {
            $this->queueSeason($season);
        }
    }
}
