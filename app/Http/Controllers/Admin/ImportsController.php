<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCapWagesJob;
use App\Jobs\ImportFantraxPlayersJob;
use App\Jobs\ImportNHLPlayerJob;
use App\Jobs\ImportPbpNhlJob;
use App\Services\PlatformState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class ImportsController extends Controller
{
    public function __construct(private PlatformState $platformState)
    {
    }

    public function index()
    {
        $imports = collect([
            ['key' => 'nhl', 'label' => 'NHL Players', 'job' => ImportNHLPlayerJob::class],
            ['key' => 'fantrax', 'label' => 'Fantrax Players', 'job' => ImportFantraxPlayersJob::class],
            ['key' => 'contracts', 'label' => 'Contracts', 'job' => ImportCapWagesJob::class],
            ['key' => 'pbp', 'label' => 'Play by Play', 'job' => ImportPbpNhlJob::class],
        ])->map(function ($import) {
            $batch = DB::table('job_batches')
                ->where('name', 'like', "%{$import['key']}%")
                ->latest('created_at')
                ->first();

            return [
                'key' => $import['key'],
                'label' => $import['label'],
                'batch' => $batch ? Bus::findBatch($batch->id) : null,
                'last_run' => $batch->created_at ?? null,
                'duration' => $batch?->finished_at ? now()->parse($batch->finished_at)->diffInSeconds($batch->created_at) . 's' : null,
                'counts' => $batch?->total_jobs ? "{$batch->total_jobs} jobs" : null,
                'can_rerun_failed' => true,
            ];
        })->all();

        return view('admin.imports', ['imports' => $imports]);
    }

    public function run(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->platformState->initialized(), 403);
        $batch = Bus::batch([
            $this->resolveJob($key),
        ])->name("manual-{$key}-import")
            ->onQueue('default')
            ->dispatch();

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }

    public function retry(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->platformState->initialized(), 403);
        $job = $this->resolveJob($key);
        $batch = Bus::batch([$job])->name("manual-{$key}-retry")
            ->onQueue('default')
            ->dispatch();

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }

    private function resolveJob(string $key)
    {
        return match ($key) {
            'nhl' => new ImportNHLPlayerJob(),
            'fantrax' => new ImportFantraxPlayersJob(),
            'contracts' => new ImportCapWagesJob(),
            'pbp' => new ImportPbpNhlJob(),
            default => abort(404),
        };
    }
}
