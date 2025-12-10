<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunImportCommandJob;
use App\Jobs\ValidateInitializationJob;
use App\Services\PlatformState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class InitializationController extends Controller
{
    public function __construct(private PlatformState $platformState)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $batchId = $request->query('batch_id');

        if (! $batchId) {
            return response()->json(['message' => 'Batch ID required.'], 400);
        }

        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        return response()->json([
            'progress' => $batch->progress(),
            'processed' => $batch->processedJobs(),
            'total' => $batch->totalJobs,
            'failed' => $batch->failedJobs,
            'finished' => $batch->finished(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        if ($this->platformState->initialized()) {
            return response()->json(['message' => 'Platform already initialized.'], 403);
        }

        $nhlPlayersJob = new RunImportCommandJob('nhl:import', ['--players' => true], 'nhl');

        $nhlPlayersJob->chain([
            new RunImportCommandJob('fx:import', ['--players' => true], 'fantrax'),
            new RunImportCommandJob('cap:import', ['--per-page' => 100, '--all' => true], 'contracts'),
            new ValidateInitializationJob,
        ]);

        $batch = Bus::batch([
            $nhlPlayersJob,
        ])->name('platform-initialization')
            ->allowFailures()
            ->onQueue('default')
            ->dispatch();

        return response()->json([
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
        ]);
    }
}
