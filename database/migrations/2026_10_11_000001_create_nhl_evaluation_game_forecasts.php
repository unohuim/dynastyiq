<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store bounded game work separately from immutable player forecasts and later comparisons. */
    public function up(): void
    {
        Schema::create('nhl_evaluation_game_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('nhl_next_game_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->string('strength', 8);
            $table->string('period', 8);
            $table->string('status', 16)->default('queued');
            $table->string('phase', 16)->default('forecast');
            $table->uuid('work_token');
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('lineup');
            $table->timestamp('source_cutoff_at');
            $table->timestamp('forecasted_at')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'nhl_game_id', 'strength', 'period'], 'uq_evaluation_game_forecast');
            $table->index(['evaluation_id', 'status']);
        });
        Schema::create('nhl_evaluation_game_player_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('forecast_id')->constrained('nhl_evaluation_game_forecasts')->cascadeOnDelete();
            $table->unsignedInteger('nhl_player_id');
            $table->unsignedInteger('nhl_team_id');
            $table->string('player_name')->nullable();
            $table->json('inputs');
            $table->decimal('sat_per_60', 12, 4)->nullable();
            $table->decimal('toi_per_game_seconds', 12, 4)->nullable();
            $table->string('sat_direction', 12)->nullable();
            $table->json('comparison')->nullable();
            $table->timestamps();
            $table->unique(['forecast_id', 'nhl_player_id'], 'uq_evaluation_game_player_forecast');
        });
    }

    /** Remove only the evaluation game forecast tables. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_evaluation_game_player_forecasts');
        Schema::dropIfExists('nhl_evaluation_game_forecasts');
    }
};
