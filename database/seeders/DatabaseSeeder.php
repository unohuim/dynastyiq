<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\PerspectiveSeeder;
use Database\Seeders\DiscordCommandsSeeder;


class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            
            UserSeeder::class,            
            RoleSeeder::class,
            PerspectiveSeeder::class,
            //DiscordCommandsSeeder::class,
        ]);

    }
}
