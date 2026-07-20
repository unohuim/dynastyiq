<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Repositories\NhlImportProgressRepo;
use App\Support\NhlImportStages;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () use ($gameId): void {
            $unitShiftIds = DB::table('nhl_unit_shifts')
                ->where('nhl_game_id', $gameId)
                ->pluck('id');

            if ($unitShiftIds->isNotEmpty()) {
                DB::table('event_unit_shifts')
                    ->whereIn('unit_shift_id', $unitShiftIds)
                    ->delete();
            }

            DB::table('nhl_unit_game_strength_summaries')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_player_game_strength_summaries')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_unit_game_summaries')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_unit_shifts')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_shifts')->where('nhl_game_id', $gameId)->delete();

            $validationIds = DB::table('nhl_game_validations')
                ->where('nhl_game_id', $gameId)
                ->pluck('id');

            if ($validationIds->isNotEmpty()) {
                DB::table('nhl_game_validation_deltas')
                    ->whereIn('validation_id', $validationIds)
                    ->delete();
            }

            DB::table('nhl_game_validations')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_boxscores')->where('nhl_game_id', $gameId)->delete();
            DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId)->delete();
            DB::table('play_by_plays')->where('nhl_game_id', $gameId)->delete();

            DB::table('nhl_import_progress')->where('game_id', (string) $gameId)->delete();
        });
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
