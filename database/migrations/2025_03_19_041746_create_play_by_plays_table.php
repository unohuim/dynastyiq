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
        Schema::create('play_by_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhl_game_id');
            $table->string('season_id');
            $table->string('game_date');
            $table->foreignId('away_team_id');
            $table->foreignId('home_team_id');
            $table->string('away_team_abbrev');
            $table->string('home_team_abbrev');
            $table->integer('event_owner_team_id')->nullable();
            $table->integer('period')->nullable();
            $table->string('time_in_period')->nullable();
            $table->string('time_remaining')->nullable();
            $table->integer('seconds_in_period')->nullable();
            $table->integer('seconds_in_game')->nullable();
            $table->integer('seconds_remaining')->nullable();
            $table->integer('seconds_since_last_event')->nullable();
            $table->string('type_desc_key')->nullable();
            $table->string('desc_key')->nullable();
            $table->string('penalty_count')->default(0);

            // $table->integer('away_G');
            // $table->integer('home_G');
            // $table->int('away_SOG');
            // $table->int('home_SOG');
            $table->string('strength');
            $table->string('nhl_event_id')->nullable();
            $table->string('period_type')->nullable();
            $table->integer('situation_code')->nullable();
            $table->integer('type_code')->nullable();

            $table->integer('duration')->nullable();
            $table->string('penalty_type_code')->nullable();

            $table->integer('sort_order')->nullable();
            $table->integer('fo_winning_player_id')->nullable();
            $table->integer('fo_losing_player_id')->nullable();
            $table->integer('x_coord')->default(0);
            $table->integer('y_coord')->default(0);
            $table->string('home_team_defending_side')->nullable();
            $table->string('zone_code')->nullable();
            $table->string('home_zone_code')->nullable();
            $table->string('away_zone_code')->nullable();
            $table->string('code_type')->nullable();

            $table->integer('committed_by_player_id')->nullable();
            $table->integer('drawn_by_player_id')->nullable();
            $table->string('shot_type')->nullable();
            $table->integer('shooting_player_id')->nullable();
            $table->integer('goalie_in_net_player_id')->nullable();
            $table->integer('away_sog')->default(0);
            $table->integer('home_sog')->default(0);
            $table->integer('blocking_player_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('secondary_reason')->nullable();
            $table->integer('hitting_player_id')->nullable();
            $table->integer('hittee_player_id')->nullable();
            $table->integer('player_id')->nullable();
            $table->integer('scoring_player_id')->nullable();
            $table->integer('scoring_player_total')->default(0);
            $table->integer('assist1_player_id')->nullable();
            $table->integer('assist1_player_total')->default(0);
            $table->integer('assist2_player_id')->nullable();
            $table->integer('assist2_player_total')->default(0);
            $table->string('highlight_clip_sharing_url')->nullable();
            $table->bigInteger('highlight_clip_id')->nullable();
            $table->integer('away_score')->default(0);
            $table->integer('home_score')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('play_by_plays');
    }
};
