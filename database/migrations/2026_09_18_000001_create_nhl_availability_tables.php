<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_player_injury_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();
            $table->string('provider', 32);
            $table->string('provider_player_key')->nullable();
            $table->string('player_name');
            $table->string('team_abbrev', 10)->nullable();
            $table->string('position', 10)->nullable();
            $table->string('body_part')->nullable();
            $table->string('availability', 32);
            $table->string('evidence_level', 32);
            $table->text('status_text')->nullable();
            $table->text('anticipated_return_text')->nullable();
            $table->date('anticipated_return_date')->nullable();
            $table->string('anticipated_return_precision', 24)->default('unknown');
            $table->timestamp('provider_published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->string('meaning_hash', 64);
            $table->text('source_url')->nullable();
            $table->json('raw_evidence')->nullable();
            $table->timestamps();

            $table->index(['provider', 'player_id', 'fetched_at']);
            $table->index(['team_abbrev', 'availability']);
        });

        Schema::create('nhl_player_injuries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->nullable()->unique();
            $table->string('player_name');
            $table->string('team_abbrev', 10)->nullable()->index();
            $table->string('position', 10)->nullable();
            $table->string('body_part')->nullable();
            $table->string('availability', 32)->index();
            $table->string('evidence_level', 32)->index();
            $table->text('status_text')->nullable();
            $table->text('anticipated_return_text')->nullable();
            $table->date('anticipated_return_date')->nullable();
            $table->string('anticipated_return_precision', 24)->default('unknown');
            $table->json('sources');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('nhl_starting_goalie_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id')->nullable()->index();
            $table->date('game_date')->index();
            $table->string('team_abbrev', 10);
            $table->string('opponent_abbrev', 10)->nullable();
            $table->boolean('is_home')->nullable();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();
            $table->string('player_name');
            $table->string('provider', 32);
            $table->string('provider_player_key')->nullable();
            $table->string('status', 24);
            $table->timestamp('provider_published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->text('source_url')->nullable();
            $table->json('raw_evidence')->nullable();
            $table->timestamps();

            $table->index(['game_date', 'team_abbrev', 'fetched_at'], 'goalie_date_team_fetched_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_starting_goalie_observations');
        Schema::dropIfExists('nhl_player_injuries');
        Schema::dropIfExists('nhl_player_injury_observations');
    }
};
