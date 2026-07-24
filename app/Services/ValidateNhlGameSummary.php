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

        if ($this->applyGoalieShiftCountReconciliations($gameId, $deltas)) {
            $deltas = $this->comparator->compare($gameId);
        }

        if ($this->applyLimitedPreseasonBoxscoreReconciliations($gameId, $deltas)) {
            $deltas = $this->comparator->compare($gameId);
        }

        $shiftchartMismatchDeltas = [];

        if ($this->isShiftchartMismatch($gameId, $deltas)) {
            $shiftchartMismatchDeltas = $deltas;

            if ($this->applyOfficialShiftchartMismatchTotals($gameId, $deltas)) {
                $deltas = $this->comparator->compare($gameId);
            }
        }

        if ($shiftchartMismatchDeltas === [] && empty($deltas)) {
            $shiftchartMismatchDeltas = $this->existingShiftchartMismatchDeltas($gameId);
        }

        $status = $shiftchartMismatchDeltas === []
            ? $this->validationStatus($gameId, $deltas)
            : NhlGameValidation::STATUS_SHIFTCHART_MISMATCH;
        $persistedDeltas = $shiftchartMismatchDeltas === [] ? $deltas : $shiftchartMismatchDeltas;

        $validation = DB::transaction(function () use ($gameId, $persistedDeltas, $status): NhlGameValidation {
            $validation = NhlGameValidation::updateOrCreate(
                [
                    'nhl_game_id' => $gameId,
                    'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
                ],
                [
                    'status' => $status,
                    'mismatch_count' => count($persistedDeltas),
                    'checked_at' => now(),
                    'approved_at' => $status === NhlGameValidation::STATUS_APPROVED ? now() : null,
                    'approved_by' => null,
                ]
            );

            $validation->deltas()->delete();

            foreach ($persistedDeltas as $delta) {
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

        if (! empty($persistedDeltas) && $validation->shouldRetainTroubleshootingDirectory()) {
            try {
                $this->troubleshootingExporter->export($validation);
            } catch (\Throwable $exception) {
                Log::warning('Failed to export NHL validation troubleshooting snapshots.', [
                    'game_id' => $gameId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($validation->shouldDeleteTroubleshootingDirectory()) {
            $this->troubleshootingExporter->deleteGameDirectory($gameId);
        }

        return $validation;
    }

    /**
     * @param array<int,array<string,mixed>> $deltas
     */
    private function validationStatus(int $gameId, array $deltas): string
    {
        if (! empty($deltas)) {
            return NhlGameValidation::STATUS_INVALIDATED;
        }

        if (! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return NhlGameValidation::STATUS_INCOMPLETE;
        }

        return NhlGameValidation::STATUS_APPROVED;
    }

    /**
     * Accept official skater boxscore totals when preseason PBP is explicitly limited.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function applyLimitedPreseasonBoxscoreReconciliations(int $gameId, array $deltas): bool
    {
        if (empty($deltas) || ! $this->isLimitedPreseasonGame($gameId)) {
            return false;
        }

        $changed = false;
        $playerIds = collect($deltas)
            ->pluck('nhl_player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values();

        foreach ($playerIds as $playerId) {
            $boxscore = NhlBoxscore::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first([
                    'nhl_team_id',
                    'position',
                    'goals',
                    'assists',
                    'points',
                    'plus_minus',
                    'penalty_minutes',
                    'sog',
                    'hits',
                    'blocks',
                    'faceoffs_won',
                    'faceoffs_lost',
                    'power_play_goals',
                    'faceoff_win_percentage',
                ]);

            $summary = NhlGameSummary::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first();

            if (! $boxscore || ! $summary || strtoupper((string) $boxscore->position) === 'G') {
                continue;
            }

            $sog = (int) $boxscore->sog;
            $goals = (int) $boxscore->goals;
            $powerPlayGoals = (int) $boxscore->power_play_goals;
            $faceoffsWon = (int) $boxscore->faceoffs_won;
            $faceoffsLost = (int) $boxscore->faceoffs_lost;

            $summary->forceFill([
                'nhl_team_id' => (int) $boxscore->nhl_team_id,
                'g' => $goals,
                'a' => (int) $boxscore->assists,
                'pts' => (int) $boxscore->points,
                'plus_minus' => (int) $boxscore->plus_minus,
                'pim' => (int) $boxscore->penalty_minutes,
                'sog' => $sog,
                'h' => (int) $boxscore->hits,
                'b' => (int) $boxscore->blocks,
                'ppg' => $powerPlayGoals,
                'fow' => $faceoffsWon,
                'fol' => $faceoffsLost,
                'fot' => $faceoffsWon + $faceoffsLost,
                'fow_percentage' => round(((float) $boxscore->faceoff_win_percentage) * 100, 2),
                'sog_p' => $sog > 0 ? round($goals / $sog, 3) : 0.0,
            ])->save();

            $changed = true;

            Log::info('Applied limited preseason NHL skater summary reconciliation from official boxscore.', [
                'game_id' => $gameId,
                'nhl_player_id' => $playerId,
            ]);
        }

        return $changed;
    }

    private function isLimitedPreseasonGame(int $gameId): bool
    {
        return NhlGame::query()
            ->where('nhl_game_id', $gameId)
            ->where('game_type', 1)
            ->where('limited_scoring', true)
            ->exists();
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

    /**
     * Accept official goalie shift-count convention when trusted TOI already matches.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function applyGoalieShiftCountReconciliations(int $gameId, array $deltas): bool
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

            if ($fields !== ['shifts']) {
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
                || $boxscore->toi_seconds === null
                || abs((int) $boxscore->toi_seconds - (int) $summary->toi) > 1
            ) {
                continue;
            }

            $summary->forceFill([
                'shifts' => (int) $boxscore->shifts,
            ])->save();

            $changed = true;

            Log::info('Applied NHL goalie shift-count reconciliation from official boxscore convention.', [
                'game_id' => $gameId,
                'nhl_player_id' => (int) $playerId,
                'summary_shifts' => (int) $playerDeltas->first()['summary_value'],
                'boxscore_shifts' => (int) $boxscore->shifts,
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

    /**
     * @param array<int,array<string,mixed>> $deltas
     */
    private function isShiftchartMismatch(int $gameId, array $deltas): bool
    {
        if (empty($deltas) || ! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return false;
        }

        $gameType = NhlGame::query()
            ->where('nhl_game_id', $gameId)
            ->value('game_type');

        if ((int) $gameType === 1) {
            return false;
        }

        foreach ($deltas as $delta) {
            if (
                ! isset($delta['nhl_player_id'])
                || ! in_array($delta['field'] ?? null, ['toi_seconds', 'shifts'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accept official boxscore TOI and shift totals once remaining deltas prove
     * only unreconcilable shiftchart disagreement.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function applyOfficialShiftchartMismatchTotals(int $gameId, array $deltas): bool
    {
        $changed = false;
        $playerIds = collect($deltas)
            ->pluck('nhl_player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values();

        foreach ($playerIds as $playerId) {
            $boxscore = NhlBoxscore::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['shifts', 'toi_seconds']);

            $summary = NhlGameSummary::query()
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->first(['id', 'shifts', 'toi']);

            if (! $boxscore || ! $summary || $boxscore->toi_seconds === null) {
                continue;
            }

            $summary->forceFill([
                'toi' => (int) $boxscore->toi_seconds,
                'shifts' => (int) $boxscore->shifts,
            ])->save();

            $changed = true;
        }

        if ($changed) {
            Log::info('Accepted official boxscore TOI and shifts for NHL shiftchart mismatch validation.', [
                'game_id' => $gameId,
                'player_count' => $playerIds->count(),
                'delta_count' => count($deltas),
            ]);
        }

        return $changed;
    }

    /**
     * Keep an auditable shiftchart mismatch when a later validation sees no
     * deltas only because official boxscore TOI and shifts were already applied.
     *
     * @return array<int,array<string,mixed>>
     */
    private function existingShiftchartMismatchDeltas(int $gameId): array
    {
        $validation = NhlGameValidation::query()
            ->where('nhl_game_id', $gameId)
            ->where('validation_type', NhlGameValidation::TYPE_SUMMARY_BOXSCORE)
            ->with('deltas')
            ->first();

        if (! $validation || ! in_array($validation->status, [
            NhlGameValidation::STATUS_FAILED,
            NhlGameValidation::STATUS_SHIFTCHART_MISMATCH,
        ], true)) {
            return [];
        }

        $deltas = $validation->deltas
            ->map(static fn (NhlGameValidationDelta $delta): array => [
                'nhl_player_id' => $delta->nhl_player_id,
                'field' => $delta->field,
                'boxscore_value' => $delta->boxscore_value,
                'summary_value' => $delta->summary_value,
                'delta' => $delta->delta,
                'severity' => $delta->severity,
            ])
            ->values()
            ->all();

        return $this->isShiftchartMismatch($gameId, $deltas) ? $deltas : [];
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
