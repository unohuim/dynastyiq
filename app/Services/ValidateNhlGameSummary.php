<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameValidation;
use App\Models\NhlGameValidationDelta;
use App\Models\NhlGame;
use App\Models\NhlBoxscore;
use App\Models\NhlGameSummary;
use App\Models\PlayByPlay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists summary-vs-boxscore validation state for one NHL game.
 */
class ValidateNhlGameSummary
{
    public function __construct(
        private readonly CompareNhlPbPBoxscore $comparator,
        private readonly NhlValidationTroubleshootingExporter $troubleshootingExporter,
        private readonly NhlGameSourcePreflight $sourcePreflight,
    ) {
    }

    /**
     * Run validation and persist the latest state.
     */
    public function validate(int $gameId): NhlGameValidation
    {
        $deltas = $this->comparator->compare($gameId);

        if ($this->applyTinySkaterToiReconciliations($gameId, $deltas)) {
            $deltas = $this->comparator->compare($gameId);
        }

        if ($this->applyTinyZeroAppearanceGoalieShiftReconciliations($gameId, $deltas)) {
            $deltas = $this->comparator->compare($gameId);
        }

        $status = $this->validationStatus($gameId, $deltas);

        $validation = DB::transaction(function () use ($gameId, $deltas, $status): NhlGameValidation {
            $validation = NhlGameValidation::updateOrCreate(
                [
                    'nhl_game_id' => $gameId,
                    'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
                ],
                [
                    'status' => $status,
                    'mismatch_count' => count($deltas),
                    'checked_at' => now(),
                    'approved_at' => $status === NhlGameValidation::STATUS_APPROVED ? now() : null,
                    'approved_by' => null,
                ]
            );

            $validation->deltas()->delete();

            foreach ($deltas as $delta) {
                NhlGameValidationDelta::create([
                    'validation_id' => $validation->id,
                    'nhl_player_id' => $delta['nhl_player_id'] ?? null,
                    'field' => $delta['field'],
                    'boxscore_value' => $this->stringValue($delta['boxscore_value'] ?? null),
                    'summary_value' => $this->stringValue($delta['summary_value'] ?? null),
                    'delta' => $delta['delta'] ?? null,
                    'severity' => $delta['severity'] ?? NhlGameValidationDelta::SEVERITY_ERROR,
                ]);
            }

            return $validation->refresh();
        });

        if (!empty($deltas)) {
            try {
                $this->troubleshootingExporter->export($validation);
            } catch (\Throwable $exception) {
                Log::warning('Failed to export NHL validation troubleshooting snapshots.', [
                    'game_id' => $gameId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $validation;
    }

    /**
     * @param array<int,array<string,mixed>> $deltas
     */
    private function validationStatus(int $gameId, array $deltas): string
    {
        if (! empty($deltas)) {
            $gameType = NhlGame::query()
                ->where('nhl_game_id', $gameId)
                ->value('game_type');

            if ((int) $gameType === 1) {
                return NhlGameValidation::STATUS_INVALIDATED;
            }

            return NhlGameValidation::STATUS_FAILED;
        }

        if (! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return NhlGameValidation::STATUS_INCOMPLETE;
        }

        return NhlGameValidation::STATUS_APPROVED;
    }

    /**
     * Accept official skater TOI for tiny TOI-only clock discrepancies.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function applyTinySkaterToiReconciliations(int $gameId, array $deltas): bool
    {
        if (empty($deltas) || ! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return false;
        }

        $changed = false;
        $deltasByPlayer = collect($deltas)
            ->filter(fn (array $delta): bool => isset($delta['nhl_player_id']))
            ->groupBy(fn (array $delta): int => (int) $delta['nhl_player_id']);

        foreach ($deltasByPlayer as $playerId => $playerDeltas) {
            if ($playerDeltas->count() !== 1) {
                continue;
            }

            $delta = $playerDeltas->first();

            if (($delta['field'] ?? null) !== 'toi_seconds' || abs((float) ($delta['delta'] ?? 0)) >= 20) {
                continue;
            }

            $boxscore = NhlBoxscore::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['position', 'shifts', 'toi_seconds']);

            $summary = NhlGameSummary::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['id', 'shifts', 'toi']);

            if (
                ! $boxscore
                || ! $summary
                || strtoupper((string) $boxscore->position) === 'G'
                || (int) $boxscore->shifts !== (int) $summary->shifts
                || $boxscore->toi_seconds === null
            ) {
                continue;
            }

            $summary->forceFill([
                'toi' => (int) $boxscore->toi_seconds,
            ])->save();

            $changed = true;

            Log::info('Applied tiny NHL skater TOI reconciliation from official boxscore.', [
                'game_id' => $gameId,
                'nhl_player_id' => (int) $playerId,
                'summary_toi_seconds' => (int) ($delta['summary_value'] ?? $summary->toi),
                'boxscore_toi_seconds' => (int) $boxscore->toi_seconds,
                'delta_seconds' => (float) ($delta['delta'] ?? 0),
            ]);
        }

        return $changed;
    }

    /**
     * Accept official zero-appearance goalie totals for tiny shiftchart-only artifacts.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function applyTinyZeroAppearanceGoalieShiftReconciliations(int $gameId, array $deltas): bool
    {
        if (empty($deltas) || ! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return false;
        }

        $changed = false;
        $deltasByPlayer = collect($deltas)
            ->filter(fn (array $delta): bool => isset($delta['nhl_player_id']))
            ->groupBy(fn (array $delta): int => (int) $delta['nhl_player_id']);

        foreach ($deltasByPlayer as $playerId => $playerDeltas) {
            $fields = $playerDeltas
                ->pluck('field')
                ->map(static fn (mixed $field): string => (string) $field)
                ->unique()
                ->values()
                ->all();

            if (array_diff($fields, ['toi_seconds', 'shifts']) !== []) {
                continue;
            }

            $toiDelta = $playerDeltas->firstWhere('field', 'toi_seconds');

            if (! is_array($toiDelta) || abs((float) ($toiDelta['delta'] ?? 0)) >= 30) {
                continue;
            }

            $boxscore = NhlBoxscore::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['position', 'shifts', 'toi_seconds']);

            $summary = NhlGameSummary::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['id', 'shifts', 'toi']);

            if (
                ! $boxscore
                || ! $summary
                || strtoupper((string) $boxscore->position) !== 'G'
                || (int) ($boxscore->toi_seconds ?? 0) !== 0
                || (int) ($boxscore->shifts ?? 0) !== 0
                || $this->playByPlayShowsGoalieInNet($gameId, (int) $playerId)
            ) {
                continue;
            }

            $summary->forceFill([
                'toi' => 0,
                'shifts' => 0,
            ])->save();

            $changed = true;

            Log::info('Applied tiny NHL zero-appearance goalie shiftchart reconciliation from official boxscore.', [
                'game_id' => $gameId,
                'nhl_player_id' => (int) $playerId,
                'summary_toi_seconds' => (int) ($toiDelta['summary_value'] ?? $summary->toi),
                'boxscore_toi_seconds' => (int) ($boxscore->toi_seconds ?? 0),
                'delta_seconds' => (float) ($toiDelta['delta'] ?? 0),
            ]);
        }

        return $changed;
    }

    private function playByPlayShowsGoalieInNet(int $gameId, int $playerId): bool
    {
        return PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->where('goalie_in_net_player_id', $playerId)
            ->exists();
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
