<?php

namespace Database\Seeders;

use App\Models\Perspective;
use Illuminate\Database\Seeder;

class PerspectiveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Perspective::whereIn('slug', [
            'goalie',
            'nhl',
            'yahoo-standard',
            'skaters-fantasy',
            'skaters-advanced',
            'goalies-fantasy',
            'goalies-splits',
        ])->delete();

        $perspectives = [
            [
                'name' => 'Skaters',
                'slug' => 'skaters',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => true,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'toi', 'label' => 'TOI', 'type' => 'string'],
                        ['key' => 'gp', 'label' => 'GP', 'type' => 'int'],
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
                        ['key' => 'ppp', 'label' => 'PPP', 'type' => 'int'],
                        ['key' => 'sog', 'label' => 'SOG', 'type' => 'int'],
                        ['key' => 'sat', 'label' => 'SAT', 'type' => 'int'],
                        ['key' => 'h', 'label' => 'Hits', 'type' => 'int'],
                        ['key' => 'b', 'label' => 'Blk', 'type' => 'int'],
                        ['key' => 'plus_minus', 'label' => '+/-', 'type' => 'int'],
                    ],
                    'sort' => [
                        'sortKey' => 'pts',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'pos_type' => [
                            'operator' => '!=',
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => ['F', 'C', 'LW', 'RW', 'D'],
                    ],
                ]),
            ],
            [
                'name' => 'Skaters Adv',
                'slug' => 'skaters-adv',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => true,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'ipp', 'label' => 'IPP', 'type' => 'float'],
                        ['key' => 'gf_pct', 'label' => 'GF%', 'type' => 'float'],
                        ['key' => 'cf_pct', 'label' => 'CF%', 'type' => 'float'],
                        ['key' => 'pdo', 'label' => 'PDO', 'type' => 'float'],
                        ['key' => 'ozs_pct', 'label' => 'OZS%', 'type' => 'float'],
                    ],
                    'sort' => [
                        'sortKey' => 'ipp',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'pos_type' => [
                            'operator' => '!=',
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => ['F', 'C', 'LW', 'RW', 'D'],
                    ],
                ]),
            ],
            [
                'name' => 'Goalies',
                'slug' => 'goalies',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => true,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'wins', 'label' => 'W', 'type' => 'int'],
                        ['key' => 'losses', 'label' => 'L', 'type' => 'int'],
                        ['key' => 'ot_losses', 'label' => 'OTL', 'type' => 'int'],
                        ['key' => 'starts', 'label' => 'GS', 'type' => 'int'],
                        ['key' => 'sv', 'label' => 'SV', 'type' => 'int'],
                        ['key' => 'sa', 'label' => 'SA', 'type' => 'int'],
                        ['key' => 'sv_pct', 'label' => 'SV%', 'type' => 'float'],
                        ['key' => 'gaa', 'label' => 'GAA', 'type' => 'float'],
                        ['key' => 'so', 'label' => 'SO', 'type' => 'int'],
                        ['key' => 'quality_starts', 'label' => 'QS', 'type' => 'int'],
                        ['key' => 'quality_start_percentage', 'label' => 'QS%', 'type' => 'float'],
                    ],
                    'sort' => [
                        'sortKey' => 'wins',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'pos_type' => [
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => [],
                    ],
                ]),
            ],
            [
                'name' => 'Goalies Adv',
                'slug' => 'goalies-adv',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => true,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'evsv', 'label' => 'EVSV', 'type' => 'int'],
                        ['key' => 'ev_sv_pct', 'label' => 'EVSV%', 'type' => 'float'],
                        ['key' => 'ppsv', 'label' => 'PPSV', 'type' => 'int'],
                        ['key' => 'pp_sv_pct', 'label' => 'PPSV%', 'type' => 'float'],
                        ['key' => 'pksv', 'label' => 'PKSV', 'type' => 'int'],
                        ['key' => 'pk_sv_pct', 'label' => 'PKSV%', 'type' => 'float'],
                        ['key' => 'shosv', 'label' => 'SOSV', 'type' => 'int'],
                        ['key' => 'ga_per_gp', 'label' => 'GA/GP', 'type' => 'float'],
                        ['key' => 'really_bad_starts', 'label' => 'RBS', 'type' => 'int'],
                    ],
                    'sort' => [
                        'sortKey' => 'evsv',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'pos_type' => [
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => [],
                    ],
                ]),
            ],
            [
                'name' => 'Prospects',
                'slug' => 'prospects',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => false,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
                        ['key' => 'g_per_gp', 'label' => 'G/gp', 'type' => 'float'],
                        ['key' => 'pts_per_gp', 'label' => 'PTS/gp', 'type' => 'float'],
                    ],
                    'sort' => [
                        'sortKey' => 'pts',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'operator' => '!=',
                            'value' => 'NHL',
                            'locked' => true,
                        ],
                        'is_prospect' => [
                            'value' => true,
                            'locked' => true,
                        ],
                        'pos_type' => [
                            'operator' => '!=',
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => ['F', 'C', 'LW', 'RW', 'D'],
                    ],
                ]),
            ],
            [
                'name' => 'Prospects - Goalies',
                'slug' => 'prospects-goalies',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'is_slicable' => false,
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'wins', 'label' => 'W', 'type' => 'int'],
                        ['key' => 'losses', 'label' => 'L', 'type' => 'int'],
                        ['key' => 'ot_losses', 'label' => 'OTL', 'type' => 'int'],
                        ['key' => 'saves', 'label' => 'SV', 'type' => 'int'],
                        ['key' => 'shots_against', 'label' => 'SA', 'type' => 'int'],
                        ['key' => 'goals_against', 'label' => 'GA', 'type' => 'int'],
                        ['key' => 'sv_pct', 'label' => 'SV%', 'type' => 'float'],
                        ['key' => 'gaa', 'label' => 'GAA', 'type' => 'float'],
                        ['key' => 'shutouts', 'label' => 'SO', 'type' => 'int'],
                    ],
                    'sort' => [
                        'sortKey' => 'wins',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'operator' => '!=',
                            'value' => 'NHL',
                            'locked' => true,
                        ],
                        'is_prospect' => [
                            'value' => true,
                            'locked' => true,
                        ],
                        'pos_type' => [
                            'value' => 'G',
                            'locked' => true,
                        ],
                    ],
                    'ui' => [
                        'positionButtons' => [],
                    ],
                ]),
            ],
        ];

        foreach ($perspectives as $perspective) {
            Perspective::updateOrCreate(
                ['slug' => $perspective['slug']],
                $perspective
            );
        }
    }
}
