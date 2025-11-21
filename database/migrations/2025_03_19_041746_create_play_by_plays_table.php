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
            $table->foreignId('nhl_game_id')->index();            
            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();

            $table->integer('event_owner_team_id')->nullable()->index();
            $table->integer('period')->nullable()->index();
            $table->string('time_in_period')->nullable();
            $table->string('time_remaining')->nullable();
            $table->integer('seconds_in_period')->nullable();
            $table->integer('seconds_in_game')->nullable();
            $table->integer('seconds_remaining')->nullable();
            $table->integer('seconds_since_last_event')->nullable();
            $table->string('type_desc_key')->nullable();
            $table->string('desc_key')->nullable();

            $table->string('strength')->nullable();
            $table->string('nhl_event_id')->nullable();
            $table->string('period_type')->nullable();
            $table->string('situation_code')->nullable(); // string for codes like "1551"
            $table->integer('type_code')->nullable();

            $table->integer('duration')->nullable();
            $table->string('penalty_type_code')->nullable();

            $table->integer('sort_order')->nullable();
            $table->integer('fo_winning_player_id')->nullable()->index();
            $table->integer('fo_losing_player_id')->nullable()->index();
            $table->integer('x_coord')->nullable();
            $table->integer('y_coord')->nullable();
            $table->string('home_team_defending_side')->nullable();
            $table->decimal('shot_distance', 8, 2)->nullable();
            $table->decimal('shot_angle', 7, 3)->nullable();
            $table->string('zone_code')->nullable();
            $table->string('code_type')->nullable();

            $table->integer('scoring_player_id')->nullable()->index();
            $table->integer('scoring_player_total')->default(0);
            $table->integer('assist1_player_id')->nullable()->index();
            $table->integer('assist1_player_total')->default(0);
            $table->integer('assist2_player_id')->nullable()->index();
            $table->integer('assist2_player_total')->default(0);

            $table->integer('committed_by_player_id')->nullable()->index();
            $table->integer('drawn_by_player_id')->nullable()->index();
            $table->string('shot_type')->nullable();
            $table->integer('shooting_player_id')->nullable()->index();
            $table->integer('goalie_in_net_player_id')->nullable()->index();
            $table->integer('blocking_player_id')->nullable()->index();
            $table->string('reason')->nullable();
            $table->string('secondary_reason')->nullable();
            $table->integer('hitting_player_id')->nullable()->index();
            $table->integer('hittee_player_id')->nullable()->index();
            

            $table->string('highlight_clip_sharing_url')->nullable();
            $table->unsignedBigInteger('highlight_clip_id')->nullable();
            $table->integer('away_score')->nullable()->default(0);
            $table->integer('home_score')->nullable()->default(0);

            $table->json('metadata')->nullable(); // for flexible additional data

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
