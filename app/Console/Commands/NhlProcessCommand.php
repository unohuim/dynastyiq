<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\NhlOrchestratorJob;
use App\Models\NhlGameImportRun;
use App\Services\NhlImportOrchestrator;
use Illuminate\Console\Command;

class NhlProcessCommand extends Command
{
    protected $signature = 'nhl:process {--date=}';
    protected $description = 'Supervise run-aware NHL orchestrator work for queued discovery runs.';

    public function handle(NhlImportOrchestrator $orchestrator): int
    {
        $single = $this->option('date');

        if ($single) {
            dispatch(new NhlOrchestratorJob((string) $single));
            $this->info('Dispatched for 1 game_date(s).');

            return self::SUCCESS;
        }

        $run = $this->claimNextDiscoveryRun();

        if (! $run) {
            $this->info('No discovery run with scheduled work.');

            return self::SUCCESS;
        }

        $dispatched = $orchestrator->fillActiveGameSlotsForRun($run->id);

        broadcast(new NhlGameImportStatusUpdated('processing-queued', $run->id));

        $this->info(sprintf(
            'Supervised discovery run #%d and dispatched %d game(s).',
            $run->id,
            $dispatched
        ));

        return self::SUCCESS;
    }

    /**
     * Claim or continue the oldest discovery run that has scheduled import stage work.
     */
    private function claimNextDiscoveryRun(): ?NhlGameImportRun
    {
        $run = NhlGameImportRun::query()
            ->where('action', NhlGameImportRun::ACTION_DISCOVER)
            ->whereIn('status', [NhlGameImportRun::STATUS_RUNNING, NhlGameImportRun::STATUS_QUEUED])
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('nhl_import_progress')
                    ->where('nhl_import_progress.status', 'scheduled')
                    ->whereColumn('nhl_import_progress.run_id', 'nhl_game_import_runs.id');
            })
            ->orderByRaw("case when status = 'running' then 0 else 1 end")
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if (! $run) {
            return null;
        }

        if ($run->status === NhlGameImportRun::STATUS_QUEUED) {
            $payload = $run->payload ?? [];
            $payload['processing_started_at'] = now()->toIso8601String();
            $payload['processing_requested_by'] = null;
            $payload['processing_requested_by_command'] = 'nhl:process';

            $updated = NhlGameImportRun::query()
                ->whereKey($run->id)
                ->where('status', NhlGameImportRun::STATUS_QUEUED)
                ->update([
                    'status' => NhlGameImportRun::STATUS_RUNNING,
                    'queued_jobs' => (int) $run->date_count,
                    'payload' => $payload,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                return null;
            }
        }

        return $run->refresh();
    }
}
