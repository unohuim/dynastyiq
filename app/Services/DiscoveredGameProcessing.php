<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\GamesDiscovered;
use App\Models\NhlGameImportRun;
use Illuminate\Support\Facades\DB;

/** Connect completed discovery to the existing bounded import pipeline. */
class DiscoveredGameProcessing
{
    /** Claim committed discovery once; repeated delivery safely refills existing slots. */
    public function start(int $runId): void
    {
        $ready = DB::transaction(function () use ($runId): bool {
            $run = NhlGameImportRun::query()->whereKey($runId)->lock('for no key update')->first();
            if (! $run || $run->action !== 'discover'
                || in_array($run->status, ['failed', 'pausing', 'paused', 'cancelling', 'cancelled'], true)) {
                return false;
            }
            $payload = $run->payload ?? [];
            // Preserve already-queued scheduler runs; unmarked manual discoveries never opt in.
            $autoProcess = $payload['auto_process']
                ?? (($payload['scheduled_process'] ?? null) === ScheduledProcessManager::NHL_DISCOVERY);
            if ($autoProcess !== true
                || count(array_unique($payload['discovery_completed_dates'] ?? [])) < $run->date_count
                || ($payload['process_scope'] ?? null) !== null
                || ($run->status === 'completed' && isset($payload['processing_started_at']))) {
                return false;
            }
            if (! DB::table('nhl_import_progress')->where('run_id', $runId)->whereIn('status', ['scheduled', 'running'])->exists()) {
                return false;
            }
            $payload['processing_started_at'] ??= now()->toIso8601String();
            $payload['processing_requested_by_event'] = GamesDiscovered::class;
            unset($payload['completed_at']);
            $run->update(['status' => 'running', 'payload' => $payload]);

            return true;
        });
        if ($ready) {
            app(NhlImportOrchestrator::class)->fillActiveGameSlotsForRun($runId);
        }
    }
}
