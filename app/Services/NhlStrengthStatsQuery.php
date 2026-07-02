<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Queries pre-aggregated strength-aware NHL on-ice summaries.
 */
class NhlStrengthStatsQuery
{
    /** @var array<int,string> */
    private const TOTAL_FIELDS = [
        'toi',
        'shifts',
        'ozs',
        'nzs',
        'dzs',
        'gf',
        'ga',
        'sf',
        'sa',
        'satf',
        'sata',
        'ff',
        'fa',
        'bf',
        'ba',
        'hf',
        'ha',
        'fow',
        'fol',
        'fot',
        'pim_f',
        'pim_a',
        'penalties_f',
        'penalties_a',
    ];

    /** @var array<int,string> */
    private const PLAYER_ONLY_FIELDS = [
        'individual_g',
        'individual_a',
        'individual_pts',
    ];

    /**
     * Aggregate player strength totals for season/date filters.
     *
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    public function players(array $filters = [], string $slice = 'total'): Collection
    {
        $query = DB::table('nhl_player_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->join('players as p', 'p.id', '=', 's.player_id')
            ->groupBy('s.player_id', 's.nhl_player_id', 'p.full_name', 's.strength')
            ->selectRaw($this->selectTotals([
                's.player_id',
                's.nhl_player_id',
                'p.full_name',
                's.strength',
            ], array_merge(self::TOTAL_FIELDS, self::PLAYER_ONLY_FIELDS)));

        $this->applyFilters($query, $filters);

        return $this->deriveRates($query->get(), $slice, true);
    }

    /**
     * Aggregate unit strength totals for season/date filters.
     *
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    public function units(array $filters = [], string $slice = 'total'): Collection
    {
        $query = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->groupBy('s.unit_id', 'u.unit_type', 'u.team_abbrev', 's.strength')
            ->selectRaw($this->selectTotals([
                's.unit_id',
                'u.unit_type',
                'u.team_abbrev',
                's.strength',
            ], self::TOTAL_FIELDS));

        $this->applyFilters($query, $filters);

        return $this->deriveRates($query->get(), $slice, false);
    }

    /**
     * @param array<int,string> $identityFields
     * @param array<int,string> $totalFields
     */
    private function selectTotals(array $identityFields, array $totalFields): string
    {
        $identity = implode(', ', $identityFields);
        $totals = collect($totalFields)
            ->map(fn (string $field): string => "SUM(s.{$field}) as {$field}")
            ->implode(', ');

        return "{$identity}, COUNT(DISTINCT s.nhl_game_id) as gp, {$totals}";
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['season_id'])) {
            $query->where('g.season_id', (string) $filters['season_id']);
        }

        if (! empty($filters['game_type'])) {
            $query->where('g.game_type', (int) $filters['game_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('g.game_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('g.game_date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['strength'])) {
            $query->where('s.strength', strtoupper((string) $filters['strength']));
        }
    }

    /**
     * @param Collection<int,object> $rows
     * @return Collection<int,object>
     */
    private function deriveRates(Collection $rows, string $slice, bool $includeIpp): Collection
    {
        return $rows->map(function (object $row) use ($slice, $includeIpp): object {
            $gamesPlayed = (int) $row->gp;
            $toi = (int) $row->toi;

            foreach (array_merge(self::TOTAL_FIELDS, self::PLAYER_ONLY_FIELDS) as $field) {
                if (! property_exists($row, $field)) {
                    continue;
                }

                $row->{$field} = $this->sliceValue(
                    $field,
                    (float) $row->{$field},
                    $gamesPlayed,
                    $toi,
                    $slice
                );
            }

            if ($includeIpp) {
                $points = property_exists($row, 'individual_pts') ? (float) $row->individual_pts : 0;
                $gf = property_exists($row, 'gf') ? (float) $row->gf : 0;
                $row->ipp = $gf > 0 ? round($points / $gf, 4) : 0;
            }

            return $row;
        });
    }

    private function sliceValue(string $field, float $total, int $gamesPlayed, int $toi, string $slice): float|int
    {
        if ($field === 'toi' && $slice === 'p60') {
            return (int) $total;
        }

        if ($slice === 'pgp') {
            return $gamesPlayed > 0 ? round($total / $gamesPlayed, 3) : 0;
        }

        if ($slice === 'p60') {
            return $toi > 0 ? round($total / ($toi / 3600), 3) : 0;
        }

        return (int) $total;
    }
}
