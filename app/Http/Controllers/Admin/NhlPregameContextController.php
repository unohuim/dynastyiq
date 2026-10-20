<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\ScheduledProcess;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\ScheduledProcessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin surface for durable, analysis-only pregame context builds. */
class NhlPregameContextController extends Controller
{
    /** @var array<string,string> */
    private const ANALYSIS_METRICS = [
        'ev_corsi_for_per_60' => 'Next-game EV Corsi For /60',
        'ev_shots_for_per_60' => 'Next-game EV shots for /60',
        'ev_goals_for_per_60' => 'Next-game EV individual goals /60',
    ];

    /** @var array<string,string> */
    private const ANALYSIS_FACTORS = [
        'venue' => 'Venue',
        'rest' => 'Days of rest',
        'workload' => 'Schedule workload',
        'sat_trend' => 'EV SAT /60 trend (last 10 vs season)',
    ];

    /** Build the model's Train and optional Test seasons using the existing context pipeline. */
    public function buildForModel(Request $request, NhlModelRun $run, NhlPregameContextOrchestrator $orchestrator): RedirectResponse|JsonResponse
    {
        $this->assertSatModel($run);
        $build = $orchestrator->queueModelBackfill($run, $request->user()?->id);
        if (! $request->expectsJson()) {
            return redirect()->route('admin.nhl-sat-models.index')->with('status', 'Queued Build Pregame.');
        }

        return response()->json([
            'message' => 'Queued Build Pregame.',
            'progress_html' => view('admin.nhl-sat-models._pregame-progress', ['pregameBuild' => $build])->render(),
        ], 202);
    }

    /** Read progress without loading model projections or changing the model's training state. */
    public function modelProgress(NhlModelRun $run): JsonResponse
    {
        $this->assertSatModel($run);

        return response()->json([
            'progress_html' => view('admin.nhl-sat-models._pregame-progress', [
                'pregameBuild' => NhlPregameContextRun::latestForModel($run->id),
            ])->render(),
        ])->header('Cache-Control', 'no-store');
    }

