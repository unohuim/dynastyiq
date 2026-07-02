<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportYahooPlayersPageJob;
use App\Models\ImportRun;
use App\Models\YahooFantasyConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Imports Yahoo Fantasy players into provider-owned staging storage.
 */
class YahooPlayerImportController extends Controller
{
    /**
     * Queue an all-player Yahoo Fantasy import using a persisted Yahoo OAuth connection.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ]);

        $connection = YahooFantasyConnection::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'connected')
            ->first();

        abort_if(! $connection, 409, 'Yahoo OAuth connection is required before importing players.');

        $pageSize = max(1, min((int) ($validated['page_size'] ?? config('yahoo.fantasy.players_page_size', 25)), 25));
        $startedAt = now();
        $importRun = ImportRun::create([
            'source' => 'yahoo',
            'status' => 'working',
            'command' => null,
            'options' => ['all_players' => true, 'page_size' => $pageSize],
            'meta' => ['dynamic_total' => true],
            'ran_at' => $startedAt,
            'started_at' => $startedAt,
            'total_records' => null,
            'processed_records' => 0,
            'successful_records' => 0,
            'failed_records' => 0,
            'skipped_records' => 0,
            'progress_label' => 'Importing Yahoo players',
        ]);

        ImportYahooPlayersPageJob::dispatch($connection->id, $importRun->id, 0, $pageSize);

        return response()->json([
            'ok' => true,
            'queued' => true,
            'import_run' => $this->importRunPayload($importRun->refresh()),
        ]);
    }

    /**
     * Return an import run payload compatible with the admin import card.
     *
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
