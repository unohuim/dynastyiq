<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\RoleUser;


class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [

            [
                'id' => 1,
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'level' => 11,
                'is_active' => true
            ],

            [
                'id' => 2,
                'name' => 'Admin',
                'slug' => 'admin',
                'level' => 10,
                'is_active' => true
            ],

            [
                'id' => 3,
                'name' => 'User',
                'slug' => 'user',
                'level' => 3,
                'is_active' => true
            ]


        ];


        foreach($roles as $role)
        {
            Role::create($role);
        }



        $role_user = [

            [
                'role_id' => 1,
                'user_id' => 1,
                'tenant_id' =>1,
            ],

            [
                'role_id' => 2,
                'user_id' => 2,
                'tenant_id' =>2,
            ],
        ];


        foreach($role_user as $ru)
        {
            RoleUser::create($ru);
        }
    }
}