    /** Read one model's automatic pregame-update settings and latest build status. */
    public function autoSyncSettings(NhlModelRun $run): JsonResponse
    {
        $this->assertSatModel($run);
        $process = ScheduledProcess::query()
            ->where('key', ScheduledProcessManager::pregameContextKey($run->id))
            ->first();
        $latestBuild = NhlPregameContextRun::latestForModel($run->id);

        return response()->json([
            'enabled' => (bool) ($process?->enabled ?? false),
            'frequency_hours' => (int) ($process?->frequency_hours ?? 24),
            'start_time' => $process?->start_time ?? '03:50',
            'timezone' => $process?->timezone ?? 'America/Toronto',
            'include_update_pregame' => (bool) data_get($process?->settings, 'include_update_pregame', true),
            'last_dispatched_at' => $process?->last_dispatched_at?->toIso8601String(),
            'next_due_at' => $process?->next_due_at?->toIso8601String(),
            'last_error' => $process?->last_error,
            'latest_build' => $latestBuild === null ? null : [
                'status' => $latestBuild->status,
                'created_at' => $latestBuild->created_at?->toIso8601String(),
                'total_games' => (int) $latestBuild->total_games,
                'completed_games' => (int) $latestBuild->completed_games,
                'blocked_games' => (int) $latestBuild->blocked_games,
                'failed_games' => (int) $latestBuild->failed_games,
                'last_error' => $latestBuild->last_error,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** Save timing and task-qualification settings without dispatching a build. */
    public function updateAutoSyncSettings(Request $request, NhlModelRun $run, ScheduledProcessManager $manager): JsonResponse
    {
        $this->assertSatModel($run);
        $input = $request->validate([
            'frequency_hours' => ['sometimes', 'required', 'integer', 'between:1,168'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'include_update_pregame' => ['sometimes', 'required', 'boolean'],
        ]);
        $key = ScheduledProcessManager::pregameContextKey($run->id);
        $settings = DB::transaction(function () use ($input, $key, $manager): ScheduledProcess {
            $process = ScheduledProcess::query()->firstOrCreate(
                ['key' => $key],
                ['enabled' => false, 'start_time' => '03:50', 'timezone' => 'America/Toronto',
                    'frequency_hours' => 24, 'settings' => ['include_update_pregame' => true]],
            );
            $process = ScheduledProcess::query()->lockForUpdate()->findOrFail($process->id);
            $attributes = array_intersect_key($input, array_flip(['start_time', 'frequency_hours']));
            if (isset($input['include_update_pregame'])) {
                $attributes['settings'] = array_merge($process->settings ?? [], [
                    'include_update_pregame' => (bool) $input['include_update_pregame'],
                ]);
            }
            $process->fill($attributes);
            if ($process->isDirty(['start_time', 'frequency_hours'])) {
                $process->next_due_at = $process->last_dispatched_at
                    ? $manager->nextDue($process, $process->last_dispatched_at)
                    : null;
            }
            $process->save();

            return $process->fresh();
        });

        return response()->json([
            'message' => 'Pregame auto-sync settings saved.',
            'enabled' => (bool) $settings->enabled,
            'frequency_hours' => (int) $settings->frequency_hours,
            'start_time' => $settings->start_time,
            'timezone' => $settings->timezone,
            'include_update_pregame' => (bool) data_get($settings->settings, 'include_update_pregame', true),
            'last_dispatched_at' => $settings->last_dispatched_at?->toIso8601String(),
            'next_due_at' => $settings->next_due_at?->toIso8601String(),
            'last_error' => $settings->last_error,
        ]);
    }

    /** Toggle automatic updates immediately, without starting work in the request. */
    public function toggleAutoSync(Request $request, NhlModelRun $run): JsonResponse
    {
        $this->assertSatModel($run);
        $input = $request->validate(['enabled' => ['required', 'boolean']]);
        $key = ScheduledProcessManager::pregameContextKey($run->id);
        $process = DB::transaction(function () use ($input, $key): ScheduledProcess {
            $process = ScheduledProcess::query()->firstOrCreate(
                ['key' => $key],
                ['enabled' => false, 'start_time' => '03:50', 'timezone' => 'America/Toronto',
                    'frequency_hours' => 24, 'settings' => ['include_update_pregame' => true]],
            );
            $process = ScheduledProcess::query()->lockForUpdate()->findOrFail($process->id);
            $wasEnabled = (bool) $process->enabled;
            $process->enabled = (bool) $input['enabled'];
            if (! $wasEnabled && $process->enabled) {
                $process->next_due_at = null;
            }
            $process->save();

            return $process->fresh();
        });

        return response()->json([
            'message' => $process->enabled ? 'Pregame auto-sync enabled.' : 'Pregame auto-sync disabled.',
            'enabled' => (bool) $process->enabled,
            'next_due_at' => $process->next_due_at?->toIso8601String(),
        ]);
    }

    /** Restrict model actions to the SAT training registry. */
    private function assertSatModel(NhlModelRun $run): void
    {
        abort_unless($run->model_family === NhlModelRun::FAMILY_SAT
            && $run->workflow_stage === NhlModelRun::STAGE_TRAINING, 404);
    }

    /** Display grouped, pregame-only context effects against actual EV game outcomes. */
    public function effects(Request $request): View
    {
        $seasonIds = DB::table('nhl_player_game_pregame_contexts as contexts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'contexts.nhl_game_id')
            ->distinct()
            ->orderByDesc('games.season_id')
            ->pluck('games.season_id')
            ->map(fn (mixed $seasonId): string => (string) $seasonId)
            ->all();

        $input = $request->validate([
            'season_id' => ['nullable', Rule::in(['all', ...$seasonIds])],
            'metric' => ['nullable', Rule::in(array_keys(self::ANALYSIS_METRICS))],
            'factor' => ['nullable', Rule::in(array_keys(self::ANALYSIS_FACTORS))],
            'sort' => ['nullable', Rule::in(['group', 'sample_size', 'actual_average', 'delta_from_baseline'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $seasonId = (string) ($input['season_id'] ?? 'all');
        $metric = (string) ($input['metric'] ?? 'ev_corsi_for_per_60');
        $factor = (string) ($input['factor'] ?? 'venue');
        $sort = (string) ($input['sort'] ?? 'sample_size');
        $direction = (string) ($input['direction'] ?? 'desc');
        $effects = $this->contextEffects($seasonId, $metric, $factor);
        $baseline = $effects['baseline'];
        $rows = collect($effects['rows'])->sortBy(
            $sort,
            SORT_REGULAR,
            $direction === 'desc',
        )->values();

        return view('admin.nhl-sat-models.context-effects', [
            'seasonIds' => $seasonIds,
            'selectedSeasonId' => $seasonId,
            'metrics' => self::ANALYSIS_METRICS,
            'selectedMetric' => $metric,
            'factors' => self::ANALYSIS_FACTORS,
            'selectedFactor' => $factor,
            'sort' => $sort,
            'direction' => $direction,
            'baseline' => $baseline,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array{baseline:array{sample_size:int,actual_average:float|null},rows:array<int,array{group:string,sample_size:int,actual_average:float,delta_from_baseline:float}>}
     */
    private function contextEffects(string $seasonId, string $metric, string $factor): array
    {
        if ($seasonId === '') {
            return ['baseline' => ['sample_size' => 0, 'actual_average' => null], 'rows' => []];
        }

        $groups = [];
        $total = 0.0;
        $sampleSize = 0;
        $query = DB::table('nhl_player_game_pregame_contexts as contexts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'contexts.nhl_game_id')
            ->join('nhl_player_game_strength_summaries as outcomes', function ($join): void {
                $join->on('outcomes.nhl_game_id', '=', 'contexts.nhl_game_id')
                    ->on('outcomes.nhl_player_id', '=', 'contexts.nhl_player_id')
                    ->where('outcomes.strength', '=', 'EV');
            })
            ->leftJoin('nhl_team_game_pregame_contexts as teams', 'teams.id', '=', 'contexts.team_context_id')
            ->where('contexts.context_version', \App\Services\NhlPregameContextBuilder::VERSION)
            ->when($seasonId !== 'all', fn ($query) => $query->where('games.season_id', $seasonId))
            ->select([
                'contexts.venue',
                'contexts.metrics',
                'teams.schedule_metrics',
                'outcomes.toi',
                'outcomes.satf',
                'outcomes.sf',
                'outcomes.individual_g',
            ])
            ->orderBy('contexts.id');

        foreach ($query->cursor() as $row) {
            $actual = $this->actualOutcome($row, $metric);
            if ($actual === null) {
                continue;
            }

            $group = $this->contextGroup($row, $factor);
            if ($group === null) {
                continue;
            }

            $groups[$group] ??= ['group' => $group, 'sample_size' => 0, 'total' => 0.0];
            $groups[$group]['sample_size']++;
            $groups[$group]['total'] += $actual;
            $total += $actual;
            $sampleSize++;
        }

        $baseline = $sampleSize > 0 ? round($total / $sampleSize, 2) : null;
        $rows = collect($groups)->map(function (array $group) use ($baseline): array {
            $actual = round($group['total'] / $group['sample_size'], 2);

            return [
                'group' => $group['group'],
                'sample_size' => $group['sample_size'],
                'actual_average' => $actual,
                'delta_from_baseline' => round($actual - ($baseline ?? $actual), 2),
            ];
        })->values()->all();

        return [
            'baseline' => ['sample_size' => $sampleSize, 'actual_average' => $baseline],
            'rows' => $rows,
        ];
    }

    private function actualOutcome(object $row, string $metric): ?float
    {
        $toi = (int) $row->toi;
        if ($toi === 0) {
            return null;
        }

        return match ($metric) {
            'ev_corsi_for_per_60' => ((int) $row->satf) * 3600 / $toi,
            'ev_shots_for_per_60' => ((int) $row->sf) * 3600 / $toi,
            'ev_goals_for_per_60' => ((int) $row->individual_g) * 3600 / $toi,
        };
    }

    private function contextGroup(object $row, string $factor): ?string
    {
        $metrics = json_decode((string) $row->metrics, true);
        $schedule = json_decode((string) $row->schedule_metrics, true);

        return match ($factor) {
            'venue' => $row->venue === 'home' ? 'Home' : 'Away',
            'rest' => is_array($schedule) ? $this->restGroup($schedule['days_rest'] ?? null) : null,
            'workload' => is_array($schedule) ? (($schedule['three_in_four'] ?? false) ? 'Three in four nights' : 'Not three in four nights') : null,
            'sat_trend' => is_array($metrics) ? $this->satTrendGroup($metrics) : null,
        };
    }

    private function restGroup(mixed $daysRest): string
    {
        return match (is_numeric($daysRest) ? (int) $daysRest : null) {
            0 => 'No rest (back-to-back)',
            1 => 'One day rest',
            2 => 'Two days rest',
            null => 'No prior-game sample',
            default => 'Three or more days rest',
        };
    }

    /** @param array<string,mixed> $metrics */
    private function satTrendGroup(array $metrics): ?string
    {
        $lastTen = data_get($metrics, 'strength.EV.last_10.sat_per_60');
        $season = data_get($metrics, 'strength.EV.season_to_date.sat_per_60');
        if (! is_numeric($lastTen) || ! is_numeric($season)) {
            return null;
        }

        $delta = (float) $lastTen - (float) $season;
        if ($delta >= 2.0) {
            return 'At least 2.0 higher';
        }
        if ($delta >= 0.5) {
            return '0.5 to 1.99 higher';
        }
        if ($delta > -0.5) {
            return 'Within 0.5';
        }
        if ($delta > -2.0) {
            return '0.5 to 1.99 lower';
        }

        return 'At least 2.0 lower';
    }
}
