<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_lineup_observation_players', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable()->after('nhl_lineup_observation_id');
            $table->string('team_abbrev', 10)->nullable()->after('team_id');
            $table->index(['team_id', 'team_abbrev'], 'nhl_lineup_player_team_index');
        });

        DB::table('nhl_lineup_observations')
            ->select(['id', 'team_id', 'team_abbrev'])
            ->orderBy('id')
            ->chunkById(500, function ($observations): void {
                foreach ($observations as $observation) {
                    DB::table('nhl_lineup_observation_players')
                        ->where('nhl_lineup_observation_id', $observation->id)
                        ->update([
                            'team_id' => $observation->team_id,
                            'team_abbrev' => $observation->team_abbrev,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('nhl_lineup_observation_players', function (Blueprint $table): void {
            $table->dropIndex('nhl_lineup_player_team_index');
            $table->dropColumn(['team_id', 'team_abbrev']);
        });
    }
};
