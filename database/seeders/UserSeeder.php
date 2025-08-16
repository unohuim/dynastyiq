<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\RankingProfile;
use App\Models\Organization;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Add more entries as needed; org is created/updated first, then user.
        $rows = [
            [
                'user' => [
                    'name'     => 'unohuim.',
                    'email'    => 'robert@woofs.ca',
                    'password' => 'password',
                ],
                'org'  => [
                    'short_name' => 'Uno',
                    'name'       => 'Unohuim org',
                ],
            ],
        ];

        foreach ($rows as $row) {
            // 1) Ensure Organization exists (idempotent)
            $org = Organization::updateOrCreate(
                ['short_name' => $row['org']['short_name']],
                ['name' => $row['org']['name']]
            );

            // 2) Create/Update User with tenant_id = org id (idempotent by email)
            $user = User::updateOrCreate(
                ['email' => $row['user']['email']],
                [
                    'name'              => $row['user']['name'],
                    'password'          => Hash::make($row['user']['password']),
                    'tenant_id'         => $org->id,
                    'email_verified_at' => Carbon::now(),
                ]
            );

            // 3) Ensure a default Ranking Profile per user/tenant
            RankingProfile::firstOrCreate([
                'name'       => 'Default Ranking Profile',
                'author_id'  => $user->id,
                'tenant_id'  => $org->id,
                'visibility' => 'private',
                'sport'      => 'hockey',
            ]);
        }
    }
}
