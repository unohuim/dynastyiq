<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameValidation;
use App\Models\NhlGameValidationDelta;
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
            return NhlGameValidation::STATUS_FAILED;
        }

        if (! $this->sourcePreflight->storedShiftsAvailable($gameId)) {
            return NhlGameValidation::STATUS_INCOMPLETE;
        }

        return NhlGameValidation::STATUS_APPROVED;
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
