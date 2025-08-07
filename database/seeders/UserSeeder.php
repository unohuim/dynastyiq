<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\RankingProfile;


class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [

            [
                'id' => 1,

                'tenant_id' => 1,

                'name' => 'superadmin',

                'email' => 'super@dynastyiq.com',

                'email_verified_at' => Carbon::now(),

                'password' => Hash::make('password')
            ],

            [
                'id' => 2,

                'tenant_id' => 2,

                'name' => 'unohuim',

                'email' => 'colquhoun.r@gmail.com',

                'email_verified_at' => Carbon::now(),

                'password' => Hash::make('password')
            ],

            [
                'id' => 3,

                'tenant_id' => 3,

                'name' => 'victor',

                'email' => 'victor@fhl.com',

                'email_verified_at' => Carbon::now(),

                'password' => Hash::make('password')
            ],

        ];


        foreach($users as $user)
        {
            User::create($user);

            RankingProfile::firstOrCreate([
                'name' => 'Default Ranking Profile',
                'author_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'visibility' => 'private',
                'sport' => 'hockey',
            ]);
        }



    }
}
