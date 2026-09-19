<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminImportSchedule;
use App\Models\ImportRun;
use App\Services\AdminImports;
use Illuminate\Console\Command;
use Throwable;

class DispatchScheduledAdminImportsCommand extends Command
{
    protected $signature = 'admin:dispatch-scheduled-imports';

    protected $description = 'Queue enabled admin imports whose persisted schedule is due';

    public function handle(AdminImports $imports): int
    {
        $due = AdminImportSchedule::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_due_at')->orWhere('next_due_at', '<=', now()))
            ->orderBy('id')
            ->get();

        foreach ($due as $schedule) {
            if (ImportRun::query()->where('source', $schedule->source_key)->where('status', 'working')->exists()) {
                continue;
            }

            try {
            $imports->dispatchScheduled($schedule->source_key, $this->commandOptions($schedule));
                $schedule->update([
                    'last_dispatched_at' => now(),
                    'next_due_at' => now()->addSeconds($schedule->interval_seconds),
                ]);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function commandOptions(AdminImportSchedule $schedule): array
    {
        if ($schedule->source_key !== 'nhl-starting-goalies') {
            return [];
        }

        return $schedule->lane_key === 'future'
            ? ['--future-window' => true]
            : ['--date' => [today()->toDateString()]];
    }
}
