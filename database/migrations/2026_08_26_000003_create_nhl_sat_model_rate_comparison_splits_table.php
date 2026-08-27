<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_sat_model_entity_rate_comparison_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->constrained('nhl_model_runs')
                ->cascadeOnDelete();
            $table->string('test_season_id', 8);
            $table->string('profile_type', 40);
            $table->string('entity_key', 120);
            $table->integer('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->string('entity_role', 40)->nullable();
            $table->string('team_context', 20)->nullable();
            $table->string('situation', 12);
            $table->decimal('train_gp_per_season', 12, 4)->nullable();
            $table->decimal('test_gp_per_season', 12, 4)->nullable();
            $table->unsignedInteger('train_toi_seconds')->nullable();
            $table->unsignedInteger('test_toi_seconds')->nullable();
            $table->decimal('train_toi_per_gp', 12, 4)->nullable();
            $table->decimal('test_toi_per_gp', 12, 4)->nullable();
            $table->unsignedInteger('train_sat')->nullable();
            $table->unsignedInteger('test_sat')->nullable();
            $table->decimal('train_sat_per_gp', 12, 4)->nullable();
            $table->decimal('test_sat_per_gp', 12, 4)->nullable();
            $table->decimal('train_sat_per_60', 12, 4)->nullable();
            $table->decimal('test_sat_per_60', 12, 4)->nullable();
            $table->unsignedInteger('train_hdsat')->nullable();
            $table->unsignedInteger('test_hdsat')->nullable();
            $table->decimal('train_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('test_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('train_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('test_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('train_hdsat_sat_rate', 12, 6)->nullable();
            $table->decimal('test_hdsat_sat_rate', 12, 6)->nullable();
            $table->unsignedInteger('train_sog')->nullable();
            $table->unsignedInteger('test_sog')->nullable();
            $table->decimal('train_sog_per_gp', 12, 4)->nullable();
            $table->decimal('test_sog_per_gp', 12, 4)->nullable();
            $table->decimal('train_sog_per_60', 12, 4)->nullable();
            $table->decimal('test_sog_per_60', 12, 4)->nullable();
            $table->unsignedInteger('train_goals')->nullable();
            $table->unsignedInteger('test_goals')->nullable();
            $table->decimal('train_goals_per_gp', 12, 4)->nullable();
            $table->decimal('test_goals_per_gp', 12, 4)->nullable();
            $table->decimal('train_goals_per_60', 12, 4)->nullable();
            $table->decimal('test_goals_per_60', 12, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key', 'situation'],
                'uq_nhl_sat_model_rate_compare_split'
            );
            $table->index(['model_run_id', 'profile_type', 'situation'], 'ix_nhl_sat_model_rate_compare_split_type');
            $table->index(['profile_type', 'entity_id', 'situation'], 'ix_nhl_sat_model_rate_compare_split_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_entity_rate_comparison_splits');
    }
};
