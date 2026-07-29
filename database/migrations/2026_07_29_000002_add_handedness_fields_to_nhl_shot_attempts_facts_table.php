<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nhl_shot_attempts_facts', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_shoots')) {
                $table->string('shooter_shoots', 1)->nullable()->after('shooter_player_id');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_catches')) {
                $table->string('goalie_catches', 1)->nullable()->after('goalie_player_id');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'shot_side')) {
                $table->string('shot_side', 16)->nullable()->after('abs_shot_angle');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'is_off_wing_attempt')) {
                $table->boolean('is_off_wing_attempt')->nullable()->after('shot_side');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_hand_matchup_bucket')) {
                $table->string('goalie_hand_matchup_bucket', 32)->nullable()->after('is_off_wing_attempt');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_shot_attempts_facts', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_hand_matchup_bucket')) {
                $table->dropColumn('goalie_hand_matchup_bucket');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'is_off_wing_attempt')) {
                $table->dropColumn('is_off_wing_attempt');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'shot_side')) {
                $table->dropColumn('shot_side');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_catches')) {
                $table->dropColumn('goalie_catches');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_shoots')) {
                $table->dropColumn('shooter_shoots');
            }
        });
    }
};
