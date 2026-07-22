<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RebuildNhlGameImportJob;
use App\Models\NhlGameValidation;
use App\Models\PlayByPlay;
use App\Repositories\NhlImportProgressRepo;
use App\Services\NhlImportOrchestrator;
use App\Services\NhlPbpEventNormalizer;
use App\Services\NhlSourceOnlyPbpReview;
use App\Services\RebuildNhlEventShiftLinks;
use App\Services\ValidateNhlGameSummary;
use App\Services\VerifyNhlHtmlPlayByPlay;
use App\Support\NhlImportStages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NhlGameValidationController extends Controller
{
    public function __construct(private readonly NhlPbpEventNormalizer $normalizer)
    {
    }

    public function index(Request $request): View|JsonResponse
    {
        $status = $request->string('status', NhlGameValidation::STATUS_FAILED)->toString();
        $validationType = $request->string('validation_type')->toString();

        $query = NhlGameValidation::query()
            ->with('game')
            ->withCount('deltas')
            ->withCount('pbpSourceMismatches')
            ->latest('checked_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($validationType !== '') {
            $query->where('validation_type', $validationType);
        }

        $validations = $query->paginate(25)->withQueryString();

        if ($request->expectsJson() && $request->boolean('admin_panel')) {
            return response()->json([
                'html' => view('admin.nhl-validations._index-content', [
                    'validations' => $validations,
                    'status' => $status,
                    'validationType' => $validationType,
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
            'validationType' => $validationType,
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
            'pbpSourceMismatches' => fn ($query) => $query
                ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderBy('period')
                ->orderBy('time_in_period'),
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
     * Return the recent imported PBP games fragment for admin enrichment.
     */
    public function pbp(): JsonResponse
    {
        $latestPbpGames = DB::query()
            ->fromSub(
                DB::table('play_by_plays')
                    ->selectRaw('nhl_game_id, count(*) as events_count, max(updated_at) as last_pbp_at')
                    ->groupBy('nhl_game_id'),
                'pbp_games'
            )
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'pbp_games.nhl_game_id')
            ->leftJoin('nhl_game_validations as validations', function ($join): void {
                $join->on('validations.nhl_game_id', '=', 'pbp_games.nhl_game_id')
                    ->where('validations.validation_type', NhlGameValidation::TYPE_PBP_HTML_REPORT);
            })
            ->select([
                'pbp_games.nhl_game_id',
                'pbp_games.events_count',
                'pbp_games.last_pbp_at',
                'games.game_date',
                'games.away_team_abbrev',
                'games.home_team_abbrev',
                'validations.id as validation_id',
                'validations.status as validation_status',
                'validations.mismatch_count',
                'validations.checked_at',
            ])
            ->orderByDesc('pbp_games.last_pbp_at')
            ->limit(10)
            ->get();

        return response()->json([
            'html' => view('admin.nhl-validations._pbp-content', [
                'games' => $latestPbpGames,
            ])->render(),
        ]);
    }

    /**
     * Run HTML PBP enrichment for one imported game.
     */
    public function enrichPbp(int $gameId, VerifyNhlHtmlPlayByPlay $verifier): JsonResponse
    {
        abort_unless(
            DB::table('play_by_plays')->where('nhl_game_id', $gameId)->exists(),
            404
        );

        $eventsCount = $verifier->verify($gameId);
        $validation = NhlGameValidation::query()
            ->where('nhl_game_id', $gameId)
            ->where('validation_type', NhlGameValidation::TYPE_PBP_HTML_REPORT)
            ->first();

        return response()->json([
            'status' => $validation?->status,
            'mismatch_count' => $validation?->mismatch_count ?? 0,
            'events_count' => $eventsCount,
        ]);
    }

    /**
     * Rebuild shiftchart-derived event/unit links for one imported game.
     */
    public function rebuildEventShifts(int $gameId, RebuildNhlEventShiftLinks $rebuilder): JsonResponse
    {
        abort_unless(
            DB::table('play_by_plays')->where('nhl_game_id', $gameId)->exists(),
            404
        );

        return response()->json($rebuilder->rebuild($gameId));
    }

    /**
     * Return one source-only Full PBP review step.
     */
    public function fullPbp(Request $request, int $gameId, NhlSourceOnlyPbpReview $reviewer): JsonResponse
    {
        abort_unless(
            DB::table('play_by_plays')->where('nhl_game_id', $gameId)->exists(),
            404
        );

        $review = $reviewer->review($gameId, max(0, $request->integer('index')));

        return response()->json([
            'html' => view('admin.nhl-validations._full-pbp-content', [
                'review' => $review,
            ])->render(),
            'event_count' => $review['event_count'],
            'mismatch_count' => $review['mismatch_count'],
            'current_index' => $review['current_index'],
            'next_mismatch_index' => $review['next_mismatch_index'],
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
            'resolution' => 'accepted_exception',
        ]);

        if ($validation->validation_type === NhlGameValidation::TYPE_SUMMARY_BOXSCORE) {
            $orchestrator->onSuccess(
                (int) $validation->nhl_game_id,
                NhlImportStages::VALIDATE_SUMMARY,
                ['items_count' => (int) $validation->mismatch_count]
            );
        }

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

        if (in_array($validation->status, [
            NhlGameValidation::STATUS_APPROVED,
            NhlGameValidation::STATUS_INVALIDATED,
            NhlGameValidation::STATUS_SHIFTCHART_MISMATCH,
        ], true)) {
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
        NhlGameValidation $validation
    ): RedirectResponse|JsonResponse
    {
        RebuildNhlGameImportJob::dispatch((int) $validation->nhl_game_id);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'game_rebuild_queued']);
        }

        return redirect()
            ->route('admin.nhl-validations.index')
            ->with('status', 'Full game rebuild queued from PBP.');
    }

    public function rerunHtmlPbp(
        Request $request,
        NhlGameValidation $validation,
        VerifyNhlHtmlPlayByPlay $verifier
    ): RedirectResponse|JsonResponse
    {
        abort_unless($validation->validation_type === NhlGameValidation::TYPE_PBP_HTML_REPORT, 404);

        $verifier->verify((int) $validation->nhl_game_id);
        $validation = NhlGameValidation::query()
            ->where('nhl_game_id', $validation->nhl_game_id)
            ->where('validation_type', NhlGameValidation::TYPE_PBP_HTML_REPORT)
            ->firstOrFail();

        if ($request->expectsJson()) {
            return response()->json($validation);
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', 'HTML PBP verification rerun.');
    }

    public function acceptApiPbp(
        Request $request,
        NhlGameValidation $validation
    ): RedirectResponse|JsonResponse
    {
        return $this->resolvePbpValidation($request, $validation, 'accepted_api', 'API PBP accepted as canonical.');
    }

    public function acceptHtmlPbpPositions(
        Request $request,
        NhlGameValidation $validation
    ): RedirectResponse|JsonResponse
    {
        return $this->resolvePbpValidation($request, $validation, 'accepted_positions_only', 'HTML PBP positions accepted only.');
    }

    public function acknowledgePbpMismatch(
        Request $request,
        NhlGameValidation $validation
    ): RedirectResponse|JsonResponse
    {
        return $this->resolvePbpValidation($request, $validation, 'acknowledged', 'HTML/API PBP mismatch acknowledged.');
    }

    private function resolvePbpValidation(
        Request $request,
        NhlGameValidation $validation,
        string $resolution,
        string $message
    ): RedirectResponse|JsonResponse
    {
        abort_unless($validation->validation_type === NhlGameValidation::TYPE_PBP_HTML_REPORT, 404);

        $validation->update([
            'status' => NhlGameValidation::STATUS_ACCEPTED_EXCEPTION,
            'approved_at' => now(),
            'approved_by' => $request->user()?->id,
            'resolution' => $resolution,
            'resolution_note' => $request->string('note')->toString() ?: null,
        ]);

        if ($request->expectsJson()) {
            return response()->json($validation->refresh());
        }

        return redirect()
            ->route('admin.nhl-validations.show', $validation)
            ->with('status', $message);
    }
}
