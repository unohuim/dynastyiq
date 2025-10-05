<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_roster_memberships', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('platform_team_id')
                ->constrained('platform_teams')
                ->cascadeOnDelete();

            $table->foreignId('player_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('platform', ['fantrax', 'yahoo', 'espn'])->index();
            $table->string('platform_player_id')->nullable()->index();

            $table->string('slot')->nullable();
            $table->enum('status', ['active', 'bench', 'ir', 'na', 'taxi'])->nullable();
            $table->json('eligibility')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->unique(['platform_team_id', 'player_id', 'starts_at'], 'uq_roster_period_start');
            $table->index(['platform_team_id', 'ends_at'], 'idx_team_current');
            $table->index(['player_id', 'ends_at'], 'idx_player_current');
            $table->index(['platform', 'platform_player_id'], 'idx_platform_external');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_roster_memberships');
    }
};
