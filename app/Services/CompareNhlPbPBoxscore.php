<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameSummary;
use App\Models\NhlGameValidationDelta;
use App\Models\PlayByPlay;

/**
 * Compares computed NHL game summaries against official NHL boxscore totals.
 */
class CompareNhlPbPBoxscore
{
    private const PERCENTAGE_TOLERANCE = 0.001;

    /** @var array<int,string> */
    private const SHIFT_DEPENDENT_FIELDS = [
        'plus_minus',
        'shifts',
        'toi_seconds',
    ];

    /** @var array<string,string> */
    private const SKATER_EXACT_FIELD_MAP = [
        'goals' => 'g',
        'assists' => 'a',
        'points' => 'pts',
        'penalty_minutes' => 'pim',
        'sog' => 'sog',
        'hits' => 'h',
        'blocks' => 'b',
        'power_play_goals' => 'ppg',
        'plus_minus' => 'plus_minus',
        'shifts' => 'shifts',
    ];

    /** @var array<string,string> */
    private const GOALIE_EXACT_FIELD_MAP = [
        'penalty_minutes' => 'pim',
        'goals_against' => 'ga',
        'saves' => 'sv',
        'shots_against' => 'sa',
        'ev_saves' => 'evsv',
        'ev_shots_against' => 'evsa',
        'pp_saves' => 'ppsv',
        'pp_shots_against' => 'ppsa',
        'pk_saves' => 'pksv',
        'pk_shots_against' => 'pksa',
    ];

    /** @var array<string,string> */
    private const TOLERATED_FIELD_MAP = [
        'toi_seconds' => 'toi',
    ];

    /**
     * Compare one game and return normalized field deltas.
     *
     * @return array<int,array{
     *     nhl_player_id:int|null,
     *     field:string,
     *     boxscore_value:mixed,
     *     summary_value:mixed,
     *     delta:float|int|null,
     *     severity:string
     * }>
     */
    public function compare(int $gameId): array
    {
        $deltas = [];
        $shiftsAvailable = app(NhlGameSourcePreflight::class)->storedShiftsAvailable($gameId);
        $boxscores = NhlBoxscore::where('nhl_game_id', $gameId)->get();
        $summaries = NhlGameSummary::where('nhl_game_id', $gameId)
            ->get()
            ->keyBy('nhl_player_id');

        foreach ($boxscores as $boxscore) {
            $playerId = $boxscore->nhl_player_id ? (int) $boxscore->nhl_player_id : null;
            $summary = $playerId !== null ? $summaries->get($playerId) : null;

            if (! $summary) {
                continue;
            }

            $exactFieldMap = $this->isGoalie($boxscore) ? self::GOALIE_EXACT_FIELD_MAP : self::SKATER_EXACT_FIELD_MAP;

            foreach ($exactFieldMap as $boxscoreField => $summaryField) {
                if (! $shiftsAvailable && in_array($boxscoreField, self::SHIFT_DEPENDENT_FIELDS, true)) {
                    continue;
                }

                $boxscoreValue = $this->numericValue($boxscore->{$boxscoreField});
                $summaryValue = $this->numericValue($summary->{$summaryField});

                if ($boxscoreValue !== $summaryValue) {
                    if ($this->isToleratedMatchPenaltyPimDelta($gameId, $playerId, $boxscoreField, $boxscoreValue, $summaryValue)) {
                        continue;
                    }

                    $deltas[] = $this->delta(
                        $playerId,
                        $boxscoreField,
                        $boxscoreValue,
                        $summaryValue,
                        $summaryValue - $boxscoreValue
                    );
                }
            }

            if (! $this->isGoalie($boxscore)) {
                $this->compareFaceoffPercentage($deltas, $playerId, $boxscore, $summary);
            }

            if ($this->isGoalie($boxscore)) {
                $this->compareGoalieDerivedFields($deltas, $playerId, $boxscore, $summary);
            }

            foreach (self::TOLERATED_FIELD_MAP as $boxscoreField => $summaryField) {
                if (! $shiftsAvailable && in_array($boxscoreField, self::SHIFT_DEPENDENT_FIELDS, true)) {
                    continue;
                }

                $boxscoreValue = (float) $boxscore->{$boxscoreField};
                $summaryValue = (float) $summary->{$summaryField};

                $delta = abs($boxscoreValue - $summaryValue);

                if ($boxscoreField === 'toi_seconds' && $delta > 1.0) {
                    $deltas[] = $this->delta(
                        $playerId,
                        $boxscoreField,
                        $boxscoreValue,
                        $summaryValue,
                        $summaryValue - $boxscoreValue
                    );
                }
            }
        }

        foreach ($summaries as $playerId => $summary) {
            if ($boxscores->firstWhere('nhl_player_id', $playerId)) {
                continue;
            }

            if ($this->hasComparableTotals($summary, $shiftsAvailable)) {
                $deltas[] = $this->delta(
                    (int) $playerId,
                    'boxscore_record',
                    null,
                    'present',
                    null
                );
            }
        }

        return $deltas;
    }

