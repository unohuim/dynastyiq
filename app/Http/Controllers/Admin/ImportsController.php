<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use App\Services\AdminImports;
use App\Services\PlatformState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class ImportsController extends Controller
{
    public function __construct(private PlatformState $platformState, private AdminImports $imports)
    {
    }

    public function index()
    {
        $imports = $this->imports->sources()->map(function (array $source) {
            $batch = DB::table('job_batches')
                ->where('name', 'like', "%{$source['key']}%")
                ->latest('created_at')
                ->first();

            $lastRun = ImportRun::query()
                ->where('source', $source['key'])
                ->latest('ran_at')
                ->first();

            return [
                'key' => $source['key'],
                'label' => $source['label'],
                'batch' => $batch ? Bus::findBatch($batch->id) : null,
                'last_run' => $lastRun?->ran_at ?? $batch->created_at ?? null,
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
        $batch = $this->imports->dispatch($key);

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }

    public function retry(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->platformState->initialized(), 403);
        $batch = $this->imports->dispatch($key);

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }
}
