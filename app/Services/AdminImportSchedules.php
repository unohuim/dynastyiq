<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminImportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Manages persisted admin-import cadence settings and dispatch eligibility. */
class AdminImportSchedules
{
    public const ANTICIPATED_LINEUPS = 'nhl-anticipated-lineups';
    public const GAME_BOXSCORES = 'nhl-game-boxscores';

    public const DEFINITIONS = [
        'nhl-starting-goalies' => ['today' => 900, 'future' => 3600],
        'nhl-injuries' => ['current' => 900],
        self::ANTICIPATED_LINEUPS => ['within_two_hours' => 900, 'outside_two_hours' => 3600],
        self::GAME_BOXSCORES => ['today' => 60],
    ];

    /**
     * Return or initialize every schedule lane for an import source.
     *
     * @return Collection<int, AdminImportSchedule>
     */
    public function forSource(string $sourceKey): Collection
    {
        $definitions = self::DEFINITIONS[$sourceKey] ?? null;
        abort_unless($definitions, 404);

        return collect($definitions)->map(function (int $seconds, string $lane) use ($sourceKey): AdminImportSchedule {
            return AdminImportSchedule::query()->firstOrCreate(
                ['source_key' => $sourceKey, 'lane_key' => $lane],
                [
                    'enabled' => false,
                    'lane_enabled' => true,
                    'interval_seconds' => $seconds,
                    'recurrence_mode' => 'recurring',
                    'daily_start_time' => $sourceKey === self::ANTICIPATED_LINEUPS ? '00:00:00' : null,
                    'timezone' => $sourceKey === self::ANTICIPATED_LINEUPS ? config('app.timezone', 'UTC') : null,
                ]
            );
        })->values();
    }

    /**
     * Build the admin-facing schedule payload for an import source.
     *
     * @return array<string,mixed>
     */
    public function payload(string $sourceKey): array
    {
        $rows = $this->forSource($sourceKey);

        $payload = [
            'enabled' => $rows->every(fn (AdminImportSchedule $row): bool => $row->enabled),
            'lanes' => $rows->mapWithKeys(fn (AdminImportSchedule $row): array => [$row->lane_key => [
                'interval_seconds' => $row->interval_seconds,
                'last_dispatched_at' => $row->last_dispatched_at?->toIso8601String(),
                'next_due_at' => $row->next_due_at?->toIso8601String(),
            ]])->all(),
        ];

        if ($sourceKey === self::ANTICIPATED_LINEUPS) {
            $outside = $rows->firstWhere('lane_key', 'outside_two_hours');
            $within = $rows->firstWhere('lane_key', 'within_two_hours');
            $payload['timing'] = [
                'daily_start_time' => substr((string) ($outside?->daily_start_time ?: '00:00:00'), 0, 5),
                'timezone' => $outside?->timezone ?: config('app.timezone', 'UTC'),
                'outside_mode' => $outside?->recurrence_mode === 'once' ? 'once' : 'recurring',
                'within_two_hours_enabled' => (bool) ($within?->lane_enabled ?? true),
            ];
        }

        return $payload;
    }

    /**
     * Persist global, lane interval, and anticipated-lineup timing settings.
     *
     * @param array<string,int> $intervals
     * @param array<string,mixed> $timing
     * @return array<string,mixed>
     */
    public function update(string $sourceKey, bool $enabled, array $intervals, array $timing = []): array
    {
        $rows = $this->forSource($sourceKey);
        foreach ($rows as $row) {
            $seconds = $intervals[$row->lane_key] ?? $row->interval_seconds;
            $attributes = [
                'enabled' => $enabled,
                'interval_seconds' => $seconds,
                'next_due_at' => $enabled ? ($row->next_due_at ?? now()) : null,
            ];

            if ($sourceKey === self::ANTICIPATED_LINEUPS) {
                $dailyStart = $timing['daily_start_time']
                    ?? substr((string) ($row->daily_start_time ?: '00:00:00'), 0, 5);
                $attributes['daily_start_time'] = $dailyStart . ':00';
                $attributes['timezone'] = $timing['timezone']
                    ?? $row->timezone
                    ?? config('app.timezone', 'UTC');
                $attributes['lane_enabled'] = $row->lane_key !== 'within_two_hours'
                    || (bool) ($timing['within_two_hours_enabled'] ?? $row->lane_enabled ?? true);
                $attributes['recurrence_mode'] = $row->lane_key === 'outside_two_hours'
                    ? ($timing['outside_mode'] ?? $row->recurrence_mode ?? 'recurring')
                    : 'recurring';
                if (! $attributes['lane_enabled']) {
                    $attributes['next_due_at'] = null;
                } elseif ($enabled) {
                    $attributes['next_due_at'] = $this->initialLineupDueAt(
                        $row,
                        $attributes,
                        CarbonImmutable::now()
                    );
                }
            }

            $row->update($attributes);
        }

        return $this->payload($sourceKey);
    }

