<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlPregameContextRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin surface for durable, analysis-only pregame context builds. */
class NhlPregameContextController extends Controller
{
    public function index(): View
    {
        $seasons = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->select('season_id')
            ->selectRaw('COUNT(*) as completed_games')
            ->selectRaw('(SELECT COUNT(*) FROM nhl_game_summaries summaries INNER JOIN nhl_games source_games ON source_games.nhl_game_id = summaries.nhl_game_id WHERE source_games.season_id = nhl_games.season_id) as player_summary_count')
            ->groupBy('season_id')->orderByDesc('season_id')->get();

        return view('admin.nhl-sat-models.context', [
            'runs' => NhlPregameContextRun::query()->latest()->limit(12)->get(),
            'seasons' => $seasons,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $seasonIds = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->distinct()->pluck('season_id')->map(fn (mixed $id): string => (string) $id)->all();
        $input = $request->validate([
            'season_ids' => ['required', 'array', 'min:1'],
            'season_ids.*' => ['required', Rule::in($seasonIds)],
        ]);
        $selected = collect($input['season_ids'])->map(fn (mixed $id): string => (string) $id)->unique()->sort()->values()->all();
        $active = NhlPregameContextRun::query()->whereIn('status', [NhlPregameContextRun::STATUS_QUEUED, NhlPregameContextRun::STATUS_RUNNING])
            ->whereJsonContains('season_ids', $selected[0])->exists();
        if ($active) {
            return $request->expectsJson()
                ? response()->json(['message' => 'A context build is already active for one of these seasons.'], 422)
                : back()->withErrors(['season_ids' => 'A context build is already active for one of these seasons.']);
        }

        $run = NhlPregameContextRun::query()->create([
            'action' => NhlPregameContextRun::ACTION_BACKFILL,
            'status' => NhlPregameContextRun::STATUS_QUEUED,
            'season_ids' => $selected,
            'created_by' => $request->user()?->id,
            'options' => ['context_version' => \App\Services\NhlPregameContextBuilder::VERSION],
        ]);
        PrepareNhlPregameContextRunJob::dispatch($run->id);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Queued bounded pregame-context backfill.', 'run' => $run], 202);
        }

        return redirect()->route('admin.nhl-sat-models.context')->with('status', 'Queued bounded pregame-context backfill.');
    }
}
