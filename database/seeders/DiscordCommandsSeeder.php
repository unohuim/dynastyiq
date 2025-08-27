<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DiscordCommand;

class DiscordCommandsSeeder extends Seeder
{
    public function run(): void
    {
        // Root: /diq
        DiscordCommand::updateOrCreate(
            ['command_slug' => 'diq'],
            [
                'name' => 'DIQ',
                'parent_slug' => null,
                'description' => 'Top-level DynastyIQ command.',
                'handler_kind' => 'service',
                'handler_ref' => 'App\\Services\\Discord\\DispatchDIQ',
                'http_method' => null,
                'usage' => "/diq <command>\nOR\n/diq → lists all commands",
                'link_path' => '/',
                'brand_hint' => 'Powered by DynastyIQ — dynastyiq.com',
                'param_keys' => [],
                'enum_options' => [],
                'has_defaults' => false,
                'defaults' => null,
                'allowed_overrides' => [],
                'max_sorts' => 1,
                'auth_scope' => 'user',
                'enabled' => true,
                'version' => 1,
            ]
        );

        // /diq stats (perspectives are handled by the stats system, not as child commands)
        DiscordCommand::updateOrCreate(
            ['command_slug' => 'stats'],
            [
                'name' => 'Stats',
                'parent_slug' => 'diq',
                'description' => 'Access statistics via perspectives (e.g., nhl, yahoo-standard, prospects, onice-players, onice-units, onice-teams).',
                'handler_kind' => 'service',
                'handler_ref' => 'App\\Services\\Discord\\StatsHandler',
                'http_method' => 'POST',
                'usage' => "/diq stats <perspective_slug> [resource] [period] [slice]\nOR\n/diq stats → lists perspectives you can use",
                'link_path' => '/stats',
                'brand_hint' => 'Powered by DynastyIQ — dynastyiq.com',
                'param_keys' => ['resource','period','slice','limit','page'],
                'enum_options' => [
                    'resource' => ['player','unit','team'],
                    'period' => ['season','last30','lastweek','thisweek','range'],
                    'slice' => ['total','p60','pgp'],
                ],
                'has_defaults' => false,
                'defaults' => null,
                'allowed_overrides' => ['resource','period','slice','limit','page','sort'],
                'max_sorts' => 1,
                'auth_scope' => 'user',
                'enabled' => true,
                'version' => 1,
            ]
        );

        // Note: perspectives like "nhl", "yahoo-standard", "prospects",
        // "onice-players", "onice-units", "onice-teams" are NOT inserted into
        // discord_commands. They should be seeded/managed in your perspectives table
        // and resolved by StatsHandler when parsing `/diq stats <perspective_slug>`.
    }
}
