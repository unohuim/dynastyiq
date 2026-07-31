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
        Schema::create('nhl_faceoff_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('play_by_play_id')
                ->constrained('play_by_plays')
                ->cascadeOnDelete();
            $table->foreignId('next_play_by_play_id')
                ->nullable()
                ->constrained('play_by_plays')
                ->nullOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('nhl_event_id')->nullable();
            $table->string('season_id', 8)->nullable();
            $table->date('game_date')->nullable();

            $table->string('fact_version', 40)->default('faceoff_facts_v1');
            $table->integer('period')->nullable();
            $table->string('period_type', 12)->nullable();
            $table->integer('seconds_in_game')->nullable();
            $table->integer('seconds_since_last_event')->nullable();
            $table->string('situation_code')->nullable();
            $table->string('strength', 12)->nullable();
            $table->string('strength_bucket', 32)->nullable();

            $table->integer('winning_team_id')->nullable();
            $table->string('winning_team_abbrev', 16)->nullable();
            $table->integer('losing_team_id')->nullable();
            $table->string('losing_team_abbrev', 16)->nullable();
            $table->integer('winning_player_id')->nullable();
            $table->integer('losing_player_id')->nullable();

            $table->string('zone_code', 12)->nullable();
            $table->string('winning_team_zone', 12)->nullable();
            $table->string('losing_team_zone', 12)->nullable();
            $table->string('zone_bucket', 32)->nullable();
            $table->string('winning_team_zone_bucket', 32)->nullable();
            $table->string('losing_team_zone_bucket', 32)->nullable();

            $table->unsignedBigInteger('winning_unit_id')->nullable();
            $table->unsignedBigInteger('losing_unit_id')->nullable();
            $table->json('winning_on_ice_player_ids')->nullable();
            $table->json('losing_on_ice_player_ids')->nullable();

            $table->string('next_event_type')->nullable();
            $table->integer('next_event_team_id')->nullable();
            $table->string('next_event_zone', 12)->nullable();
            $table->string('next_event_zone_bucket', 32)->nullable();
            $table->integer('next_event_seconds_delta')->nullable();
            $table->string('advancement_bucket', 32)->nullable();
            $table->integer('advancement_value')->nullable();

            $table->json('facts_payload')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')
                ->references('nhl_game_id')
                ->on('nhl_games')
                ->cascadeOnDelete();
            $table->foreign('winning_unit_id')
                ->references('id')
                ->on('nhl_units')
                ->nullOnDelete();
            $table->foreign('losing_unit_id')
                ->references('id')
                ->on('nhl_units')
                ->nullOnDelete();
            $table->unique('play_by_play_id', 'uq_nhl_faceoff_facts_pbp');
            $table->index(['season_id', 'game_date'], 'ix_nhl_fof_season_date');
            $table->index(['nhl_game_id', 'winning_team_id'], 'ix_nhl_fof_game_winning_team');
            $table->index(['nhl_game_id', 'losing_team_id'], 'ix_nhl_fof_game_losing_team');
            $table->index(['winning_team_id', 'winning_team_zone_bucket'], 'ix_nhl_fof_team_zone');
            $table->index(['winning_player_id', 'winning_team_zone_bucket'], 'ix_nhl_fof_winner_zone');
            $table->index(['winning_unit_id', 'winning_team_zone_bucket'], 'ix_nhl_fof_unit_zone');
            $table->index(['advancement_bucket', 'winning_team_zone_bucket'], 'ix_nhl_fof_advancement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_faceoff_facts');
    }
};
