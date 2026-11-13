<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameValidation;
use App\Repositories\NhlImportProgressRepo;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;

/** Keep the run's read projection current after its source transaction commits. */
class RefreshNhlImportProgressSummary implements ShouldHandleEventsAfterCommit
{
    /** Resolve only affected run identities; never sweep all history on a status event. */
    public function handle(NhlGameImportStatusUpdated $event): void
    {
        if ($event->progressSummaryPrepared) {
            return;
        }
        $ids = $event->runId !== null ? [$event->runId]
            : ($event->gameId !== null ? DB::table('nhl_import_progress')->where('game_id', $event->gameId)
                ->whereNotNull('run_id')->distinct()->pluck('run_id')->all() : []);
        if ($event->runId === null && $event->gameId !== null) {
            $date = DB::table('nhl_import_progress')->where('game_id', $event->gameId)->value('game_date');
            if ($date !== null) {
                $legacy = DB::table('nhl_game_import_runs as runs')->where('action', 'process')
                    ->whereNotNull('runs.payload->progress_summary')
                    ->whereRaw('((start_date <= ? AND end_date >= ?) OR (end_date <= ? AND start_date >= ?))',
                        [$date, $date, $date, $date])
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nhl_import_progress as owned')
                        ->whereColumn('owned.run_id', 'runs.id'))->pluck('runs.id')->all();
                $ids = array_unique([...$ids, ...$legacy]);
            }
        }
        foreach ($ids as $id) {
            app(NhlImportProgressRepo::class)->refreshRunSummary((int) $id);
        }
        $event->progressSummaryPrepared = true;
    }

    /** Keep saved failed-rerun counts current after a validation is corrected or removed. */
    public function validationChanged(NhlGameValidation $validation): void
    {
        $gameId = (int) $validation->nhl_game_id;
        DB::afterCommit(function () use ($gameId): void {
            $date = DB::table('nhl_games')->where('nhl_game_id', $gameId)->value('game_date');
            if ($date === null) {
                return;
            }
            $ids = DB::table('nhl_game_import_runs')->whereNotNull('payload->progress_summary')
                ->where(function ($query) use ($date): void {
                    $query->where(fn ($range) => $range->where('start_date', '<=', $date)->where('end_date', '>=', $date))
                        ->orWhere(fn ($range) => $range->where('end_date', '<=', $date)->where('start_date', '>=', $date));
                })->pluck('id');
            foreach ($ids as $id) {
                app(NhlImportProgressRepo::class)->refreshRunSummary((int) $id);
            }
        });
    }
}
