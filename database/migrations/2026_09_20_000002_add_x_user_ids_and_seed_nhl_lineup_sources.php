<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int,array{name:string,handle:string,team_id:int,team_abbrev:string}> */
    private array $sources = [
        ['name' => 'Carolina Hurricanes', 'handle' => 'Canes', 'team_id' => 12, 'team_abbrev' => 'CAR'],
        ['name' => 'Ryan Henkel', 'handle' => 'RyanHenkel_', 'team_id' => 12, 'team_abbrev' => 'CAR'],
        ['name' => 'Walt Ruff', 'handle' => 'WaltRuff', 'team_id' => 12, 'team_abbrev' => 'CAR'],
        ['name' => 'Florida Panthers', 'handle' => 'FlaPanthers', 'team_id' => 13, 'team_abbrev' => 'FLA'],
        ['name' => 'Jameson Olive', 'handle' => 'JamesonCoop', 'team_id' => 13, 'team_abbrev' => 'FLA'],
        ['name' => 'George Richards', 'handle' => 'GeorgeRichards', 'team_id' => 13, 'team_abbrev' => 'FLA'],
        ['name' => 'Utah Mammoth', 'handle' => 'utahmammoth', 'team_id' => 68, 'team_abbrev' => 'UTA'],
        ['name' => 'Utah Mammoth PR', 'handle' => 'UtahMammoth_PR', 'team_id' => 68, 'team_abbrev' => 'UTA'],
        ['name' => 'Brogan Houston', 'handle' => 'houston_brogan', 'team_id' => 68, 'team_abbrev' => 'UTA'],
        ['name' => 'Colorado Avalanche', 'handle' => 'Avalanche', 'team_id' => 21, 'team_abbrev' => 'COL'],
        ['name' => 'Evan Rawal', 'handle' => 'evanrawal', 'team_id' => 21, 'team_abbrev' => 'COL'],
        ['name' => 'DNVR Avalanche', 'handle' => 'DNVR_Avalanche', 'team_id' => 21, 'team_abbrev' => 'COL'],
    ];

    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('platform_user_id')->nullable()->after('handle');
            $table->unique(['platform', 'platform_user_id']);
        });

        foreach ($this->sources as $seed) {
            $sourceId = DB::table('sources')
                ->where('platform', 'x')
                ->where('handle', $seed['handle'])
                ->value('id');

            if ($sourceId === null) {
                $sourceId = DB::table('sources')->insertGetId([
                    'platform' => 'x',
                    'name' => $seed['name'],
                    'handle' => $seed['handle'],
                    'canonical_url' => 'https://x.com/' . $seed['handle'],
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('source_scopes')->updateOrInsert(
                [
                    'source_id' => $sourceId,
                    'sport' => 'hockey',
                    'league' => 'NHL',
                    'team_id' => $seed['team_id'],
                ],
                [
                    'team_abbrev' => $seed['team_abbrev'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropUnique(['platform', 'platform_user_id']);
            $table->dropColumn('platform_user_id');
        });
    }
};
