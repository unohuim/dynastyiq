<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NhlDiscoveryJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlPregameContextRun;
use Illuminate\Support\Facades\DB;

/** Bridge pilot source gaps into independently owned canonical Full imports. */
class NhlPregameSourceRepairs
{
    /** Persist one linked discovery per excluded game; replay never creates a second child. */
    public function begin(NhlPregameContextRun $run): void
    {
        if (! data_get($run->options, 'repair_sources') || data_get($run->options, 'source_repairs_complete')) {
            return;
        }
        $ids = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
            ->whereJsonLength('readiness->source_blockers', '>', 0)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($run, $id): void {
                $parent = NhlPregameContextRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($parent->status !== 'running') {
                    return;
                }
                $game = DB::table('nhl_pregame_context_run_games')->where('id', $id)->lockForUpdate()->first();
                $readiness = json_decode($game->readiness ?? '{}', true);
                if (isset($readiness['source_repair']['import_run_id'])) {
                    $child = NhlGameImportRun::find($readiness['source_repair']['import_run_id']);
                    if ($child?->status === 'queued' && ! isset($readiness['source_repair']['dispatched_at'])) {
                        $this->dispatchDiscovery($game, $child);
                    }
                    return;
                }
                $child = NhlGameImportRun::create(['action' => 'discover', 'mode' => 'date', 'status' => 'queued',
                    'start_date' => $game->game_date, 'end_date' => $game->game_date, 'date_count' => 1, 'queued_jobs' => 1,
                    'created_by' => $parent->created_by, 'payload' => ['auto_process' => true,
                        'pregame_repair_game_id' => (int) $game->nhl_game_id, 'pregame_repair_run_id' => $parent->id]]);
                $readiness['source_repair'] = ['import_run_id' => $child->id];
                DB::table('nhl_pregame_context_run_games')->where('id', $id)->update([
                    'readiness' => json_encode($readiness, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                $this->dispatchDiscovery($game, $child);
            });
        }
    }

    /** Record queue handoff only after publication; interrupted handoff may safely replay the same child. */
    private function dispatchDiscovery(object $game, NhlGameImportRun $child): void
    {
        DB::afterCommit(function () use ($game, $child): void {
            NhlDiscoveryJob::dispatch($game->game_date, $game->game_date, $child->id);
            DB::table('nhl_pregame_context_run_games')->where('id', $game->id)
                ->update(['readiness->source_repair->dispatched_at' => now()->toIso8601String()]);
        });
    }

    /** Wait for canonical Full completion, then admit context using the repaired historical sources. */
    public function ready(NhlPregameContextRun $run): bool
    {
        if (! data_get($run->options, 'repair_sources') || data_get($run->options, 'source_repairs_complete')) {
            return true;
        }
        if ($run->status === 'completed' && (int) $run->total_games === 0) {
            return true;
        }
        $this->begin($run);
        $rows = $this->rows($run);
        if (collect($rows)->contains(fn ($row) => in_array($row['state'], ['queued', 'discovering', 'importing', 'paused'], true))) {
            return false;
        }
        DB::transaction(function () use ($run, $rows): void {
            $owner = NhlPregameContextRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($owner->status !== 'running') {
                return;
            }
            foreach ($rows as $row) {
                $work = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->where('nhl_game_id', $row['game_id'])->first();
                $readiness = json_decode($work->readiness ?? '{}', true);
                if ($row['imported'] && DB::table('nhl_game_summaries')->where('nhl_game_id', $row['game_id'])
                    ->distinct()->count('nhl_team_id') < 2) {
                    $row['imported'] = false;
                    $row['error'] = 'Full import finished without player summaries for both teams.';
                    $readiness['source_repair']['error'] = $row['error'];
                }
                $readiness['source_blockers'] = $row['imported'] ? [] : [$row['error']];
                DB::table('nhl_pregame_context_run_games')->where('id', $work->id)->update([
                    'state' => $row['imported'] ? 'pending' : 'blocked', 'last_error' => $row['error'],
                    'readiness' => json_encode($readiness, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $blockedDays = 0;
            foreach ($owner->days()->get() as $day) {
                $ready = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
                    ->where('game_date', $day->game_date)->where('state', '!=', 'blocked')->exists();
                $day->update(['league_state' => $ready ? 'pending' : 'complete', 'context_state' => $ready ? 'pending' : 'complete']);
                $blockedDays += $ready ? 0 : 1;
            }
            $owner->update(['options' => array_merge($owner->options ?? [], ['source_repairs_complete' => true,
                'source_blocked_days' => $blockedDays])]);
        });
        return $run->fresh()->status === 'running';
    }

    /** Relay parent maintenance controls only to its own linked import children. */
    public function control(NhlPregameContextRun $run, string $action): bool
    {
        $settled = true;
        $children = NhlGameImportRun::where('payload->pregame_repair_run_id', $run->id)->get();
        foreach ($children as $child) {
            $control = app(NhlGameImportRunControl::class);
            if ($action === 'pause' && in_array($child->status, ['queued', 'running'], true)) {
                $child = $control->pause($child->id);
            } elseif ($action === 'resume' && in_array($child->status, ['paused', 'pausing'], true)) {
                $child = $control->resume($child->id);
            } elseif ($action === 'cancel' && in_array($child->status, ['queued', 'running', 'paused', 'pausing', 'cancelling'], true)) {
                $child = $control->cancel($child->id);
            } elseif ($action === 'resume' && $child->status === 'completed' && ! data_get($child->payload, 'processing_started_at')) {
                app(DiscoveredGameProcessing::class)->start($child->id);
            }
            $settled = $settled && ! in_array($control->settledStatus($child), ['running', 'queued', 'pausing', 'cancelling'], true);
        }
        return $settled;
    }

    /** Read per-game source and context progress without starting work or modifying import records. */
    public function rows(NhlPregameContextRun $run): array
    {
        $games = DB::table('nhl_pregame_context_run_games as work')->join('nhl_games as game', 'game.nhl_game_id', '=', 'work.nhl_game_id')
            ->where('work.run_id', $run->id)->whereNotNull('work.readiness->source_repair')
            ->orderBy('work.game_date')->orderBy('work.nhl_game_id')->get(['work.*', 'game.home_team_id', 'game.away_team_id']);
        $children = NhlGameImportRun::where('payload->pregame_repair_run_id', $run->id)->get()->keyBy('id');
        $progress = DB::table('nhl_import_progress')->whereIn('run_id', $children->keys())
            ->selectRaw("run_id, COUNT(*) AS qty, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS finished,
                MIN(CASE WHEN status = 'running' THEN import_type ELSE NULL END) AS current_stage")
            ->groupBy('run_id')->get()->keyBy('run_id');
        $teams = DB::table('nhl_teams')->pluck('abbrev', 'nhl_id');
        $rows = [];
        foreach ($games as $game) {
            $readiness = json_decode($game->readiness ?? '{}', true);
            $child = $children->get(data_get($readiness, 'source_repair.import_run_id'));
            $imported = $child?->status === 'completed' && data_get($child->payload, 'post_process_enrichment_completed_at') !== null;
            $state = $game->state === 'complete' ? 'repaired' : ($imported ? 'building_context' : 'queued');
            $error = null;
            if ($child === null) {
                $state = 'failed';
                $error = 'The linked game import is missing. Retry this game.';
            } elseif (in_array($child->status, ['failed', 'cancelled'], true)) {
                $state = 'failed';
                $error = $child->last_error ?: 'Game import '.$child->status.'.';
            } elseif (in_array($child->status, ['pausing', 'paused', 'cancelling'], true)) {
                $state = 'paused';
            } elseif (! $imported && $child->status === 'completed') {
                if (! data_get($child->payload, 'processing_started_at') && (int) ($progress->get($child->id)?->qty ?? 0) > 0) {
                    $state = 'queued';
                } else {
                    $state = 'failed';
                    $error = 'Discovery did not produce a completed Full import for this game. Check source availability or overlapping imports.';
                }
            } elseif (! $imported && $child->status === 'running') {
                $state = data_get($child->payload, 'processing_started_at') ? 'importing' : 'discovering';
            }
            if ($imported && in_array($game->state, ['failed', 'blocked'], true) && data_get($run->options, 'source_repairs_complete')) {
                $state = 'failed';
                $error = $game->last_error;
            }
            $rows[] = ['game_id' => (int) $game->nhl_game_id, 'date' => $game->game_date,
                'matchup' => ($teams[$game->away_team_id] ?? $game->away_team_id).' @ '.($teams[$game->home_team_id] ?? $game->home_team_id),
                'import_run_id' => $child?->id, 'state' => $state, 'imported' => $imported, 'error' => $error,
                'completed_stages' => (int) ($progress->get($child?->id)?->finished ?? 0),
                'total_stages' => (int) ($progress->get($child?->id)?->qty ?? 0),
                'current_stage' => $progress->get($child?->id)?->current_stage
                    ?? (data_get($child?->payload, 'post_process_enrichment_started_at') && ! $imported ? 'enrichment' : null)];
        }
        return $rows;
    }
}