    /**
     * Compare normalized faceoff percentage for skaters.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function compareFaceoffPercentage(array &$deltas, ?int $playerId, NhlBoxscore $boxscore, NhlGameSummary $summary): void
    {
        $boxscoreValue = (float) $boxscore->faceoff_win_percentage;
        $summaryValue = ((float) $summary->fow_percentage) / 100;

        if (abs($boxscoreValue - $summaryValue) <= self::PERCENTAGE_TOLERANCE) {
            return;
        }

        $deltas[] = $this->delta(
            $playerId,
            'faceoff_win_percentage',
            $boxscoreValue,
            $summaryValue,
            $summaryValue - $boxscoreValue
        );
    }

    /**
     * Compare goalie-only derived fields not stored directly on NHL boxscores.
     *
     * @param array<int,array<string,mixed>> $deltas
     */
    private function compareGoalieDerivedFields(array &$deltas, ?int $playerId, NhlBoxscore $boxscore, NhlGameSummary $summary): void
    {
        $this->compareDerivedExact(
            $deltas,
            $playerId,
            'ev_goals_against',
            $this->numericValue($boxscore->ev_goals_against),
            $this->numericValue($summary->evga)
        );
        $this->compareDerivedExact(
            $deltas,
            $playerId,
            'pp_goals_against',
            $this->numericValue($boxscore->pp_goals_against),
            $this->numericValue($summary->ppga)
        );
        $this->compareDerivedExact(
            $deltas,
            $playerId,
            'pk_goals_against',
            $this->numericValue($boxscore->pk_goals_against),
            $this->numericValue($summary->pkga)
        );

        $boxscoreSavePercentage = $this->percentage((float) $boxscore->saves, (float) $boxscore->shots_against);
        $summarySavePercentage = $this->percentage((float) $summary->sv, (float) $summary->sa);

        if (abs($boxscoreSavePercentage - $summarySavePercentage) <= self::PERCENTAGE_TOLERANCE) {
            return;
        }

        $deltas[] = $this->delta(
            $playerId,
            'save_percentage',
            $boxscoreSavePercentage,
            $summarySavePercentage,
            $summarySavePercentage - $boxscoreSavePercentage
        );
    }

    /**
     * @param array<int,array<string,mixed>> $deltas
     */
    private function compareDerivedExact(array &$deltas, ?int $playerId, string $field, int|float $boxscoreValue, int|float $summaryValue): void
    {
        if ($boxscoreValue === $summaryValue) {
            return;
        }

        $deltas[] = $this->delta(
            $playerId,
            $field,
            $boxscoreValue,
            $summaryValue,
            $summaryValue - $boxscoreValue
        );
    }

    /**
     * NHL boxscores omit the extra ten minutes that play-by-play assigns to match penalties.
     */
    private function isToleratedMatchPenaltyPimDelta(
        int $gameId,
        ?int $playerId,
        string $boxscoreField,
        int|float $boxscoreValue,
        int|float $summaryValue
    ): bool {
        if ($boxscoreField !== 'penalty_minutes' || $playerId === null) {
            return false;
        }

        $delta = $summaryValue - $boxscoreValue;

        if ($delta <= 0 || fmod((float) $delta, 10.0) !== 0.0) {
            return false;
        }

        $matchPenalties = PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->where('type_desc_key', 'penalty')
            ->where('committed_by_player_id', $playerId)
            ->where(function ($query): void {
                $query
                    ->whereRaw("UPPER(COALESCE(penalty_type_code, '')) = 'MAT'")
                    ->orWhere('desc_key', 'match-penalty');
            })
            ->count();

        return (float) $delta === (float) ($matchPenalties * 10);
    }

    /**
     * @return array{
     *     nhl_player_id:int|null,
     *     field:string,
     *     boxscore_value:mixed,
     *     summary_value:mixed,
     *     delta:float|int|null,
     *     severity:string
     * }
     */
    private function delta(
        ?int $playerId,
        string $field,
        mixed $boxscoreValue,
        mixed $summaryValue,
        float|int|null $delta
    ): array {
        return [
            'nhl_player_id' => $playerId,
            'field' => $field,
            'boxscore_value' => $boxscoreValue,
            'summary_value' => $summaryValue,
            'delta' => $delta,
            'severity' => NhlGameValidationDelta::SEVERITY_ERROR,
        ];
    }

    private function numericValue(mixed $value): int|float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value) && str_contains((string) $value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }

    private function percentage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return round($numerator / $denominator, 3);
    }

    private function isGoalie(NhlBoxscore $boxscore): bool
    {
        return strtoupper((string) $boxscore->position) === 'G';
    }

    private function hasComparableTotals(NhlGameSummary $summary, bool $shiftsAvailable): bool
    {
        foreach ([...self::SKATER_EXACT_FIELD_MAP, ...self::GOALIE_EXACT_FIELD_MAP] as $boxscoreField => $summaryField) {
            if (! $shiftsAvailable && in_array($boxscoreField, self::SHIFT_DEPENDENT_FIELDS, true)) {
                continue;
            }

            if ($this->numericValue($summary->{$summaryField}) !== 0) {
                return true;
            }
        }

        return false;
    }
}
