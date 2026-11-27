<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlSatPregameWorkspace;
use App\Services\NhlPregameWorkstreams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pilot-only controls for the shared Pregame workspace; prediction sources stay model-owned. */
class NhlSatWorkspaceController extends Controller
{
    /** Queue only missing Train/Test context for the uniquely selected historical pilot. */
    public function repair(Request $request, NhlSatPregameWorkspace $workspace, NhlPregameContextOrchestrator $orchestrator): JsonResponse
    {
        $pilot = $workspace->pilot();
        abort_if($pilot === null, 409, 'Sep2026 pilot source is unavailable.');
        $input = $request->validate(['area' => ['nullable', Rule::in(array_keys(NhlPregameWorkstreams::AREAS))]]);
        $run = isset($input['area']) ? app(NhlPregameWorkstreams::class)->repairArea($pilot, $input['area'], $request->user()?->id)
            : app(NhlPregameWorkstreams::class)->repair($pilot, $request->user()?->id);

        return response()->json(['message' => 'Queued missing Train and Test context repair. Source blockers will be listed by game.',
            'progress' => $workspace->progress($run)], 202);
    }

    /** Assign six independently schedulable area limits without changing Horizon process counts. */
    public function goals(Request $request, NhlSatPregameWorkspace $workspace, NhlPregameWorkstreams $workstreams): JsonResponse
    {
        $rules = ['areas' => ['required', 'array:'.implode(',', array_keys(NhlPregameWorkstreams::AREAS))]];
        foreach (NhlPregameWorkstreams::AREAS as $area => $name) {
            $rules['areas.'.$area] = ['required', 'integer', 'between:0,25'];
        }
        $input = $request->validate($rules);
        $pilot = $workspace->pilot();
        abort_if($pilot === null, 409, 'Sep2026 pilot source is unavailable.');
        $limits = array_map('intval', $input['areas']);
        $workstreams->allocate($pilot, $limits);

        return response()->json(['message' => 'Area allocations updated. Claimed tasks finish their current page; new assignments use these limits.', 'areas' => $limits]);
    }

    /** Change claim concurrency for the pilot without provisioning or stopping Horizon workers. */
    public function workers(Request $request, NhlSatPregameWorkspace $workspace): JsonResponse
    {
        $input = $request->validate(['league' => ['required', 'integer', 'between:1,25'],
            'context' => ['required', 'integer', 'between:1,25']]);
        if ($input['league'] + $input['context'] > 25) {
            throw ValidationException::withMessages(['league' => 'Use at most 25 combined concurrent claims.']);
        }
        $pilot = $workspace->pilot();
        abort_if($pilot === null, 409, 'Sep2026 pilot source is unavailable.');
        DB::transaction(function () use ($pilot, $input): void {
            $model = NhlModelRun::query()->lockForUpdate()->findOrFail($pilot->id);
            abort_unless(NhlSatPregameWorkspace::isPilot($model), 409, 'Pilot source changed. Reload before saving.');
            $config = $model->run_config ?? [];
            $config['pregame_workspace'] = ['league' => (int) $input['league'], 'context' => (int) $input['context']];
            $model->update(['run_config' => $config]);
            $run = NhlPregameContextRun::query()->where('model_run_id', $model->id)
                ->whereIn('status', ['queued', 'running', 'pausing', 'paused'])->orderByDesc('id')->lockForUpdate()->first();
            if ($run !== null) {
                $options = $run->options ?? [];
                $options['workspace_concurrency'] = $config['pregame_workspace'];
                $run->update(['options' => $options]);
            }
        });

        return response()->json(['message' => 'Sep2026 claim limits updated. Existing claims will finish normally.', 'limits' => $input]);
    }

    /** Inspect persisted pilot metrics only; a read never runs calculations or creates context. */
    public function inspect(Request $request, NhlSatPregameWorkspace $workspace): JsonResponse
    {
        $input = $request->validate(['game_id' => ['required', 'integer', 'min:1'],
            'entity' => ['required', Rule::in(['team', 'player'])]]);

        return response()->json($workspace->inspect((int) $input['game_id'], $input['entity']))->header('Cache-Control', 'no-store');
    }
}
