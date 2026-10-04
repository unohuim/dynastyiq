<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineRun;
use App\Models\NhlSatEngineStack;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persists a reviewed discovery recommendation without changing prediction selection. */
final class NhlSatEngineStackCreator
{
    /** @param list<int> $candidateIds */
    public function create(NhlSatEngineRun $run, string $name, array $candidateIds): NhlSatEngineStack
    {
        if (! in_array($run->status, ['queued', 'running', 'ranking', 'complete'], true)) {
            throw ValidationException::withMessages(['stack' => 'Failed or cancelled runs cannot create a stack.']);
        }

        return DB::transaction(function () use ($run, $name, $candidateIds): NhlSatEngineStack {
            $rows = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)
                ->whereIn('id', $candidateIds)->whereNotNull('metrics')->get()->keyBy('id');
            if ($rows->count() !== count($candidateIds)) {
                throw ValidationException::withMessages(['candidate_ids' => 'Every stack member must be a completed candidate from this run.']);
            }

            $stack = NhlSatEngineStack::query()->create(['name' => $name]);
            foreach ($candidateIds as $index => $candidateId) {
                $candidate = $rows->get($candidateId);
                $role = $index === 0 ? 'Foundation' : 'Supplement ' . $index;
                $engine = (new NhlSatEngine())->saveDefinition([
                    'name' => $name . ' — ' . $role,
                    'test_model_run_id' => $run->model_run_id,
                    'model_run_id' => $run->model_run_id,
                    'settings' => json_decode($candidate->settings, true, 512, JSON_THROW_ON_ERROR),
                    'discovery_run_id' => $run->kind === 'discovery' ? $run->id : null,
                    'discovery_candidate_id' => $run->kind === 'discovery' ? $candidate->id : null,
                    'discovery_win_pct' => $run->kind === 'discovery' ? $candidate->win_pct : null,
                    'discovery_coverage_pct' => $run->kind === 'discovery' ? $candidate->coverage_pct : null,
                ]);
                $stack->members()->create(['engine_id' => $engine->id, 'priority' => $index + 1]);
            }

            return $stack->load('members.engine');
        });
    }
}
