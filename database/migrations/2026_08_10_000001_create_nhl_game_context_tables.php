<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_officials', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name');
            $table->string('normalized_name')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('nhl_staff', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name');
            $table->string('normalized_name')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('nhl_game_officials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->foreignId('nhl_official_id')->constrained('nhl_officials')->cascadeOnDelete();
            $table->string('role', 32);
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('provider_name');
            $table->string('source', 32)->default('right-rail');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')->references('nhl_game_id')->on('nhl_games')->cascadeOnDelete();
            $table->unique(['nhl_game_id', 'role', 'sequence']);
            $table->index(['nhl_official_id', 'role']);
            $table->index(['role', 'provider_name']);
        });

        Schema::create('nhl_game_team_staff', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->foreignId('nhl_staff_id')->constrained('nhl_staff')->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_team_id')->nullable();
            $table->string('team_side', 16);
            $table->string('role', 32);
            $table->string('provider_name');
            $table->string('source', 32)->default('right-rail');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')->references('nhl_game_id')->on('nhl_games')->cascadeOnDelete();
            $table->unique(['nhl_game_id', 'team_side', 'role']);
            $table->index(['nhl_staff_id', 'role']);
            $table->index(['nhl_team_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_game_team_staff');
        Schema::dropIfExists('nhl_game_officials');
        Schema::dropIfExists('nhl_staff');
        Schema::dropIfExists('nhl_officials');
    }
};
