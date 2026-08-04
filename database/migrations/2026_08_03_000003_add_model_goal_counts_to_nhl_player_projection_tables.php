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
        Schema::table('nhl_player_season_projections', function (Blueprint $table): void {
            $table->unsignedInteger('source_model_goals')->default(0)->after('source_goals');
        });

        Schema::table('nhl_player_projection_profile_buckets', function (Blueprint $table): void {
            $table->unsignedInteger('source_model_goals')->default(0)->after('source_goals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_player_projection_profile_buckets', function (Blueprint $table): void {
            $table->dropColumn('source_model_goals');
        });

        Schema::table('nhl_player_season_projections', function (Blueprint $table): void {
            $table->dropColumn('source_model_goals');
        });
    }
};
