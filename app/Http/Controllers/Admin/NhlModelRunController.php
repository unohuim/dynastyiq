<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\NhlSatModelUpdated;
use App\Jobs\BuildNhlSatModelEntityProfilesJob;
use App\Jobs\BuildNhlSatModelEntityRateComparisonsJob;
use App\Jobs\BuildNhlSatModelEntityRateProjectionsJob;
use App\Jobs\BuildNhlSatModelEntityToiProjectionsJob;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlExpectedGoalsModelBucket;
use App\Models\NhlModelRun;
use App\Services\NhlExpectedGoalsBackfiller;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CRUD surface for SAT model definitions.
 */
class NhlModelRunController extends Controller
{
    private const ENTITY_PROFILE_REVIEW_SHARE_COVERAGE = 0.6;

    /**
     * Show SAT models.
     */
    public function index(Request $request): View
    {
        $input = $request->validate([
            'status' => ['nullable', Rule::in(NhlModelRun::statuses())],
        ]);

        $query = NhlModelRun::query()
            ->where('model_family', NhlModelRun::FAMILY_SAT)
            ->where('workflow_stage', NhlModelRun::STAGE_TRAINING)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (($input['status'] ?? null) !== null && $input['status'] !== '') {
            $query->where('status', $input['status']);
        }

        $runs = $query->paginate(25)->withQueryString();

        return view('admin.nhl-sat-models.index', [
            'filters' => [
                'status' => $input['status'] ?? null,
            ],
            'seasonOptions' => $this->shotAttemptSeasonOptions(),
            'statuses' => NhlModelRun::statuses(),
            'comparisonStates' => $this->rateComparisonStatesForRuns($runs->getCollection()),
            'genericBucketStabilityStates' => $this->genericBucketStabilityStatesForRuns($runs->getCollection()),
            'toiProjectionStates' => $this->toiProjectionStatesForRuns($runs->getCollection()),
            'trainingDriftStates' => $this->trainingDriftStatesForRuns($runs->getCollection()),
            'trainingSummaries' => $this->trainingSummariesForRuns($runs->getCollection()),
            'runs' => $runs,
        ]);
    }

    /**
     * Store a SAT model definition.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'model_version' => ['required', 'string', 'max:80'],
            'train_season_ids' => ['required', 'array', 'min:1'],
            'train_season_ids.*' => ['required', 'digits:8'],
            'test_season_id' => ['nullable', 'digits:8'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trainSeasonIds = $this->seasonIdsFromArray($input['train_season_ids'] ?? []);

        $run = NhlModelRun::query()->create([
            'run_key' => $this->uniqueRunKey($input['name'], $input['model_version']),
            'name' => $input['name'],
            'model_family' => NhlModelRun::FAMILY_SAT,
            'workflow_stage' => NhlModelRun::STAGE_TRAINING,
            'model_version' => $input['model_version'],
            'train_start_season_id' => min($trainSeasonIds),
            'train_end_season_id' => max($trainSeasonIds),
            'train_season_ids' => $trainSeasonIds,
            'season_weights' => $this->defaultSeasonWeights($trainSeasonIds),
            'target_season_id' => $input['test_season_id'] ?? null,
            'status' => NhlModelRun::STATUS_DRAFT,
            'run_config' => [
                'created_from' => 'admin_nhl_sat_models',
                'execution_attached' => false,
            ],
            'notes' => $input['notes'] ?? null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Model created.',
                'row_html' => $this->renderRow($run),
            ], 201);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', 'Model created.');
    }

    /**
     * Evaluate SOG or SAT danger for a SAT model.
     */
    public function train(Request $request, NhlModelRun $run): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'evaluation' => ['nullable', Rule::in(['sog', 'sat'])],
            'smoothing_prior_attempts' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $evaluation = $input['evaluation'] ?? 'sog';
        $target = $evaluation === 'sat'
            ? NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL
            : NhlExpectedGoalsBackfiller::TARGET_GOAL;
        $workflowAction = $evaluation === 'sat' ? 'eval_sat' : 'eval_sog';
        $message = $evaluation === 'sat' ? 'Eval SAT complete.' : 'Eval SOG complete.';

        $trainSeasonIds = $this->seasonIdsFromArray($run->train_season_ids ?? []);

