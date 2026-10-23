<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlGameImportRun;
use App\Services\NhlGameImportRunControl;
use Illuminate\Http\JsonResponse;

/** Admin-only controls for existing game import and discovery runs. */
class NhlGameImportRunControlController extends Controller
{
    /** Stop admission and allow claimed work to finish. */
    public function pause(NhlGameImportRun $run, NhlGameImportRunControl $control): JsonResponse
    {
        $updated = $control->pause($run->id);
        return response()->json(['message' => 'Pause requested.', 'run' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    /** Requeue unfinished work from its saved checkpoint. */
    public function resume(NhlGameImportRun $run, NhlGameImportRunControl $control): JsonResponse
    {
        $updated = $control->resume($run->id);
        return response()->json(['message' => 'Run resumed.', 'run' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    /** Cancel the run without terminating any queue workers. */
    public function cancel(NhlGameImportRun $run, NhlGameImportRunControl $control): JsonResponse
    {
        $updated = $control->cancel($run->id);
        return response()->json(['message' => 'Run cancelled.', 'run' => ['id' => $updated->id, 'status' => $updated->status]]);
    }
}
