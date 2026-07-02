<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlGameValidation;
use App\Models\PlayByPlay;
use App\Repositories\NhlImportProgressRepo;
use App\Services\NhlGameImportRebuilder;
use App\Services\NhlImportOrchestrator;
use App\Services\NhlPbpEventNormalizer;
use App\Services\ValidateNhlGameSummary;
use App\Support\NhlImportStages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NhlGameValidationController extends Controller
{
    public function __construct(private readonly NhlPbpEventNormalizer $normalizer)
    {
    }

    public function index(Request $request): View|JsonResponse
    {
        $status = $request->string('status', NhlGameValidation::STATUS_FAILED)->toString();

        $query = NhlGameValidation::query()
            ->with('game')
            ->withCount('deltas')
            ->latest('checked_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $validations = $query->paginate(25)->withQueryString();

        if ($request->expectsJson() && $request->boolean('admin_panel')) {
            return response()->json([
                'html' => view('admin.nhl-validations._index-content', [
                    'validations' => $validations,
                    'status' => $status,
                    'embedded' => true,
                ])->render(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json($validations);
        }

        return view('admin.nhl-validations.index', [
            'validations' => $validations,
            'status' => $status,
        ]);
    }

    public function show(Request $request, NhlGameValidation $validation): View|JsonResponse
    {
        $validation->load([
            'game',
            'deltas' => fn ($query) => $query
                ->with('player')
                ->orderBy('nhl_player_id')
                ->orderBy('field'),
        ]);
        $this->attachPbpContext($validation);

        if ($request->expectsJson() && $request->boolean('admin_panel')) {
            return response()->json([
                'id' => $validation->id,
                'html' => view('admin.nhl-validations._detail-content', [
                    'validation' => $validation,
                    'embedded' => true,
                ])->render(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json($validation);
        }

        return view('admin.nhl-validations.show', [
            'validation' => $validation,
        ]);
    }

    /**
     * Attach small PBP snippets to validation deltas for admin triage.
     */
    private function attachPbpContext(NhlGameValidation $validation): void
    {
        foreach ($validation->deltas as $delta) {
            $playerId = (int) ($delta->nhl_player_id ?? 0);

            if (!$playerId) {
                $delta->setAttribute('pbp_context', []);
                continue;
            }

            $query = PlayByPlay::query()
                ->where('nhl_game_id', $validation->nhl_game_id)
                ->orderBy('seconds_in_game')
                ->orderBy('sort_order')
                ->limit(12);

            match ($delta->field) {
                'penalty_minutes' => $query->where('committed_by_player_id', $playerId),
                'sog',
                'power_play_goals',
                'goals',
                'assists',
                'points' => $query->where(function ($events) use ($playerId): void {
                    $events->where('shooting_player_id', $playerId)
                        ->orWhere('scoring_player_id', $playerId)
                        ->orWhere('assist1_player_id', $playerId)
                        ->orWhere('assist2_player_id', $playerId);
                })->whereIn('type_desc_key', ['shot-on-goal', 'goal']),
                'plus_minus' => $query
                    ->where('type_desc_key', 'goal')
                    ->whereIn('id', function ($linkedEvents) use ($validation, $playerId): void {
                        $linkedEvents
                            ->select('eus.event_id')
                            ->from('event_unit_shifts as eus')
                            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
                            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'us.unit_id')
                            ->join('players as linked_players', 'linked_players.id', '=', 'up.player_id')
                            ->where('us.nhl_game_id', $validation->nhl_game_id)
                            ->where('linked_players.nhl_id', $playerId);
                    }),
                'saves',
                'shots_against',
                'goals_against',
                'ev_saves',
                'ev_shots_against',
                'ev_goals_against',
                'pp_saves',
                'pp_shots_against',
                'pp_goals_against',
                'pk_saves',
                'pk_shots_against',
                'pk_goals_against',
                'save_percentage' => $query
                    ->where('goalie_in_net_player_id', $playerId)
                    ->whereIn('type_desc_key', ['shot-on-goal', 'goal']),
                default => $query->whereRaw('1 = 0'),
            };

            if (str_starts_with($delta->field, 'ev_')) {
                $query->where('strength', 'EV');
            } elseif (str_starts_with($delta->field, 'pp_')) {
                $query->where('strength', 'PP');
            } elseif (str_starts_with($delta->field, 'pk_')) {
                $query->where('strength', 'PK');
            }

            $delta->setAttribute('pbp_context', $query->get()->map(fn (PlayByPlay $event): array => [
                'event_id' => $event->nhl_event_id,
                'period' => $event->period,
                'time' => $event->time_in_period,
                'type' => $event->type_desc_key,
                'detail' => $event->desc_key ?? $event->reason,
                'strength' => $event->strength,
                'situation_code' => $event->situation_code,
                'period_type' => $event->period_type,
                'raw_type_code' => $event->metadata['event']['typeCode'] ?? $event->type_code,
                'shot_type' => $event->shot_type,
                'goalie_in_net_player_id' => $event->goalie_in_net_player_id,
                'counts_as_sog' => $this->normalizer->isShotOnGoal($event),
                'provider_sog' => [
                    'away' => $event->metadata['details']['awaySOG'] ?? null,
                    'home' => $event->metadata['details']['homeSOG'] ?? null,
                ],
            ])->all());
        }
    }

    public function acceptException(
        Request $request,
        NhlGameValidation $validation,
        NhlImportOrchestrator $orchestrator
    ): RedirectResponse|JsonResponse
    {
        $validation->update([
            'status' => NhlGameValidation::STATUS_ACCEPTED_EXCEPTION,
            'approved_at' => now(),
            'approved_by' => $request->user()?->id,
        ]);

        $orchestrator->onSuccess(
            (int) $validation->nhl_game_id,
            NhlImportStages::VALIDATE_SUMMARY,
            ['items_count' => (int) $validation->mismatch_count]
        );

        if ($request->expectsJson()) {
            return response()->json($validation->refresh());
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', 'Validation accepted as an exception.');
    }

    public function rerun(
        Request $request,
        NhlGameValidation $validation,
        ValidateNhlGameSummary $validator,
        NhlImportProgressRepo $progress,
        NhlImportOrchestrator $orchestrator
    ): RedirectResponse|JsonResponse
    {
        $validation = $validator->validate((int) $validation->nhl_game_id);

        if ($validation->status === NhlGameValidation::STATUS_APPROVED) {
            $orchestrator->onSuccess(
                (int) $validation->nhl_game_id,
                NhlImportStages::VALIDATE_SUMMARY,
                ['items_count' => 0]
            );
        } else {
            $progress->markError(
                (int) $validation->nhl_game_id,
                NhlImportStages::VALIDATE_SUMMARY,
                "NHL game {$validation->nhl_game_id} summary validation failed with {$validation->mismatch_count} deltas."
            );
        }

        if ($request->expectsJson()) {
            return response()->json($validation->refresh());
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', 'Validation rerun.');
    }

    public function rerunSummary(
        Request $request,
        NhlGameValidation $validation,
        NhlImportProgressRepo $progress,
        NhlImportOrchestrator $orchestrator
    ): RedirectResponse|JsonResponse
    {
        $progress->reschedule((int) $validation->nhl_game_id, NhlImportStages::SUMMARY);
        $orchestrator->dispatchJob((int) $validation->nhl_game_id, NhlImportStages::SUMMARY);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'summary_queued']);
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', 'Summary rerun queued.');
    }

    public function rerunBoxscore(
        Request $request,
        NhlGameValidation $validation,
        NhlImportProgressRepo $progress,
        NhlImportOrchestrator $orchestrator
    ): RedirectResponse|JsonResponse
    {
        $progress->reschedule((int) $validation->nhl_game_id, NhlImportStages::BOXSCORE);
        $orchestrator->dispatchJob((int) $validation->nhl_game_id, NhlImportStages::BOXSCORE);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'boxscore_queued']);
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', 'Boxscore rerun queued.');
    }

    public function rebuildGame(
        Request $request,
        NhlGameValidation $validation,
        NhlGameImportRebuilder $rebuilder
    ): RedirectResponse|JsonResponse
    {
        $rebuilder->rebuild((int) $validation->nhl_game_id);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'game_rebuild_queued']);
        }

        return redirect()
            ->route('admin.nhl-validations.index')
            ->with('status', 'Full game rebuild queued from PBP.');
    }
}
