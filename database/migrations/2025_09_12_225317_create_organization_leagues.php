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
        Schema::create('organization_leagues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('league_id')
                ->constrained('leagues')
                ->cascadeOnDelete();

            $table->foreignId('discord_server_id')
                ->nullable()
                ->constrained('discord_servers')
                ->nullOnDelete(); // keep the link if the server is removed

            // Optional metadata
            $table->timestamp('linked_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            // Enforce "only one organization for a league"
            $table->unique('league_id', 'uq_league_single_org');

            // Helpful lookup
            $table->index(['organization_id', 'league_id'], 'idx_org_league_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_leagues');
    }
};
