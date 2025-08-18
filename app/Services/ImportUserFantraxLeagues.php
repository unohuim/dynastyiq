<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Traits\HasAPITrait;
use InvalidArgumentException;

class ImportUserFantraxLeagues
{
    use HasAPITrait;

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

            // Maintain these three separately
            $scoringSystemType       = data_get($resp, 'scoringSystem.type');
            $scoringCategories       = data_get($resp, 'scoringSystem.scoringCategories', []);
            $scoringCategorySettings = data_get($resp, 'scoringSystem.scoringCategorySettings', []);

            // Also keep the richer normalized view we had before
            $normalized = $this->normalizeScoring($resp ?? []);

            dd([
                'league' => [
                    'id'                => $league->id,
                    'name'              => $league->name ?? null,
                    'fantrax_league_id' => $league->fantrax_league_id,
                ],
                'scoring_system_type'       => $scoringSystemType,
                'scoring_categories'        => $scoringCategories,
                'scoring_category_settings' => $scoringCategorySettings,
                'normalized'                => $normalized,
            ]);
        }
    }

    /**
     * Normalize scoring settings into:
     * [
     *   'HOCKEY_SKATING' => [
     *      'INDIVIDUAL_ASSISTS' => [
     *          'meta' => ['short' => 'A', 'name' => 'Assists', 'id' => '2090'],
     *          'by_position' => ['DEFAULT' => 4.0, 'DEFENSE' => 4.5],
     *      ],
     *   ],
     *   'HOCKEY_GOALIE' => [...]
     * ]
     */
    private function normalizeScoring(array $payload): array
    {
        $groups = data_get($payload, 'scoringSystem.scoringCategorySettings')
            ?? data_get($payload, 'scoringCategorySettings')
            ?? [];

        $out = [];

        foreach ((array) $groups as $groupBlock) {
            $groupCode = (string) (data_get($groupBlock, 'group.code') ?? 'UNKNOWN');

            foreach ((array) data_get($groupBlock, 'configs', []) as $cfg) {
                $posCode = (string) (data_get($cfg, 'position.code') ?? 'DEFAULT');

                $catCode = (string) data_get($cfg, 'scoringCategory.code');
                if ($catCode === '') {
                    continue;
                }

                $short = data_get($cfg, 'scoringCategory.shortName');
                $name  = data_get($cfg, 'scoringCategory.name');
                $catId = (string) (data_get($cfg, 'scoringCategory.id') ?? '');

                $pointsRaw = data_get($cfg, 'scoringCategory.points');
                $points    = $this->parsePoints($pointsRaw);

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

        $simple = data_get($payload, 'scoringSystem.scoringCategories')
            ?? data_get($payload, 'scoringCategories')
            ?? [];

        foreach (['SKATING' => 'HOCKEY_SKATING', 'GOALIE' => 'HOCKEY_GOALIE'] as $key => $groupAlias) {
            $map = (array) data_get($simple, $key, []);
            foreach ($map as $shortOrCode => $value) {
                $catKey = is_string($shortOrCode) ? $shortOrCode : (string) $shortOrCode;
                $points = $this->parsePoints($value);

                if (!isset($out[$groupAlias][$catKey])) {
                    $out[$groupAlias][$catKey] = [
                        'meta' => [
                            'short' => $catKey,
                            'name'  => null,
                            'id'    => null,
                        ],
                        'by_position' => ['DEFAULT' => $points],
                    ];
                } elseif (!isset($out[$groupAlias][$catKey]['by_position']['DEFAULT'])) {
                    $out[$groupAlias][$catKey]['by_position']['DEFAULT'] = $points;
                }
            }
        }

        return $out;
    }

    /**
     * Parse numeric or "points0.5"/"points-1" strings to float.
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
}
