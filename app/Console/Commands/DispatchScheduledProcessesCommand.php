<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScheduledProcessManager;
use Illuminate\Console\Command;

/** Lightweight scheduler entrypoint for persisted process settings. */
class DispatchScheduledProcessesCommand extends Command
{
    protected $signature = 'processes:dispatch-due';
    protected $description = 'Dispatch enabled scheduled processes whose settings are due';

    /** Delegate due-work decisions to the central manager. */
    public function handle(ScheduledProcessManager $manager): int
    {
        $manager->tick();

        return self::SUCCESS;
    }
}
