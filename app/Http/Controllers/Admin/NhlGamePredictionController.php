<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunNhlGamePredictionJob;
use App\Models\NhlSatEngineStack;
use App\Services\NhlGamePredictionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Shared super-admin operational runs and durable revision inspection, separate from custom engines. */
final class NhlGamePredictionController extends Controller
{
    /** Admit one inclusive date-range run and enqueue one bounded job per stored game. */
    public function store(Request $request, NhlGamePredictionHistory $history): JsonResponse
    {
        [$start, $end] = $this->dates($request);
        $stack = NhlSatEngineStack::query()->where('is_default', true)->with('members.engine')->first();
        if ($stack === null || $stack->members->isEmpty()) {
            throw ValidationException::withMessages(['stack' => 'Select a non-empty default stack before running predictions.']);
        }
        $games = $this->games($start, $end)->pluck('nhl_game_id');
        if ($games->isEmpty()) {
            throw ValidationException::withMessages(['start_date' => 'No stored NHL games were found for these dates.']);
        }
        [$runId, $itemIds] = DB::transaction(function () use ($start, $end, $stack, $games, $request): array {
            $id = DB::table('nhl_game_prediction_runs')->insertGetId([
                'start_date' => $start, 'end_date' => $end, 'status' => 'queued', 'total_games' => $games->count(),
                'requested_stack' => $stack->toJson(), 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('nhl_game_prediction_run_games')->insert($games->map(fn ($gameId): array => [
                'run_id' => $id, 'nhl_game_id' => $gameId, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ])->all());
            return [$id, DB::table('nhl_game_prediction_run_games')->where('run_id', $id)->pluck('id')];
        });
        try {
            Bus::batch($itemIds->map(fn ($id) => new RunNhlGamePredictionJob((int) $id))->all())
                ->name('NHL game predictions #'.$runId)->onQueue('projections')->allowFailures()->dispatch();
        } catch (Throwable $error) {
            foreach ($itemIds as $id) {
                $history->finish((int) $id, 'failed', error: 'Unable to queue prediction: '.mb_substr($error->getMessage(), 0, 1500));
            }
        }
        return response()->json(['run_id' => $runId, 'total_games' => $games->count()], 202);
    }

    /** Latest successful canonical predictions stay visible even while a replacement is queued or fails. */
    public function index(Request $request, NhlGamePredictionHistory $history): JsonResponse
    {
        [$start, $end] = $this->dates($request);
        $request->validate(['run_id' => ['nullable', 'integer', 'exists:nhl_game_prediction_runs,id'], 'page' => ['nullable', 'integer', 'min:1']]);
        $run = $request->filled('run_id') ? DB::table('nhl_game_prediction_runs')->find($request->integer('run_id'))
            : DB::table('nhl_game_prediction_runs')->where('start_date', $start)->where('end_date', $end)->orderByDesc('id')->first();
        $games = $this->games($start, $end)->orderBy('game_date')->orderBy('nhl_game_id')->paginate(50);
        $ids = collect($games->items())->pluck('nhl_game_id');
        $latestIds = DB::table('nhl_game_prediction_revisions')->whereIn('nhl_game_id', $ids)
            ->where('is_canonical', true)->where('prediction_available', true)
            ->selectRaw('MAX(id) as id')->groupBy('nhl_game_id');
        $revisions = DB::table('nhl_game_prediction_revisions')->whereIn('id', $latestIds)
            ->get(['id', 'nhl_game_id', 'run_id', 'source', 'predicted_at', 'prediction_available', 'is_canonical', 'summary'])->keyBy('nhl_game_id');
        $items = $run === null ? collect() : DB::table('nhl_game_prediction_run_games')->where('run_id', $run->id)
            ->whereIn('nhl_game_id', $ids)->get()->keyBy('nhl_game_id');
        $counts = $run === null ? collect() : DB::table('nhl_game_prediction_run_games')->where('run_id', $run->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $recent = DB::table('nhl_game_prediction_runs')->orderByDesc('id')->limit(20)
            ->get(['id', 'start_date', 'end_date', 'status', 'total_games', 'created_at']);
        return response()->json([
            'run' => $run === null ? null : ['id' => $run->id, 'status' => $run->status, 'total_games' => $run->total_games,
                'counts' => $counts, 'created_at' => $run->created_at, 'finished_at' => $run->finished_at],
            'runs' => $recent,
            'games' => collect($games->items())->map(fn (object $game): array => [
                'nhl_game_id' => $game->nhl_game_id, 'date' => $game->game_date,
                'away' => $game->away_team_abbrev, 'home' => $game->home_team_abbrev,
                'actual_away' => in_array($game->game_state, ['OFF', 'FINAL'], true) ? $game->away_team_score : null,
                'actual_home' => in_array($game->game_state, ['OFF', 'FINAL'], true) ? $game->home_team_score : null,
                'revision' => $revisions->has($game->nhl_game_id) ? $history->summary($revisions[$game->nhl_game_id]) : null,
                'work' => $items->get($game->nhl_game_id),
            ]),
            'current_page' => $games->currentPage(), 'last_page' => $games->lastPage(), 'total' => $games->total(),
        ]);
    }

    /** Paginated immutable history includes unavailable and explicitly overridden variants. */
    public function revisions(int $game, Request $request, NhlGamePredictionHistory $history): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $rows = DB::table('nhl_game_prediction_revisions')->where('nhl_game_id', $game)->orderByDesc('id')
            ->paginate(10, ['id', 'run_id', 'source', 'predicted_at', 'prediction_available', 'is_canonical', 'summary']);
        return response()->json(['revisions' => collect($rows->items())->map($history->summary(...)),
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]);
    }

    /** Read the complete saved response, inputs and frozen configuration without recalculating. */
    public function show(int $revision, NhlGamePredictionHistory $history): JsonResponse
    {
        $row = DB::table('nhl_game_prediction_revisions')->find($revision);
        abort_if($row === null, 404);
        return response()->json(['revision' => $history->summary($row), 'payload' => $history->payload($row),
            'request_inputs' => json_decode($row->request_inputs, true, 512, JSON_THROW_ON_ERROR),
            'evidence' => json_decode($row->evidence, true, 512, JSON_THROW_ON_ERROR)]);
    }

    /** Validate bounded inclusive ranges; omitting the end date selects just the start date.
     * @return array{string,string}
     */
    private function dates(Request $request): array
    {
        $data = $request->validate(['start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $start = $data['start_date'];
        $end = $data['end_date'] ?? $start;
        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 365) {
            throw ValidationException::withMessages(['end_date' => 'Choose a range of at most 366 days.']);
        }
        return [$start, $end];
    }

    /** Read schedule rows only; predictions never start schedule imports. */
    private function games(string $start, string $end): \Illuminate\Database\Query\Builder
    {
        return DB::table('nhl_games')->whereBetween('game_date', [$start, $end])->whereIn('game_type', [1, 2, 3])
            ->where(fn ($query) => $query->whereNull('game_state')->orWhereNotIn('game_state', ['PPD', 'CNCL']));
    }
}
