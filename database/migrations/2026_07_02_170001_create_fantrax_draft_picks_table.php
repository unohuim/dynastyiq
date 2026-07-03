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
        Schema::create('fantrax_draft_picks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')
                ->constrained('platform_leagues')
                ->cascadeOnDelete();
            $table->string('provider_pick_key', 120);
            $table->unsignedInteger('overall_pick')->nullable();
            $table->unsignedInteger('round')->nullable();
            $table->unsignedInteger('pick')->nullable();
            $table->unsignedInteger('pick_in_round')->nullable();
            $table->string('fantrax_team_id')->nullable();
            $table->string('fantrax_player_id')->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->string('payload_hash', 64);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_league_id', 'provider_pick_key'], 'uq_fantrax_draft_pick_provider');
            $table->index(['platform_league_id', 'overall_pick'], 'idx_fantrax_draft_pick_overall');
            $table->index(['platform_league_id', 'fantrax_player_id'], 'idx_fantrax_draft_pick_player');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantrax_draft_picks');
    }
};
