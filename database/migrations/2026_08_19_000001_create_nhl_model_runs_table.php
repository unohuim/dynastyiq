<?php

declare(strict_types=1);

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
        Schema::create('nhl_model_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_key', 160)->unique();
            $table->string('name', 160);
            $table->string('model_family', 64);
            $table->string('workflow_stage', 32);
            $table->string('model_version', 80);
            $table->string('train_start_season_id', 8)->nullable();
            $table->string('train_end_season_id', 8)->nullable();
            $table->json('train_season_ids');
            $table->json('season_weights')->nullable();
            $table->string('target_season_id', 8)->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('run_config')->nullable();
            $table->json('metrics')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['model_family', 'workflow_stage'], 'ix_nhl_model_runs_family_stage');
            $table->index(['target_season_id', 'status'], 'ix_nhl_model_runs_target_status');
            $table->index(['train_start_season_id', 'train_end_season_id'], 'ix_nhl_model_runs_train_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_model_runs');
    }
};