    /** Determine whether a due schedule has an eligible import window. */
    public function shouldDispatch(AdminImportSchedule $schedule, CarbonImmutable $now): bool
    {
        if ($schedule->source_key === self::GAME_BOXSCORES) {
            return DB::table('nhl_games')
                ->whereDate('game_date', $now->utc()->toDateString())
                ->where(fn ($query) => $query->whereNull('game_state')->orWhere('game_state', '<>', 'FINAL'))
                ->exists();
        }

        if ($schedule->source_key !== self::ANTICIPATED_LINEUPS) {
            return true;
        }

        if (! $schedule->lane_enabled) {
            return false;
        }

        $timezone = $schedule->timezone ?: config('app.timezone', 'UTC');
        $localNow = $now->setTimezone($timezone);
        $anchor = CarbonImmutable::parse(
            $localNow->toDateString() . ' ' . ($schedule->daily_start_time ?: '00:00:00'),
            $timezone
        );
        if ($localNow->lessThan($anchor)) {
            return false;
        }

        $lastDispatchedAt = $schedule->last_dispatched_at?->setTimezone($timezone);
        if ($schedule->lane_key === 'outside_two_hours'
            && $schedule->recurrence_mode === 'once'
            && $lastDispatchedAt?->isSameDay($localNow)) {
            return false;
        }

        $query = DB::table('nhl_games')
            ->whereNotNull('start_time_utc');

        if ($schedule->lane_key === 'within_two_hours') {
            $query->whereDate('game_date', $localNow->toDateString())
                ->where('start_time_utc', '<=', $now->addHours(2));
        } else {
            $query->whereBetween('game_date', [
                $localNow->toDateString(),
                $localNow->addDay()->toDateString(),
            ])->where('start_time_utc', '>', $now->addHours(2));
        }

        return $query->exists();
    }

    /** Persist the dispatch timestamp and calculate the lane's next due time. */
    public function markDispatched(AdminImportSchedule $schedule, CarbonImmutable $now): void
    {
        if ($schedule->source_key !== self::ANTICIPATED_LINEUPS) {
            $schedule->update([
                'last_dispatched_at' => $now,
                'next_due_at' => $now->addSeconds($schedule->interval_seconds),
            ]);

            return;
        }

        $timezone = $schedule->timezone ?: config('app.timezone', 'UTC');
        $localNow = $now->setTimezone($timezone);
        $nextDue = $localNow->addSeconds($schedule->interval_seconds);

        if ($schedule->lane_key === 'outside_two_hours') {
            $anchor = CarbonImmutable::parse(
                $localNow->toDateString() . ' ' . ($schedule->daily_start_time ?: '00:00:00'),
                $timezone
            );
            if ($schedule->recurrence_mode === 'once') {
                $nextDue = $anchor->addDay();
            } else {
                $elapsed = (int) max(0, $anchor->diffInSeconds($localNow, false));
                $slot = intdiv($elapsed, $schedule->interval_seconds) + 1;
                $nextDue = $anchor->addSeconds($slot * $schedule->interval_seconds);
                if (! $nextDue->isSameDay($localNow)) {
                    $nextDue = $anchor->addDay();
                }
            }
        }

        $schedule->update([
            'last_dispatched_at' => $now,
            'next_due_at' => $nextDue->utc(),
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function initialLineupDueAt(
        AdminImportSchedule $schedule,
        array $attributes,
        CarbonImmutable $now
    ): CarbonImmutable {
        if ($schedule->lane_key === 'within_two_hours') {
            return $now;
        }

        $timezone = (string) $attributes['timezone'];
        $localNow = $now->setTimezone($timezone);
        $anchor = CarbonImmutable::parse(
            $localNow->toDateString() . ' ' . $attributes['daily_start_time'],
            $timezone
        );
        if ($localNow->lessThanOrEqualTo($anchor)) {
            return $anchor->utc();
        }

        if ($attributes['recurrence_mode'] === 'once') {
            $lastDispatchedAt = $schedule->last_dispatched_at?->setTimezone($timezone);

            return $lastDispatchedAt?->isSameDay($localNow) ? $anchor->addDay()->utc() : $now;
        }

        $elapsed = (int) max(0, $anchor->diffInSeconds($localNow, false));
        $slot = intdiv($elapsed, (int) $attributes['interval_seconds']) + 1;
        $nextDue = $anchor->addSeconds($slot * (int) $attributes['interval_seconds']);

        return ($nextDue->isSameDay($localNow) ? $nextDue : $anchor->addDay())->utc();
    }
}
