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
        Schema::create('yahoo_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_external_identity_id')
                ->nullable()
                ->constrained('player_external_identities')
                ->nullOnDelete();
            $table->foreignId('player_id')
                ->nullable()
                ->constrained('players')
                ->nullOnDelete();
            $table->string('game_key', 40)->index();
            $table->string('player_key', 120)->unique();
            $table->string('yahoo_player_id', 80);
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('editorial_team_abbr', 40)->nullable();
            $table->string('display_position', 40)->nullable();
            $table->json('eligible_positions')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['game_key', 'yahoo_player_id']);
            $table->index('player_external_identity_id');
            $table->index('player_id');
            $table->index(['editorial_team_abbr', 'display_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('yahoo_players');
    }
};
