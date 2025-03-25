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
                'name' => 'DynastyIQ',
            ],

            [
                'name' => 'Unohuim org',
            ],

        ];


        foreach($orgs as $org)
        {
            Organization::create($org);
        }
    }
}
