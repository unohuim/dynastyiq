<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\NhlOrchestratorJob;
use App\Jobs\SeasonSumJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlGameSourceStatus;
use App\Repositories\NhlImportProgressRepo;
use App\Services\NhlGameImportRebuilder;
use App\Services\NhlGameSourcePreflight;
use App\Services\NhlImportOrchestrator;
use App\Support\NhlImportStages;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin endpoints for dispatching and monitoring NHL game import work.
 */
class NhlGameImportController extends Controller
{
    private const SHIFT_RERUN_STAGES = [
        NhlImportStages::SHIFTS,
        NhlImportStages::SHIFT_UNITS,
        NhlImportStages::CONNECT_EVENTS,
        NhlImportStages::SUM_GAME_UNITS,
        NhlImportStages::VALIDATE_SUMMARY,
    ];

    /**
     * Return recent admin game import runs with current pipeline progress.
     */
    public function status(): JsonResponse
    {
        $runs = NhlGameImportRun::query()
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (NhlGameImportRun $run): array => $this->serializeRun($run));

        return response()->json([
            'runs' => $runs,
            'processable' => [
                'date_count' => $this->processableDateCount(),
            ],
            'seasons' => $this->availableSeasons(),
        ]);
    }

    /**
     * Return all games with currently missing NHL provider source records.
     */
    public function sourceGaps(): JsonResponse
    {
        return response()->json([
            'gaps' => $this->sourceGapRows(),
        ]);
    }

    /**
     * Re-check source availability for one game and queue the narrowest useful rerun.
     */
    public function rerunSourceGap(
        int $gameId,
        NhlGameSourcePreflight $preflight,
        NhlGameImportRebuilder $rebuilder,
        NhlImportProgressRepo $progress,
        NhlImportOrchestrator $orchestrator
    ): JsonResponse {
        $previousBlockedSources = NhlGameSourceStatus::query()
            ->where('nhl_game_id', $gameId)
            ->where('status', '!=', NhlGameSourceStatus::STATUS_AVAILABLE)
            ->pluck('source')
            ->unique()
            ->values()
            ->all();

        $result = $preflight->check($gameId);
        $action = 'source_checked';

        if ($result['core_allowed'] && $this->hasCoreGap($previousBlockedSources)) {
            $rebuilder->rebuild($gameId);
            $action = 'game_rebuild_queued';
        } elseif (
            $result['core_allowed']
            && $result['on_ice_allowed']
            && in_array(NhlGameSourceStatus::SOURCE_SHIFTS, $previousBlockedSources, true)
        ) {
            foreach (self::SHIFT_RERUN_STAGES as $stage) {
                $progress->reschedule($gameId, $stage);
            }

            $orchestrator->dispatchJob($gameId, NhlImportStages::SHIFTS);
            $action = 'shift_stages_queued';
        }

        return response()->json([
            'status' => $action,
            'source_result' => $result,
            'gaps' => $this->sourceGapRows(),
        ], 202);
    }

    /**
     * Queue a full rebuild for a stopped game import.
     */
    public function rerunStoppedGame(int $gameId, NhlGameImportRebuilder $rebuilder): JsonResponse
    {
        $rebuilder->rebuild($gameId);

        return response()->json([
            'status' => 'game_rebuild_queued',
            'game_id' => $gameId,
        ], 202);
    }

    /**
     * Queue NHL game discovery for a validated date selection.
     */
    public function discover(Request $request): JsonResponse
    {
        $range = $this->normalizeDateSelection($this->validatedInput($request), true);

        $run = NhlGameImportRun::create([
            'action' => NhlGameImportRun::ACTION_DISCOVER,
            'mode' => $range['mode'],
            'status' => NhlGameImportRun::STATUS_QUEUED,
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'date_count' => $range['date_count'],
            'queued_jobs' => 1,
            'payload' => $range['payload'],
            'created_by' => $request->user()?->id,
        ]);

        NhlDiscoveryJob::dispatch($range['start'], $range['end'], $run->id);
        broadcast(new NhlGameImportStatusUpdated('discovery-queued', $run->id));

        return response()->json([
            'message' => 'Discovery queued.',
            'run' => $this->serializeRun($run->refresh()),
        ], 202);
    }

    /**
     * Queue NHL orchestrator jobs for a validated date selection.
     */
    public function process(
        Request $request,
        NhlImportOrchestrator $orchestrator,
        NhlImportProgressRepo $progress
    ): JsonResponse {
        $input = $this->validatedInput($request);
        $discoveryRun = $this->discoveryRunFromInput($input);
        $range = $discoveryRun
            ? $this->rangeFromRun($discoveryRun)
            : $this->normalizeDateSelection($input, false);
        $dates = $this->dateStrings($range['start'], $range['end']);
        $reprocessExisting = $request->boolean('reprocess_existing');

        $run = $discoveryRun;

        if (! $run) {
            $run = NhlGameImportRun::create([
                'action' => NhlGameImportRun::ACTION_PROCESS,
                'mode' => $range['mode'],
                'status' => NhlGameImportRun::STATUS_QUEUED,
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'date_count' => count($dates),
                'queued_jobs' => count($dates),
                'payload' => $range['payload'],
                'created_by' => $request->user()?->id,
            ]);
        }

        if ($discoveryRun) {
            if ($reprocessExisting) {
                $reprocessCount = $progress->rescheduleExistingRowsForRun(
                    $discoveryRun->id,
                    $range['start']->toDateString(),
                    $range['end']->toDateString()
                );

                if ($reprocessCount === 0) {
                    throw ValidationException::withMessages([
                        'run_id' => 'No existing NHL import stages were found for this run range.',
                    ]);
                }
            }

            $payload = $discoveryRun->payload ?? [];
            $payload['processing_started_at'] = now()->toIso8601String();
            $payload['processing_requested_by'] = $request->user()?->id;
            $payload['reprocess_existing'] = $reprocessExisting;

            $discoveryRun->update([
                'status' => NhlGameImportRun::STATUS_RUNNING,
                'queued_jobs' => count($dates),
                'payload' => $payload,
            ]);

            $run = $discoveryRun->refresh();
        }

        if ($discoveryRun) {
            $orchestrator->fillActiveGameSlotsForRun($run->id);
        } else {
            foreach ($dates as $date) {
                NhlOrchestratorJob::dispatch($date);
            }
        }

        broadcast(new NhlGameImportStatusUpdated('processing-queued', $run->id));

        return response()->json([
            'message' => 'Processing queued.',
            'run' => $this->serializeRun($run->refresh()),
        ], 202);
    }

    /**
     * Queue a season-level rollup from game summaries into season stats.
     */
    public function seasonSync(Request $request): JsonResponse
    {
        $input = $request->validate([
            'season' => ['required', 'digits:8'],
        ]);
        $seasonId = (string) $input['season'];
        [$start, $end] = $this->seasonBounds($seasonId);

        $run = NhlGameImportRun::create([
            'action' => NhlGameImportRun::ACTION_SEASON_SYNC,
            'mode' => NhlGameImportRun::MODE_SEASON,
            'status' => NhlGameImportRun::STATUS_QUEUED,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'date_count' => 1,
            'queued_jobs' => 1,
            'payload' => [
                'season' => $seasonId,
                'season_label' => $this->seasonLabel($seasonId),
            ],
            'created_by' => $request->user()?->id,
        ]);

        SeasonSumJob::dispatch($seasonId, $run->id);
        broadcast(new NhlGameImportStatusUpdated('season-sync-queued', $run->id));

        return response()->json([
            'message' => 'Season sync queued.',
            'run' => $this->serializeRun($run->refresh()),
        ], 202);
    }

    /**
     * Validate supported command-like date options from the admin UI.
     *
     * @return array<string, mixed>
     */
    private function validatedInput(Request $request): array
    {
        return $request->validate([
            'date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:0'],
            'newdays' => ['nullable', 'integer', 'min:1'],
            'season' => ['nullable', 'digits:8'],
            'run_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * Resolve an optional discovery run id from the process request.
     *
     * @param array<string, mixed> $input
     */
    private function discoveryRunFromInput(array $input): ?NhlGameImportRun
    {
        $runId = (int) ($input['run_id'] ?? 0);

        if ($runId <= 0) {
            return null;
        }

        $run = NhlGameImportRun::query()->find($runId);

        if (! $run || $run->action !== NhlGameImportRun::ACTION_DISCOVER) {
            throw ValidationException::withMessages([
                'run_id' => 'Choose a discovery run to process.',
            ]);
        }

        return $run;
    }

    /**
     * Build a date range from an existing discovery run.
     *
     * @return array{start: Carbon, end: Carbon, date_count: int, mode: string, payload: array<string, mixed>}
     */
    private function rangeFromRun(NhlGameImportRun $run): array
    {
        return [
            'start' => Carbon::parse($run->start_date)->startOfDay(),
            'end' => Carbon::parse($run->end_date)->startOfDay(),
            'date_count' => (int) $run->date_count,
            'mode' => (string) $run->mode,
            'payload' => $run->payload ?? [],
        ];
    }

    /**
     * Convert request options into later start and earlier end dates.
     *
     * @param array<string, mixed> $input
     * @return array{start: Carbon, end: Carbon, date_count: int, mode: string, payload: array<string, mixed>}
     */
    private function normalizeDateSelection(array $input, bool $requireExplicitSelection): array
    {
        unset($input['run_id']);

        $payload = array_filter($input, fn ($value): bool => $value !== null && $value !== '');
        $modeCount = (isset($payload['date']) ? 1 : 0)
            + (isset($payload['season']) ? 1 : 0)
            + (isset($payload['newdays']) ? 1 : 0);

        if ($modeCount > 1) {
            throw ValidationException::withMessages([
                'date' => 'Use date, season, or new days by itself.',
            ]);
        }

        if ($modeCount === 1 && (isset($payload['start']) || isset($payload['end']) || isset($payload['days']))) {
            throw ValidationException::withMessages([
                'date' => 'Date, season, and new days cannot be combined with range fields.',
            ]);
        }

        if ($requireExplicitSelection && $payload === []) {
            throw ValidationException::withMessages([
                'date' => 'Choose a date option before queuing discovery.',
            ]);
        }

        if (isset($payload['date'])) {
            $date = Carbon::parse((string) $payload['date'])->startOfDay();

            return $this->rangeResult($date, $date, NhlGameImportRun::MODE_DATE, $payload);
        }

        if (isset($payload['season'])) {
            [$start, $end] = $this->seasonBounds((string) $payload['season']);

            return $this->rangeResult($start, $end, NhlGameImportRun::MODE_SEASON, $payload);
        }

        if (isset($payload['newdays'])) {
            $oldest = DB::table('nhl_import_progress')->min('game_date');

            if (!$oldest) {
                throw ValidationException::withMessages([
                    'newdays' => 'New days requires existing NHL import progress rows.',
                ]);
            }

            $start = Carbon::parse($oldest)->startOfDay();
            $end = $start->copy()->subDays((int) $payload['newdays']);

            return $this->rangeResult($start, $end, NhlGameImportRun::MODE_NEWDAYS, $payload);
        }

        $start = isset($payload['start']) ? Carbon::parse((string) $payload['start'])->startOfDay() : null;
        $end = isset($payload['end']) ? Carbon::parse((string) $payload['end'])->startOfDay() : null;
        $days = isset($payload['days']) ? (int) $payload['days'] : null;

        if ($start && $end) {
            return $this->rangeResult($start, $end, NhlGameImportRun::MODE_RANGE, $payload);
        }

        if ($start && $days !== null) {
            return $this->rangeResult($start, $start->copy()->subDays($days), NhlGameImportRun::MODE_DAYS, $payload);
        }

        if ($end && $days !== null) {
            return $this->rangeResult($end->copy()->addDays($days), $end, NhlGameImportRun::MODE_DAYS, $payload);
        }

        if ($days !== null) {
            $today = Carbon::today();

            return $this->rangeResult($today, $today->copy()->subDays($days), NhlGameImportRun::MODE_DAYS, $payload);
        }

        if ($start) {
            return $this->rangeResult($start, $start, NhlGameImportRun::MODE_RANGE, $payload);
        }

        if ($end) {
            return $this->rangeResult($end, $end, NhlGameImportRun::MODE_RANGE, $payload);
        }

        $today = Carbon::today();

        return $this->rangeResult($today, $today, NhlGameImportRun::MODE_DEFAULT, $payload);
    }

    /**
     * Build a normalized range response.
     *
     * @param array<string, mixed> $payload
     * @return array{start: Carbon, end: Carbon, date_count: int, mode: string, payload: array<string, mixed>}
     */
    private function rangeResult(Carbon $start, Carbon $end, string $mode, array $payload): array
    {
        if ($start->lt($end)) {
            [$start, $end] = [$end, $start];
        }

        $dateCount = (int) $end->diffInDays($start) + 1;

        return [
            'start' => $start,
            'end' => $end,
            'date_count' => $dateCount,
            'mode' => $mode,
            'payload' => $payload,
        ];
    }

    /**
     * Calculate NHL season date bounds using the discovery command convention.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function seasonBounds(string $seasonId): array
    {
        $startYear = (int) substr($seasonId, 0, 4);
        $endYear = (int) substr($seasonId, 4, 4);

        if ($endYear !== $startYear + 1) {
            throw ValidationException::withMessages([
                'season' => 'Season must be a contiguous season id like 20252026.',
            ]);
        }

        return [
            Carbon::create($endYear, 8, 31)->startOfDay(),
            Carbon::create($startYear, 9, 1)->startOfDay(),
        ];
    }

    /**
     * Return inclusive date strings from later date to earlier date.
     *
     * @return array<int, string>
     */
    private function dateStrings(Carbon $start, Carbon $end): array
    {
        $dates = [];

        for ($cursor = $start->copy(); $cursor->gte($end); $cursor->subDay()) {
            $dates[] = $cursor->toDateString();
        }

        return $dates;
    }

    /**
     * Serialize a run with current import-progress-derived counts.
     *
     * @return array<string, mixed>
     */
    private function serializeRun(NhlGameImportRun $run): array
    {
        $progress = $this->progressForRun($run);

        return [
            'id' => $run->id,
            'action' => $run->action,
            'processing_started' => (bool) (($run->payload ?? [])['processing_started_at'] ?? false),
            'mode' => $run->mode,
            'status' => $progress['status'],
            'stored_status' => $run->status,
            'start_date' => $run->start_date?->toDateString(),
            'end_date' => $run->end_date?->toDateString(),
            'date_count' => $run->date_count,
            'queued_jobs' => $run->queued_jobs,
            'payload' => $run->payload ?? [],
            'last_error' => $run->last_error,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'progress' => $progress,
            'facts' => $run->action === NhlGameImportRun::ACTION_SEASON_SYNC ? [] : $this->factsForRun($run),
            'games' => $run->action === NhlGameImportRun::ACTION_SEASON_SYNC ? [] : $this->gamesForRun($run),
        ];
    }

    /**
     * Summarize NHL import progress rows inside a run's date window.
     *
     * @return array<string, mixed>
     */
    private function progressForRun(NhlGameImportRun $run): array
    {
        if ($run->action === NhlGameImportRun::ACTION_SEASON_SYNC) {
            return $this->seasonSyncProgressForRun($run);
        }

        $rows = $this->progressQueryForRun($run)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $scheduled = (int) ($rows['scheduled'] ?? 0);
        $running = (int) ($rows['running'] ?? 0);
        $completed = (int) ($rows['completed'] ?? 0);
        $skipped = (int) ($rows['skipped'] ?? 0);
        $failed = (int) ($rows['error'] ?? 0);
        $total = $scheduled + $running + $completed + $skipped + $failed;
        $percentage = $total > 0 ? (int) floor((($completed + $skipped) / $total) * 100) : 0;
        $status = $this->computedStatus($run, $total, $scheduled, $running, $completed, $skipped, $failed);
        $lastError = $this->progressQueryForRun($run)
            ->where('status', 'error')
            ->whereNotNull('last_error')
            ->latest('updated_at')
            ->value('last_error');

        return [
            'status' => $status,
            'total_stage_rows' => $total,
            'scheduled_stage_rows' => $scheduled,
            'running_stage_rows' => $running,
            'completed_stage_rows' => $completed,
            'skipped_stage_rows' => $skipped,
            'failed_stage_rows' => $failed,
            'percentage' => $percentage,
            'last_error' => $lastError,
        ];
    }

    /**
     * Serialize progress for a season stats rollup run.
     *
     * @return array<string, mixed>
     */
    private function seasonSyncProgressForRun(NhlGameImportRun $run): array
    {
        $status = $run->status;
        $percentage = match ($status) {
            NhlGameImportRun::STATUS_COMPLETED, NhlGameImportRun::STATUS_FAILED => 100,
            NhlGameImportRun::STATUS_RUNNING => 35,
            default => 0,
        };

        return [
            'status' => $status,
            'total_stage_rows' => 1,
            'scheduled_stage_rows' => $status === NhlGameImportRun::STATUS_QUEUED ? 1 : 0,
            'running_stage_rows' => $status === NhlGameImportRun::STATUS_RUNNING ? 1 : 0,
            'completed_stage_rows' => $status === NhlGameImportRun::STATUS_COMPLETED ? 1 : 0,
            'skipped_stage_rows' => 0,
            'failed_stage_rows' => $status === NhlGameImportRun::STATUS_FAILED ? 1 : 0,
            'percentage' => $percentage,
            'last_error' => $run->last_error,
        ];
    }

    /**
     * Return known import facts inside a run's date window.
     *
     * @return array<string, int>
     */
    private function factsForRun(NhlGameImportRun $run): array
    {
        $query = $this->progressQueryForRun($run);

        return [
            'selected_date_count' => $run->date_count,
            'discovered_game_date_count' => (int) (clone $query)->distinct()->count('game_date'),
            'discovered_game_count' => (int) (clone $query)->distinct()->count('game_id'),
            'scheduled_stage_rows' => (int) (clone $query)->where('status', 'scheduled')->count(),
            'skipped_stage_rows' => (int) (clone $query)->where('status', 'skipped')->count(),
            'total_stage_rows' => (int) (clone $query)->count(),
        ];
    }

    /**
     * Return per-game import progress inside a run's date window.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gamesForRun(NhlGameImportRun $run): array
    {
        $rows = DB::table('nhl_import_progress as progress')
            ->select([
                'progress.game_id',
                'progress.game_date',
                'progress.import_type',
                'progress.status',
                'progress.last_error',
            ])
            ->when(
                $this->hasRunScopedProgress($run),
                fn ($query) => $query->where('progress.run_id', $run->id),
                fn ($query) => $query->whereBetween(
                    'progress.game_date',
                    [$run->end_date->toDateString(), $run->start_date->toDateString()]
                )
            )
            ->orderBy('progress.game_date')
            ->orderBy('progress.game_id')
            ->orderBy('progress.import_type')
            ->get();

        $games = DB::table('nhl_games')
            ->select(['nhl_game_id', 'away_team_abbrev', 'home_team_abbrev'])
            ->whereIn('nhl_game_id', $rows->pluck('game_id')->unique()->values()->all())
            ->get()
            ->keyBy(fn ($game) => (string) $game->nhl_game_id);
        $sourceStatuses = DB::table('nhl_game_source_statuses')
            ->select(['nhl_game_id', 'source', 'status', 'reason', 'url', 'details', 'checked_at'])
            ->whereIn('nhl_game_id', $rows->pluck('game_id')->unique()->values()->all())
            ->orderBy('source')
            ->get()
            ->groupBy(fn ($status) => (string) $status->nhl_game_id);

        return $rows
            ->groupBy('game_id')
            ->map(function ($gameRows, $gameId) use ($games, $sourceStatuses): array {
                $scheduled = (int) $gameRows->where('status', 'scheduled')->count();
                $running = (int) $gameRows->where('status', 'running')->count();
                $completed = (int) $gameRows->where('status', 'completed')->count();
                $skipped = (int) $gameRows->where('status', 'skipped')->count();
                $failed = (int) $gameRows->where('status', 'error')->count();
                $total = $scheduled + $running + $completed + $skipped + $failed;
                $first = $gameRows->first();
                $game = $games->get((string) $gameId);
                $statuses = $sourceStatuses
                    ->get((string) $gameId, collect())
                    ->map(fn ($status): array => [
                        'source' => $status->source,
                        'status' => $status->status,
                        'reason' => $status->reason,
                        'url' => $status->url,
                        'details' => is_string($status->details)
                            ? json_decode($status->details, true)
                            : $status->details,
                        'checked_at' => $status->checked_at,
                    ])
                    ->values()
                    ->all();
                $blockedSources = array_values(array_filter(
                    $statuses,
                    fn (array $status): bool => $status['status'] !== 'available'
                ));

                return [
                    'game_id' => $gameId,
                    'game_date' => $first->game_date,
                    'away_team_abbrev' => $game?->away_team_abbrev,
                    'home_team_abbrev' => $game?->home_team_abbrev,
                    'scheduled_stage_rows' => $scheduled,
                    'running_stage_rows' => $running,
                    'completed_stage_rows' => $completed,
                    'skipped_stage_rows' => $skipped,
                    'failed_stage_rows' => $failed,
                    'total_stage_rows' => $total,
                    'percentage' => $total > 0 ? (int) floor((($completed + $skipped) / $total) * 100) : 0,
                    'last_error' => $gameRows->where('status', 'error')->pluck('last_error')->filter()->first(),
                    'source_statuses' => $statuses,
                    'blocked_sources' => $blockedSources,
                ];
            })
            ->values()
            ->all();
    }

    private function hasRunScopedProgress(NhlGameImportRun $run): bool
    {
        return DB::table('nhl_import_progress')
            ->where('run_id', $run->id)
            ->exists();
    }

    private function progressQueryForRun(NhlGameImportRun $run)
    {
        $query = DB::table('nhl_import_progress');

        if ($this->hasRunScopedProgress($run)) {
            return $query->where('run_id', $run->id);
        }

        return $query->whereBetween('game_date', [
            $run->end_date->toDateString(),
            $run->start_date->toDateString(),
        ]);
    }

    /**
     * Return source gaps grouped by game for the admin source-health queue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sourceGapRows(): array
    {
        $rows = DB::table('nhl_game_source_statuses as statuses')
            ->leftJoin('nhl_games as games', 'games.nhl_game_id', '=', 'statuses.nhl_game_id')
            ->select([
                'statuses.nhl_game_id',
                'statuses.source',
                'statuses.status',
                'statuses.reason',
                'statuses.url',
                'statuses.details',
                'statuses.checked_at',
                'games.game_date',
                'games.away_team_abbrev',
                'games.home_team_abbrev',
            ])
            ->where('statuses.status', '!=', NhlGameSourceStatus::STATUS_AVAILABLE)
            ->orderByDesc('statuses.checked_at')
            ->orderBy('statuses.nhl_game_id')
            ->orderBy('statuses.source')
            ->get();

        return $rows
            ->groupBy('nhl_game_id')
            ->map(function ($gameRows, $gameId): array {
                $first = $gameRows->first();

                return [
                    'game_id' => (int) $gameId,
                    'game_date' => $first->game_date,
                    'away_team_abbrev' => $first->away_team_abbrev,
                    'home_team_abbrev' => $first->home_team_abbrev,
                    'sources' => $gameRows->map(fn ($row): array => [
                        'source' => $row->source,
                        'status' => $row->status,
                        'reason' => $row->reason,
                        'url' => $row->url,
                        'details' => is_string($row->details)
                            ? json_decode($row->details, true)
                            : $row->details,
                        'checked_at' => $row->checked_at,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $sources
     */
    private function hasCoreGap(array $sources): bool
    {
        return in_array(NhlGameSourceStatus::SOURCE_PBP, $sources, true)
            || in_array(NhlGameSourceStatus::SOURCE_BOXSCORE, $sources, true);
    }

    /**
     * Count distinct game dates that currently have scheduled stage work.
     */
    private function processableDateCount(): int
    {
        return (int) DB::table('nhl_import_progress')
            ->where('status', 'scheduled')
            ->distinct()
            ->count('game_date');
    }

    /**
     * Return seasons with imported NHL games for season-sync controls.
     *
     * @return array<int, array{season: string, label: string}>
     */
    private function availableSeasons(): array
    {
        return DB::table('nhl_games')
            ->select('season_id')
            ->whereNotNull('season_id')
            ->distinct()
            ->orderByDesc('season_id')
            ->pluck('season_id')
            ->map(fn ($seasonId): array => [
                'season' => (string) $seasonId,
                'label' => $this->seasonLabel((string) $seasonId),
            ])
            ->values()
            ->all();
    }

    /**
     * Format an NHL season id for admin controls.
     */
    private function seasonLabel(string $seasonId): string
    {
        if (! preg_match('/^\d{8}$/', $seasonId)) {
            return $seasonId;
        }

        return substr($seasonId, 0, 4) . '-' . substr($seasonId, 6, 2);
    }

    /**
     * Derive the user-facing run state from pipeline stage counts.
     */
    private function computedStatus(
        NhlGameImportRun $run,
        int $total,
        int $scheduled,
        int $running,
        int $completed,
        int $skipped,
        int $failed
    ): string {
        if ($failed > 0) {
            return NhlGameImportRun::STATUS_FAILED;
        }

        $processingStarted = (bool) (($run->payload ?? [])['processing_started_at'] ?? false);

        if ($run->action === NhlGameImportRun::ACTION_DISCOVER && ! $processingStarted && $total > 0) {
            return NhlGameImportRun::STATUS_COMPLETED;
        }

        if ($total > 0 && ($completed + $skipped) === $total) {
            return NhlGameImportRun::STATUS_COMPLETED;
        }

        if ($running > 0 || $scheduled > 0 || $completed > 0) {
            return NhlGameImportRun::STATUS_RUNNING;
        }

        return $run->status;
    }
}
