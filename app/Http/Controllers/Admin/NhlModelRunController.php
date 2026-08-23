<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\NhlSatModelUpdated;
use App\Jobs\BuildNhlSatModelEntityProfilesJob;
use App\Jobs\BuildNhlSatModelEntityRateComparisonsJob;
use App\Jobs\BuildNhlSatModelEntityRateProjectionsJob;
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
        ])->merge($this->aggregateComparisonDemographicRows(
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
        ])->merge($this->aggregateComparisonDemographicRows(
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

        $filename = Str::slug($run->name . '-' . $profileType . '-aggregate-compare-60') . '.csv';

        return response()->streamDownload(function () use ($collectionRows, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $this->aggregateComparisonExportHeader());

            foreach ($collectionRows as $row) {
                fputcsv($handle, $this->aggregateComparisonExportRow($row, 'collection'));
            }

            foreach ($rows as $row) {
                fputcsv($handle, $this->aggregateComparisonExportRow($row, 'entity'));
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
    private function rateProjectionComparisonSorts(): array
    {
        return [
            'entity' => 'entity_key',
            'bucket' => 'matched_bucket_key',
            'source_sat' => 'train_sat',
            'test_sat' => 'test_sat',
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
            ->groupBy('summaries.nhl_player_id');

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
            ->selectRaw('SUM(train_toi.train_toi_seconds) as train_toi_seconds')
            ->selectRaw('SUM(latest_comparison_rates.last_toi_seconds) as last_toi_seconds')
            ->selectRaw('SUM(latest_entity_games.games) as last_games')
            ->selectRaw('SUM(test_toi.test_toi_seconds) as test_toi_seconds')
            ->selectRaw('SUM(train_sat) as train_sat')
            ->selectRaw('SUM(train_sog) as train_sog')
            ->selectRaw('SUM(train_goals) as train_goals')
            ->selectRaw('SUM(test_sat) as test_sat')
            ->selectRaw('SUM(test_sog) as test_sog')
            ->selectRaw('SUM(test_goals) as test_goals')
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
            'train_games',
            'last_games',
            'test_games',
            'train_toi_gp',
            'last_toi_gp',
            'test_toi_gp',
            'train_sat_gp',
            'test_sat_gp',
            'train_sog_gp',
            'test_sog_gp',
            'train_g_gp',
            'test_g_gp',
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
        $perGame = fn ($value, int $games): ?float => $games > 0 && $value !== null
            ? ((float) $value) / $games
            : null;
        $toiPerGame = fn ($seconds, int $games): ?float => $games > 0 && $seconds !== null
            ? (((float) $seconds) / 60) / $games
            : null;

        return [
            $section,
            $section === 'collection' ? ($row->collection_label ?? null) : ($row->entity_name ?? $row->entity_key ?? null),
            $section === 'collection' ? ($row->collection_context ?? null) : ($row->entity_role ?? $row->profile_type ?? null),
            $section === 'entity' ? ($row->entity_key ?? null) : null,
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
            $row->train_games ?? null,
            $row->last_games ?? null,
            $row->test_games ?? null,
            $toiPerGame($row->train_toi_seconds ?? null, $trainGames),
            $toiPerGame($row->last_toi_seconds ?? null, $lastGames),
            $toiPerGame($row->test_toi_seconds ?? null, $testGames),
            $perGame($row->train_sat ?? null, $trainGames),
            $perGame($row->test_sat ?? null, $testGames),
            $perGame($row->train_sog ?? null, $trainGames),
            $perGame($row->test_sog ?? null, $testGames),
            $perGame($row->train_goals ?? null, $trainGames),
            $perGame($row->test_goals ?? null, $testGames),
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
        ];
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
            'trainingDriftState' => $this->trainingDriftStateForRun($run),
            'trainingSummary' => $this->trainingSummaryForRun($run),
        ])->render();
    }
}
