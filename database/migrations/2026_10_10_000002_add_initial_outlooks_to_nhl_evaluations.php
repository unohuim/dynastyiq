<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve historical evaluations while adding named forecast containers and revisioned outlooks. */
    public function up(): void
    {
        Schema::table('nhl_next_game_evaluations', function (Blueprint $table): void {
            $table->string('name', 160)->nullable();
        });
        Schema::create('nhl_evaluation_player_outlooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('nhl_next_game_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('nhl_player_id');
            $table->string('player_name')->nullable();
            $table->string('strength', 8);
            $table->string('period', 8);
            $table->unsignedInteger('revision')->default(1);
            $table->decimal('sat_per_60', 12, 4)->nullable();
            $table->decimal('toi_per_game_seconds', 12, 4)->nullable();
            $table->json('provenance');
            $table->timestamps();
            $table->unique(['evaluation_id', 'nhl_player_id', 'strength', 'period', 'revision'], 'uq_evaluation_player_outlook');
        });
    }

    /** Remove only this feature's additions. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_evaluation_player_outlooks');
        Schema::table('nhl_next_game_evaluations', fn (Blueprint $table) => $table->dropColumn('name'));
    }
};
