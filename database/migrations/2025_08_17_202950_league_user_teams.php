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
        Schema::create('league_user_teams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->boolean('is_active')->default(true);
            $table->json('extras')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One team assignment per user per league; and no duplicate user↔team rows
            $table->unique(['user_id', 'league_id'], 'uq_user_league');
            $table->unique(['user_id', 'team_id'], 'uq_user_team');

            $table->index(['league_id', 'team_id'], 'idx_league_team_lookup');
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_user_teams');
    }
};
