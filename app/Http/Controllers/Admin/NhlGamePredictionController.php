<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NhlGamePredictionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Super-admin operational runs and durable revision inspection for the shared default engine. */
final class NhlGamePredictionController extends Controller
{
    /** Admit one inclusive date-range run and enqueue one bounded job per stored game. */
    public function store(Request $request, NhlGamePredictionHistory $history): JsonResponse
    {
        [$start, $end] = $this->dates($request);
        return response()->json($history->queueRun($start, $end, $request->user()->id), 202);
    }

    /** Read disabled-by-default automation settings without creating work or changing configuration. */
    public function automation(\App\Services\ScheduledProcessManager $manager): JsonResponse
    {
        return response()->json($manager->predictionSettings());
    }

    /** Save exactly one trigger mode; changing settings never starts a prediction. */
    public function updateAutomation(Request $request, \App\Services\ScheduledProcessManager $manager): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'trigger_mode' => ['required', 'in:time,event'],
            'start_time' => ['required_if:trigger_mode,time', 'date_format:H:i'],
            'frequency_hours' => ['required_if:trigger_mode,time', 'integer', 'between:1,168'],
        ]);
        $process = DB::transaction(function () use ($data, $manager): \App\Models\ScheduledProcess {
            // The unique key handles concurrent first saves without overwriting existing settings.
            DB::table('scheduled_processes')->insertOrIgnore([
                'key' => $manager::NHL_PREDICTIONS, 'enabled' => false, 'start_time' => '06:00',
                'timezone' => 'America/Toronto', 'frequency_hours' => 24,
                'settings' => json_encode(['trigger_mode' => 'time'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $process = \App\Models\ScheduledProcess::query()->where('key', $manager::NHL_PREDICTIONS)->lockForUpdate()->firstOrFail();
            $settings = $process->settings;
            $changedMode = ($settings['trigger_mode'] ?? 'time') !== $data['trigger_mode'];
            $settings['trigger_mode'] = $data['trigger_mode'];
            $process->fill(['enabled' => $data['enabled'], 'settings' => $settings,
                'start_time' => $data['start_time'] ?? $process->start_time,
                'frequency_hours' => $data['frequency_hours'] ?? $process->frequency_hours]);
            if ($changedMode || $process->isDirty(['enabled', 'start_time', 'frequency_hours'])) {
                $process->next_due_at = null;
            }
            $process->save();

            return $process->fresh();
        });

        return response()->json($process);
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
        $measuredAt = Carbon::now('UTC');

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
                'engine_time_seconds' => $this->engineTime(
                    $items->get($game->nhl_game_id), $run?->created_at, $measuredAt
                ),
            ]),
            'current_page' => $games->currentPage(), 'last_page' => $games->lastPage(), 'total' => $games->total(),
        ]);
    }

    /** Total wall time for the selected run, including queue, preparation and retries. */
    private function engineTime(?object $work, ?string $queuedAt, Carbon $measuredAt): ?int
    {
        if ($work === null || $queuedAt === null) {
            return null;
        }
        if ($work->finished_at === null && in_array($work->status, ['completed', 'unavailable', 'failed'], true)) {
            return null;
        }
        $end = $work->finished_at === null ? $measuredAt : Carbon::parse($work->finished_at, 'UTC');

        return max(0, $end->getTimestamp() - Carbon::parse($queuedAt, 'UTC')->getTimestamp());
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
        return DB::table('nhl_games')->whereBetween('game_date', [$start, $end])->whereIn('game_type', [1, 2, 3]);
    }
}
