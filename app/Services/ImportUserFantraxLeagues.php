<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Traits\HasAPITrait;
use InvalidArgumentException;

/**
 * Service: ImportUserFantraxLeagues
 *
 * Fetches Fantrax league info for a user's linked leagues and normalizes
 * scoring settings into a single, predictable structure.
 */
class ImportUserFantraxLeagues
{
    use HasAPITrait;

    /**
     * Import and inspect a user's Fantrax leagues.
     *
     * @param User $user
     * @return void
     */
    public function import(User $user): void
    {
        if (!$user instanceof User) {
            throw new InvalidArgumentException('A valid User is required.');
        }

        $leagues = $user->fantraxLeagues()->get();

        foreach ($leagues as $league) {
            $resp = $this->getAPIData('fantrax', 'league_info', [
                'leagueId' => $league->fantrax_league_id,
            ]);

            $scoringSystemType       = data_get($resp, 'scoringSystem.type');
            $scoringCategories       = data_get($resp, 'scoringSystem.scoringCategories', []);
            $scoringCategorySettings = data_get($resp, 'scoringSystem.scoringCategorySettings', []);

            $normalized = $this->normalizeScoring($resp ?? []);

            if ($league->fantrax_league_id === 'tg011rysm9ym6xij') {
                dd([
                    'league' => [
                        'id'                => $league->id,
                        'name'              => $league->league_name ?? null,
                        'fantrax_league_id' => $league->fantrax_league_id,
                    ],
                    'scoring_system_type'       => $scoringSystemType,
                    'scoring_categories'        => $scoringCategories,
                    'scoring_category_settings' => $scoringCategorySettings,
                    'normalized'                => $normalized,
                ]);
            }
        }
    }

    /**
     * Normalize scoring settings into a structure like:
     * [
     *   'HOCKEY_SKATING' => [
     *      'INDIVIDUAL_ASSISTS' => [
     *          'meta' => ['short' => 'A', 'name' => 'Assists', 'id' => '2090'],
     *          'by_position' => ['DEFAULT' => 4.0, 'DEFENSE' => 4.5],
     *      ],
     *   ],
     *   'HOCKEY_GOALIE' => [...]
     * ]
     *
     * DEDUPES: Maps short keys from simple maps (e.g., "G", "A", "Blk")
     * to their canonical codes discovered in rich blocks (e.g., "INDIVIDUAL_GOALS").
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeScoring(array $payload): array
    {
        $groups =
            data_get($payload, 'scoringSystem.scoringCategorySettings')
            ?? data_get($payload, 'scoringCategorySettings')
            ?? [];

        $out   = [];
        $alias = []; // [$groupCode][SHORT] => CANONICAL_CODE

        // 1) Rich blocks with explicit groups/configs/positions
        foreach ((array) $groups as $groupBlock) {
            $groupCode = (string) (data_get($groupBlock, 'group.code') ?? 'UNKNOWN');

            foreach ((array) data_get($groupBlock, 'configs', []) as $cfg) {
                $posCode = $this->mapPosition((string) (data_get($cfg, 'position.code') ?? 'DEFAULT'));

                $catCode = (string) data_get($cfg, 'scoringCategory.code');
                if ($catCode === '') {
                    continue;
                }

                $short = data_get($cfg, 'scoringCategory.shortName');
                $name  = data_get($cfg, 'scoringCategory.name');
                $catId = (string) (data_get($cfg, 'scoringCategory.id') ?? '');

                $pointsRaw = data_get($cfg, 'scoringCategory.points');
                $points    = $this->parsePoints($pointsRaw);

                // Record alias from short -> canonical for this group
                if (is_string($short) && $short !== '') {
                    $alias[$groupCode][strtoupper($short)] = $catCode;
                }

                if (!isset($out[$groupCode][$catCode])) {
                    $out[$groupCode][$catCode] = [
                        'meta' => [
                            'short' => is_string($short) ? $short : null,
                            'name'  => is_string($name) ? $name : null,
                            'id'    => $catId !== '' ? $catId : null,
                        ],
                        'by_position' => [],
                    ];
                }

                $out[$groupCode][$catCode]['by_position'][$posCode] = $points;
            }
        }

        // 2) Simple maps that may be scalars OR per-position arrays
        $simple =
            data_get($payload, 'scoringSystem.scoringCategories')
            ?? data_get($payload, 'scoringCategories')
            ?? [];

        foreach (['SKATING' => 'HOCKEY_SKATING', 'GOALIE' => 'HOCKEY_GOALIE'] as $key => $groupAlias) {
            $map = (array) data_get($simple, $key, []);
            foreach ($map as $shortOrCode => $value) {
                $shortKey = strtoupper(is_string($shortOrCode) ? $shortOrCode : (string) $shortOrCode);

                // Resolve to canonical code when we have an alias; otherwise use as-is
                $canonical = $alias[$groupAlias][$shortKey] ?? $shortKey;

                // Ensure node exists; preserve any prior meta from rich blocks
                if (!isset($out[$groupAlias][$canonical])) {
                    $out[$groupAlias][$canonical] = [
                        'meta' => [
                            'short' => $shortKey,
                            'name'  => null,
                            'id'    => null,
                        ],
                        'by_position' => [],
                    ];
                } else {
                    // If meta.short is empty but we have a shortKey, record it
                    if (!isset($out[$groupAlias][$canonical]['meta']['short']) || $out[$groupAlias][$canonical]['meta']['short'] === null) {
                        $out[$groupAlias][$canonical]['meta']['short'] = $shortKey;
                    }
                }

                // If simple map provides per-position overrides (array), record them
                if (is_array($value)) {
                    foreach ($value as $posKey => $ptsVal) {
                        $pos = $this->mapPosition((string) $posKey);
                        $out[$groupAlias][$canonical]['by_position'][$pos] = $this->parsePoints($ptsVal);
                    }
                    continue;
                }

                // Otherwise treat as DEFAULT if not already set by rich blocks
                if (!isset($out[$groupAlias][$canonical]['by_position']['DEFAULT'])) {
                    $out[$groupAlias][$canonical]['by_position']['DEFAULT'] = $this->parsePoints($value);
                }
            }
        }

        return $out;
    }

    /**
     * Parse numeric or "points0.5"/"points-1" strings to float.
     *
     * @param mixed $value
     * @return float
     */
    private function parsePoints(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (preg_match('/^points(-?\d+(?:\.\d+)?)$/', $v, $m)) {
                return (float) $m[1];
            }
        }

        return 0.0;
    }

    /**
     * Map Fantrax position shorthands to canonical codes.
     *
     * @param string $pos
     * @return string
     */
    private function mapPosition(string $pos): string
    {
        $u = strtoupper(trim($pos));

        return match ($u) {
            'DEFAULT', 'ALL' => 'DEFAULT',
            'F'              => 'FORWARD',
            'D'              => 'DEFENSE',
            'C'              => 'CENTER',
            'LW'             => 'LEFT_WING',
            'RW'             => 'RIGHT_WING',
            'G'              => 'GOALIE',
            default          => $u, // keep any unknown codes verbatim
        };
    }
}
