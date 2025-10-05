<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Perspective;

class PerspectiveSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $perspectives = [

            [
                'name' => 'Skaters',
                'slug' => 'skaters',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
                        ['key' => 'toi', 'label' => 'TOI', 'type' => 'int'],
                        ['key' => 'ppp', 'label' => 'PPP', 'type' => 'int'],
                        ['key' => 'b', 'label' => 'Blk', 'type' => 'int'],
                        ['key' => 'h', 'label' => 'Hits', 'type' => 'int'],
                        ['key' => 'sat', 'label' => 'SAT', 'type' => 'int'],
                        ['key' => 'sog', 'label' => 'SOG', 'type' => 'int'],

                    ],
                    'sort' => [
                        'sortKey' => 'pts',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'value' => 'NHL',
                            'locked' => true,
                        ],

                    ],
                ]),
            ],

            [
                'name' => 'Goalies',
                'slug' => 'goalie',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'sa', 'label' => 'SA', 'type' => 'int'],
                        ['key' => 'sv', 'label' => 'SV', 'type' => 'int'],
                        ['key' => 'ga', 'label' => 'GA', 'type' => 'int'],
                        ['key' => 'so', 'label' => 'SO', 'type' => 'int'],


                    ],
                    'sort' => [
                        'sortKey' => 'sv',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'value' => 'NHL',
                            'locked' => true,
                        ],

                    ],
                ]),
            ],



            [
                'name' => 'nhl.com',
                'slug' => 'nhl',
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
                        ['key' => 'plus_minus', 'label' => '+/-', 'type' => 'int'],
                        ['key' => 'pim', 'label' => 'PIM', 'type' => 'int'],
                        ['key' => 'pts_per_gp', 'label' => 'P/GP', 'type' => 'float'],
                        ['key' => 'ppp', 'label' => 'PPP', 'type' => 'int'],
                        ['key' => 'sog', 'label' => 'S', 'type' => 'int'],
                    ],
                    'sort' => [
                        'sortKey' => 'pts',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'value' => 'NHL',
                            'locked' => true,
                        ],
                        'season_id' => [
                            'value' => '20242025',
                            'locked' => false,
                        ],
                    ],
                ]),
            ],

            [
                'name' => 'Standard Yahoo',
                'slug' => 'yahoo-standard',
                'author_id' => 1,
                'organization_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'plus_minus', 'label' => '+/-', 'type' => 'int'],
                        ['key' => 'ppp', 'label' => 'PPP', 'type' => 'int'],
                        ['key' => 'sog', 'label' => 'SOG', 'type' => 'int'],
                        ['key' => 'h', 'label' => 'Hits', 'type' => 'int'],
                    ],
                    'sort' => [
                        'sortKey' => 'ppp',
                        'sortDirection' => 'desc',
                    ],
                    'filters' => [
                        'league_abbrev' => [
                            'value' => 'NHL',
                            'locked' => true,
                        ],
                        'season_id' => [
                            'value' => '20242025',
                            'locked' => true,
                        ],
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
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],

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

                    ],
                ]),
            ],
        ];

        foreach ($perspectives as $perspective) {
            Perspective::create($perspective);
        }
    }
}
