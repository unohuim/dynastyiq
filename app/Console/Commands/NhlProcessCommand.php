<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\NhlOrchestratorJob;
use App\Models\NhlGameImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NhlProcessCommand extends Command
{
    protected $signature = 'nhl:process {--date=}';
    protected $description = 'Dispatch run-aware NHL orchestrator jobs for queued discovery work.';

    public function handle(): int
    {
        $single = $this->option('date');

        if ($single) {
            dispatch(new NhlOrchestratorJob((string) $single));
            $this->info('Dispatched for 1 game_date(s).');

            return self::SUCCESS;
        }

        $run = $this->claimNextDiscoveryRun();

        if (! $run) {
            $this->info('No queued discovery run with scheduled work.');

            return self::SUCCESS;
        }

        $dates = $this->dateStrings(
            Carbon::parse($run->start_date)->startOfDay(),
            Carbon::parse($run->end_date)->startOfDay()
        );

        foreach ($dates as $date) {
            dispatch(new NhlOrchestratorJob($date));
        }

        broadcast(new NhlGameImportStatusUpdated('processing-queued', $run->id));

        $this->info(sprintf(
            'Dispatched discovery run #%d for %d game_date(s).',
            $run->id,
            count($dates)
        ));

        return self::SUCCESS;
    }

    /**
     * Claim the oldest queued discovery run that has scheduled import stage work.
     */
    private function claimNextDiscoveryRun(): ?NhlGameImportRun
    {
        $run = NhlGameImportRun::query()
            ->where('action', NhlGameImportRun::ACTION_DISCOVER)
            ->where('status', NhlGameImportRun::STATUS_QUEUED)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('nhl_import_progress')
                    ->where('nhl_import_progress.status', 'scheduled')
                    ->whereColumn('nhl_import_progress.game_date', '>=', 'nhl_game_import_runs.end_date')
                    ->whereColumn('nhl_import_progress.game_date', '<=', 'nhl_game_import_runs.start_date');
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if (! $run) {
            return null;
        }

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

        return $run->refresh();
    }

    /**
     * Return inclusive date strings from later date to earlier date.
     *
     * @return array<int, string>
     */
    private function dateStrings(Carbon $start, Carbon $end): array
    {
        $dates = [];

        for ($cursor = $start->copy(); $cursor->gte($end); $cursor->subDay()) {
            $dates[] = $cursor->toDateString();
        }

        return $dates;
    }
}
