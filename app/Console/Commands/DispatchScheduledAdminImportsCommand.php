<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminImportSchedule;
use App\Models\ImportRun;
use App\Services\AdminImports;
use App\Services\AdminImportSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class DispatchScheduledAdminImportsCommand extends Command
{
    protected $signature = 'admin:dispatch-scheduled-imports';

    protected $description = 'Queue enabled admin imports whose persisted schedule is due';

    public function handle(AdminImports $imports, AdminImportSchedules $schedules): int
    {
        $now = CarbonImmutable::now();
        $due = AdminImportSchedule::query()
            ->where('enabled', true)
            ->where('lane_enabled', true)
            ->where(fn ($query) => $query->whereNull('next_due_at')->orWhere('next_due_at', '<=', now()))
            ->orderBy('id')
            ->get();

        foreach ($due as $schedule) {
            if (! $schedules->shouldDispatch($schedule, $now)) {
                continue;
            }

            if (ImportRun::query()->where('source', $schedule->source_key)->where('status', 'working')->exists()) {
                continue;
            }

            try {
                $imports->dispatchScheduled($schedule->source_key, $this->commandOptions($schedule));
                $schedules->markDispatched($schedule, $now);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function commandOptions(AdminImportSchedule $schedule): array
    {
        if ($schedule->source_key === 'nhl-anticipated-lineups') {
            return ['--window' => $schedule->lane_key === 'within_two_hours'
                ? 'within-two-hours'
                : 'outside-two-hours'];
        }

        if ($schedule->source_key !== 'nhl-starting-goalies') {
            return [];
        }

        return $schedule->lane_key === 'future'
            ? ['--future-window' => true]
            : ['--date' => [today()->toDateString()]];
    }
}
