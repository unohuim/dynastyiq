<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleUser;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'id'        => 1,
                'name'      => 'Super Admin',
                'slug'      => 'super-admin',
                'level'     => 11,
                'is_active' => true,
                'scope'     => 'global',
            ],
            [
                'id'        => 2,
                'name'      => 'Admin',
                'slug'      => 'admin',
                'level'     => 10,
                'is_active' => true,
                'scope'     => 'organization',
            ],
            [
                'id'        => 3,
                'name'      => 'Manager',
                'slug'      => 'manager',
                'level'     => 2,
                'is_active' => true,
                'scope'     => 'organization',
            ],
            [
                'id'        => 4,
                'name'      => 'Scout',
                'slug'      => 'scout',
                'level'     => 3,
                'is_active' => true,
                'scope'     => 'organization',
            ],
            [
                'id'        => 5,
                'name'      => 'Commissioner',
                'slug'      => 'commissioner',
                'level'     => 3,
                'is_active' => true,
                'scope'     => 'organization',
            ],
            [
                'id'        => 6,
                'name'      => 'Creator',
                'slug'      => 'creator',
                'level'     => 3,
                'is_active' => true,
                'scope'     => 'organization',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['id' => $role['id']],
                [
                    'name'      => $role['name'],
                    'slug'      => $role['slug'],
                    'level'     => $role['level'],
                    'is_active' => $role['is_active'],
                    'scope'     => $role['scope'],
                ]
            );
        }

        $roleUser = [
            // Global assignment: Super Admin to user 1 (organization_id = null)
            [
                'role_id'         => 1,
                'user_id'         => 1,
                'organization_id' => null,
            ],

            [
                'role_id'         => 2,
                'user_id'         => 1,
                'organization_id' => 1,
            ],
        ];

        foreach ($roleUser as $ru) {
            RoleUser::updateOrCreate(
                [
                    'role_id'         => $ru['role_id'],
                    'user_id'         => $ru['user_id'],
                    'organization_id' => $ru['organization_id'],
                ],
                []
            );
        }
    }
}
