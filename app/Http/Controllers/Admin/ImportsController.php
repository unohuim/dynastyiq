<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use App\Services\AdminImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class ImportsController extends Controller
{
    public function __construct(private AdminImports $imports)
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
                'group' => $source['group'] ?? 'player',
                'batch' => $batch ? Bus::findBatch($batch->id) : null,
                'last_run' => $lastRun?->finished_at ?? $lastRun?->started_at ?? $batch->created_at ?? null,
                'duration' => $lastRun?->duration_seconds !== null
                    ? "{$lastRun->duration_seconds}s"
                    : ($batch?->finished_at ? now()->parse($batch->finished_at)->diffInSeconds($batch->created_at) . 's' : null),
                'status' => $lastRun?->status,
                'progress' => $lastRun ? $this->importRunPayload($lastRun)['progress'] : null,
                'counts' => $batch?->total_jobs ? "{$batch->total_jobs} jobs" : null,
                'run_url' => $this->importRunUrl($source),
                'status_url' => route('admin.imports.status', ['key' => $source['key']]),
                'can_rerun_failed' => (bool) ($source['can_retry'] ?? true),
            ];
        })->all();

        return view('admin.imports', ['imports' => $imports]);
    }

    public function run(Request $request, string $key)
    {
        $batch = $this->imports->dispatch($key);

        if ($request->wantsJson()) {
            $importRun = ImportRun::query()->where('batch_id', $batch->id)->first();

            return response()->json([
                'batch_id' => $batch->id,
                'started_at' => now()->toIso8601String(),
                'import_run' => $importRun ? $this->importRunPayload($importRun) : null,
            ]);
        }

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }

    public function status(string $key): JsonResponse
    {
        $this->imports->source($key);

        $importRun = ImportRun::query()
            ->where('source', $key)
            ->latest('started_at')
            ->first();

        return response()->json([
            'import_run' => $importRun ? $this->importRunPayload($importRun) : null,
        ]);
    }

    public function retry(Request $request, string $key)
    {
        $batch = $this->imports->dispatch($key);

        if ($request->wantsJson()) {
            $importRun = ImportRun::query()->where('batch_id', $batch->id)->first();

            return response()->json([
                'batch_id' => $batch->id,
                'started_at' => now()->toIso8601String(),
                'import_run' => $importRun ? $this->importRunPayload($importRun) : null,
            ]);
        }

        return Redirect::to(URL::route('admin.imports', ['batch_id' => $batch->id]));
    }

    /**
     * @param array<string,mixed> $source
     */
    private function importRunUrl(array $source): ?string
    {
        if (isset($source['run_route'])) {
            return route($source['run_route']);
        }

        if (isset($source['command'])) {
            return route('admin.imports.run', ['key' => $source['key']]);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function importRunPayload(ImportRun $importRun): array
    {
        $total = $importRun->total_records;
        $processed = $importRun->processed_records ?? 0;
        $dynamicTotal = (bool) ($importRun->meta['dynamic_total'] ?? false);
        $percentage = $total && ! $dynamicTotal
            ? min(100, (int) floor(($processed / max(1, $total)) * 100))
            : null;

        return [
            'id' => $importRun->id,
            'source' => $importRun->source,
            'status' => $importRun->status,
            'started_at' => $importRun->started_at?->toIso8601String(),
            'finished_at' => $importRun->finished_at?->toIso8601String(),
            'duration_seconds' => $importRun->duration_seconds,
            'progress' => [
                'label' => $importRun->progress_label,
                'total_records' => $total,
                'processed_records' => $processed,
                'successful_records' => $importRun->successful_records ?? 0,
                'failed_records' => $importRun->failed_records ?? 0,
                'skipped_records' => $importRun->skipped_records ?? 0,
                'dynamic_total' => $dynamicTotal,
                'percentage' => $percentage,
            ],
        ];
    }
}