        if ($trainSeasonIds === []) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This model needs at least one Training Season before it can be trained.',
                ], 422);
            }

            return back()->withErrors(['run' => 'This model needs at least one Training Season before it can be trained.']);
        }

        $minimumBucketAttempts = 0;
        $smoothingPriorAttempts = (int) ($input['smoothing_prior_attempts'] ?? 100);
        $version = $this->trainingVersion($run);

        $run->forceFill([
            'status' => NhlModelRun::STATUS_RUNNING,
            'run_config' => array_merge($run->run_config ?? [], [
                'training_version' => $version,
                $workflowAction . '_version' => $version,
                'minimum_bucket_attempts' => $minimumBucketAttempts,
                'smoothing_prior_attempts' => $smoothingPriorAttempts,
            ]),
            'metrics' => array_merge($run->metrics ?? [], [
                $workflowAction . '_started_at' => now()->toIso8601String(),
                'training_targets' => [$target],
                $workflowAction . '_targets' => [$target],
            ]),
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        app(NhlExpectedGoalsBackfiller::class)->trainBucketsForRun(
            run: $run->fresh(),
            version: $version,
            minimumBucketAttempts: $minimumBucketAttempts,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: false,
            predictionTarget: $target
        );

        $model = $this->bucketModelForTarget($run->fresh(), $target);
        $metrics = array_filter([
            'trained_at' => now()->toIso8601String(),
            'training_total_sat' => data_get($model?->metrics, 'training_total_sat'),
            'training_total_sog' => data_get($model?->metrics, 'training_total_sog'),
            'training_attempts' => data_get($model?->metrics, 'training_attempts'),
            'training_excluded_sat' => data_get($model?->metrics, 'training_excluded_sat'),
            'training_excluded_sog' => data_get($model?->metrics, 'training_excluded_sog'),
            'training_excluded_sat_rate' => data_get($model?->metrics, 'training_excluded_sat_rate'),
            'training_excluded_sog_rate' => data_get($model?->metrics, 'training_excluded_sog_rate'),
            'sat_factor_evaluation' => data_get($model?->metrics, 'sat_factor_evaluation'),
            'sog_factor_evaluation' => data_get($model?->metrics, 'sog_factor_evaluation'),
        ], static fn (mixed $value): bool => $value !== null);

        $run->forceFill([
            'status' => NhlModelRun::STATUS_COMPLETE,
            'metrics' => array_merge($run->metrics ?? [], $metrics, [
                $workflowAction . '_completed_at' => now()->toIso8601String(),
            ]),
            'completed_at' => now(),
        ])->save();

        try {
            broadcast(new NhlSatModelUpdated((int) $run->id, $evaluation === 'sat' ? 'sat-eval-completed' : 'sog-eval-completed'));
        } catch (\Throwable) {
            // The Ajax response already carries the updated row; broadcast failure should not fail the eval.
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'row_html' => $this->renderRow($run),
            ]);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', $message);
    }

    /**
     * Show trained probability buckets for a SAT model.
     */
    public function buckets(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $sorts = $this->bucketSorts();
        $input = $request->validate([
            'target' => ['nullable', Rule::in([
                NhlExpectedGoalsBackfiller::TARGET_GOAL,
                NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            ])],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $target = $input['target'] ?? NhlExpectedGoalsBackfiller::TARGET_GOAL;
        $sort = $input['sort'] ?? 'smoothed_probability';
        $direction = $input['direction'] ?? 'desc';
        $model = $this->bucketModelForTarget($run, $target);

        $bucketQuery = NhlExpectedGoalsModelBucket::query()
            ->whereRaw('1 = 0');

        if ($model !== null) {
            $bucketQuery = NhlExpectedGoalsModelBucket::query()
                ->where('expected_goals_model_id', $model->id);
        }

        $buckets = $bucketQuery
            ->orderBy($sorts[$sort], $direction)
            ->orderByDesc('attempts')
            ->orderBy('bucket_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.buckets', [
            'buckets' => $buckets,
            'direction' => $direction,
            'model' => $model,
            'run' => $run,
            'sort' => $sort,
            'sorts' => $sorts,
            'target' => $target,
            'factorEvaluation' => $this->factorEvaluationForModel($model, $target),
            'trainingSummary' => $this->trainingSummaryForModel($model, $run, $target),
            'targets' => [
                NhlExpectedGoalsBackfiller::TARGET_GOAL => 'SOG Danger',
                NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL => 'SAT Danger',
            ],
        ]);
    }

    /**
     * Build model-run entity SAT profiles.
     */
    public function buildProfiles(Request $request, NhlModelRun $run): RedirectResponse|JsonResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $satModel = $this->bucketModelForTarget($run, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);
        $sogModel = $this->bucketModelForTarget($run, NhlExpectedGoalsBackfiller::TARGET_GOAL);

        if ($run->status === NhlModelRun::STATUS_RUNNING) {
            $message = 'This model already has work running.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($run->target_season_id !== null && ! Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')) {
            $message = 'Run migrations before building test profiles.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($satModel === null) {
            $message = 'Eval SAT before building profiles.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        $run->forceFill([
            'status' => NhlModelRun::STATUS_RUNNING,
            'metrics' => array_merge($run->metrics ?? [], [
                'profiles_started_at' => now()->toIso8601String(),
            ]),
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        BuildNhlSatModelEntityProfilesJob::dispatch(
            modelRunId: (int) $run->id,
            satModelId: (int) $satModel->id,
            sogModelId: $sogModel === null ? null : (int) $sogModel->id
        );

        try {
            broadcast(new NhlSatModelUpdated((int) $run->id, 'profiles-queued'));
        } catch (\Throwable) {
            // The Ajax response already carries the updated row; broadcast failure should not fail queueing.
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Profiles queued.',
                'row_html' => $this->renderRow($run),
            ]);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', 'Profiles queued.');
    }

    /**
     * Build model-run entity /60 projections from SAT profiles.
     */
    public function buildRateProjections(Request $request, NhlModelRun $run): RedirectResponse|JsonResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        if (! Schema::hasTable('nhl_sat_model_entity_rate_projection_buckets')) {
            $message = 'Run migrations before building /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if (! DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $run->id)->exists()) {
            $message = 'Build profiles before building /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($run->status === NhlModelRun::STATUS_RUNNING) {
            $message = 'This model already has work running.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        $run->forceFill([
            'status' => NhlModelRun::STATUS_RUNNING,
            'metrics' => array_merge($run->metrics ?? [], [
                'rate_projections_started_at' => now()->toIso8601String(),
            ]),
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        BuildNhlSatModelEntityRateProjectionsJob::dispatch(modelRunId: (int) $run->id);

        try {
            broadcast(new NhlSatModelUpdated((int) $run->id, 'rate-projections-queued'));
        } catch (\Throwable) {
            // The Ajax response already carries the updated row; broadcast failure should not fail queueing.
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Queued /60.',
                'row_html' => $this->renderRow($run),
            ]);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', 'Queued /60.');
    }

    /**
     * Build model-run entity TOI projections from SAT model training seasons.
     */
    public function buildToiProjections(Request $request, NhlModelRun $run): RedirectResponse|JsonResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        if (! Schema::hasTable('nhl_sat_model_entity_toi_projections')) {
            $message = 'Run migrations before building TOI.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if (! DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $run->id)->exists()) {
            $message = 'Build profiles before building TOI.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($run->status === NhlModelRun::STATUS_RUNNING) {
            $message = 'This model already has work running.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        $run->forceFill([
            'status' => NhlModelRun::STATUS_RUNNING,
            'metrics' => array_merge($run->metrics ?? [], [
                'toi_projections_started_at' => now()->toIso8601String(),
            ]),
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        BuildNhlSatModelEntityToiProjectionsJob::dispatch(modelRunId: (int) $run->id);

        try {
            broadcast(new NhlSatModelUpdated((int) $run->id, 'toi-projections-queued'));
        } catch (\Throwable) {
            // The Ajax response already carries the updated row; broadcast failure should not fail queueing.
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Queued TOI.',
                'row_html' => $this->renderRow($run),
            ]);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', 'Queued TOI.');
    }

    /**
     * Queue model-run entity /60 comparisons from projections and test profiles.
     */
    public function buildRateComparisons(Request $request, NhlModelRun $run): RedirectResponse|JsonResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        if (
            ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_buckets')
            || ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
        ) {
            $message = 'Run migrations before comparing /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($run->target_season_id === null) {
            $message = 'Choose a test season before comparing /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if (! DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $run->id)->exists()) {
            $message = 'Build /60 before comparing /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if (! DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $run->target_season_id)
            ->exists()
        ) {
            $message = 'Build test profiles before comparing /60.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        if ($run->status === NhlModelRun::STATUS_RUNNING) {
            $message = 'This model already has work running.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['run' => $message]);
        }

        $run->forceFill([
            'status' => NhlModelRun::STATUS_RUNNING,
            'metrics' => array_merge($run->metrics ?? [], [
                'rate_comparisons_started_at' => now()->toIso8601String(),
            ]),
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        BuildNhlSatModelEntityRateComparisonsJob::dispatch(modelRunId: (int) $run->id);

        try {
            broadcast(new NhlSatModelUpdated((int) $run->id, 'rate-comparisons-queued'));
        } catch (\Throwable) {
            // The Ajax response already carries the updated row; broadcast failure should not fail queueing.
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Queued Compare /60.',
                'row_html' => $this->renderRow($run),
            ]);
        }

        return redirect()
            ->route('admin.nhl-sat-models.index')
            ->with('status', 'Queued Compare /60.');
    }

    /**
     * Show entity SAT profiles for a SAT model.
     */
    public function profiles(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->profileSorts();
        $hasShrinkageWeight = Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'shrinkage_weight');
        $hasPer60 = Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'source_xsat_per_60');

        if (! $hasShrinkageWeight) {
            $sorts['shrinkage_weight'] = 'confidence_score';
        }

        if (! $hasPer60) {
            $sorts['source_xsat_per_60'] = 'source_sat';
            $sorts['source_xsog_per_60'] = 'expected_sog';
            $sorts['source_xg_per_60'] = 'expected_goals';
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'include_long_tail' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'source_sat';
        $direction = $input['direction'] ?? 'desc';
        $includeLongTail = (bool) ($input['include_long_tail'] ?? false);
        $search = trim((string) ($input['q'] ?? ''));
        $summary = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('COUNT(DISTINCT entity_key) as entities')
            ->selectRaw('SUM(source_sat) as source_sat')
            ->selectRaw('SUM(source_sog) as source_sog')
            ->selectRaw('SUM(source_goals) as source_goals')
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');
        $profileAverages = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->selectRaw('AVG(source_sat) as source_sat')
            ->selectRaw('AVG(source_sog) as source_sog')
            ->selectRaw('AVG(source_goals) as source_goals')
            ->selectRaw('AVG(source_profile_share) as source_profile_share')
            ->selectRaw($hasPer60 ? 'AVG(source_xsat_per_60) as source_xsat_per_60' : '0::numeric as source_xsat_per_60')
            ->selectRaw($hasPer60 ? 'AVG(source_xsog_per_60) as source_xsog_per_60' : '0::numeric as source_xsog_per_60')
            ->selectRaw($hasPer60 ? 'AVG(source_xg_per_60) as source_xg_per_60' : '0::numeric as source_xg_per_60')
            ->selectRaw('AVG(expected_sog) as expected_sog')
            ->selectRaw('AVG(expected_goals) as expected_goals')
            ->selectRaw('AVG(sat_probability) as sat_probability')
            ->selectRaw('AVG(goal_probability) as goal_probability')
            ->selectRaw('AVG(confidence_score) as confidence_score')
            ->selectRaw($hasShrinkageWeight ? 'AVG(shrinkage_weight) as shrinkage_weight' : '0::numeric as shrinkage_weight')
            ->first();

        $profileRows = DB::table('nhl_sat_model_entity_profile_buckets as profile_rows')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('entity_name', 'ilike', $like)
                        ->orWhere('entity_key', 'ilike', $like)
                        ->orWhere('matched_bucket_key', 'ilike', $like);
                });
            })
            ->selectRaw($hasShrinkageWeight ? 'profile_rows.*' : 'profile_rows.*, 0::numeric as shrinkage_weight')
            ->when(! $hasPer60, function ($query): void {
                $query
                    ->selectRaw('NULL::numeric as source_toi_seconds')
                    ->selectRaw('NULL::numeric as source_xsat_per_60')
                    ->selectRaw('NULL::numeric as source_xsog_per_60')
                    ->selectRaw('NULL::numeric as source_xg_per_60');
            })
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY entity_key ORDER BY source_profile_share DESC, source_sat DESC, matched_bucket_key) as profile_share_rank')
            ->selectRaw('SUM(source_profile_share) OVER (PARTITION BY entity_key ORDER BY source_profile_share DESC, source_sat DESC, matched_bucket_key ROWS UNBOUNDED PRECEDING) as cumulative_profile_share');

        $profiles = DB::query()
            ->fromSub($profileRows, 'profiles')
            ->when(! $includeLongTail, function ($query): void {
                $query->where(function ($coreQuery): void {
                    $coreQuery
                        ->where('profile_share_rank', 1)
                        ->orWhere(function ($rankedQuery): void {
                            $rankedQuery
                                ->where('profile_share_rank', '<=', 6)
                                ->where(function ($shareQuery): void {
                                    $shareQuery
                                        ->where('cumulative_profile_share', '<=', self::ENTITY_PROFILE_REVIEW_SHARE_COVERAGE)
                                        ->orWhereRaw(
                                            '(cumulative_profile_share - source_profile_share) < ?',
                                            [self::ENTITY_PROFILE_REVIEW_SHARE_COVERAGE]
                                        );
                                });
                        });
                });
            })
            ->when(! $includeLongTail, function ($query): void {
                $query->where('source_sat', '>=', 2);
            })
            ->orderBy($sorts[$sort], $direction)
            ->orderByDesc('source_sat')
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.profiles', [
            'direction' => $direction,
            'includeLongTail' => $includeLongTail,
            'profileAverages' => $profileAverages,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'profiles' => $profiles,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Show latest-training-season drift against aggregate entity SAT profiles.
     */
    public function trainingDrift(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->trainingDriftSorts();
        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);

        if (
            $latestTrainingSeasonId === null
            || ! Schema::hasTable('nhl_sat_model_entity_profile_buckets')
            || ! Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')
        ) {
            $drifts = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.training-drift', [
                'collectionRows' => collect(),
                'direction' => 'desc',
                'drifts' => $drifts,
                'latestTrainingSeasonId' => $latestTrainingSeasonId,
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'run' => $run,
                'search' => '',
                'sort' => 'xsat_drift_abs',
                'sorts' => $sorts,
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'xsat_drift_abs';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));

        $summary = DB::table('nhl_sat_model_entity_profile_buckets as training')
            ->leftJoin('nhl_sat_model_entity_test_profile_buckets as latest', function ($join) use ($latestTrainingSeasonId): void {
                $join
                    ->on('latest.model_run_id', '=', 'training.model_run_id')
                    ->on('latest.profile_type', '=', 'training.profile_type')
                    ->on('latest.entity_key', '=', 'training.entity_key')
                    ->on('latest.matched_bucket_key', '=', 'training.matched_bucket_key')
                    ->where('latest.test_season_id', '=', $latestTrainingSeasonId);
            })
            ->where('training.model_run_id', $run->id)
            ->selectRaw('training.profile_type')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('COUNT(DISTINCT training.entity_key) as entities')
            ->selectRaw('COUNT(*) FILTER (WHERE latest.id IS NOT NULL) as matched_rows')
            ->selectRaw('SUM(training.source_sat) as train_sat')
            ->selectRaw('SUM(latest.source_sat) as latest_sat')
            ->groupBy('training.profile_type')
            ->get()
            ->keyBy('profile_type');
        $collectionRows = $this->trainingDriftCollectionRows(
            run: $run,
            profileType: $profileType,
            latestTrainingSeasonId: $latestTrainingSeasonId
        );
        $trainGameRows = $this->trainingDriftEntityGameRows(
            profileType: $profileType,
            seasonIds: $this->seasonIdsFromArray($run->train_season_ids ?? [])
        );
        $latestGameRows = $this->trainingDriftEntityGameRows(
            profileType: $profileType,
            seasonIds: [$latestTrainingSeasonId]
        );

        $driftRows = DB::table('nhl_sat_model_entity_profile_buckets as training')
            ->leftJoin('nhl_sat_model_entity_test_profile_buckets as latest', function ($join) use ($latestTrainingSeasonId): void {
                $join
                    ->on('latest.model_run_id', '=', 'training.model_run_id')
                    ->on('latest.profile_type', '=', 'training.profile_type')
                    ->on('latest.entity_key', '=', 'training.entity_key')
                    ->on('latest.matched_bucket_key', '=', 'training.matched_bucket_key')
                    ->where('latest.test_season_id', '=', $latestTrainingSeasonId);
            })
            ->leftJoinSub($trainGameRows, 'train_games', 'train_games.entity_key', '=', 'training.entity_key')
            ->leftJoinSub($latestGameRows, 'latest_games', 'latest_games.entity_key', '=', 'training.entity_key')
            ->where('training.model_run_id', $run->id)
            ->where('training.profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('training.entity_name', 'ilike', $like)
                        ->orWhere('training.entity_key', 'ilike', $like);
                });
            })
            ->selectRaw('training.profile_type')
            ->selectRaw('training.entity_key')
            ->selectRaw('MAX(training.entity_id) as entity_id')
            ->selectRaw('MAX(training.entity_name) as entity_name')
            ->selectRaw('MAX(training.entity_role) as entity_role')
            ->selectRaw('MAX(training.team_context) as team_context')
            ->selectRaw('COUNT(*) as bucket_rows')
            ->selectRaw('COUNT(*) FILTER (WHERE latest.id IS NOT NULL) as matched_bucket_rows')
            ->selectRaw('MAX(COALESCE(train_games.games, 0)) as train_games')
            ->selectRaw('MAX(COALESCE(latest_games.games, 0)) as latest_games')
            ->selectRaw('SUM(training.source_sat)::numeric / NULLIF(MAX(COALESCE(train_games.games, 0)), 0) as train_sat')
            ->selectRaw('SUM(training.source_sog)::numeric / NULLIF(MAX(COALESCE(train_games.games, 0)), 0) as train_sog')
            ->selectRaw('SUM(training.source_goals)::numeric / NULLIF(MAX(COALESCE(train_games.games, 0)), 0) as train_goals')
            ->selectRaw('SUM(training.source_profile_share) as train_profile_share')
            ->selectRaw('SUM(training.source_xsat_per_60) as train_xsat_per_60')
            ->selectRaw('SUM(training.source_xsog_per_60) as train_xsog_per_60')
            ->selectRaw('SUM(training.source_xg_per_60) as train_xg_per_60')
            ->selectRaw('SUM(COALESCE(latest.source_sat, 0))::numeric / NULLIF(MAX(COALESCE(latest_games.games, 0)), 0) as latest_sat')
            ->selectRaw('SUM(COALESCE(latest.source_sog, 0))::numeric / NULLIF(MAX(COALESCE(latest_games.games, 0)), 0) as latest_sog')
            ->selectRaw('SUM(COALESCE(latest.source_goals, 0))::numeric / NULLIF(MAX(COALESCE(latest_games.games, 0)), 0) as latest_goals')
            ->selectRaw('SUM(latest.source_profile_share) as latest_profile_share')
            ->selectRaw('SUM(latest.source_xsat_per_60) as latest_xsat_per_60')
            ->selectRaw('SUM(latest.source_xsog_per_60) as latest_xsog_per_60')
            ->selectRaw('SUM(latest.source_xg_per_60) as latest_xg_per_60')
            ->selectRaw('(SUM(latest.source_profile_share) - SUM(training.source_profile_share)) as share_drift')
            ->selectRaw('CASE WHEN ABS(SUM(training.source_profile_share)) > 0 THEN (SUM(latest.source_profile_share) - SUM(training.source_profile_share)) / ABS(SUM(training.source_profile_share)) ELSE NULL END as share_drift_rate')
            ->selectRaw('(SUM(latest.source_xsat_per_60) - SUM(training.source_xsat_per_60)) as xsat_drift')
            ->selectRaw('ABS(SUM(latest.source_xsat_per_60) - SUM(training.source_xsat_per_60)) as xsat_drift_abs')
            ->selectRaw('CASE WHEN ABS(SUM(training.source_xsat_per_60)) > 0 THEN (SUM(latest.source_xsat_per_60) - SUM(training.source_xsat_per_60)) / ABS(SUM(training.source_xsat_per_60)) ELSE NULL END as xsat_drift_rate')
            ->selectRaw('(SUM(latest.source_xsog_per_60) - SUM(training.source_xsog_per_60)) as xsog_drift')
            ->selectRaw('ABS(SUM(latest.source_xsog_per_60) - SUM(training.source_xsog_per_60)) as xsog_drift_abs')
            ->selectRaw('CASE WHEN ABS(SUM(training.source_xsog_per_60)) > 0 THEN (SUM(latest.source_xsog_per_60) - SUM(training.source_xsog_per_60)) / ABS(SUM(training.source_xsog_per_60)) ELSE NULL END as xsog_drift_rate')
            ->selectRaw('(SUM(latest.source_xg_per_60) - SUM(training.source_xg_per_60)) as xg_drift')
            ->selectRaw('ABS(SUM(latest.source_xg_per_60) - SUM(training.source_xg_per_60)) as xg_drift_abs')
            ->selectRaw('CASE WHEN ABS(SUM(training.source_xg_per_60)) > 0 THEN (SUM(latest.source_xg_per_60) - SUM(training.source_xg_per_60)) / ABS(SUM(training.source_xg_per_60)) ELSE NULL END as xg_drift_rate')
            ->groupBy('training.profile_type', 'training.entity_key');

        $drifts = DB::query()
            ->fromSub($driftRows, 'drifts')
            ->orderByRaw($sorts[$sort] . ' ' . $direction . ' NULLS LAST')
            ->orderByDesc('train_sat')
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.training-drift', [
            'collectionRows' => $collectionRows,
            'direction' => $direction,
            'drifts' => $drifts,
            'latestTrainingSeasonId' => $latestTrainingSeasonId,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Show generic profile-bucket stability across S1, S2, and S3.
     */
    public function genericBucketStability(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->genericBucketStabilitySorts();

        if (! Schema::hasTable('nhl_sat_model_generic_bucket_stabilities')) {
            $rows = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.bucket-stability', [
                'direction' => 'desc',
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'rows' => $rows,
                'run' => $run,
                'search' => '',
                'sort' => 's3_s2',
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 's3_s2';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));

        $summary = DB::table('nhl_sat_model_generic_bucket_stabilities')
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('SUM(train_sat) as train_sat')
            ->selectRaw('SUM(latest_sat) as latest_sat')
            ->selectRaw('SUM(test_sat) as test_sat')
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');

        $rows = $this->genericBucketStabilityQuery($run, $profileType, $search)
            ->orderBy($sorts[$sort], $direction)
            ->orderByDesc('train_sat')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.bucket-stability', [
            'direction' => $direction,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'rows' => $rows,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'summary' => $summary,
        ]);
    }

    /**
     * Download generic profile-bucket stability rows.
     */
    public function exportGenericBucketStability(Request $request, NhlModelRun $run): StreamedResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );
        abort_unless(Schema::hasTable('nhl_sat_model_generic_bucket_stabilities'), 404);

        $profileTypes = $this->profileTypes();
        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $search = trim((string) ($input['q'] ?? ''));
        $rows = $this->genericBucketStabilityQuery($run, $profileType, $search)
            ->orderByDesc('test_minus_latest_xsat_per_60')
            ->orderByDesc('train_sat')
            ->get();
        $filename = Str::slug($run->name . '-' . $profileType . '-bucket-stability') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'profile_type',
                'bucket_key',
                'bucket_dimensions',
                'train_entities',
                'prior_entities',
                'latest_entities',
                'test_entities',
                'train_sat',
                'prior_sat',
                'latest_sat',
                'test_sat',
                'train_xsat_60',
                'prior_xsat_60',
                'latest_xsat_60',
                'test_xsat_60',
                's2_minus_s1',
                's3_minus_s2',
                's3_minus_train',
                's2_minus_s1_pct',
                's3_minus_s2_pct',
                's3_minus_train_pct',
                's2_direction',
                's3_direction',
                'reversed_after_s2',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->profile_type,
                    $row->matched_bucket_key,
                    is_string($row->bucket_dimensions ?? null) ? $row->bucket_dimensions : json_encode($row->bucket_dimensions),
                    $row->train_entity_count,
                    $row->prior_entity_count,
                    $row->latest_entity_count,
                    $row->test_entity_count,
                    $row->train_sat,
                    $row->prior_sat,
                    $row->latest_sat,
                    $row->test_sat,
                    $row->train_xsat_per_60,
                    $row->prior_xsat_per_60,
                    $row->latest_xsat_per_60,
                    $row->test_xsat_per_60,
                    $row->latest_minus_prior_xsat_per_60,
                    $row->test_minus_latest_xsat_per_60,
                    $row->test_minus_train_xsat_per_60,
                    $row->latest_minus_prior_xsat_rate,
                    $row->test_minus_latest_xsat_rate,
                    $row->test_minus_train_xsat_rate,
                    $row->latest_direction,
                    $row->test_direction,
                    $row->reversed_after_latest,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Show entity /60 projections for a SAT model.
     */
    public function rateProjections(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->rateProjectionSorts();

        if (! Schema::hasTable('nhl_sat_model_entity_rate_projection_buckets')) {
            $projections = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.rate-projections', [
                'direction' => 'desc',
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'projections' => $projections,
                'run' => $run,
                'search' => '',
                'sort' => 'projected_xsat_per_60',
                'sorts' => $sorts,
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'projected_xsat_per_60';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));
        $summary = DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('COUNT(DISTINCT entity_key) as entities')
            ->selectRaw('SUM(source_sat) as source_sat')
            ->selectRaw('SUM(projected_xsat_per_60) as projected_xsat_per_60')
            ->selectRaw('SUM(projected_xsog_per_60) as projected_xsog_per_60')
            ->selectRaw('SUM(projected_xg_per_60) as projected_xg_per_60')
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');

        $projections = DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('entity_name', 'ilike', $like)
                        ->orWhere('entity_key', 'ilike', $like)
                        ->orWhere('matched_bucket_key', 'ilike', $like);
                });
            })
            ->orderBy($sorts[$sort], $direction)
            ->orderByDesc('source_sat')
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.rate-projections', [
            'direction' => $direction,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'projections' => $projections,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Show entity TOI projections for a SAT model.
     */
    public function toiProjections(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = [
            'skater_offense' => 'Skater Offense',
            'skater_defense' => 'Skater Defense',
        ];
        $sorts = $this->toiProjectionSorts();

        if (! Schema::hasTable('nhl_sat_model_entity_toi_projections')) {
            $projections = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.toi-projections', [
                'direction' => 'desc',
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'projections' => $projections,
                'run' => $run,
                'search' => '',
                'sort' => 'projected_toi_per_game_seconds',
                'sorts' => $sorts,
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'projected_toi_per_game_seconds';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));
        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $trainingSeasonIds = $this->seasonIdsFromArray($run->train_season_ids ?? []);
        $summary = DB::table('nhl_sat_model_entity_toi_projections')
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as entities')
            ->selectRaw('SUM(projected_games) as projected_games')
            ->selectRaw('SUM(projected_toi_seconds) as projected_toi_seconds')
            ->selectRaw('AVG(projected_toi_per_game_seconds) as projected_toi_per_game_seconds')
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');
        $profileTrainToiSubquery = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->selectRaw('profile_type')
            ->selectRaw('entity_key')
            ->selectRaw('MAX(source_toi_seconds) as profile_train_toi_seconds')
            ->groupBy('profile_type', 'entity_key');
        $profileLatestToiSubquery = DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->where('test_season_id', (string) $latestTrainingSeasonId)
            ->selectRaw('profile_type')
            ->selectRaw('entity_key')
            ->selectRaw('MAX(source_toi_seconds) as profile_latest_toi_seconds')
            ->groupBy('profile_type', 'entity_key');
        $profileTrainGameRows = $this->trainingDriftEntityGameRows($profileType, $trainingSeasonIds);
        $profileLatestGameRows = $latestTrainingSeasonId === null
            ? DB::query()
                ->fromRaw("(SELECT NULL::varchar as entity_key, 0::integer as games) as entity_games")
                ->whereRaw('1 = 0')
            : $this->trainingDriftEntityGameRows($profileType, [$latestTrainingSeasonId]);

        $projections = DB::table('nhl_sat_model_entity_toi_projections as toi_projections')
            ->leftJoinSub($profileTrainToiSubquery, 'profile_train_toi', function ($join): void {
                $join
                    ->on('profile_train_toi.profile_type', '=', 'toi_projections.profile_type')
                    ->on('profile_train_toi.entity_key', '=', 'toi_projections.entity_key');
            })
            ->leftJoinSub($profileLatestToiSubquery, 'profile_latest_toi', function ($join): void {
                $join
                    ->on('profile_latest_toi.profile_type', '=', 'toi_projections.profile_type')
                    ->on('profile_latest_toi.entity_key', '=', 'toi_projections.entity_key');
            })
            ->leftJoinSub($profileTrainGameRows, 'profile_train_games', 'profile_train_games.entity_key', '=', 'toi_projections.entity_key')
            ->leftJoinSub($profileLatestGameRows, 'profile_latest_games', 'profile_latest_games.entity_key', '=', 'toi_projections.entity_key')
            ->where('toi_projections.model_run_id', $run->id)
            ->where('toi_projections.profile_type', $profileType)
            ->select('toi_projections.*')
            ->addSelect([
                'profile_train_toi.profile_train_toi_seconds',
                'profile_latest_toi.profile_latest_toi_seconds',
                'profile_train_games.games as profile_train_games',
                'profile_latest_games.games as profile_latest_games',
            ])
            ->selectRaw(
                'CASE
                    WHEN profile_train_toi.profile_train_toi_seconds IS NOT NULL
                        AND profile_latest_toi.profile_latest_toi_seconds IS NOT NULL
                        AND profile_train_games.games IS NOT NULL
                        AND profile_latest_games.games IS NOT NULL
                        AND (profile_train_games.games - profile_latest_games.games) > 0
                    THEN GREATEST(0, (profile_train_toi.profile_train_toi_seconds - profile_latest_toi.profile_latest_toi_seconds) / NULLIF(profile_train_games.games - profile_latest_games.games, 0))
                    ELSE NULL
                END as s1_toi_per_game_seconds'
            )
            ->selectRaw(
                'CASE
                    WHEN profile_latest_toi.profile_latest_toi_seconds IS NOT NULL
                        AND profile_latest_games.games IS NOT NULL
                        AND profile_latest_games.games > 0
                    THEN profile_latest_toi.profile_latest_toi_seconds / profile_latest_games.games
                    ELSE NULL
                END as s2_toi_per_game_seconds'
            )
            ->selectRaw(
                'CASE
                    WHEN profile_train_toi.profile_train_toi_seconds IS NOT NULL
                        AND profile_train_games.games IS NOT NULL
                        AND profile_train_games.games > 0
                    THEN profile_train_toi.profile_train_toi_seconds / profile_train_games.games
                    ELSE NULL
                END as profile_train_toi_per_game_seconds'
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('toi_projections.entity_name', 'ilike', $like)
                        ->orWhere('toi_projections.entity_key', 'ilike', $like)
                        ->orWhere('toi_projections.position', 'ilike', $like);
                });
            })
            ->orderBy($sorts[$sort], $direction)
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.toi-projections', [
            'direction' => $direction,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'projections' => $projections,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Show raw bucket-level held-out test-season /60 comparison rows.
     */
    public function compareRateProjectionsRaw(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->rateProjectionComparisonSorts();

        if (
            ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_buckets')
            || ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
        ) {
            $comparisons = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.rate-projection-comparison', [
                'comparisonState' => $this->rateComparisonStateForRun($run),
                'comparisons' => $comparisons,
                'direction' => 'desc',
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'run' => $run,
                'search' => '',
                'sort' => 'projected_xsat_per_60',
                'sorts' => $sorts,
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'projected_xsat_per_60';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));
        $testSeasonId = (string) ($run->target_season_id ?? '');

        $summary = DB::table('nhl_sat_model_entity_rate_comparison_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $testSeasonId)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('COUNT(DISTINCT entity_key) as entities')
            ->selectRaw('COUNT(*) FILTER (WHERE test_profile_share IS NOT NULL) as matched_rows')
            ->selectRaw('SUM(projected_xsat_per_60) as projected_xsat_per_60')
            ->selectRaw('SUM(test_xsat_per_60) as test_xsat_per_60')
            ->selectRaw('SUM(projected_xsog_per_60) as projected_xsog_per_60')
            ->selectRaw('SUM(test_xsog_per_60) as test_xsog_per_60')
            ->selectRaw('SUM(projected_xg_per_60) as projected_xg_per_60')
            ->selectRaw('SUM(test_xg_per_60) as test_xg_per_60')
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');

        $comparisons = DB::table('nhl_sat_model_entity_rate_comparison_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $testSeasonId)
            ->where('profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('entity_name', 'ilike', $like)
                        ->orWhere('entity_key', 'ilike', $like)
                        ->orWhere('matched_bucket_key', 'ilike', $like);
                });
            })
            ->orderByRaw($sorts[$sort] . ' ' . $direction . ' NULLS LAST')
            ->orderByDesc('train_sat')
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.rate-projection-comparison', [
            'comparisonState' => $this->rateComparisonStateForRun($run),
            'comparisons' => $comparisons,
            'direction' => $direction,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Show aggregate entity-level held-out test-season /60 comparison rows.
     */
    public function compareRateProjectionsAggregate(Request $request, NhlModelRun $run): View
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );

        $profileTypes = $this->profileTypes();
        $sorts = $this->rateProjectionComparisonSorts();
        $hasHdsatColumns = $this->hasAggregateComparisonHdsatColumns();
        $hasEvalColumns = $this->hasAggregateComparisonEvalColumns();

        if (! $hasHdsatColumns) {
            $sorts = array_diff_key($sorts, array_flip([
                'train_hdsat',
                'test_hdsat',
                'train_hdsat_per_60',
                'test_hdsat_per_60',
                'hdsat_drift',
                'hdsat_drift_rate',
            ]));
        }

        if (! $hasEvalColumns) {
            $sorts = array_diff_key($sorts, array_flip([
                'train_eval_sat_per_60',
                'test_eval_sat_per_60',
                'train_eval_hdsat_per_60',
                'test_eval_hdsat_per_60',
                'train_eval_hdsat_sat_rate',
                'test_eval_hdsat_sat_rate',
                'train_eval_sog_per_60',
                'test_eval_sog_per_60',
                'train_eval_goals_per_60',
                'test_eval_goals_per_60',
                'train_eval_toi_per_gp',
                'test_eval_toi_per_gp',
            ]));
        }

        if (! Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')) {
            $aggregates = DB::table('nhl_model_runs')->whereRaw('1 = 0')->paginate(50);

            return view('admin.nhl-sat-models.rate-projection-aggregate-comparison', [
                'aggregates' => $aggregates,
                'collectionRows' => collect(),
                'comparisonState' => $this->rateComparisonStateForRun($run),
                'direction' => 'desc',
                'profileType' => 'skater_offense',
                'profileTypes' => $profileTypes,
                'run' => $run,
                'search' => '',
                'sort' => 'xsat_error',
                'sorts' => $sorts,
                'summary' => collect(),
            ]);
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'xsat_error';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));
        $testSeasonId = (string) ($run->target_season_id ?? '');
        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $ageDate = preg_match('/^\d{8}$/', $testSeasonId) === 1
            ? substr($testSeasonId, 0, 4) . '-10-01'
            : now()->toDateString();

        $summary = DB::table('nhl_sat_model_entity_rate_comparison_aggregates')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $testSeasonId)
            ->selectRaw('profile_type')
            ->selectRaw('COUNT(*) as entities')
            ->selectRaw('SUM(bucket_rows) as rows')
            ->selectRaw('SUM(train_sat) as train_sat')
            ->selectRaw('SUM(test_sat) as test_sat')
            ->selectRaw('SUM(projected_xsat_per_60) as projected_xsat_per_60')
            ->selectRaw('SUM(test_xsat_per_60) as test_xsat_per_60')
            ->when($hasHdsatColumns, function ($query): void {
                $query
                    ->selectRaw('SUM(train_hdsat) as train_hdsat')
                    ->selectRaw('SUM(test_hdsat) as test_hdsat');
            })
            ->groupBy('profile_type')
            ->get()
            ->keyBy('profile_type');

        $aggregateQuery = $this->aggregateComparisonBaseQuery(
            run: $run,
            profileType: $profileType,
            testSeasonId: $testSeasonId,
            latestTrainingSeasonId: $latestTrainingSeasonId,
            search: $search
        );

        $hasGameCounts = Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_games')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_games');
        $collectionRows = collect([
            $this->aggregateComparisonCollectionRow(
                aggregateQuery: clone $aggregateQuery,
                hasGameCounts: $hasGameCounts,
                label: 'Grand Total',
                context: 'Collection'
            ),
        ])->merge($this->aggregateComparisonSplitCollectionRows(
            aggregateQuery: $aggregateQuery,
            run: $run,
            profileType: $profileType,
            testSeasonId: $testSeasonId
        ))->merge($this->aggregateComparisonDemographicRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType,
            testSeasonId: $testSeasonId
        ))->merge($this->aggregateComparisonGoalRateRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType
        ))->merge($this->aggregateComparisonPositionRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts
        ))->merge($this->aggregateComparisonTopSignalRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType
        ));

        $aggregates = $this->aggregateComparisonRowsQuery(
            aggregateQuery: clone $aggregateQuery,
            profileType: $profileType,
            ageDate: $ageDate
        )
            ->orderByRaw($sorts[$sort] . ' ' . $direction . ' NULLS LAST')
            ->orderByDesc('train_sat')
            ->orderBy('entity_key')
            ->paginate(50)
            ->withQueryString();

        return view('admin.nhl-sat-models.rate-projection-aggregate-comparison', [
            'aggregates' => $aggregates,
            'collectionRows' => $collectionRows,
            'comparisonState' => $this->rateComparisonStateForRun($run),
            'direction' => $direction,
            'profileType' => $profileType,
            'profileTypes' => $profileTypes,
            'run' => $run,
            'search' => $search,
            'sort' => $sort,
            'sorts' => $sorts,
            'summary' => $summary,
        ]);
    }

    /**
     * Download the aggregate /60 comparison rows for offline review.
     */
    public function exportRateProjectionsAggregate(Request $request, NhlModelRun $run): StreamedResponse
    {
        abort_unless(
            $run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING,
            404
        );
        abort_unless(Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates'), 404);

        $profileTypes = $this->profileTypes();
        $sorts = $this->rateProjectionComparisonSorts();
        $hasHdsatColumns = $this->hasAggregateComparisonHdsatColumns();
        $hasEvalColumns = $this->hasAggregateComparisonEvalColumns();

        if (! $hasHdsatColumns) {
            $sorts = array_diff_key($sorts, array_flip([
                'train_hdsat',
                'test_hdsat',
                'train_hdsat_per_60',
                'test_hdsat_per_60',
                'hdsat_drift',
                'hdsat_drift_rate',
            ]));
        }

        if (! $hasEvalColumns) {
            $sorts = array_diff_key($sorts, array_flip([
                'train_eval_sat_per_60',
                'test_eval_sat_per_60',
                'train_eval_hdsat_per_60',
                'test_eval_hdsat_per_60',
                'train_eval_hdsat_sat_rate',
                'test_eval_hdsat_sat_rate',
                'train_eval_sog_per_60',
                'test_eval_sog_per_60',
                'train_eval_goals_per_60',
                'test_eval_goals_per_60',
                'train_eval_toi_per_gp',
                'test_eval_toi_per_gp',
            ]));
        }

        $input = $request->validate([
            'profile_type' => ['nullable', Rule::in(array_keys($profileTypes))],
            'sort' => ['nullable', Rule::in(array_keys($sorts))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $profileType = $input['profile_type'] ?? 'skater_offense';
        $sort = $input['sort'] ?? 'xsat_error';
        $direction = $input['direction'] ?? 'desc';
        $search = trim((string) ($input['q'] ?? ''));
        $testSeasonId = (string) ($run->target_season_id ?? '');
        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $ageDate = preg_match('/^\d{8}$/', $testSeasonId) === 1
            ? substr($testSeasonId, 0, 4) . '-10-01'
            : now()->toDateString();
        $hasGameCounts = Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_games')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_games');

        $aggregateQuery = $this->aggregateComparisonBaseQuery(
            run: $run,
            profileType: $profileType,
            testSeasonId: $testSeasonId,
            latestTrainingSeasonId: $latestTrainingSeasonId,
            search: $search
        );
        $collectionRows = collect([
            $this->aggregateComparisonCollectionRow(
                aggregateQuery: clone $aggregateQuery,
                hasGameCounts: $hasGameCounts,
                label: 'Grand Total',
                context: 'Collection'
            ),
        ])->merge($this->aggregateComparisonSplitCollectionRows(
            aggregateQuery: $aggregateQuery,
            run: $run,
            profileType: $profileType,
            testSeasonId: $testSeasonId
        ))->merge($this->aggregateComparisonDemographicRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType,
            testSeasonId: $testSeasonId
        ))->merge($this->aggregateComparisonGoalRateRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType
        ))->merge($this->aggregateComparisonPositionRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts
        ))->merge($this->aggregateComparisonTopSignalRows(
            aggregateQuery: $aggregateQuery,
            hasGameCounts: $hasGameCounts,
            profileType: $profileType
        ));
        $rows = $this->aggregateComparisonRowsQuery(
            aggregateQuery: clone $aggregateQuery,
            profileType: $profileType,
            ageDate: $ageDate
        )
            ->orderByRaw($sorts[$sort] . ' ' . $direction . ' NULLS LAST')
            ->orderByDesc('train_sat')
            ->orderBy('entity_key')
            ->get();
        $splitRows = $this->aggregateComparisonSplitRowsQuery(
            aggregateQuery: clone $aggregateQuery,
            run: $run,
            profileType: $profileType,
            testSeasonId: $testSeasonId
        )->get();

        $filename = Str::slug($run->name . '-' . $profileType . '-aggregate-compare-60') . '.csv';

        return response()->streamDownload(function () use ($collectionRows, $rows, $splitRows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $this->aggregateComparisonExportHeader());

            foreach ($collectionRows as $row) {
                fputcsv($handle, $this->aggregateComparisonExportRow($row, 'collection'));
            }

            foreach ($rows as $row) {
                fputcsv($handle, $this->aggregateComparisonExportRow($row, 'entity'));
            }

            foreach ($splitRows as $row) {
                fputcsv($handle, $this->aggregateComparisonExportRow($row, 'split'));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @param array<int, string>|array<int, int> $seasonIds
     * @return array<int, string>
     */
    private function seasonIdsFromArray(array $seasonIds): array
    {
        return collect($seasonIds)
            ->map(fn (mixed $seasonId): string => trim((string) $seasonId))
            ->filter(fn (string $seasonId): bool => preg_match('/^\d{8}$/', $seasonId) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $seasonIds
     * @return array<string, float>
     */
    private function defaultSeasonWeights(array $seasonIds): array
    {
        $count = count($seasonIds);

        if ($count === 0) {
            return [];
        }

        $weights = [];
        $denominator = array_sum(range(1, $count));

        foreach (array_values($seasonIds) as $index => $seasonId) {
            $weights[$seasonId] = round(($index + 1) / $denominator, 6);
        }

        return $weights;
    }

    /**
     * @return array<int, string>
     */
    private function shotAttemptSeasonOptions(): array
    {
        return DB::table('nhl_shot_attempts_facts')
            ->whereNotNull('season_id')
            ->distinct()
            ->orderByDesc('season_id')
            ->pluck('season_id')
            ->map(fn (mixed $seasonId): string => (string) $seasonId)
            ->all();
    }

    private function uniqueRunKey(string $name, string $version): string
    {
        $base = Str::slug('sat-' . $version . '-' . $name) ?: 'sat-model';
        $key = $base;
        $suffix = 2;

        while (NhlModelRun::query()->where('run_key', $key)->exists()) {
            $key = $base . '-' . $suffix;
            $suffix++;
        }

        return $key;
    }

    private function trainingVersion(NhlModelRun $run): string
    {
        return $run->model_version . '__run_' . $run->id;
    }

    private function bucketModelForTarget(NhlModelRun $run, string $target): ?NhlExpectedGoalsModel
    {
        $version = $this->trainingVersion($run);
        $expectedAction = $target === NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL
            ? 'eval_sat'
            : 'eval_sog';
        $expectedSampleMode = $target === NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL
            ? 'sat'
            : 'sog';
        $models = NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->where('prediction_target', $target)
            ->whereNotNull('trained_at')
            ->whereHas('buckets')
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->get();

        $matchesExpectedMetadata = fn (NhlExpectedGoalsModel $model): bool => data_get($model->feature_config, 'sample_mode') === $expectedSampleMode
            && (
                data_get($model->feature_config, 'workflow_action') === null
                || data_get($model->feature_config, 'workflow_action') === $expectedAction
            );

        foreach ([
            fn (NhlExpectedGoalsModel $model): bool => $model->version === $version && $matchesExpectedMetadata($model),
            fn (NhlExpectedGoalsModel $model): bool => $matchesExpectedMetadata($model),
            fn (NhlExpectedGoalsModel $model): bool => $model->version === $version,
            fn (NhlExpectedGoalsModel $model): bool => true,
        ] as $matcher) {
            $model = $models->first($matcher);

            if ($model !== null) {
                return $model;
            }
        }

        return NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->where('prediction_target', $target)
            ->where('version', $version)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param iterable<int, NhlModelRun> $runs
     * @return array<int, array{total:int,eligible:int,excluded:int,excluded_rate:float}>
     */
    private function trainingSummariesForRuns(iterable $runs): array
    {
        $summaries = [];

        foreach ($runs as $run) {
            $summaries[(int) $run->id] = $this->trainingSummaryForRun($run);
        }

        return $summaries;
    }

    /**
     * @return array{total:int,eligible:int,excluded:int,excluded_rate:float}
     */
    private function trainingSummaryForRun(NhlModelRun $run): array
    {
        $metrics = $run->metrics ?? [];
        $total = data_get($metrics, 'training_total_sog') ?? data_get($metrics, 'training_total_sat');
        $eligible = data_get($metrics, 'training_attempts');
        $excluded = data_get($metrics, 'training_excluded_sog') ?? data_get($metrics, 'training_excluded_sat');
        $excludedRate = data_get($metrics, 'training_excluded_sog_rate') ?? data_get($metrics, 'training_excluded_sat_rate');

        if ($total !== null && $eligible !== null && $excluded !== null && $excludedRate !== null) {
            return [
                'total' => (int) $total,
                'eligible' => (int) $eligible,
                'excluded' => (int) $excluded,
                'excluded_rate' => (float) $excludedRate,
            ];
        }

        return app(NhlExpectedGoalsBackfiller::class)
            ->sogTrainingEligibilityCounts($run->train_season_ids ?? []);
    }

    /**
     * @return array{total:int,eligible:int,excluded:int,excluded_rate:float}
     */
    private function trainingSummaryForModel(?NhlExpectedGoalsModel $model, NhlModelRun $run, string $target): array
    {
        $metrics = $model?->metrics ?? [];
        $isSatDanger = $target === NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL;
        $total = $isSatDanger
            ? data_get($metrics, 'training_total_sat')
            : data_get($metrics, 'training_total_sog');
        $eligible = data_get($metrics, 'training_attempts');
        $excluded = $isSatDanger
            ? data_get($metrics, 'training_excluded_sat')
            : data_get($metrics, 'training_excluded_sog');
        $excludedRate = $isSatDanger
            ? data_get($metrics, 'training_excluded_sat_rate')
            : data_get($metrics, 'training_excluded_sog_rate');

        if ($total !== null && $eligible !== null && $excluded !== null && $excludedRate !== null) {
            return [
                'total' => (int) $total,
                'eligible' => (int) $eligible,
                'excluded' => (int) $excluded,
                'excluded_rate' => (float) $excludedRate,
            ];
        }

        $backfiller = app(NhlExpectedGoalsBackfiller::class);

        return $isSatDanger
            ? $backfiller->trainingEligibilityCounts($run->train_season_ids ?? [])
            : $backfiller->sogTrainingEligibilityCounts($run->train_season_ids ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function factorEvaluationForModel(?NhlExpectedGoalsModel $model, string $target): ?array
    {
        if ($model === null) {
            return null;
        }

        $isSatDanger = $target === NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL;
        $evaluationKey = $isSatDanger ? 'sat_factor_evaluation' : 'sog_factor_evaluation';
        $existingEvaluation = data_get($model->feature_config, $evaluationKey)
            ?? data_get($model->metrics, $evaluationKey);

        if (is_array($existingEvaluation) && $existingEvaluation !== []) {
            return $existingEvaluation;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function bucketSorts(): array
    {
        return [
            'bucket_key' => 'bucket_key',
            'fallback_level' => 'fallback_level',
            'attempts' => 'attempts',
            'successes' => 'goals',
            'raw_rate' => 'raw_goal_rate',
            'smoothed_probability' => 'smoothed_goal_probability',
            'confidence_score' => 'confidence_score',
            'shrinkage_weight' => 'shrinkage_weight',
            'updated_at' => 'updated_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function profileTypes(): array
    {
        return [
            'skater_offense' => 'Skater Offense',
            'skater_defense' => 'Skater Defense',
            'goalie_faced' => 'Goalies',
            'team_offense' => 'Team Offense',
            'team_defense' => 'Team Defense',
            'staff_offense' => 'Staff Offense',
            'staff_defense' => 'Staff Defense',
            'official' => 'Officials',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function profileSorts(): array
    {
        return [
            'entity' => 'entity_key',
            'bucket' => 'matched_bucket_key',
            'source_sat' => 'source_sat',
            'source_sog' => 'source_sog',
            'source_goals' => 'source_goals',
            'share' => 'source_profile_share',
            'expected_sog' => 'expected_sog',
            'source_xsat_per_60' => 'source_xsat_per_60',
            'source_xsog_per_60' => 'source_xsog_per_60',
            'source_xg_per_60' => 'source_xg_per_60',
            'sog_above_expected' => 'sog_above_expected',
            'expected_goals' => 'expected_goals',
            'goals_above_expected' => 'goals_above_expected',
            'sat_probability' => 'sat_probability',
            'goal_probability' => 'goal_probability',
            'confidence_score' => 'confidence_score',
            'shrinkage_weight' => 'shrinkage_weight',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rateProjectionSorts(): array
    {
        return [
            'entity' => 'entity_key',
            'bucket' => 'matched_bucket_key',
            'source_sat' => 'source_sat',
            'share' => 'source_profile_share',
            'source_xsat_per_60' => 'source_xsat_per_60',
            'projected_xsat_per_60' => 'projected_xsat_per_60',
            'projected_xsog_per_60' => 'projected_xsog_per_60',
            'projected_xg_per_60' => 'projected_xg_per_60',
            'overall_rate_multiplier' => 'overall_rate_multiplier',
            'shrunk_tendency_multiplier' => 'shrunk_tendency_multiplier',
            'confidence_score' => 'confidence_score',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toiProjectionSorts(): array
    {
        return [
            'entity' => 'toi_projections.entity_key',
            'position' => 'toi_projections.position',
            'age' => 'toi_projections.age_years',
            'prior_games' => 'toi_projections.prior_games',
            'latest_games' => 'toi_projections.latest_games',
            'train_games' => 'toi_projections.train_games',
            'prior_toi_per_game_seconds' => 's1_toi_per_game_seconds',
            'latest_toi_per_game_seconds' => 's2_toi_per_game_seconds',
            'train_toi_per_game_seconds' => 'profile_train_toi_per_game_seconds',
            'projected_games' => 'toi_projections.projected_games',
            'projected_toi_per_game_seconds' => 'toi_projections.projected_toi_per_game_seconds',
            'projected_toi_hours' => 'toi_projections.projected_toi_hours',
            'role_adjustment_seconds_per_game' => 'toi_projections.role_adjustment_seconds_per_game',
            'age_adjustment_seconds_per_game' => 'toi_projections.age_adjustment_seconds_per_game',
            'confidence_score' => 'toi_projections.confidence_score',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rateProjectionComparisonSorts(): array
    {
        return [
            'entity' => 'entity_key',
            'bucket' => 'matched_bucket_key',
            'source_sat' => 'train_sat',
            'test_sat' => 'test_sat',
            'train_hdsat' => 'train_hdsat',
            'test_hdsat' => 'test_hdsat',
            'train_hdsat_per_60' => 'train_hdsat_per_60',
            'test_hdsat_per_60' => 'test_hdsat_per_60',
            'hdsat_drift' => 'hdsat_drift',
            'hdsat_drift_rate' => 'hdsat_drift_rate',
            'train_eval_sat_per_60' => 'train_eval_sat_per_60',
            'test_eval_sat_per_60' => 'test_eval_sat_per_60',
            'train_eval_hdsat_per_60' => 'train_eval_hdsat_per_60',
            'test_eval_hdsat_per_60' => 'test_eval_hdsat_per_60',
            'train_eval_hdsat_sat_rate' => 'train_eval_hdsat_sat_rate',
            'test_eval_hdsat_sat_rate' => 'test_eval_hdsat_sat_rate',
            'train_eval_sog_per_60' => 'train_eval_sog_per_60',
            'test_eval_sog_per_60' => 'test_eval_sog_per_60',
            'train_eval_goals_per_60' => 'train_eval_goals_per_60',
            'test_eval_goals_per_60' => 'test_eval_goals_per_60',
            'train_eval_toi_per_gp' => 'train_eval_toi_per_gp',
            'test_eval_toi_per_gp' => 'test_eval_toi_per_gp',
            'test_sog' => 'test_sog',
            'test_goals' => 'test_goals',
            'share' => 'train_profile_share',
            'test_share' => 'test_profile_share',
            'share_drift' => 'share_drift',
            'share_drift_rate' => 'share_drift_rate',
            'source_xsat_per_60' => 'train_xsat_per_60',
            'projected_xsat_per_60' => 'projected_xsat_per_60',
            'test_xsat_per_60' => 'test_xsat_per_60',
            'xsat_drift' => 'xsat_drift',
            'xsat_drift_rate' => 'xsat_drift_rate',
            'xsat_error' => 'xsat_error',
            'xsat_error_rate' => 'xsat_error_rate',
            'xsat_delta' => 'xsat_error',
            'xsat_delta_rate' => 'xsat_error_rate',
            'source_xsog_per_60' => 'train_xsog_per_60',
            'projected_xsog_per_60' => 'projected_xsog_per_60',
            'test_xsog_per_60' => 'test_xsog_per_60',
            'xsog_drift' => 'xsog_drift',
            'xsog_drift_rate' => 'xsog_drift_rate',
            'xsog_error' => 'xsog_error',
            'xsog_error_rate' => 'xsog_error_rate',
            'xsog_delta' => 'xsog_error',
            'xsog_delta_rate' => 'xsog_error_rate',
            'source_xg_per_60' => 'train_xg_per_60',
            'projected_xg_per_60' => 'projected_xg_per_60',
            'test_xg_per_60' => 'test_xg_per_60',
            'xg_drift' => 'xg_drift',
            'xg_drift_rate' => 'xg_drift_rate',
            'xg_error' => 'xg_error',
            'xg_error_rate' => 'xg_error_rate',
            'xg_delta' => 'xg_error',
            'xg_delta_rate' => 'xg_error_rate',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function trainingDriftSorts(): array
    {
        return [
            'entity' => 'entity_key',
            'bucket' => 'bucket_rows',
            'bucket_rows' => 'bucket_rows',
            'matched_bucket_rows' => 'matched_bucket_rows',
            'train_sat' => 'train_sat',
            'latest_sat' => 'latest_sat',
            'train_sog' => 'train_sog',
            'latest_sog' => 'latest_sog',
            'train_goals' => 'train_goals',
            'latest_goals' => 'latest_goals',
            'share' => 'train_profile_share',
            'latest_share' => 'latest_profile_share',
            'share_drift' => 'share_drift',
            'share_drift_rate' => 'share_drift_rate',
            'train_xsat_per_60' => 'train_xsat_per_60',
            'latest_xsat_per_60' => 'latest_xsat_per_60',
            'xsat_drift' => 'xsat_drift',
            'xsat_drift_abs' => 'xsat_drift_abs',
            'xsat_drift_rate' => 'xsat_drift_rate',
            'train_xsog_per_60' => 'train_xsog_per_60',
            'latest_xsog_per_60' => 'latest_xsog_per_60',
            'xsog_drift' => 'xsog_drift',
            'xsog_drift_abs' => 'xsog_drift_abs',
            'xsog_drift_rate' => 'xsog_drift_rate',
            'train_xg_per_60' => 'train_xg_per_60',
            'latest_xg_per_60' => 'latest_xg_per_60',
            'xg_drift' => 'xg_drift',
            'xg_drift_abs' => 'xg_drift_abs',
            'xg_drift_rate' => 'xg_drift_rate',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function genericBucketStabilitySorts(): array
    {
        return [
            'bucket' => 'matched_bucket_key',
            'train_sat' => 'train_sat',
            'prior_sat' => 'prior_sat',
            'latest_sat' => 'latest_sat',
            'test_sat' => 'test_sat',
            'train_xsat_per_60' => 'train_xsat_per_60',
            'prior_xsat_per_60' => 'prior_xsat_per_60',
            'latest_xsat_per_60' => 'latest_xsat_per_60',
            'test_xsat_per_60' => 'test_xsat_per_60',
            's2_s1' => 'latest_minus_prior_xsat_per_60',
            's3_s2' => 'test_minus_latest_xsat_per_60',
            's3_train' => 'test_minus_train_xsat_per_60',
            's2_s1_rate' => 'latest_minus_prior_xsat_rate',
            's3_s2_rate' => 'test_minus_latest_xsat_rate',
            's3_train_rate' => 'test_minus_train_xsat_rate',
            'reversed' => 'reversed_after_latest',
        ];
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function genericBucketStabilityQuery(
        NhlModelRun $run,
        string $profileType,
        string $search
    ): \Illuminate\Database\Query\Builder {
        return DB::table('nhl_sat_model_generic_bucket_stabilities')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->where('matched_bucket_key', 'ilike', $like)
                        ->orWhereRaw('bucket_dimensions::text ilike ?', [$like]);
                });
            });
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function trainingDriftCollectionRows(
        NhlModelRun $run,
        string $profileType,
        string $latestTrainingSeasonId
    ): \Illuminate\Support\Collection {
        $rows = collect([
            $this->trainingDriftCollectionRow(
                run: $run,
                profileType: $profileType,
                latestTrainingSeasonId: $latestTrainingSeasonId,
                label: 'Grand Total',
                context: 'Collection'
            ),
        ]);

        if ($profileType !== 'skater_offense') {
            return $rows;
        }

        $ageDate = preg_match('/^\d{8}$/', $latestTrainingSeasonId) === 1
            ? substr($latestTrainingSeasonId, 0, 4) . '-10-01'
            : now()->toDateString();
        $ageExpression = "DATE_PART('year', AGE(?::date, players.dob))";
        $ageGroups = [
            ['label' => 'Age 26-29', 'where' => "{$ageExpression} BETWEEN 26 AND 29"],
            ['label' => 'Age 30+', 'where' => "{$ageExpression} >= 30"],
            ['label' => 'Age 34+', 'where' => "{$ageExpression} >= 34"],
            ['label' => 'Age 25 and under', 'where' => "{$ageExpression} <= 25"],
        ];

        foreach ($ageGroups as $ageGroup) {
            $row = $this->trainingDriftCollectionRow(
                run: $run,
                profileType: $profileType,
                latestTrainingSeasonId: $latestTrainingSeasonId,
                label: $ageGroup['label'],
                context: 'Skater Offense',
                ageDate: $ageDate,
                ageWhere: $ageGroup['where']
            );

            if ((int) ($row->entities ?? 0) > 0) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * Build entity-level eligible game counts for Training Drift per-game rates.
     *
     * @param array<int, string> $seasonIds
     */
    private function trainingDriftEntityGameRows(string $profileType, array $seasonIds): \Illuminate\Database\Query\Builder
    {
        $definition = app(NhlSatModelEntityProfileBuilder::class)->profileDefinitions()[$profileType] ?? null;
        $seasonIds = $this->seasonIdsFromArray($seasonIds);

        if ($definition === null || $seasonIds === []) {
            return DB::query()
                ->fromRaw("(SELECT NULL::varchar as entity_key, 0::integer as games) as entity_games")
                ->whereRaw('1 = 0');
        }

        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $sql = <<<SQL
SELECT
    {$definition['entity_key']} as entity_key,
    COUNT(DISTINCT facts.nhl_game_id) as games
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
{$definition['joins']}
WHERE facts.season_id IN ({$seasonPlaceholders})
    AND games.game_type = ?
    AND COALESCE(facts.period_type, '') <> 'SO'
    AND COALESCE(facts.is_empty_net, false) = false
    AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
    AND {$definition['where']}
GROUP BY {$definition['entity_key']}
SQL;

        return DB::query()
            ->fromRaw("({$sql}) as entity_games", [
                ...$seasonIds,
                2,
            ]);
    }

    private function trainingDriftCollectionRow(
        NhlModelRun $run,
        string $profileType,
        string $latestTrainingSeasonId,
        string $label,
        string $context,
        ?string $ageDate = null,
        ?string $ageWhere = null
    ): object {
        $trainingSeasonIds = $this->seasonIdsFromArray($run->train_season_ids ?? []);
        $trainGameCount = max(1, $this->trainingDriftGameCount($trainingSeasonIds, $ageDate, $ageWhere));
        $latestGameCount = max(1, $this->trainingDriftGameCount([$latestTrainingSeasonId], $ageDate, $ageWhere));
        $query = DB::table('nhl_sat_model_entity_profile_buckets as training')
            ->leftJoin('nhl_sat_model_entity_test_profile_buckets as latest', function ($join) use ($latestTrainingSeasonId): void {
                $join
                    ->on('latest.model_run_id', '=', 'training.model_run_id')
                    ->on('latest.profile_type', '=', 'training.profile_type')
                    ->on('latest.entity_key', '=', 'training.entity_key')
                    ->on('latest.matched_bucket_key', '=', 'training.matched_bucket_key')
                    ->where('latest.test_season_id', '=', $latestTrainingSeasonId);
            })
            ->where('training.model_run_id', $run->id)
            ->where('training.profile_type', $profileType);

        if ($ageDate !== null && $ageWhere !== null) {
            $query
                ->leftJoin('players', 'players.nhl_id', '=', 'training.entity_id')
                ->whereNotNull('players.dob')
                ->whereRaw($ageWhere, [$ageDate]);
        }

        $row = $query
            ->selectRaw('?::varchar as collection_label', [$label])
            ->selectRaw('?::varchar as collection_context', [$context])
            ->selectRaw('COUNT(DISTINCT training.entity_key) as entities')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('COUNT(*) FILTER (WHERE latest.id IS NOT NULL) as matched_rows')
            ->selectRaw('?::integer as train_games', [$trainGameCount])
            ->selectRaw('?::integer as latest_games', [$latestGameCount])
            ->selectRaw('SUM(training.source_sat)::numeric / ? as train_sat', [$trainGameCount])
            ->selectRaw('SUM(COALESCE(latest.source_sat, 0))::numeric / ? as latest_sat', [$latestGameCount])
            ->selectRaw('(SUM(COALESCE(latest.source_sat, 0))::numeric / ?) - (SUM(training.source_sat)::numeric / ?) as sat_drift', [$latestGameCount, $trainGameCount])
            ->selectRaw('CASE WHEN SUM(ABS(training.source_sat)) > 0 THEN ((SUM(COALESCE(latest.source_sat, 0))::numeric / ?) - (SUM(training.source_sat)::numeric / ?)) / (SUM(ABS(training.source_sat))::numeric / ?) ELSE NULL END as sat_drift_rate', [$latestGameCount, $trainGameCount, $trainGameCount])
            ->selectRaw('SUM(training.source_sog)::numeric / ? as train_sog', [$trainGameCount])
            ->selectRaw('SUM(COALESCE(latest.source_sog, 0))::numeric / ? as latest_sog', [$latestGameCount])
            ->selectRaw('(SUM(COALESCE(latest.source_sog, 0))::numeric / ?) - (SUM(training.source_sog)::numeric / ?) as sog_drift', [$latestGameCount, $trainGameCount])
            ->selectRaw('CASE WHEN SUM(ABS(training.source_sog)) > 0 THEN ((SUM(COALESCE(latest.source_sog, 0))::numeric / ?) - (SUM(training.source_sog)::numeric / ?)) / (SUM(ABS(training.source_sog))::numeric / ?) ELSE NULL END as sog_drift_rate', [$latestGameCount, $trainGameCount, $trainGameCount])
            ->selectRaw('SUM(training.source_goals)::numeric / ? as train_goals', [$trainGameCount])
            ->selectRaw('SUM(COALESCE(latest.source_goals, 0))::numeric / ? as latest_goals', [$latestGameCount])
            ->selectRaw('(SUM(COALESCE(latest.source_goals, 0))::numeric / ?) - (SUM(training.source_goals)::numeric / ?) as goals_drift', [$latestGameCount, $trainGameCount])
            ->selectRaw('CASE WHEN SUM(ABS(training.source_goals)) > 0 THEN ((SUM(COALESCE(latest.source_goals, 0))::numeric / ?) - (SUM(training.source_goals)::numeric / ?)) / (SUM(ABS(training.source_goals))::numeric / ?) ELSE NULL END as goals_drift_rate', [$latestGameCount, $trainGameCount, $trainGameCount])
            ->selectRaw('SUM(training.source_xsat_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as train_xsat_per_60')
            ->selectRaw('SUM(COALESCE(latest.source_xsat_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as latest_xsat_per_60')
            ->selectRaw('(SUM(COALESCE(latest.source_xsat_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xsat_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) as xsat_drift')
            ->selectRaw('CASE WHEN SUM(ABS(training.source_xsat_per_60)) > 0 THEN ((SUM(COALESCE(latest.source_xsat_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xsat_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0))) / (SUM(ABS(training.source_xsat_per_60)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) ELSE NULL END as xsat_drift_rate')
            ->selectRaw('SUM(training.source_xsog_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as train_xsog_per_60')
            ->selectRaw('SUM(COALESCE(latest.source_xsog_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as latest_xsog_per_60')
            ->selectRaw('(SUM(COALESCE(latest.source_xsog_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xsog_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) as xsog_drift')
            ->selectRaw('CASE WHEN SUM(ABS(training.source_xsog_per_60)) > 0 THEN ((SUM(COALESCE(latest.source_xsog_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xsog_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0))) / (SUM(ABS(training.source_xsog_per_60)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) ELSE NULL END as xsog_drift_rate')
            ->selectRaw('SUM(training.source_xg_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as train_xg_per_60')
            ->selectRaw('SUM(COALESCE(latest.source_xg_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0) as latest_xg_per_60')
            ->selectRaw('(SUM(COALESCE(latest.source_xg_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xg_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) as xg_drift')
            ->selectRaw('CASE WHEN SUM(ABS(training.source_xg_per_60)) > 0 THEN ((SUM(COALESCE(latest.source_xg_per_60, 0)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) - (SUM(training.source_xg_per_60) / NULLIF(COUNT(DISTINCT training.entity_key), 0))) / (SUM(ABS(training.source_xg_per_60)) / NULLIF(COUNT(DISTINCT training.entity_key), 0)) ELSE NULL END as xg_drift_rate')
            ->first();

        return $row ?? (object) [
            'collection_label' => $label,
            'collection_context' => $context,
            'entities' => 0,
        ];
    }

    /**
     * Count eligible NHL games for training drift collection per-game rates.
     */
    private function trainingDriftGameCount(array $seasonIds, ?string $ageDate = null, ?string $ageWhere = null): int
    {
        if ($seasonIds === []) {
            return 0;
        }

        $query = DB::table('nhl_shot_attempts_facts as facts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->whereIn('facts.season_id', $seasonIds)
            ->where('games.game_type', 2)
            ->whereRaw("COALESCE(facts.period_type, '') <> 'SO'")
            ->whereRaw('COALESCE(facts.is_empty_net, false) = false')
            ->whereRaw("COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'");

        if ($ageDate !== null && $ageWhere !== null) {
            $query
                ->join('players', 'players.nhl_id', '=', 'facts.shooter_player_id')
                ->whereNotNull('players.dob')
                ->whereRaw($ageWhere, [$ageDate]);
        }

        return (int) $query
            ->distinct('facts.nhl_game_id')
            ->count('facts.nhl_game_id');
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function aggregateComparisonDemographicRows(
        $aggregateQuery,
        bool $hasGameCounts,
        string $profileType,
        string $testSeasonId
    ): \Illuminate\Support\Collection {
        $rows = collect();

        if ($profileType !== 'skater_offense') {
            return $rows;
        }

        $ageDate = preg_match('/^\d{8}$/', $testSeasonId) === 1
            ? substr($testSeasonId, 0, 4) . '-10-01'
            : now()->toDateString();
        $ageExpression = "DATE_PART('year', AGE(?::date, players.dob))";
        $ageGroups = [
            ['label' => 'Age 26-29', 'where' => "{$ageExpression} BETWEEN 26 AND 29"],
            ['label' => 'Age 30+', 'where' => "{$ageExpression} >= 30"],
            ['label' => 'Age 34+', 'where' => "{$ageExpression} >= 34"],
            ['label' => 'Age 25 and under', 'where' => "{$ageExpression} <= 25"],
        ];

        foreach ($ageGroups as $ageGroup) {
            $query = (clone $aggregateQuery)
                ->leftJoin('players', 'players.nhl_id', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_id')
                ->whereNotNull('players.dob')
                ->whereRaw($ageGroup['where'], [$ageDate]);
            $row = $this->aggregateComparisonCollectionRow(
                aggregateQuery: $query,
                hasGameCounts: $hasGameCounts,
                label: $ageGroup['label'],
                context: 'Skater Offense'
            );

            if ((int) ($row->entities ?? 0) > 0) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function aggregateComparisonGoalRateRows(
        $aggregateQuery,
        bool $hasGameCounts,
        string $profileType
    ): \Illuminate\Support\Collection {
        $rows = collect();

        if ($profileType !== 'skater_offense') {
            return $rows;
        }

        $goalRateGroups = [
            [
                'label' => 'Forward High G/GP',
                'context' => 'Forwards · Training G/GP >= 0.40',
                'where' => "players.position IN ('C', 'L', 'R') AND training_goal_rates.train_g_gp >= 0.40",
            ],
            [
                'label' => 'Forward Mid G/GP',
                'context' => 'Forwards · Training G/GP 0.20-0.39',
                'where' => "players.position IN ('C', 'L', 'R') AND training_goal_rates.train_g_gp >= 0.20 AND training_goal_rates.train_g_gp < 0.40",
            ],
            [
                'label' => 'Forward Low G/GP',
                'context' => 'Forwards · Training G/GP < 0.20',
                'where' => "players.position IN ('C', 'L', 'R') AND training_goal_rates.train_g_gp < 0.20",
            ],
            [
                'label' => 'D High G/GP',
                'context' => 'Defense · Training G/GP >= 0.15',
                'where' => "players.position = 'D' AND training_goal_rates.train_g_gp >= 0.15",
            ],
            [
                'label' => 'D Mid G/GP',
                'context' => 'Defense · Training G/GP 0.07-0.149',
                'where' => "players.position = 'D' AND training_goal_rates.train_g_gp >= 0.07 AND training_goal_rates.train_g_gp < 0.15",
            ],
            [
                'label' => 'D Low G/GP',
                'context' => 'Defense · Training G/GP < 0.07',
                'where' => "players.position = 'D' AND training_goal_rates.train_g_gp < 0.07",
            ],
        ];

        foreach ($goalRateGroups as $goalRateGroup) {
            $query = (clone $aggregateQuery)
                ->leftJoin('players', 'players.nhl_id', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_id')
                ->whereNotNull('training_goal_rates.train_g_gp')
                ->whereNotNull('players.position')
                ->whereRaw($goalRateGroup['where']);
            $row = $this->aggregateComparisonCollectionRow(
                aggregateQuery: $query,
                hasGameCounts: $hasGameCounts,
                label: $goalRateGroup['label'],
                context: $goalRateGroup['context']
            );

            if ((int) ($row->entities ?? 0) > 0) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function aggregateComparisonPositionRows(
        $aggregateQuery,
        bool $hasGameCounts
    ): \Illuminate\Support\Collection {
        $rows = collect();
        $positionGroups = [
            ['label' => 'Position C', 'context' => 'Position', 'where' => "players.position = 'C'"],
            ['label' => 'Position L', 'context' => 'Position', 'where' => "players.position = 'L'"],
            ['label' => 'Position R', 'context' => 'Position', 'where' => "players.position = 'R'"],
            ['label' => 'Position D', 'context' => 'Position', 'where' => "players.position = 'D'"],
            ['label' => 'Position G', 'context' => 'Position', 'where' => "players.position = 'G'"],
            ['label' => 'Forwards', 'context' => 'Position Type', 'where' => "players.position IN ('C', 'L', 'R')"],
            ['label' => 'Defense', 'context' => 'Position Type', 'where' => "players.position = 'D'"],
            ['label' => 'Goalies', 'context' => 'Position Type', 'where' => "players.position = 'G'"],
        ];

        foreach ($positionGroups as $positionGroup) {
            $query = (clone $aggregateQuery)
                ->leftJoin('players', 'players.nhl_id', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_id')
                ->whereNotNull('players.position')
                ->whereRaw($positionGroup['where']);
            $row = $this->aggregateComparisonCollectionRow(
                aggregateQuery: $query,
                hasGameCounts: $hasGameCounts,
                label: $positionGroup['label'],
                context: $positionGroup['context']
            );

            if ((int) ($row->entities ?? 0) > 0) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function aggregateComparisonTopSignalRows(
        $aggregateQuery,
        bool $hasGameCounts,
        string $profileType
    ): \Illuminate\Support\Collection {
        $rows = collect();

        if ($profileType !== 'skater_offense' || ! $this->hasAggregateComparisonEvalColumns()) {
            return $rows;
        }

        $groups = [
            [
                'label' => 'Top 50 Train PTS/GP',
                'context' => 'Training points per game',
                'sort' => 'training_goal_rates.train_pts_gp',
                'where' => 'training_goal_rates.train_pts_gp IS NOT NULL',
            ],
            [
                'label' => 'Top 50 Train G/GP',
                'context' => 'Training goals per game',
                'sort' => 'train_eval_goals_per_gp',
                'where' => 'train_eval_goals_per_gp IS NOT NULL',
            ],
            [
                'label' => 'Top 50 Train TOI/GP',
                'context' => 'Training usage',
                'sort' => 'train_eval_toi_per_gp',
                'where' => 'train_eval_toi_per_gp IS NOT NULL',
            ],
            [
                'label' => 'Top 50 F PTS/GP',
                'context' => 'Forwards · training points per game',
                'sort' => 'training_goal_rates.train_pts_gp',
                'where' => "training_goal_rates.train_pts_gp IS NOT NULL AND players.position IN ('C', 'L', 'R')",
            ],
            [
                'label' => 'Top 50 D TOI/GP',
                'context' => 'Defense · training usage',
                'sort' => 'train_eval_toi_per_gp',
                'where' => "train_eval_toi_per_gp IS NOT NULL AND players.position = 'D'",
            ],
        ];

        foreach ($groups as $group) {
            $keys = (clone $aggregateQuery)
                ->leftJoin('players', 'players.nhl_id', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_id')
                ->whereRaw($group['where'])
                ->orderByRaw($group['sort'] . ' DESC NULLS LAST')
                ->limit(50)
                ->pluck('nhl_sat_model_entity_rate_comparison_aggregates.entity_key')
                ->all();

            if ($keys === []) {
                continue;
            }

            $row = $this->aggregateComparisonCollectionRow(
                aggregateQuery: (clone $aggregateQuery)->whereIn('nhl_sat_model_entity_rate_comparison_aggregates.entity_key', $keys),
                hasGameCounts: $hasGameCounts,
                label: $group['label'],
                context: $group['context']
            );

            if ((int) ($row->entities ?? 0) > 0) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function aggregateComparisonSplitCollectionRows(
        $aggregateQuery,
        NhlModelRun $run,
        string $profileType,
        string $testSeasonId
    ): \Illuminate\Support\Collection {
        if (
            $profileType !== 'skater_offense'
            || ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_splits')
        ) {
            return collect();
        }

        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $trainSeasonCount = max(1, count($this->seasonIdsFromArray($run->train_season_ids ?? [])));
        $latestSplitMetrics = $latestTrainingSeasonId === null || ! $this->hasGameSummarySplitHdsatColumns()
            ? null
            : $this->aggregateComparisonSeasonSplitMetricsQuery($run, $latestTrainingSeasonId);
        $projectionSplits = $this->aggregateComparisonProjectionSplitsQuery($run, $profileType);
        $entityKeys = (clone $aggregateQuery)
            ->select('nhl_sat_model_entity_rate_comparison_aggregates.entity_key');

        $query = DB::table('nhl_sat_model_entity_rate_comparison_splits as splits')
            ->where('splits.model_run_id', $run->id)
            ->where('splits.test_season_id', $testSeasonId)
            ->where('splits.profile_type', $profileType)
            ->whereIn('splits.entity_key', $entityKeys)
            ->whereIn('splits.situation', ['all', 'ev', 'pp', 'pk'])
            ->selectRaw("UPPER(splits.situation)::varchar as collection_label")
            ->selectRaw("'Situation Split'::varchar as collection_context")
            ->selectRaw('COUNT(*) as entities')
            ->selectRaw('AVG(splits.train_gp_per_season) as train_eval_gp_per_season')
            ->selectRaw('AVG(splits.test_gp_per_season) as test_eval_gp_per_season')
            ->selectRaw('SUM(splits.train_toi_seconds) as train_eval_toi_seconds')
            ->selectRaw('SUM(splits.test_toi_seconds) as test_eval_toi_seconds')
            ->selectRaw('AVG(splits.train_toi_per_gp) as train_eval_toi_per_gp')
            ->selectRaw('AVG(splits.test_toi_per_gp) as test_eval_toi_per_gp')
            ->selectRaw('SUM(splits.train_sat) as train_eval_sat')
            ->selectRaw('SUM(splits.test_sat) as test_eval_sat')
            ->selectRaw('AVG(splits.train_sat_per_gp) as train_eval_sat_per_gp')
            ->selectRaw('AVG(splits.test_sat_per_gp) as test_eval_sat_per_gp')
            ->selectRaw('AVG(splits.train_sat_per_60) as train_eval_sat_per_60')
            ->selectRaw('AVG(splits.test_sat_per_60) as test_eval_sat_per_60')
            ->selectRaw('SUM(splits.train_hdsat) as train_eval_hdsat')
            ->selectRaw('SUM(splits.test_hdsat) as test_eval_hdsat')
            ->selectRaw('AVG(splits.train_hdsat_per_gp) as train_eval_hdsat_per_gp')
            ->selectRaw('AVG(splits.test_hdsat_per_gp) as test_eval_hdsat_per_gp')
            ->selectRaw('AVG(splits.train_hdsat_per_60) as train_eval_hdsat_per_60')
            ->selectRaw('AVG(splits.test_hdsat_per_60) as test_eval_hdsat_per_60')
            ->selectRaw('AVG(splits.train_hdsat_sat_rate) as train_eval_hdsat_sat_rate')
            ->selectRaw('AVG(splits.test_hdsat_sat_rate) as test_eval_hdsat_sat_rate')
            ->selectRaw('SUM(splits.train_sog) as train_eval_sog')
            ->selectRaw('SUM(splits.test_sog) as test_eval_sog')
            ->selectRaw('AVG(splits.train_sog_per_gp) as train_eval_sog_per_gp')
            ->selectRaw('AVG(splits.test_sog_per_gp) as test_eval_sog_per_gp')
            ->selectRaw('AVG(splits.train_sog_per_60) as train_eval_sog_per_60')
            ->selectRaw('AVG(splits.test_sog_per_60) as test_eval_sog_per_60')
            ->selectRaw('SUM(splits.train_goals) as train_eval_goals')
            ->selectRaw('SUM(splits.test_goals) as test_eval_goals')
            ->selectRaw('AVG(splits.train_goals_per_gp) as train_eval_goals_per_gp')
            ->selectRaw('AVG(splits.test_goals_per_gp) as test_eval_goals_per_gp')
            ->selectRaw('AVG(splits.train_goals_per_60) as train_eval_goals_per_60')
            ->selectRaw('AVG(splits.test_goals_per_60) as test_eval_goals_per_60')
            ->selectRaw('SUM(splits.train_goals)::numeric / NULLIF(SUM(splits.train_sog), 0) as train_eval_sh_pct')
            ->selectRaw('SUM(splits.test_goals)::numeric / NULLIF(SUM(splits.test_sog), 0) as test_eval_sh_pct')
            ->selectRaw('SUM(splits.train_goals)::numeric / NULLIF(SUM(splits.train_sat), 0) as train_eval_goal_sat_rate')
            ->selectRaw('SUM(splits.test_goals)::numeric / NULLIF(SUM(splits.test_sat), 0) as test_eval_goal_sat_rate')
            ->selectRaw('GREATEST(0, SUM(splits.train_gp_per_season * ?::numeric) - SUM(COALESCE(s2_splits.gp, 0))) as s1_gp', [$trainSeasonCount])
            ->selectRaw('SUM(COALESCE(s2_splits.gp, 0)) as s2_gp')
            ->selectRaw('SUM(splits.test_gp_per_season) as s3_gp')
            ->selectRaw('GREATEST(0, SUM(splits.train_toi_seconds) - SUM(COALESCE(s2_splits.toi_seconds, 0))) as s1_toi_seconds')
            ->selectRaw('SUM(COALESCE(s2_splits.toi_seconds, 0)) as s2_toi_seconds')
            ->selectRaw('SUM(splits.test_toi_seconds) as s3_toi_seconds')
            ->selectRaw('GREATEST(0, SUM(splits.train_sat) - SUM(COALESCE(s2_splits.sat, 0))) as s1_sat')
            ->selectRaw('SUM(COALESCE(s2_splits.sat, 0)) as s2_sat')
            ->selectRaw('SUM(splits.test_sat) as s3_sat')
            ->selectRaw('GREATEST(0, SUM(splits.train_hdsat) - SUM(COALESCE(s2_splits.hdsat, 0))) as s1_hdsat')
            ->selectRaw('SUM(COALESCE(s2_splits.hdsat, 0)) as s2_hdsat')
            ->selectRaw('SUM(splits.test_hdsat) as s3_hdsat')
            ->selectRaw('GREATEST(0, SUM(splits.train_sog) - SUM(COALESCE(s2_splits.sog, 0))) as s1_sog')
            ->selectRaw('SUM(COALESCE(s2_splits.sog, 0)) as s2_sog')
            ->selectRaw('SUM(splits.test_sog) as s3_sog')
            ->selectRaw('GREATEST(0, SUM(splits.train_goals) - SUM(COALESCE(s2_splits.goals, 0))) as s1_goals')
            ->selectRaw('SUM(COALESCE(s2_splits.goals, 0)) as s2_goals')
            ->selectRaw('SUM(splits.test_goals) as s3_goals')
            ->selectRaw('NULL::numeric as train_xsat_per_60')
            ->selectRaw('NULL::numeric as last_xsat_per_60')
            ->selectRaw('NULL::numeric as projected_xsat_per_60')
            ->selectRaw('NULL::numeric as test_xsat_per_60')
            ->selectRaw('NULL::numeric as xsat_drift')
            ->selectRaw('NULL::numeric as xsat_drift_rate')
            ->selectRaw('NULL::numeric as xsat_error')
            ->selectRaw('NULL::numeric as xsat_error_rate')
            ->selectRaw('NULL::integer as xsat_error_entity_count')
            ->selectRaw('NULL::integer as xsat_error_within_3_count')
            ->selectRaw('NULL::numeric as xsat_error_within_3_rate')
            ->selectRaw('NULL::integer as xsat_error_within_5_count')
            ->selectRaw('NULL::numeric as xsat_error_within_5_rate')
            ->selectRaw('NULL::integer as xsat_error_within_10_count')
            ->selectRaw('NULL::numeric as xsat_error_within_10_rate')
            ->selectRaw('NULL::numeric as train_xsog_per_60')
            ->selectRaw('NULL::numeric as last_xsog_per_60')
            ->selectRaw('NULL::numeric as projected_xsog_per_60')
            ->selectRaw('NULL::numeric as test_xsog_per_60')
            ->selectRaw('NULL::numeric as xsog_drift')
            ->selectRaw('NULL::numeric as xsog_drift_rate')
            ->selectRaw('NULL::numeric as xsog_error')
            ->selectRaw('NULL::numeric as xsog_error_rate')
            ->selectRaw('NULL::numeric as train_xg_per_60')
            ->selectRaw('NULL::numeric as last_xg_per_60')
            ->selectRaw('NULL::numeric as projected_xg_per_60')
            ->selectRaw('NULL::numeric as test_xg_per_60')
            ->selectRaw('NULL::numeric as xg_drift')
            ->selectRaw('NULL::numeric as xg_drift_rate')
            ->selectRaw('NULL::numeric as xg_error')
            ->selectRaw('NULL::numeric as xg_error_rate')
            ->selectRaw('AVG(projection_splits.projected_sat_per_60) as projected_split_sat_per_60')
            ->selectRaw('AVG(projection_splits.projected_hdsat_per_60) as projected_split_hdsat_per_60')
            ->selectRaw('AVG(projection_splits.projected_toi_per_gp) as projected_split_toi_per_gp')
            ->selectRaw('SUM(projection_splits.projected_gp) as projected_split_gp')
            ->selectRaw('AVG(projection_splits.projected_sat_per_gp) as projected_split_sat_per_gp')
            ->selectRaw('AVG(projection_splits.projected_hdsat_per_gp) as projected_split_hdsat_per_gp')
            ->selectRaw('SUM(projection_splits.projected_sat_season) as projected_split_sat_season')
            ->selectRaw('SUM(projection_splits.projected_hdsat_season) as projected_split_hdsat_season')
            ->selectRaw('NULL::varchar as projection_split_formula_version')
            ->selectRaw('NULL::varchar as projection_split_formula_segment')
            ->selectRaw('NULL::varchar as projection_split_age_group')
            ->selectRaw('NULL::varchar as projection_split_sat_momentum_bucket')
            ->selectRaw('NULL::varchar as projection_split_hdsat_momentum_bucket')
            ->selectRaw('NULL::varchar as projection_split_toi_momentum_bucket')
            ->selectRaw('NULL::varchar as projection_split_sh_regression_bucket');

        $query->leftJoinSub($projectionSplits, 'projection_splits', function ($join): void {
            $join
                ->on('projection_splits.entity_key', '=', 'splits.entity_key')
                ->on('projection_splits.situation', '=', 'splits.situation');
        });

        if ($latestSplitMetrics !== null) {
            $query->leftJoinSub($latestSplitMetrics, 's2_splits', function ($join): void {
                $join
                    ->on('s2_splits.entity_key', '=', 'splits.entity_key')
                    ->on('s2_splits.situation', '=', 'splits.situation');
            });
        } else {
            $query->leftJoinSub(
                DB::query()->fromRaw('(SELECT NULL::varchar as entity_key, NULL::varchar as situation, NULL::numeric as gp, NULL::numeric as toi_seconds, NULL::numeric as sat, NULL::numeric as hdsat, NULL::numeric as sog, NULL::numeric as goals) as empty_s2_splits')->whereRaw('1 = 0'),
                's2_splits',
                function ($join): void {
                    $join
                        ->on('s2_splits.entity_key', '=', 'splits.entity_key')
                        ->on('s2_splits.situation', '=', 'splits.situation');
                }
            );
        }

        return $query
            ->groupBy('splits.situation')
            ->orderByRaw("CASE splits.situation WHEN 'all' THEN 0 WHEN 'ev' THEN 1 WHEN 'pp' THEN 2 WHEN 'pk' THEN 3 ELSE 9 END")
            ->get();
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Database\Query\Builder
     */
    private function aggregateComparisonSplitRowsQuery(
        $aggregateQuery,
        NhlModelRun $run,
        string $profileType,
        string $testSeasonId
    ) {
        $entityKeys = (clone $aggregateQuery)
            ->select('nhl_sat_model_entity_rate_comparison_aggregates.entity_key');

        if (
            $profileType !== 'skater_offense'
            || ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_splits')
        ) {
            return DB::table('nhl_model_runs')->whereRaw('1 = 0');
        }

        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $trainSeasonCount = max(1, count($this->seasonIdsFromArray($run->train_season_ids ?? [])));
        $latestSplitMetrics = $latestTrainingSeasonId === null || ! $this->hasGameSummarySplitHdsatColumns()
            ? null
            : $this->aggregateComparisonSeasonSplitMetricsQuery($run, $latestTrainingSeasonId);
        $projectionSplits = $this->aggregateComparisonProjectionSplitsQuery($run, $profileType);
        $query = DB::table('nhl_sat_model_entity_rate_comparison_splits as splits')
            ->leftJoin('players', 'players.nhl_id', '=', 'splits.entity_id')
            ->where('splits.model_run_id', $run->id)
            ->where('splits.test_season_id', $testSeasonId)
            ->where('splits.profile_type', $profileType)
            ->whereIn('splits.entity_key', $entityKeys)
            ->select([
                'splits.entity_key',
                'splits.entity_name',
                'splits.situation',
                'splits.team_context',
                'players.position as player_position',
            ])
            ->selectRaw('NULL::integer as player_age')
            ->selectRaw("('split:' || splits.situation)::varchar as entity_role")
            ->selectRaw('splits.train_gp_per_season as train_eval_gp_per_season')
            ->selectRaw('splits.test_gp_per_season as test_eval_gp_per_season')
            ->selectRaw('splits.train_toi_seconds as train_eval_toi_seconds')
            ->selectRaw('splits.test_toi_seconds as test_eval_toi_seconds')
            ->selectRaw('splits.train_toi_per_gp as train_eval_toi_per_gp')
            ->selectRaw('splits.test_toi_per_gp as test_eval_toi_per_gp')
            ->selectRaw('splits.train_sat as train_eval_sat')
            ->selectRaw('splits.test_sat as test_eval_sat')
            ->selectRaw('splits.train_sat_per_gp as train_eval_sat_per_gp')
            ->selectRaw('splits.test_sat_per_gp as test_eval_sat_per_gp')
            ->selectRaw('splits.train_sat_per_60 as train_eval_sat_per_60')
            ->selectRaw('splits.test_sat_per_60 as test_eval_sat_per_60')
            ->selectRaw('splits.train_hdsat as train_eval_hdsat')
            ->selectRaw('splits.test_hdsat as test_eval_hdsat')
            ->selectRaw('splits.train_hdsat_per_gp as train_eval_hdsat_per_gp')
            ->selectRaw('splits.test_hdsat_per_gp as test_eval_hdsat_per_gp')
            ->selectRaw('splits.train_hdsat_per_60 as train_eval_hdsat_per_60')
            ->selectRaw('splits.test_hdsat_per_60 as test_eval_hdsat_per_60')
            ->selectRaw('splits.train_hdsat_sat_rate as train_eval_hdsat_sat_rate')
            ->selectRaw('splits.test_hdsat_sat_rate as test_eval_hdsat_sat_rate')
            ->selectRaw('splits.train_sog as train_eval_sog')
            ->selectRaw('splits.test_sog as test_eval_sog')
            ->selectRaw('splits.train_sog_per_gp as train_eval_sog_per_gp')
            ->selectRaw('splits.test_sog_per_gp as test_eval_sog_per_gp')
            ->selectRaw('splits.train_sog_per_60 as train_eval_sog_per_60')
            ->selectRaw('splits.test_sog_per_60 as test_eval_sog_per_60')
            ->selectRaw('splits.train_goals as train_eval_goals')
            ->selectRaw('splits.test_goals as test_eval_goals')
            ->selectRaw('splits.train_goals_per_gp as train_eval_goals_per_gp')
            ->selectRaw('splits.test_goals_per_gp as test_eval_goals_per_gp')
            ->selectRaw('splits.train_goals_per_60 as train_eval_goals_per_60')
            ->selectRaw('splits.test_goals_per_60 as test_eval_goals_per_60')
            ->selectRaw('splits.train_goals::numeric / NULLIF(splits.train_sog, 0) as train_eval_sh_pct')
            ->selectRaw('splits.test_goals::numeric / NULLIF(splits.test_sog, 0) as test_eval_sh_pct')
            ->selectRaw('splits.train_goals::numeric / NULLIF(splits.train_sat, 0) as train_eval_goal_sat_rate')
            ->selectRaw('splits.test_goals::numeric / NULLIF(splits.test_sat, 0) as test_eval_goal_sat_rate')
            ->selectRaw('GREATEST(0, (splits.train_gp_per_season * ?::numeric) - COALESCE(s2_splits.gp, 0)) as s1_gp', [$trainSeasonCount])
            ->selectRaw('COALESCE(s2_splits.gp, 0) as s2_gp')
            ->selectRaw('splits.test_gp_per_season as s3_gp')
            ->selectRaw('GREATEST(0, splits.train_toi_seconds - COALESCE(s2_splits.toi_seconds, 0)) as s1_toi_seconds')
            ->selectRaw('COALESCE(s2_splits.toi_seconds, 0) as s2_toi_seconds')
            ->selectRaw('splits.test_toi_seconds as s3_toi_seconds')
            ->selectRaw('GREATEST(0, splits.train_sat - COALESCE(s2_splits.sat, 0)) as s1_sat')
            ->selectRaw('COALESCE(s2_splits.sat, 0) as s2_sat')
            ->selectRaw('splits.test_sat as s3_sat')
            ->selectRaw('GREATEST(0, splits.train_hdsat - COALESCE(s2_splits.hdsat, 0)) as s1_hdsat')
            ->selectRaw('COALESCE(s2_splits.hdsat, 0) as s2_hdsat')
            ->selectRaw('splits.test_hdsat as s3_hdsat')
            ->selectRaw('GREATEST(0, splits.train_sog - COALESCE(s2_splits.sog, 0)) as s1_sog')
            ->selectRaw('COALESCE(s2_splits.sog, 0) as s2_sog')
            ->selectRaw('splits.test_sog as s3_sog')
            ->selectRaw('GREATEST(0, splits.train_goals - COALESCE(s2_splits.goals, 0)) as s1_goals')
            ->selectRaw('COALESCE(s2_splits.goals, 0) as s2_goals')
            ->selectRaw('splits.test_goals as s3_goals')
            ->selectRaw('projection_splits.projected_sat_per_60 as projected_split_sat_per_60')
            ->selectRaw('projection_splits.projected_hdsat_per_60 as projected_split_hdsat_per_60')
            ->selectRaw('projection_splits.projected_toi_per_gp as projected_split_toi_per_gp')
            ->selectRaw('projection_splits.projected_gp as projected_split_gp')
            ->selectRaw('projection_splits.projected_sat_per_gp as projected_split_sat_per_gp')
            ->selectRaw('projection_splits.projected_hdsat_per_gp as projected_split_hdsat_per_gp')
            ->selectRaw('projection_splits.projected_sat_season as projected_split_sat_season')
            ->selectRaw('projection_splits.projected_hdsat_season as projected_split_hdsat_season')
            ->selectRaw('projection_splits.formula_version as projection_split_formula_version')
            ->selectRaw('projection_splits.formula_segment as projection_split_formula_segment')
            ->selectRaw('projection_splits.age_group as projection_split_age_group')
            ->selectRaw('projection_splits.sat_momentum_bucket as projection_split_sat_momentum_bucket')
            ->selectRaw('projection_splits.hdsat_momentum_bucket as projection_split_hdsat_momentum_bucket')
            ->selectRaw('projection_splits.toi_momentum_bucket as projection_split_toi_momentum_bucket')
            ->selectRaw('projection_splits.sh_regression_bucket as projection_split_sh_regression_bucket');

        $query->leftJoinSub($projectionSplits, 'projection_splits', function ($join): void {
            $join
                ->on('projection_splits.entity_key', '=', 'splits.entity_key')
                ->on('projection_splits.situation', '=', 'splits.situation');
        });

        if ($latestSplitMetrics !== null) {
            $query->leftJoinSub($latestSplitMetrics, 's2_splits', function ($join): void {
                $join
                    ->on('s2_splits.entity_key', '=', 'splits.entity_key')
                    ->on('s2_splits.situation', '=', 'splits.situation');
            });
        } else {
            $query->leftJoinSub(
                DB::query()->fromRaw('(SELECT NULL::varchar as entity_key, NULL::varchar as situation, NULL::numeric as gp, NULL::numeric as toi_seconds, NULL::numeric as sat, NULL::numeric as hdsat, NULL::numeric as sog, NULL::numeric as goals) as empty_s2_splits')->whereRaw('1 = 0'),
                's2_splits',
                function ($join): void {
                    $join
                        ->on('s2_splits.entity_key', '=', 'splits.entity_key')
                        ->on('s2_splits.situation', '=', 'splits.situation');
                }
            );
        }

        return $query
            ->orderBy('splits.entity_key')
            ->orderByRaw("CASE splits.situation WHEN 'all' THEN 0 WHEN 'ev' THEN 1 WHEN 'pp' THEN 2 WHEN 'pk' THEN 3 ELSE 9 END");
    }

    /**
     * Build per-player strength split metrics for one season from persisted summaries.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function aggregateComparisonProjectionSplitsQuery(NhlModelRun $run, string $profileType)
    {
        if (
            $profileType !== 'skater_offense'
            || ! Schema::hasTable('nhl_sat_model_entity_rate_projection_splits')
        ) {
            return DB::query()->fromRaw(
                '(SELECT NULL::varchar as entity_key, NULL::varchar as situation, NULL::numeric as projected_sat_per_60, NULL::numeric as projected_hdsat_per_60, NULL::numeric as projected_toi_per_gp, NULL::numeric as projected_gp, NULL::numeric as projected_sat_per_gp, NULL::numeric as projected_hdsat_per_gp, NULL::numeric as projected_sat_season, NULL::numeric as projected_hdsat_season, NULL::varchar as formula_version, NULL::varchar as formula_segment, NULL::varchar as age_group, NULL::varchar as sat_momentum_bucket, NULL::varchar as hdsat_momentum_bucket, NULL::varchar as toi_momentum_bucket, NULL::varchar as sh_regression_bucket) as empty_projection_splits'
            )->whereRaw('1 = 0');
        }

        return DB::table('nhl_sat_model_entity_rate_projection_splits')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->select([
                'entity_key',
                'situation',
                'projected_sat_per_60',
                'projected_hdsat_per_60',
                'projected_toi_per_gp',
                'projected_gp',
                'projected_sat_per_gp',
                'projected_hdsat_per_gp',
                'projected_sat_season',
                'projected_hdsat_season',
                'formula_version',
                'formula_segment',
                'age_group',
                'sat_momentum_bucket',
                'hdsat_momentum_bucket',
                'toi_momentum_bucket',
                'sh_regression_bucket',
            ]);
    }

    /**
     * Build per-player strength split metrics for one season from persisted summaries.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function aggregateComparisonSeasonSplitMetricsQuery(NhlModelRun $run, string $seasonId)
    {
        $gameType = (int) ($run->game_type ?? 2);

        return DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->crossJoin(DB::raw("(VALUES
                ('all'::varchar, NULL::varchar),
                ('ev'::varchar, 'EV'::varchar),
                ('pp'::varchar, 'PP'::varchar),
                ('pk'::varchar, 'PK'::varchar)
            ) as situations(situation, strength)"))
            ->leftJoin('nhl_player_game_strength_summaries as strength_summaries', function ($join): void {
                $join
                    ->on('strength_summaries.nhl_game_id', '=', 'summaries.nhl_game_id')
                    ->on('strength_summaries.nhl_player_id', '=', 'summaries.nhl_player_id')
                    ->on('strength_summaries.strength', '=', 'situations.strength');
            })
            ->where('games.season_id', $seasonId)
            ->where('games.game_type', $gameType)
            ->where(function ($query): void {
                $query
                    ->where('situations.situation', 'all')
                    ->orWhereRaw('COALESCE(strength_summaries.toi, 0) > 0');
            })
            ->selectRaw("('skater_offense:' || summaries.nhl_player_id::text)::varchar as entity_key")
            ->selectRaw('situations.situation')
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id)::numeric as gp')
            ->selectRaw('COALESCE(SUM(CASE WHEN situations.situation = \'all\' THEN summaries.toi ELSE strength_summaries.toi END), 0)::numeric as toi_seconds')
            ->selectRaw("COALESCE(SUM(CASE situations.situation WHEN 'all' THEN summaries.sat WHEN 'ev' THEN summaries.evsat WHEN 'pp' THEN summaries.ppsat WHEN 'pk' THEN summaries.pksat ELSE 0 END), 0)::numeric as sat")
            ->selectRaw("COALESCE(SUM(CASE situations.situation WHEN 'all' THEN summaries.hdsat WHEN 'ev' THEN summaries.evhdsat WHEN 'pp' THEN summaries.pphdsat WHEN 'pk' THEN summaries.pkhdsat ELSE 0 END), 0)::numeric as hdsat")
            ->selectRaw("COALESCE(SUM(CASE situations.situation WHEN 'all' THEN summaries.sog WHEN 'ev' THEN summaries.evsog WHEN 'pp' THEN summaries.ppsog WHEN 'pk' THEN summaries.pksog ELSE 0 END), 0)::numeric as sog")
            ->selectRaw("COALESCE(SUM(CASE situations.situation WHEN 'all' THEN summaries.g WHEN 'ev' THEN summaries.evg WHEN 'pp' THEN summaries.ppg WHEN 'pk' THEN summaries.pkg ELSE 0 END), 0)::numeric as goals")
            ->groupBy('summaries.nhl_player_id', 'situations.situation');
    }

    /**
     * Build the aggregate comparison base query shared by the screen and export.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function aggregateComparisonBaseQuery(
        NhlModelRun $run,
        string $profileType,
        string $testSeasonId,
        ?string $latestTrainingSeasonId,
        string $search
    ) {
        $trainingSeasonIds = $this->seasonIdsFromArray($run->train_season_ids ?? []);
        $trainToiSubquery = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type as train_toi_profile_type')
            ->selectRaw('entity_key as train_toi_entity_key')
            ->selectRaw('MAX(source_toi_seconds) as train_toi_seconds')
            ->groupBy('profile_type', 'entity_key');
        $latestRateSubquery = DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $latestTrainingSeasonId)
            ->selectRaw('profile_type as latest_profile_type')
            ->selectRaw('entity_key as latest_entity_key')
            ->selectRaw('SUM(source_xsat_per_60) as last_xsat_per_60')
            ->selectRaw('SUM(source_xsog_per_60) as last_xsog_per_60')
            ->selectRaw('SUM(source_xg_per_60) as last_xg_per_60')
            ->selectRaw('MAX(source_toi_seconds) as last_toi_seconds')
            ->groupBy('profile_type', 'entity_key');
        $latestGameSubquery = $latestTrainingSeasonId === null
            ? DB::query()
                ->fromRaw("(SELECT NULL::varchar as entity_key, 0::integer as games) as entity_games")
                ->whereRaw('1 = 0')
            : $this->trainingDriftEntityGameRows($profileType, [$latestTrainingSeasonId]);
        $testToiSubquery = DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $testSeasonId)
            ->selectRaw('profile_type as test_toi_profile_type')
            ->selectRaw('entity_key as test_toi_entity_key')
            ->selectRaw('MAX(source_toi_seconds) as test_toi_seconds')
            ->groupBy('profile_type', 'entity_key');
        $trainingGoalRateSubquery = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('games.season_id', $trainingSeasonIds === [] ? ['__none__'] : $trainingSeasonIds)
            ->where('games.game_type', 2)
            ->selectRaw("'skater_offense:' || summaries.nhl_player_id::text as entity_key")
            ->selectRaw('SUM(COALESCE(summaries.g, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0) as train_g_gp')
            ->selectRaw('SUM(COALESCE(summaries.pts, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0) as train_pts_gp')
            ->groupBy('summaries.nhl_player_id');
        $toiProjectionSubquery = Schema::hasTable('nhl_sat_model_entity_toi_projections')
            ? DB::table('nhl_sat_model_entity_toi_projections')
                ->where('model_run_id', $run->id)
                ->selectRaw('profile_type as toi_projection_profile_type')
                ->selectRaw('entity_key as toi_projection_entity_key')
                ->selectRaw('projected_games as projected_toi_games')
                ->selectRaw('projected_toi_seconds')
                ->selectRaw('projected_toi_per_game_seconds')
                ->selectRaw('source_role_bucket as projected_source_role_bucket')
                ->selectRaw('target_role_bucket as projected_target_role_bucket')
                ->selectRaw('role_adjustment_seconds_per_game as projected_role_adjustment_seconds_per_game')
                ->selectRaw('age_adjustment_seconds_per_game as projected_age_adjustment_seconds_per_game')
                ->selectRaw('projection_inputs as projected_toi_inputs')
            : DB::query()
                ->fromRaw('(SELECT NULL::varchar as toi_projection_profile_type, NULL::varchar as toi_projection_entity_key, NULL::numeric as projected_toi_games, NULL::numeric as projected_toi_seconds, NULL::numeric as projected_toi_per_game_seconds, NULL::varchar as projected_source_role_bucket, NULL::varchar as projected_target_role_bucket, NULL::numeric as projected_role_adjustment_seconds_per_game, NULL::numeric as projected_age_adjustment_seconds_per_game, NULL::json as projected_toi_inputs) as toi_projection_empty')
                ->whereRaw('1 = 0');
        $rateProjectionSignalSubquery = Schema::hasTable('nhl_sat_model_entity_rate_projection_buckets')
            ? DB::table('nhl_sat_model_entity_rate_projection_buckets')
                ->where('model_run_id', $run->id)
                ->selectRaw('profile_type as rate_signal_profile_type')
                ->selectRaw('entity_key as rate_signal_entity_key')
                ->selectRaw("MAX((metadata->>'pre_march_sat60')::numeric) as pre_march_sat60")
                ->selectRaw("MAX((metadata->>'late_sat60')::numeric) as late_sat60")
                ->selectRaw("MAX((metadata->>'late_sat60_delta')::numeric) as late_sat60_delta")
                ->selectRaw("MAX((metadata->>'pre_march_sat_gp')::numeric) as pre_march_sat_gp")
                ->selectRaw("MAX((metadata->>'late_sat_gp')::numeric) as late_sat_gp")
                ->selectRaw("MAX((metadata->>'late_sat_gp_delta')::numeric) as late_sat_gp_delta")
                ->selectRaw("MAX(metadata->>'late_sat_signal') as late_sat_signal")
                ->selectRaw("MAX((metadata->>'late_sat_adjustment_xsat_per_60')::numeric) as late_sat_adjustment_xsat_per_60")
                ->groupBy('profile_type', 'entity_key')
            : DB::query()
                ->fromRaw('(SELECT NULL::varchar as rate_signal_profile_type, NULL::varchar as rate_signal_entity_key, NULL::numeric as pre_march_sat60, NULL::numeric as late_sat60, NULL::numeric as late_sat60_delta, NULL::numeric as pre_march_sat_gp, NULL::numeric as late_sat_gp, NULL::numeric as late_sat_gp_delta, NULL::varchar as late_sat_signal, NULL::numeric as late_sat_adjustment_xsat_per_60) as rate_projection_signal_empty')
                ->whereRaw('1 = 0');

        return DB::table('nhl_sat_model_entity_rate_comparison_aggregates')
            ->leftJoinSub($trainToiSubquery, 'train_toi', function ($join): void {
                $join
                    ->on('train_toi.train_toi_profile_type', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.profile_type')
                    ->on('train_toi.train_toi_entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($latestRateSubquery, 'latest_comparison_rates', function ($join): void {
                $join
                    ->on('latest_comparison_rates.latest_profile_type', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.profile_type')
                    ->on('latest_comparison_rates.latest_entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($latestGameSubquery, 'latest_entity_games', function ($join): void {
                $join->on('latest_entity_games.entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($testToiSubquery, 'test_toi', function ($join): void {
                $join
                    ->on('test_toi.test_toi_profile_type', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.profile_type')
                    ->on('test_toi.test_toi_entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($trainingGoalRateSubquery, 'training_goal_rates', function ($join): void {
                $join->on('training_goal_rates.entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($toiProjectionSubquery, 'toi_projection', function ($join): void {
                $join
                    ->on('toi_projection.toi_projection_profile_type', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.profile_type')
                    ->on('toi_projection.toi_projection_entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->leftJoinSub($rateProjectionSignalSubquery, 'rate_projection_signals', function ($join): void {
                $join
                    ->on('rate_projection_signals.rate_signal_profile_type', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.profile_type')
                    ->on('rate_projection_signals.rate_signal_entity_key', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_key');
            })
            ->where('nhl_sat_model_entity_rate_comparison_aggregates.model_run_id', $run->id)
            ->where('nhl_sat_model_entity_rate_comparison_aggregates.test_season_id', $testSeasonId)
            ->where('nhl_sat_model_entity_rate_comparison_aggregates.profile_type', $profileType)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $like = '%' . $search . '%';

                    $searchQuery
                        ->where('nhl_sat_model_entity_rate_comparison_aggregates.entity_name', 'ilike', $like)
                        ->orWhere('nhl_sat_model_entity_rate_comparison_aggregates.entity_key', 'ilike', $like);
                });
            });
    }

    /**
     * Select display-only fields for aggregate comparison entity rows.
     *
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     * @return \Illuminate\Database\Query\Builder
     */
    private function aggregateComparisonRowsQuery($aggregateQuery, string $profileType, string $ageDate)
    {
        return $aggregateQuery
            ->select('nhl_sat_model_entity_rate_comparison_aggregates.*')
            ->addSelect([
                'latest_comparison_rates.last_xsat_per_60',
                'latest_comparison_rates.last_xsog_per_60',
                'latest_comparison_rates.last_xg_per_60',
                'train_toi.train_toi_seconds',
                'latest_comparison_rates.last_toi_seconds',
                'latest_entity_games.games as last_games',
                'test_toi.test_toi_seconds',
                'toi_projection.projected_toi_games',
                'toi_projection.projected_toi_seconds',
                'toi_projection.projected_toi_per_game_seconds',
                'toi_projection.projected_source_role_bucket',
                'toi_projection.projected_target_role_bucket',
                'toi_projection.projected_role_adjustment_seconds_per_game',
                'toi_projection.projected_age_adjustment_seconds_per_game',
                'toi_projection.projected_toi_inputs',
                'rate_projection_signals.pre_march_sat60',
                'rate_projection_signals.late_sat60',
                'rate_projection_signals.late_sat60_delta',
                'rate_projection_signals.pre_march_sat_gp',
                'rate_projection_signals.late_sat_gp',
                'rate_projection_signals.late_sat_gp_delta',
                'rate_projection_signals.late_sat_signal',
                'rate_projection_signals.late_sat_adjustment_xsat_per_60',
                'training_goal_rates.train_g_gp',
                'training_goal_rates.train_pts_gp',
            ])
            ->when($profileType === 'skater_offense', function ($query) use ($ageDate): void {
                $query
                    ->leftJoin('players', 'players.nhl_id', '=', 'nhl_sat_model_entity_rate_comparison_aggregates.entity_id')
                    ->addSelect('players.position as player_position')
                    ->selectRaw("FLOOR(DATE_PART('year', AGE(?::date, players.dob))) as player_age", [$ageDate]);
            });
    }

    /**
     * @param \Illuminate\Database\Query\Builder $aggregateQuery
     */
    private function aggregateComparisonCollectionRow(
        $aggregateQuery,
        bool $hasGameCounts,
        string $label,
        string $context
    ): object {
        $hasHdsatColumns = $this->hasAggregateComparisonHdsatColumns();
        $hasEvalColumns = $this->hasAggregateComparisonEvalColumns();
        $row = $aggregateQuery
            ->selectRaw('?::varchar as collection_label', [$label])
            ->selectRaw('?::varchar as collection_context', [$context])
            ->selectRaw('COUNT(*) as entities')
            ->selectRaw('SUM(bucket_rows) as bucket_rows')
            ->selectRaw('SUM(matched_bucket_rows) as matched_bucket_rows')
            ->selectRaw('AVG(train_active_bucket_count) as train_active_bucket_count')
            ->selectRaw('AVG(last_active_bucket_count) as last_active_bucket_count')
            ->selectRaw('AVG(test_active_bucket_count) as test_active_bucket_count')
            ->selectRaw('AVG(train_top_3_bucket_share) as train_top_3_bucket_share')
            ->selectRaw('AVG(last_top_3_bucket_share) as last_top_3_bucket_share')
            ->selectRaw('AVG(test_top_3_bucket_share) as test_top_3_bucket_share')
            ->selectRaw('AVG(train_other_share) as train_other_share')
            ->selectRaw('AVG(last_other_share) as last_other_share')
            ->selectRaw('AVG(test_other_share) as test_other_share')
            ->selectRaw('AVG(train_bucket_entropy) as train_bucket_entropy')
            ->selectRaw('AVG(last_bucket_entropy) as last_bucket_entropy')
            ->selectRaw('AVG(test_bucket_entropy) as test_bucket_entropy')
            ->selectRaw($hasGameCounts ? 'SUM(train_games) as train_games' : 'NULL::integer as train_games')
            ->selectRaw($hasGameCounts ? 'SUM(test_games) as test_games' : 'NULL::integer as test_games')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_gp_per_season) as train_eval_gp_per_season' : 'NULL::numeric as train_eval_gp_per_season')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_gp_per_season) as test_eval_gp_per_season' : 'NULL::numeric as test_eval_gp_per_season')
            ->selectRaw($hasEvalColumns ? 'SUM(train_eval_toi_seconds) as train_eval_toi_seconds' : 'NULL::integer as train_eval_toi_seconds')
            ->selectRaw($hasEvalColumns ? 'SUM(test_eval_toi_seconds) as test_eval_toi_seconds' : 'NULL::integer as test_eval_toi_seconds')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_toi_per_gp) as train_eval_toi_per_gp' : 'NULL::numeric as train_eval_toi_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_toi_per_gp) as test_eval_toi_per_gp' : 'NULL::numeric as test_eval_toi_per_gp')
            ->selectRaw($hasEvalColumns ? 'SUM(train_eval_sat) as train_eval_sat' : 'NULL::integer as train_eval_sat')
            ->selectRaw($hasEvalColumns ? 'SUM(test_eval_sat) as test_eval_sat' : 'NULL::integer as test_eval_sat')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_sat_per_gp) as train_eval_sat_per_gp' : 'NULL::numeric as train_eval_sat_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_sat_per_gp) as test_eval_sat_per_gp' : 'NULL::numeric as test_eval_sat_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_sat_per_60) as train_eval_sat_per_60' : 'NULL::numeric as train_eval_sat_per_60')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_sat_per_60) as test_eval_sat_per_60' : 'NULL::numeric as test_eval_sat_per_60')
            ->selectRaw($hasEvalColumns ? 'SUM(train_eval_hdsat) as train_eval_hdsat' : 'NULL::integer as train_eval_hdsat')
            ->selectRaw($hasEvalColumns ? 'SUM(test_eval_hdsat) as test_eval_hdsat' : 'NULL::integer as test_eval_hdsat')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_hdsat_per_gp) as train_eval_hdsat_per_gp' : 'NULL::numeric as train_eval_hdsat_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_hdsat_per_gp) as test_eval_hdsat_per_gp' : 'NULL::numeric as test_eval_hdsat_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_hdsat_per_60) as train_eval_hdsat_per_60' : 'NULL::numeric as train_eval_hdsat_per_60')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_hdsat_per_60) as test_eval_hdsat_per_60' : 'NULL::numeric as test_eval_hdsat_per_60')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_hdsat_sat_rate) as train_eval_hdsat_sat_rate' : 'NULL::numeric as train_eval_hdsat_sat_rate')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_hdsat_sat_rate) as test_eval_hdsat_sat_rate' : 'NULL::numeric as test_eval_hdsat_sat_rate')
            ->selectRaw($hasEvalColumns ? 'SUM(train_eval_sog) as train_eval_sog' : 'NULL::integer as train_eval_sog')
            ->selectRaw($hasEvalColumns ? 'SUM(test_eval_sog) as test_eval_sog' : 'NULL::integer as test_eval_sog')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_sog_per_gp) as train_eval_sog_per_gp' : 'NULL::numeric as train_eval_sog_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_sog_per_gp) as test_eval_sog_per_gp' : 'NULL::numeric as test_eval_sog_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_sog_per_60) as train_eval_sog_per_60' : 'NULL::numeric as train_eval_sog_per_60')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_sog_per_60) as test_eval_sog_per_60' : 'NULL::numeric as test_eval_sog_per_60')
            ->selectRaw($hasEvalColumns ? 'SUM(train_eval_goals) as train_eval_goals' : 'NULL::integer as train_eval_goals')
            ->selectRaw($hasEvalColumns ? 'SUM(test_eval_goals) as test_eval_goals' : 'NULL::integer as test_eval_goals')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_goals_per_gp) as train_eval_goals_per_gp' : 'NULL::numeric as train_eval_goals_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_goals_per_gp) as test_eval_goals_per_gp' : 'NULL::numeric as test_eval_goals_per_gp')
            ->selectRaw($hasEvalColumns ? 'AVG(train_eval_goals_per_60) as train_eval_goals_per_60' : 'NULL::numeric as train_eval_goals_per_60')
            ->selectRaw($hasEvalColumns ? 'AVG(test_eval_goals_per_60) as test_eval_goals_per_60' : 'NULL::numeric as test_eval_goals_per_60')
            ->selectRaw('SUM(train_toi.train_toi_seconds) as train_toi_seconds')
            ->selectRaw('SUM(latest_comparison_rates.last_toi_seconds) as last_toi_seconds')
            ->selectRaw('SUM(latest_entity_games.games) as last_games')
            ->selectRaw('SUM(test_toi.test_toi_seconds) as test_toi_seconds')
            ->selectRaw('SUM(toi_projection.projected_toi_games) as projected_toi_games')
            ->selectRaw('SUM(toi_projection.projected_toi_seconds) as projected_toi_seconds')
            ->selectRaw('AVG(
                CASE
                    WHEN train_toi.train_toi_seconds IS NOT NULL
                        AND latest_comparison_rates.last_toi_seconds IS NOT NULL
                        AND (train_games - COALESCE(latest_entity_games.games, 0)) > 0
                    THEN (train_toi.train_toi_seconds - latest_comparison_rates.last_toi_seconds)
                        / NULLIF((train_games - COALESCE(latest_entity_games.games, 0))::numeric, 0)
                    ELSE NULL
                END
            ) as s1_toi_per_game_seconds')
            ->selectRaw('AVG(
                CASE
                    WHEN latest_comparison_rates.last_toi_seconds IS NOT NULL
                        AND COALESCE(latest_entity_games.games, 0) > 0
                    THEN latest_comparison_rates.last_toi_seconds / NULLIF(latest_entity_games.games::numeric, 0)
                    ELSE NULL
                END
            ) as last_toi_per_game_seconds')
            ->selectRaw('AVG(toi_projection.projected_toi_per_game_seconds) FILTER (
                WHERE test_toi.test_toi_seconds IS NOT NULL
                    AND test_games > 0
                    AND toi_projection.projected_toi_per_game_seconds IS NOT NULL
            ) as projected_toi_per_game_seconds')
            ->selectRaw('AVG(
                CASE
                    WHEN test_toi.test_toi_seconds IS NOT NULL
                        AND test_games > 0
                    THEN test_toi.test_toi_seconds / NULLIF(test_games::numeric, 0)
                    ELSE NULL
                END
            ) as test_toi_per_game_seconds')
            ->selectRaw('AVG(toi_projection.projected_role_adjustment_seconds_per_game) as projected_role_adjustment_seconds_per_game')
            ->selectRaw('AVG(toi_projection.projected_age_adjustment_seconds_per_game) as projected_age_adjustment_seconds_per_game')
            ->selectRaw('AVG(rate_projection_signals.pre_march_sat60) as pre_march_sat60')
            ->selectRaw('AVG(rate_projection_signals.late_sat60) as late_sat60')
            ->selectRaw('AVG(rate_projection_signals.late_sat60_delta) as late_sat60_delta')
            ->selectRaw('AVG(rate_projection_signals.pre_march_sat_gp) as pre_march_sat_gp')
            ->selectRaw('AVG(rate_projection_signals.late_sat_gp) as late_sat_gp')
            ->selectRaw('AVG(rate_projection_signals.late_sat_gp_delta) as late_sat_gp_delta')
            ->selectRaw('NULL::varchar as late_sat_signal')
            ->selectRaw('AVG(rate_projection_signals.late_sat_adjustment_xsat_per_60) as late_sat_adjustment_xsat_per_60')
            ->selectRaw('AVG(training_goal_rates.train_g_gp) as train_g_gp')
            ->selectRaw('AVG(training_goal_rates.train_pts_gp) as train_pts_gp')
            ->selectRaw('SUM(train_sat) as train_sat')
            ->selectRaw('SUM(train_sog) as train_sog')
            ->selectRaw('SUM(train_goals) as train_goals')
            ->selectRaw('SUM(test_sat) as test_sat')
            ->selectRaw('SUM(test_sog) as test_sog')
            ->selectRaw('SUM(test_goals) as test_goals')
            ->selectRaw($hasHdsatColumns ? 'SUM(train_hdsat) as train_hdsat' : 'NULL::integer as train_hdsat')
            ->selectRaw($hasHdsatColumns ? 'SUM(test_hdsat) as test_hdsat' : 'NULL::integer as test_hdsat')
            ->selectRaw($hasHdsatColumns ? 'AVG(train_hdsat_per_60) as train_hdsat_per_60' : 'NULL::numeric as train_hdsat_per_60')
            ->selectRaw($hasHdsatColumns ? 'AVG(test_hdsat_per_60) as test_hdsat_per_60' : 'NULL::numeric as test_hdsat_per_60')
            ->selectRaw($hasHdsatColumns ? 'AVG(test_hdsat_per_60) - AVG(train_hdsat_per_60) as hdsat_drift' : 'NULL::numeric as hdsat_drift')
            ->selectRaw($hasHdsatColumns ? 'CASE WHEN ABS(AVG(train_hdsat_per_60)) > 0 THEN (AVG(test_hdsat_per_60) - AVG(train_hdsat_per_60)) / ABS(AVG(train_hdsat_per_60)) ELSE NULL END as hdsat_drift_rate' : 'NULL::numeric as hdsat_drift_rate')
            ->selectRaw('AVG(train_xsat_per_60) as train_xsat_per_60')
            ->selectRaw('AVG(latest_comparison_rates.last_xsat_per_60) as last_xsat_per_60')
            ->selectRaw('AVG(projected_xsat_per_60) as projected_xsat_per_60')
            ->selectRaw('AVG(test_xsat_per_60) as test_xsat_per_60')
            ->selectRaw('AVG(test_xsat_per_60) - AVG(train_xsat_per_60) as xsat_drift')
            ->selectRaw('CASE WHEN ABS(AVG(train_xsat_per_60)) > 0 THEN (AVG(test_xsat_per_60) - AVG(train_xsat_per_60)) / ABS(AVG(train_xsat_per_60)) ELSE NULL END as xsat_drift_rate')
            ->selectRaw('AVG(test_xsat_per_60) - AVG(projected_xsat_per_60) as xsat_error')
            ->selectRaw('CASE WHEN ABS(AVG(projected_xsat_per_60)) > 0 THEN (AVG(test_xsat_per_60) - AVG(projected_xsat_per_60)) / ABS(AVG(projected_xsat_per_60)) ELSE NULL END as xsat_error_rate')
            ->selectRaw('COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) as xsat_error_entity_count')
            ->selectRaw('COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.03) as xsat_error_within_3_count')
            ->selectRaw('COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.05) as xsat_error_within_5_count')
            ->selectRaw('COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.10) as xsat_error_within_10_count')
            ->selectRaw('CASE WHEN COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) > 0 THEN (COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.03))::numeric / COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) ELSE NULL END as xsat_error_within_3_rate')
            ->selectRaw('CASE WHEN COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) > 0 THEN (COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.05))::numeric / COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) ELSE NULL END as xsat_error_within_5_rate')
            ->selectRaw('CASE WHEN COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) > 0 THEN (COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL AND ABS((test_xsat_per_60 - projected_xsat_per_60) / ABS(projected_xsat_per_60)) < 0.10))::numeric / COUNT(*) FILTER (WHERE projected_xsat_per_60 IS NOT NULL AND ABS(projected_xsat_per_60) > 0 AND test_xsat_per_60 IS NOT NULL) ELSE NULL END as xsat_error_within_10_rate')
            ->selectRaw('AVG(train_xsog_per_60) as train_xsog_per_60')
            ->selectRaw('AVG(latest_comparison_rates.last_xsog_per_60) as last_xsog_per_60')
            ->selectRaw('AVG(projected_xsog_per_60) as projected_xsog_per_60')
            ->selectRaw('AVG(test_xsog_per_60) as test_xsog_per_60')
            ->selectRaw('AVG(test_xsog_per_60) - AVG(train_xsog_per_60) as xsog_drift')
            ->selectRaw('CASE WHEN ABS(AVG(train_xsog_per_60)) > 0 THEN (AVG(test_xsog_per_60) - AVG(train_xsog_per_60)) / ABS(AVG(train_xsog_per_60)) ELSE NULL END as xsog_drift_rate')
            ->selectRaw('AVG(test_xsog_per_60) - AVG(projected_xsog_per_60) as xsog_error')
            ->selectRaw('CASE WHEN ABS(AVG(projected_xsog_per_60)) > 0 THEN (AVG(test_xsog_per_60) - AVG(projected_xsog_per_60)) / ABS(AVG(projected_xsog_per_60)) ELSE NULL END as xsog_error_rate')
            ->selectRaw('AVG(train_xg_per_60) as train_xg_per_60')
            ->selectRaw('AVG(latest_comparison_rates.last_xg_per_60) as last_xg_per_60')
            ->selectRaw('AVG(projected_xg_per_60) as projected_xg_per_60')
            ->selectRaw('AVG(test_xg_per_60) as test_xg_per_60')
            ->selectRaw('AVG(test_xg_per_60) - AVG(train_xg_per_60) as xg_drift')
            ->selectRaw('CASE WHEN ABS(AVG(train_xg_per_60)) > 0 THEN (AVG(test_xg_per_60) - AVG(train_xg_per_60)) / ABS(AVG(train_xg_per_60)) ELSE NULL END as xg_drift_rate')
            ->selectRaw('AVG(test_xg_per_60) - AVG(projected_xg_per_60) as xg_error')
            ->selectRaw('CASE WHEN ABS(AVG(projected_xg_per_60)) > 0 THEN (AVG(test_xg_per_60) - AVG(projected_xg_per_60)) / ABS(AVG(projected_xg_per_60)) ELSE NULL END as xg_error_rate')
            ->first();

        return $row ?? (object) [
            'collection_label' => $label,
            'collection_context' => $context,
            'entities' => 0,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function aggregateComparisonExportHeader(): array
    {
        return [
            'section',
            'label',
            'context',
            'entity',
            'position',
            'age',
            'entities',
            'matched_bucket_rows',
            'bucket_rows',
            'train_active_bucket_count',
            'last_active_bucket_count',
            'test_active_bucket_count',
            'train_top_3_bucket_share',
            'last_top_3_bucket_share',
            'test_top_3_bucket_share',
            'train_other_share',
            'last_other_share',
            'test_other_share',
            'train_bucket_entropy',
            'last_bucket_entropy',
            'test_bucket_entropy',
            's1_games',
            's2_games',
            'projected_games',
            's3_games',
            's1_toi_gp',
            's2_toi_gp',
            'projected_toi_gp',
            's3_toi_gp',
            'pre_march_toi_gp',
            'late_toi_gp',
            'late_toi_gp_delta',
            'late_toi_signal',
            's1_role',
            's2_role',
            'toi_formula_segment',
            'game_formula_segment',
            's1_s2_games_delta',
            'games_movement_bucket',
            'games_projection_reason',
            's1_pp_toi_gp',
            's2_pp_toi_gp',
            'pp_toi_gp_drift',
            'pp_role_bucket',
            'role_adjustment_toi_gp',
            'age_adjustment_toi_gp',
            'pp_adjustment_toi_gp',
            'game_formula_base_games',
            'game_adjustment',
            's1_toi_season_hours',
            's2_toi_season_hours',
            'projected_toi_season_hours',
            's3_toi_season_hours',
            'train_sat_gp',
            'test_sat_gp',
            'train_hdsat_gp',
            'test_hdsat_gp',
            'train_sog_gp',
            'test_sog_gp',
            'train_g_gp',
            'test_g_gp',
            'pre_march_sat_gp',
            'late_sat_gp',
            'late_sat_gp_delta',
            'pre_march_sat_60',
            'late_sat_60',
            'late_sat_60_delta',
            'late_sat_signal',
            'late_sat_adjustment_xsat_60',
            'train_hdsat_60',
            'test_hdsat_60',
            'hdsat_drift',
            'hdsat_drift_pct',
            'train_xsat_60',
            'last_xsat_60',
            'projected_xsat_60',
            'test_xsat_60',
            'xsat_drift',
            'xsat_drift_pct',
            'xsat_error',
            'xsat_error_pct',
            'xsat_error_entity_count',
            'xsat_error_within_3_count',
            'xsat_error_within_3_pct',
            'xsat_error_within_5_count',
            'xsat_error_within_5_pct',
            'xsat_error_within_10_count',
            'xsat_error_within_10_pct',
            'train_xsog_60',
            'last_xsog_60',
            'projected_xsog_60',
            'test_xsog_60',
            'xsog_drift',
            'xsog_drift_pct',
            'xsog_error',
            'xsog_error_pct',
            'train_xg_60',
            'last_xg_60',
            'projected_xg_60',
            'test_xg_60',
            'xg_drift',
            'xg_drift_pct',
            'xg_error',
            'xg_error_pct',
            'train_eval_gp_per_season',
            'test_eval_gp_per_season',
            'train_eval_toi_minutes',
            'test_eval_toi_minutes',
            'train_eval_toi_gp',
            'test_eval_toi_gp',
            'train_eval_sat',
            'test_eval_sat',
            'train_eval_sat_gp',
            'test_eval_sat_gp',
            'train_eval_sat_60',
            'test_eval_sat_60',
            'train_eval_hdsat',
            'test_eval_hdsat',
            'train_eval_hdsat_gp',
            'test_eval_hdsat_gp',
            'train_eval_hdsat_60',
            'test_eval_hdsat_60',
            'train_eval_hdsat_sat_pct',
            'test_eval_hdsat_sat_pct',
            'train_eval_sog',
            'test_eval_sog',
            'train_eval_sog_gp',
            'test_eval_sog_gp',
            'train_eval_sog_60',
            'test_eval_sog_60',
            'train_eval_goals',
            'test_eval_goals',
            'train_eval_goals_gp',
            'test_eval_goals_gp',
            'train_eval_goals_60',
            'test_eval_goals_60',
            'train_eval_sh_pct',
            'test_eval_sh_pct',
            'train_eval_goal_sat_pct',
            'test_eval_goal_sat_pct',
            's1_gp',
            's2_gp',
            's3_gp',
            's1_toi_minutes',
            's2_toi_minutes',
            's3_toi_minutes',
            's1_toi_gp',
            's2_toi_gp',
            's3_toi_gp',
            's1_sat',
            's2_sat',
            's3_sat',
            's1_sat_gp',
            's2_sat_gp',
            's3_sat_gp',
            's1_sat_60',
            's2_sat_60',
            's3_sat_60',
            's1_hdsat',
            's2_hdsat',
            's3_hdsat',
            's1_hdsat_gp',
            's2_hdsat_gp',
            's3_hdsat_gp',
            's1_hdsat_60',
            's2_hdsat_60',
            's3_hdsat_60',
            's1_hdsat_sat_pct',
            's2_hdsat_sat_pct',
            's3_hdsat_sat_pct',
            's1_sog',
            's2_sog',
            's3_sog',
            's1_sog_gp',
            's2_sog_gp',
            's3_sog_gp',
            's1_sog_60',
            's2_sog_60',
            's3_sog_60',
            's1_goals',
            's2_goals',
            's3_goals',
            's1_goals_gp',
            's2_goals_gp',
            's3_goals_gp',
            's1_goals_60',
            's2_goals_60',
            's3_goals_60',
            's1_sh_pct',
            's2_sh_pct',
            's3_sh_pct',
            's1_goal_sat_pct',
            's2_goal_sat_pct',
            's3_goal_sat_pct',
            's1_to_s2_sat_gp_delta',
            's2_to_s3_sat_gp_delta',
            's1_to_s2_hdsat_gp_delta',
            's2_to_s3_hdsat_gp_delta',
            's1_to_s2_sh_pct_delta',
            's2_to_s3_sh_pct_delta',
            's1_to_s2_hdsat_sat_pct_delta',
            's2_to_s3_hdsat_sat_pct_delta',
            'projected_split_sat_60',
            'projected_split_hdsat_60',
            'projected_split_toi_gp',
            'projected_split_gp',
            'projected_split_sat_gp',
            'projected_split_hdsat_gp',
            'projected_split_sat_season',
            'projected_split_hdsat_season',
            'projected_split_sat_60_error',
            'projected_split_hdsat_60_error',
            'projected_split_toi_gp_error',
            'projected_split_gp_error',
            'projected_split_sat_gp_error',
            'projected_split_hdsat_gp_error',
            'projected_split_sat_season_error',
            'projected_split_hdsat_season_error',
            'projection_split_formula_version',
            'projection_split_formula_segment',
            'projection_split_age_group',
            'projection_split_sat_momentum_bucket',
            'projection_split_hdsat_momentum_bucket',
            'projection_split_toi_momentum_bucket',
            'projection_split_sh_regression_bucket',
            'train_pts_gp',
            'train_g_gp',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function aggregateComparisonExportRow(object $row, string $section): array
    {
        $trainGames = (int) ($row->train_games ?? 0);
        $lastGames = (int) ($row->last_games ?? 0);
        $testGames = (int) ($row->test_games ?? 0);
        $s1Games = max(0, $trainGames - $lastGames);
        $s1ToiSeconds = ($row->train_toi_seconds ?? null) === null || ($row->last_toi_seconds ?? null) === null
            ? null
            : max(0, (float) $row->train_toi_seconds - (float) $row->last_toi_seconds);
        $perGame = fn ($value, int $games): ?float => $games > 0 && $value !== null
            ? ((float) $value) / $games
            : null;
        $rate = fn ($num, $den): ?float => $num !== null && $den !== null && (float) $den > 0
            ? (float) $num / (float) $den
            : null;
        $per60 = fn ($value, $toiSeconds): ?float => $value !== null && $toiSeconds !== null && (float) $toiSeconds > 0
            ? ((float) $value * 3600) / (float) $toiSeconds
            : null;
        $toiPerGame = fn ($seconds, int $games): ?float => $games > 0 && $seconds !== null
            ? (((float) $seconds) / 60) / $games
            : null;
        $seasonHours = fn ($seconds, float $divisor = 1.0): ?float => $seconds !== null && $divisor > 0
            ? (((float) $seconds) / $divisor) / 3600
            : null;
        $s1ToiPerGame = ($row->s1_toi_per_game_seconds ?? null) === null
            ? $toiPerGame($s1ToiSeconds, $s1Games)
            : ((float) $row->s1_toi_per_game_seconds) / 60;
        $lastToiPerGame = ($row->last_toi_per_game_seconds ?? null) === null
            ? $toiPerGame($row->last_toi_seconds ?? null, $lastGames)
            : ((float) $row->last_toi_per_game_seconds) / 60;
        $testToiPerGame = ($row->test_toi_per_game_seconds ?? null) === null
            ? $toiPerGame($row->test_toi_seconds ?? null, $testGames)
            : ((float) $row->test_toi_per_game_seconds) / 60;
        $toiInputs = $this->decodeProjectionInputs($row->projected_toi_inputs ?? null);
        $s1Gp = (int) round((float) ($row->s1_gp ?? 0));
        $s2Gp = (int) round((float) ($row->s2_gp ?? 0));
        $s3Gp = (int) round((float) ($row->s3_gp ?? 0));
        $s1Toi = $row->s1_toi_seconds ?? null;
        $s2Toi = $row->s2_toi_seconds ?? null;
        $s3Toi = $row->s3_toi_seconds ?? null;
        $s1SatGp = $perGame($row->s1_sat ?? null, $s1Gp);
        $s2SatGp = $perGame($row->s2_sat ?? null, $s2Gp);
        $s3SatGp = $perGame($row->s3_sat ?? null, $s3Gp);
        $s1HdsatGp = $perGame($row->s1_hdsat ?? null, $s1Gp);
        $s2HdsatGp = $perGame($row->s2_hdsat ?? null, $s2Gp);
        $s3HdsatGp = $perGame($row->s3_hdsat ?? null, $s3Gp);
        $s1ShPct = $rate($row->s1_goals ?? null, $row->s1_sog ?? null);
        $s2ShPct = $rate($row->s2_goals ?? null, $row->s2_sog ?? null);
        $s3ShPct = $rate($row->s3_goals ?? null, $row->s3_sog ?? null);
        $s1HdsatSatPct = $rate($row->s1_hdsat ?? null, $row->s1_sat ?? null);
        $s2HdsatSatPct = $rate($row->s2_hdsat ?? null, $row->s2_sat ?? null);
        $s3HdsatSatPct = $rate($row->s3_hdsat ?? null, $row->s3_sat ?? null);
        $projectedSplitToiGpMinutes = ($row->projected_split_toi_per_gp ?? null) === null
            ? null
            : ((float) $row->projected_split_toi_per_gp) / 60;
        $projectedSplitSatSeason = $row->projected_split_sat_season ?? null;
        $projectedSplitHdsatSeason = $row->projected_split_hdsat_season ?? null;
        $actualSplitSatSeason = $row->s3_sat ?? null;
        $actualSplitHdsatSeason = $row->s3_hdsat ?? null;

        return [
            $section,
            $section === 'collection' ? ($row->collection_label ?? null) : ($row->entity_name ?? $row->entity_key ?? null),
            $section === 'collection'
                ? ($row->collection_context ?? null)
                : ($section === 'split' ? strtoupper((string) ($row->situation ?? '')) : ($row->entity_role ?? $row->profile_type ?? null)),
            in_array($section, ['entity', 'split'], true) ? ($row->entity_key ?? null) : null,
            $row->player_position ?? null,
            $row->player_age ?? null,
            $row->entities ?? null,
            $row->matched_bucket_rows ?? null,
            $row->bucket_rows ?? null,
            $row->train_active_bucket_count ?? null,
            $row->last_active_bucket_count ?? null,
            $row->test_active_bucket_count ?? null,
            $row->train_top_3_bucket_share ?? null,
            $row->last_top_3_bucket_share ?? null,
            $row->test_top_3_bucket_share ?? null,
            $row->train_other_share ?? null,
            $row->last_other_share ?? null,
            $row->test_other_share ?? null,
            $row->train_bucket_entropy ?? null,
            $row->last_bucket_entropy ?? null,
            $row->test_bucket_entropy ?? null,
            $s1Games,
            $row->last_games ?? null,
            $row->projected_toi_games ?? null,
            $row->test_games ?? null,
            $s1ToiPerGame,
            $lastToiPerGame,
            ($row->projected_toi_per_game_seconds ?? null) === null ? null : ((float) $row->projected_toi_per_game_seconds) / 60,
            $testToiPerGame,
            $section === 'entity' && isset($toiInputs['pre_march_toi_per_game_seconds']) ? ((float) $toiInputs['pre_march_toi_per_game_seconds']) / 60 : null,
            $section === 'entity' && isset($toiInputs['late_toi_per_game_seconds']) ? ((float) $toiInputs['late_toi_per_game_seconds']) / 60 : null,
            $section === 'entity' && isset($toiInputs['late_toi_per_game_delta_seconds']) ? ((float) $toiInputs['late_toi_per_game_delta_seconds']) / 60 : null,
            $section === 'entity' ? ($toiInputs['late_toi_signal'] ?? null) : null,
            $section === 'entity' ? ($row->projected_source_role_bucket ?? null) : null,
            $section === 'entity' ? ($row->projected_target_role_bucket ?? null) : null,
            $section === 'entity' ? ($toiInputs['toi_formula_segment'] ?? null) : null,
            $section === 'entity' ? ($toiInputs['game_formula_segment'] ?? null) : null,
            $section === 'entity' ? ($toiInputs['s1_s2_games_delta'] ?? null) : null,
            $section === 'entity' ? ($toiInputs['games_movement_bucket'] ?? null) : null,
            $section === 'entity' ? ($toiInputs['games_projection_reason'] ?? null) : null,
            $section === 'entity' && isset($toiInputs['prior_pp_toi_per_game_seconds']) ? ((float) $toiInputs['prior_pp_toi_per_game_seconds']) / 60 : null,
            $section === 'entity' && isset($toiInputs['latest_pp_toi_per_game_seconds']) ? ((float) $toiInputs['latest_pp_toi_per_game_seconds']) / 60 : null,
            $section === 'entity' && isset($toiInputs['pp_toi_per_game_drift_seconds']) ? ((float) $toiInputs['pp_toi_per_game_drift_seconds']) / 60 : null,
            $section === 'entity' ? ($toiInputs['pp_role_bucket'] ?? null) : null,
            ($row->projected_role_adjustment_seconds_per_game ?? null) === null ? null : ((float) $row->projected_role_adjustment_seconds_per_game) / 60,
            ($row->projected_age_adjustment_seconds_per_game ?? null) === null ? null : ((float) $row->projected_age_adjustment_seconds_per_game) / 60,
            isset($toiInputs['pp_adjustment_seconds_per_game']) ? ((float) $toiInputs['pp_adjustment_seconds_per_game']) / 60 : null,
            $toiInputs['game_formula_base_games'] ?? null,
            $toiInputs['game_adjustment'] ?? null,
            $seasonHours($s1ToiSeconds),
            $seasonHours($row->last_toi_seconds ?? null),
            $seasonHours($row->projected_toi_seconds ?? null),
            $seasonHours($row->test_toi_seconds ?? null),
            $perGame($row->train_sat ?? null, $trainGames),
            $perGame($row->test_sat ?? null, $testGames),
            $perGame($row->train_hdsat ?? null, $trainGames),
            $perGame($row->test_hdsat ?? null, $testGames),
            $perGame($row->train_sog ?? null, $trainGames),
            $perGame($row->test_sog ?? null, $testGames),
            $perGame($row->train_goals ?? null, $trainGames),
            $perGame($row->test_goals ?? null, $testGames),
            $row->pre_march_sat_gp ?? null,
            $row->late_sat_gp ?? null,
            $row->late_sat_gp_delta ?? null,
            $row->pre_march_sat60 ?? null,
            $row->late_sat60 ?? null,
            $row->late_sat60_delta ?? null,
            $row->late_sat_signal ?? null,
            $row->late_sat_adjustment_xsat_per_60 ?? null,
            $row->train_hdsat_per_60 ?? null,
            $row->test_hdsat_per_60 ?? null,
            $row->hdsat_drift ?? null,
            $row->hdsat_drift_rate ?? null,
            $row->train_xsat_per_60 ?? null,
            $row->last_xsat_per_60 ?? null,
            $row->projected_xsat_per_60 ?? null,
            $row->test_xsat_per_60 ?? null,
            $row->xsat_drift ?? null,
            $row->xsat_drift_rate ?? null,
            $row->xsat_error ?? null,
            $row->xsat_error_rate ?? null,
            $row->xsat_error_entity_count ?? null,
            $row->xsat_error_within_3_count ?? null,
            $row->xsat_error_within_3_rate ?? null,
            $row->xsat_error_within_5_count ?? null,
            $row->xsat_error_within_5_rate ?? null,
            $row->xsat_error_within_10_count ?? null,
            $row->xsat_error_within_10_rate ?? null,
            $row->train_xsog_per_60 ?? null,
            $row->last_xsog_per_60 ?? null,
            $row->projected_xsog_per_60 ?? null,
            $row->test_xsog_per_60 ?? null,
            $row->xsog_drift ?? null,
            $row->xsog_drift_rate ?? null,
            $row->xsog_error ?? null,
            $row->xsog_error_rate ?? null,
            $row->train_xg_per_60 ?? null,
            $row->last_xg_per_60 ?? null,
            $row->projected_xg_per_60 ?? null,
            $row->test_xg_per_60 ?? null,
            $row->xg_drift ?? null,
            $row->xg_drift_rate ?? null,
            $row->xg_error ?? null,
            $row->xg_error_rate ?? null,
            $row->train_eval_gp_per_season ?? null,
            $row->test_eval_gp_per_season ?? null,
            ($row->train_eval_toi_seconds ?? null) === null ? null : ((float) $row->train_eval_toi_seconds) / 60,
            ($row->test_eval_toi_seconds ?? null) === null ? null : ((float) $row->test_eval_toi_seconds) / 60,
            ($row->train_eval_toi_per_gp ?? null) === null ? null : ((float) $row->train_eval_toi_per_gp) / 60,
            ($row->test_eval_toi_per_gp ?? null) === null ? null : ((float) $row->test_eval_toi_per_gp) / 60,
            $row->train_eval_sat ?? null,
            $row->test_eval_sat ?? null,
            $row->train_eval_sat_per_gp ?? null,
            $row->test_eval_sat_per_gp ?? null,
            $row->train_eval_sat_per_60 ?? null,
            $row->test_eval_sat_per_60 ?? null,
            $row->train_eval_hdsat ?? null,
            $row->test_eval_hdsat ?? null,
            $row->train_eval_hdsat_per_gp ?? null,
            $row->test_eval_hdsat_per_gp ?? null,
            $row->train_eval_hdsat_per_60 ?? null,
            $row->test_eval_hdsat_per_60 ?? null,
            $row->train_eval_hdsat_sat_rate ?? null,
            $row->test_eval_hdsat_sat_rate ?? null,
            $row->train_eval_sog ?? null,
            $row->test_eval_sog ?? null,
            $row->train_eval_sog_per_gp ?? null,
            $row->test_eval_sog_per_gp ?? null,
            $row->train_eval_sog_per_60 ?? null,
            $row->test_eval_sog_per_60 ?? null,
            $row->train_eval_goals ?? null,
            $row->test_eval_goals ?? null,
            $row->train_eval_goals_per_gp ?? null,
            $row->test_eval_goals_per_gp ?? null,
            $row->train_eval_goals_per_60 ?? null,
            $row->test_eval_goals_per_60 ?? null,
            $row->train_eval_sh_pct ?? $rate($row->train_eval_goals ?? null, $row->train_eval_sog ?? null),
            $row->test_eval_sh_pct ?? $rate($row->test_eval_goals ?? null, $row->test_eval_sog ?? null),
            $row->train_eval_goal_sat_rate ?? $rate($row->train_eval_goals ?? null, $row->train_eval_sat ?? null),
            $row->test_eval_goal_sat_rate ?? $rate($row->test_eval_goals ?? null, $row->test_eval_sat ?? null),
            $row->s1_gp ?? null,
            $row->s2_gp ?? null,
            $row->s3_gp ?? null,
            $s1Toi === null ? null : ((float) $s1Toi) / 60,
            $s2Toi === null ? null : ((float) $s2Toi) / 60,
            $s3Toi === null ? null : ((float) $s3Toi) / 60,
            $toiPerGame($s1Toi, $s1Gp),
            $toiPerGame($s2Toi, $s2Gp),
            $toiPerGame($s3Toi, $s3Gp),
            $row->s1_sat ?? null,
            $row->s2_sat ?? null,
            $row->s3_sat ?? null,
            $s1SatGp,
            $s2SatGp,
            $s3SatGp,
            $per60($row->s1_sat ?? null, $s1Toi),
            $per60($row->s2_sat ?? null, $s2Toi),
            $per60($row->s3_sat ?? null, $s3Toi),
            $row->s1_hdsat ?? null,
            $row->s2_hdsat ?? null,
            $row->s3_hdsat ?? null,
            $s1HdsatGp,
            $s2HdsatGp,
            $s3HdsatGp,
            $per60($row->s1_hdsat ?? null, $s1Toi),
            $per60($row->s2_hdsat ?? null, $s2Toi),
            $per60($row->s3_hdsat ?? null, $s3Toi),
            $s1HdsatSatPct,
            $s2HdsatSatPct,
            $s3HdsatSatPct,
            $row->s1_sog ?? null,
            $row->s2_sog ?? null,
            $row->s3_sog ?? null,
            $perGame($row->s1_sog ?? null, $s1Gp),
            $perGame($row->s2_sog ?? null, $s2Gp),
            $perGame($row->s3_sog ?? null, $s3Gp),
            $per60($row->s1_sog ?? null, $s1Toi),
            $per60($row->s2_sog ?? null, $s2Toi),
            $per60($row->s3_sog ?? null, $s3Toi),
            $row->s1_goals ?? null,
            $row->s2_goals ?? null,
            $row->s3_goals ?? null,
            $perGame($row->s1_goals ?? null, $s1Gp),
            $perGame($row->s2_goals ?? null, $s2Gp),
            $perGame($row->s3_goals ?? null, $s3Gp),
            $per60($row->s1_goals ?? null, $s1Toi),
            $per60($row->s2_goals ?? null, $s2Toi),
            $per60($row->s3_goals ?? null, $s3Toi),
            $s1ShPct,
            $s2ShPct,
            $s3ShPct,
            $rate($row->s1_goals ?? null, $row->s1_sat ?? null),
            $rate($row->s2_goals ?? null, $row->s2_sat ?? null),
            $rate($row->s3_goals ?? null, $row->s3_sat ?? null),
            $s1SatGp === null || $s2SatGp === null ? null : $s2SatGp - $s1SatGp,
            $s2SatGp === null || $s3SatGp === null ? null : $s3SatGp - $s2SatGp,
            $s1HdsatGp === null || $s2HdsatGp === null ? null : $s2HdsatGp - $s1HdsatGp,
            $s2HdsatGp === null || $s3HdsatGp === null ? null : $s3HdsatGp - $s2HdsatGp,
            $s1ShPct === null || $s2ShPct === null ? null : $s2ShPct - $s1ShPct,
            $s2ShPct === null || $s3ShPct === null ? null : $s3ShPct - $s2ShPct,
            $s1HdsatSatPct === null || $s2HdsatSatPct === null ? null : $s2HdsatSatPct - $s1HdsatSatPct,
            $s2HdsatSatPct === null || $s3HdsatSatPct === null ? null : $s3HdsatSatPct - $s2HdsatSatPct,
            $row->projected_split_sat_per_60 ?? null,
            $row->projected_split_hdsat_per_60 ?? null,
            $projectedSplitToiGpMinutes,
            $row->projected_split_gp ?? null,
            $row->projected_split_sat_per_gp ?? null,
            $row->projected_split_hdsat_per_gp ?? null,
            $projectedSplitSatSeason,
            $projectedSplitHdsatSeason,
            ($row->projected_split_sat_per_60 ?? null) === null || $s3Toi === null ? null : $per60($row->s3_sat ?? null, $s3Toi) - (float) $row->projected_split_sat_per_60,
            ($row->projected_split_hdsat_per_60 ?? null) === null || $s3Toi === null ? null : $per60($row->s3_hdsat ?? null, $s3Toi) - (float) $row->projected_split_hdsat_per_60,
            $projectedSplitToiGpMinutes === null || $s3Gp <= 0 || $s3Toi === null ? null : $toiPerGame($s3Toi, $s3Gp) - $projectedSplitToiGpMinutes,
            ($row->projected_split_gp ?? null) === null || ($row->s3_gp ?? null) === null ? null : (float) $row->s3_gp - (float) $row->projected_split_gp,
            ($row->projected_split_sat_per_gp ?? null) === null || $s3SatGp === null ? null : $s3SatGp - (float) $row->projected_split_sat_per_gp,
            ($row->projected_split_hdsat_per_gp ?? null) === null || $s3HdsatGp === null ? null : $s3HdsatGp - (float) $row->projected_split_hdsat_per_gp,
            $projectedSplitSatSeason === null || $actualSplitSatSeason === null ? null : (float) $actualSplitSatSeason - (float) $projectedSplitSatSeason,
            $projectedSplitHdsatSeason === null || $actualSplitHdsatSeason === null ? null : (float) $actualSplitHdsatSeason - (float) $projectedSplitHdsatSeason,
            $row->projection_split_formula_version ?? null,
            $row->projection_split_formula_segment ?? null,
            $row->projection_split_age_group ?? null,
            $row->projection_split_sat_momentum_bucket ?? null,
            $row->projection_split_hdsat_momentum_bucket ?? null,
            $row->projection_split_toi_momentum_bucket ?? null,
            $row->projection_split_sh_regression_bucket ?? null,
            $row->train_pts_gp ?? null,
            $row->train_g_gp ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProjectionInputs(mixed $inputs): array
    {
        if (is_array($inputs)) {
            return $inputs;
        }

        if (is_object($inputs)) {
            return (array) $inputs;
        }

        if (! is_string($inputs) || trim($inputs) === '') {
            return [];
        }

        $decoded = json_decode($inputs, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function hasAggregateComparisonHdsatColumns(): bool
    {
        return Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift_rate');
    }

    private function hasGameSummarySplitHdsatColumns(): bool
    {
        return Schema::hasColumn('nhl_game_summaries', 'hdsat')
            && Schema::hasColumn('nhl_game_summaries', 'evhdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pphdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pkhdsat');
    }

    private function hasAggregateComparisonEvalColumns(): bool
    {
        return Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_gp_per_season')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_gp_per_season')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_toi_seconds')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_toi_seconds')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_toi_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_toi_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_sat_rate')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_sat_rate')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals_per_60');
    }

    /**
     * @param iterable<int, NhlModelRun> $runs
     * @return array<int, array{has_rate_projections:bool,has_test_profiles:bool,has_rate_comparisons:bool,can_build_rate_comparison:bool,can_view_rate_comparison:bool}>
     */
    private function rateComparisonStatesForRuns(iterable $runs): array
    {
        $states = [];

        foreach ($runs as $run) {
            $states[(int) $run->id] = $this->rateComparisonStateForRun($run);
        }

        return $states;
    }

    /**
     * @return array{has_rate_projections:bool,has_test_profiles:bool,has_rate_comparisons:bool,can_build_rate_comparison:bool,can_view_rate_comparison:bool}
     */
    private function rateComparisonStateForRun(NhlModelRun $run): array
    {
        $hasRateProjections = Schema::hasTable('nhl_sat_model_entity_rate_projection_buckets')
            && DB::table('nhl_sat_model_entity_rate_projection_buckets')
                ->where('model_run_id', $run->id)
                ->exists();
        $hasTestProfiles = $run->target_season_id !== null
            && Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')
            && DB::table('nhl_sat_model_entity_test_profile_buckets')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) $run->target_season_id)
                ->exists();
        $hasRateComparisons = Schema::hasTable('nhl_sat_model_entity_rate_comparison_buckets')
            && Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
            && DB::table('nhl_sat_model_entity_rate_comparison_buckets')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) ($run->target_season_id ?? ''))
                ->exists()
            && DB::table('nhl_sat_model_entity_rate_comparison_aggregates')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) ($run->target_season_id ?? ''))
                ->exists();

        return [
            'has_rate_projections' => $hasRateProjections,
            'has_test_profiles' => $hasTestProfiles,
            'has_rate_comparisons' => $hasRateComparisons,
            'can_build_rate_comparison' => $hasRateProjections && $hasTestProfiles,
            'can_view_rate_comparison' => $hasRateComparisons,
        ];
    }

    /**
     * @param iterable<int, NhlModelRun> $runs
     * @return array<int, array{has_profiles:bool,has_toi_projections:bool,can_build_toi_projection:bool,can_view_toi_projection:bool}>
     */
    private function toiProjectionStatesForRuns(iterable $runs): array
    {
        $states = [];

        foreach ($runs as $run) {
            $states[(int) $run->id] = $this->toiProjectionStateForRun($run);
        }

        return $states;
    }

    /**
     * @return array{has_profiles:bool,has_toi_projections:bool,can_build_toi_projection:bool,can_view_toi_projection:bool}
     */
    private function toiProjectionStateForRun(NhlModelRun $run): array
    {
        $hasProfiles = Schema::hasTable('nhl_sat_model_entity_profile_buckets')
            && DB::table('nhl_sat_model_entity_profile_buckets')
                ->where('model_run_id', $run->id)
                ->whereIn('profile_type', ['skater_offense', 'skater_defense'])
                ->exists();
        $hasToiProjections = Schema::hasTable('nhl_sat_model_entity_toi_projections')
            && DB::table('nhl_sat_model_entity_toi_projections')
                ->where('model_run_id', $run->id)
                ->exists();

        return [
            'has_profiles' => $hasProfiles,
            'has_toi_projections' => $hasToiProjections,
            'can_build_toi_projection' => $hasProfiles,
            'can_view_toi_projection' => $hasToiProjections,
        ];
    }

    /**
     * @param iterable<int, NhlModelRun> $runs
     * @return array<int, array{latest_training_season_id:?string,has_training_profiles:bool,has_latest_training_snapshot:bool,can_view_training_drift:bool}>
     */
    private function trainingDriftStatesForRuns(iterable $runs): array
    {
        $states = [];

        foreach ($runs as $run) {
            $states[(int) $run->id] = $this->trainingDriftStateForRun($run);
        }

        return $states;
    }

    /**
     * @return array{latest_training_season_id:?string,has_training_profiles:bool,has_latest_training_snapshot:bool,can_view_training_drift:bool}
     */
    private function trainingDriftStateForRun(NhlModelRun $run): array
    {
        $latestTrainingSeasonId = $this->latestTrainingSeasonId($run);
        $hasTrainingProfiles = Schema::hasTable('nhl_sat_model_entity_profile_buckets')
            && DB::table('nhl_sat_model_entity_profile_buckets')
                ->where('model_run_id', $run->id)
                ->exists();
        $hasLatestTrainingSnapshot = $latestTrainingSeasonId !== null
            && Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')
            && DB::table('nhl_sat_model_entity_test_profile_buckets')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', $latestTrainingSeasonId)
                ->exists();

        return [
            'latest_training_season_id' => $latestTrainingSeasonId,
            'has_training_profiles' => $hasTrainingProfiles,
            'has_latest_training_snapshot' => $hasLatestTrainingSnapshot,
            'can_view_training_drift' => $hasTrainingProfiles && $hasLatestTrainingSnapshot,
        ];
    }

    /**
     * @param iterable<int, NhlModelRun> $runs
     * @return array<int, array{has_bucket_stability:bool,can_view_bucket_stability:bool}>
     */
    private function genericBucketStabilityStatesForRuns(iterable $runs): array
    {
        $states = [];

        foreach ($runs as $run) {
            $states[(int) $run->id] = $this->genericBucketStabilityStateForRun($run);
        }

        return $states;
    }

    /**
     * @return array{has_bucket_stability:bool,can_view_bucket_stability:bool}
     */
    private function genericBucketStabilityStateForRun(NhlModelRun $run): array
    {
        $hasBucketStability = Schema::hasTable('nhl_sat_model_generic_bucket_stabilities')
            && DB::table('nhl_sat_model_generic_bucket_stabilities')
                ->where('model_run_id', $run->id)
                ->exists();

        return [
            'has_bucket_stability' => $hasBucketStability,
            'can_view_bucket_stability' => $hasBucketStability,
        ];
    }

    private function latestTrainingSeasonId(NhlModelRun $run): ?string
    {
        return collect($run->train_season_ids ?? [])
            ->map(fn (mixed $seasonId): string => trim((string) $seasonId))
            ->filter(fn (string $seasonId): bool => preg_match('/^\d{8}$/', $seasonId) === 1)
            ->sort()
            ->last();
    }

    private function renderRow(NhlModelRun $run): string
    {
        $run = $run->fresh();

        return view('admin.nhl-sat-models._model-row', [
            'comparisonState' => $this->rateComparisonStateForRun($run),
            'genericBucketStabilityState' => $this->genericBucketStabilityStateForRun($run),
            'run' => $run,
            'toiProjectionState' => $this->toiProjectionStateForRun($run),
            'trainingDriftState' => $this->trainingDriftStateForRun($run),
            'trainingSummary' => $this->trainingSummaryForRun($run),
        ])->render();
    }
}
