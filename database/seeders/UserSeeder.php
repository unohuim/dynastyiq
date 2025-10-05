<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\RankingProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'user' => [
                    'name'     => 'unohuim.',
                    'email'    => 'robert@woofs.ca',
                    'password' => 'password',
                ],
                'org' => [
                    'short_name' => 'Uno',
                    'name'       => 'Unohuim org',
                ],
            ],
        ];

        foreach ($rows as $row) {
            $slug = Str::slug($row['org']['short_name'] ?? $row['org']['name']);

            // 1) Ensure Organization exists (by slug)
            $org = Organization::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'       => $row['org']['name'],
                    'short_name' => $row['org']['short_name'] ?? null,
                ]
            );

            // 2) Create/Update User (idempotent by email)
            $user = User::updateOrCreate(
                ['email' => $row['user']['email']],
                [
                    'name'              => $row['user']['name'],
                    'password'          => Hash::make($row['user']['password']),
                    'email_verified_at' => Carbon::now(),
                ]
            );

            // 3) Ensure membership (organization_user pivot)
            $user->organizations()->syncWithoutDetaching([$org->id => ['settings' => null]]);

            // 4) Optionally set org owner if none
            if (empty($org->owner_user_id)) {
                $org->owner_user_id = $user->id;
                $org->save();
            }

            // 5) Ensure a default Ranking Profile per user/org
            RankingProfile::firstOrCreate([
                'name'             => 'Default Ranking Profile',
                'author_id'        => $user->id,
                'organization_id'  => $org->id,      // renamed from tenant_id
                'visibility'       => 'private',
                'sport'            => 'hockey',
            ]);
        }
    }
}
