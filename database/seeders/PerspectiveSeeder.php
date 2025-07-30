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
                'id' => 1,
                'name' => 'nhl.com',
                'author_id' => 1,
                'tenant_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'gp', 'label' => 'GP', 'type' => 'int'],
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
                'id' => 2,
                'name' => 'Standard Yahoo',
                'author_id' => 1,
                'tenant_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                        ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                        ['key' => 'plus_minus', 'label' => '+/-', 'type' => 'int'],
                        ['key' => 'ppp', 'label' => 'PPP', 'type' => 'int'],
                        ['key' => 'sog', 'label' => 'SOG', 'type' => 'int'],
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
                'id' => 3,
                'name' => 'Prospects',
                'author_id' => 1,
                'tenant_id' => 1,
                'visibility' => 'public_guest',
                'sport' => 'hockey',
                'settings' => json_encode([
                    'columns' => [
                        ['key' => 'gp', 'label' => 'GP', 'type' => 'int'],
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
