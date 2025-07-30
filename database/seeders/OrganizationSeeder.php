<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Organization;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $orgs = [

            [
                'id' => 1,
                'name' => 'DynastyIQ',
                'short_name' => 'DIQ',
            ],

            [
                'id' => 2,
                'name' => 'Unohuim org',
                'short_name' => 'Uno',
            ],

            [
                'id' => 3,
                'name' => 'Fantasy Hockey Life',
                'short_name' => 'FHL',
            ],

            [
                'id' => 4,
                'name' => 'Prospects to Pros',
                'short_name' => 'P2P'
            ],

        ];


        foreach($orgs as $org)
        {
            Organization::create($org);
        }
    }
}
