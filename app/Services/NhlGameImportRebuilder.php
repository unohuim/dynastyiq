<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Repositories\NhlImportProgressRepo;
use App\Support\NhlImportStages;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears game-scoped NHL import data and requeues the canonical pipeline.
 */
class NhlGameImportRebuilder
{
    public function __construct(
        private readonly NhlImportProgressRepo $progress,
        private readonly NhlImportOrchestrator $orchestrator
    ) {
    }

    /**
     * Clear raw and derived game data, then queue the game pipeline from PBP.
     */
    public function rebuild(int $gameId, ?int $runId = null): bool
    {
        $context = $this->gameContext($gameId);

        $this->clearGameScopedData($gameId);
        $this->seedProgressRows($gameId, $context, $runId);

        return $this->orchestrator->dispatchJob($gameId, NhlImportStages::PBP, $runId);
    }

    /**
     * Clear raw and derived rows owned by one NHL game.
     */
    public function clearGameScopedData(int $gameId): void
    {
        $startedAt = microtime(true);
        $steps = [];

        try {
            DB::transaction(function () use ($gameId, &$steps): void {
                $unitShiftIds = DB::table('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId)
                    ->pluck('id');

                if ($unitShiftIds->isNotEmpty()) {
                    $this->deleteAndRecord(
                        'event_unit_shifts_by_unit_shift',
                        fn (): Builder => DB::table('event_unit_shifts')->whereIn('unit_shift_id', $unitShiftIds),
                        $steps
                    );

                    $this->deleteAndRecord(
                        'nhl_unit_shift_players',
                        fn (): Builder => DB::table('nhl_unit_shift_players')->whereIn('unit_shift_id', $unitShiftIds),
                        $steps
                    );
                }

                $this->deleteAndRecord(
                    'event_unit_shifts_by_event',
                    fn (): Builder => DB::table('event_unit_shifts')
                        ->whereIn('event_id', function ($query) use ($gameId): void {
                            $query->select('id')
                                ->from('play_by_plays')
                                ->where('nhl_game_id', $gameId);
                        }),
                    $steps
                );

                $this->deleteAndRecord(
                    'nhl_unit_game_strength_summaries',
                    fn (): Builder => DB::table('nhl_unit_game_strength_summaries')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_player_game_strength_summaries',
                    fn (): Builder => DB::table('nhl_player_game_strength_summaries')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_unit_game_summaries',
                    fn (): Builder => DB::table('nhl_unit_game_summaries')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_unit_shifts',
                    fn (): Builder => DB::table('nhl_unit_shifts')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_shifts',
                    fn (): Builder => DB::table('nhl_shifts')->where('nhl_game_id', $gameId),
                    $steps
                );

                $validationIds = DB::table('nhl_game_validations')
                    ->where('nhl_game_id', $gameId)
                    ->pluck('id');

                if ($validationIds->isNotEmpty()) {
                    $this->deleteAndRecord(
                        'nhl_game_validation_deltas',
                        fn (): Builder => DB::table('nhl_game_validation_deltas')->whereIn('validation_id', $validationIds),
                        $steps
                    );
                    $this->deleteAndRecord(
                        'nhl_pbp_source_mismatches',
                        fn (): Builder => DB::table('nhl_pbp_source_mismatches')->whereIn('validation_id', $validationIds),
                        $steps
                    );
                }

                $this->deleteAndRecord(
                    'nhl_game_validations',
                    fn (): Builder => DB::table('nhl_game_validations')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_boxscores',
                    fn (): Builder => DB::table('nhl_boxscores')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'nhl_game_summaries',
                    fn (): Builder => DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId),
                    $steps
                );
                $this->deleteAndRecord(
                    'play_by_plays',
                    fn (): Builder => DB::table('play_by_plays')->where('nhl_game_id', $gameId),
                    $steps
                );

                $this->deleteAndRecord(
                    'nhl_import_progress',
                    fn (): Builder => DB::table('nhl_import_progress')->where('game_id', (string) $gameId),
                    $steps
                );
            });
        } catch (Throwable $exception) {
            Log::warning('NHL game scoped import data clear failed.', [
                'game_id' => $gameId,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'steps' => $steps,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('NHL game scoped import data cleared.', [
            'game_id' => $gameId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'steps' => $steps,
        ]);
    }

    /**
     * Delete rows from a game-scoped query and record clear-phase timing.
     *
     * @param callable(): Builder $queryFactory
     * @param list<array{step:string,deleted:int,duration_ms:int}> $steps
     */
    private function deleteAndRecord(string $step, callable $queryFactory, array &$steps): void
    {
        $startedAt = microtime(true);
        $deleted = (int) $queryFactory()->delete();

        $steps[] = [
            'step' => $step,
            'deleted' => $deleted,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Reseed scheduled progress rows for one game.
     *
     * @param array{season_id:string,game_date:string,game_type:int|null} $context
     */
    public function seedProgressRows(int $gameId, array $context, ?int $runId = null): void
    {
        $now = now();
        $rows = collect(NhlImportStages::ordered())->map(fn (string $stage): array => [
            'run_id' => $runId,
            'season_id' => (string) $context['season_id'],
            'game_date' => $context['game_date'],
            'game_id' => (string) $gameId,
            'game_type' => $context['game_type'],
            'import_type' => $stage,
            'items_count' => 0,
            'status' => 'scheduled',
            'discovered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $this->progress->insertScheduledRows($rows);
    }

    /**
     * Resolve the season/date/type values needed to reseed progress rows.
     *
     * @return array{season_id:string,game_date:string,game_type:int|null}
     */
    public function gameContext(int $gameId): array
    {
        $progress = DB::table('nhl_import_progress')
            ->where('game_id', (string) $gameId)
            ->first(['season_id', 'game_date', 'game_type']);

        if ($progress) {
            return [
                'season_id' => (string) $progress->season_id,
                'game_date' => (string) $progress->game_date,
                'game_type' => $progress->game_type === null ? null : (int) $progress->game_type,
            ];
        }

        $game = NhlGame::where('nhl_game_id', $gameId)->first(['season_id', 'game_date', 'game_type']);

        if (! $game) {
            throw new \RuntimeException("Cannot rebuild NHL game {$gameId}; no game or progress context exists.");
        }

        return [
            'season_id' => (string) $game->season_id,
            'game_date' => (string) $game->game_date,
            'game_type' => $game->game_type === null ? null : (int) $game->game_type,
        ];
    }
}
