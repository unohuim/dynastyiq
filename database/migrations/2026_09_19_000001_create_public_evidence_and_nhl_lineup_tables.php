<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32);
            $table->string('name');
            $table->string('handle')->nullable();
            $table->text('canonical_url');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['platform', 'handle']);
        });

        Schema::create('source_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 32);
            $table->string('league', 32);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('team_abbrev', 10)->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'sport', 'league', 'team_id'], 'source_scope_unique');
        });

        Schema::create('source_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('following')->nullable();
            $table->unsignedBigInteger('posts')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
        });

        Schema::create('source_engagement_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->text('evidence_url');
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('replies')->nullable();
            $table->unsignedBigInteger('reposts')->nullable();
            $table->unsignedBigInteger('views')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['source_id', 'observed_at']);
        });

        Schema::create('nhl_lineup_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->unsignedBigInteger('team_id');
            $table->string('team_abbrev', 10);
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->text('post_url');
            $table->text('post_text');
            $table->timestamp('provider_published_at')->nullable();
            $table->timestamp('observed_at');
            $table->unsignedBigInteger('like_count')->nullable();
            $table->unsignedBigInteger('reply_count')->nullable();
            $table->unsignedBigInteger('repost_count')->nullable();
            $table->unsignedBigInteger('view_count')->nullable();
            $table->string('completeness', 24);
            $table->string('structure_hash', 64);
            $table->json('raw_evidence')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')->references('nhl_game_id')->on('nhl_games')->cascadeOnDelete();
            $table->unique(['nhl_game_id', 'team_id', 'post_url'], 'nhl_lineup_post_unique');
            $table->index(['nhl_game_id', 'team_id', 'structure_hash'], 'nhl_lineup_consensus_index');
        });

        Schema::create('nhl_lineup_observation_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nhl_lineup_observation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();
            $table->string('player_name');
            $table->string('lineup_role', 16);
            $table->string('line_key', 16);
            $table->unsignedTinyInteger('slot_index');
            $table->string('resolution_status', 24);
            $table->timestamps();

            $table->unique(['nhl_lineup_observation_id', 'line_key', 'slot_index'], 'nhl_lineup_slot_unique');
        });

        Schema::create('nhl_current_lineups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->unsignedBigInteger('team_id');
            $table->string('team_abbrev', 10);
            $table->foreignId('nhl_lineup_observation_id')->constrained()->cascadeOnDelete();
            $table->string('structure_hash', 64);
            $table->string('evidence_status', 32);
            $table->unsignedTinyInteger('source_count');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamps();

            $table->foreign('nhl_game_id')->references('nhl_game_id')->on('nhl_games')->cascadeOnDelete();
            $table->unique(['nhl_game_id', 'team_id']);
        });

        Schema::create('integration_api_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('operation', 64);
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('tool_calls')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['provider', 'operation', 'occurred_at'], 'integration_usage_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_api_usage_logs');
        Schema::dropIfExists('nhl_current_lineups');
        Schema::dropIfExists('nhl_lineup_observation_players');
        Schema::dropIfExists('nhl_lineup_observations');
        Schema::dropIfExists('source_engagement_snapshots');
        Schema::dropIfExists('source_metric_snapshots');
        Schema::dropIfExists('source_scopes');
        Schema::dropIfExists('sources');
    }
};
