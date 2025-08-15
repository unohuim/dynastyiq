<?php

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
        Schema::create('nhl_games', function (Blueprint $table) {
            $table->unsignedBigInteger('nhl_game_id')->primary();
            $table->string('season_id');
            $table->integer('game_type');
            $table->date('game_date');

            $table->string('game_dow');
            $table->string('game_month');

            $table->string('venue')->nullable();
            $table->string('venue_location')->nullable();

            $table->timestamp('start_time_utc')->nullable();
            $table->string('eastern_utc_offset', 6)->nullable();
            $table->string('venue_utc_offset', 6)->nullable();

            $table->boolean('shootout_in_use')->default(false);
            $table->boolean('ot_in_use')->default(false);

            $table->string('game_state', 20)->nullable();
            $table->string('game_schedule_state', 20)->nullable();

            $table->integer('current_period')->nullable();
            $table->string('period_type', 10)->nullable();
            $table->integer('max_regulation_periods')->nullable();

            $table->string('clock_time_remaining', 20)->nullable();
            $table->string('clock_seconds_remaining', 20)->nullable();
            $table->string('clock_running', 20)->nullable();
            $table->string('clock_in_intermission', 20)->nullable();
            $table->string('clock_display_period', 20)->nullable();
            $table->string('clock_max_periods', 20)->nullable();

            $table->json('tv_broadcasts')->nullable();
            $table->json('game_outcome')->nullable();

            // Add your other fields here exactly as before...

            $table->foreignId('home_team_id')->nullable();
            $table->string('home_team_common_name')->nullable();
            $table->string('home_team_abbrev', 10)->nullable();
            $table->integer('home_team_score')->nullable();
            $table->integer('home_team_sog')->nullable();
            $table->string('home_team_logo')->nullable();
            $table->string('home_team_dark_logo')->nullable();
            $table->string('home_team_place_name')->nullable();

            $table->foreignId('away_team_id')->nullable();
            $table->string('away_team_common_name')->nullable();
            $table->string('away_team_abbrev', 10)->nullable();
            $table->integer('away_team_score')->nullable();
            $table->integer('away_team_sog')->nullable();
            $table->string('away_team_logo')->nullable();
            $table->string('away_team_dark_logo')->nullable();
            $table->string('away_team_place_name')->nullable();

            $table->boolean('limited_scoring')->default(false);


            $table->timestamps();

            $table->index('season_id');
            $table->index('game_date');
            $table->index('home_team_id');
            $table->index('away_team_id');
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_games');
    }
};
