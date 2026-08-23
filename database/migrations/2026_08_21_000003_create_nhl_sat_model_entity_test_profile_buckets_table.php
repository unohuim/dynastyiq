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
        Schema::create('nhl_sat_model_entity_test_profile_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->constrained('nhl_model_runs')
                ->cascadeOnDelete();
            $table->foreignId('sat_expected_goals_model_id')
                ->constrained('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->foreignId('sog_expected_goals_model_id')
                ->nullable()
                ->constrained('nhl_expected_goals_models')
                ->nullOnDelete();
            $table->json('source_season_ids');
            $table->string('test_season_id', 8);
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->string('profile_type', 40);
            $table->string('entity_key', 120);
            $table->integer('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->string('entity_role', 40)->nullable();
            $table->string('team_context', 20)->nullable();
            $table->string('matched_bucket_key', 600);
            $table->unsignedTinyInteger('fallback_level');
            $table->json('bucket_dimensions');
            $table->unsignedInteger('source_sat')->default(0);
            $table->unsignedInteger('source_sog')->default(0);
            $table->unsignedInteger('source_goals')->default(0);
            $table->decimal('source_profile_share', 9, 6)->default(0);
            $table->unsignedInteger('source_toi_seconds')->nullable();
            $table->decimal('source_xsat_per_60', 12, 4)->nullable();
            $table->decimal('source_xsog_per_60', 12, 4)->nullable();
            $table->decimal('source_xg_per_60', 12, 4)->nullable();
            $table->decimal('expected_sog', 12, 4)->default(0);
            $table->decimal('expected_goals', 12, 4)->default(0);
            $table->decimal('sog_above_expected', 12, 4)->default(0);
            $table->decimal('goals_above_expected', 12, 4)->default(0);
            $table->decimal('sat_probability', 9, 6)->default(0);
            $table->decimal('goal_probability', 9, 6)->default(0);
            $table->decimal('confidence_score', 9, 4)->default(0);
            $table->decimal('shrinkage_weight', 9, 4)->default(0);
            $table->string('confidence_bucket', 20)->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('profiled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_entity_test_profile'
            );
            $table->index(['model_run_id', 'profile_type'], 'ix_nhl_sat_model_entity_test_profiles_type');
            $table->index(['model_run_id', 'matched_bucket_key'], 'ix_nhl_sat_model_entity_test_profiles_bucket');
            $table->index(['profile_type', 'entity_id'], 'ix_nhl_sat_model_entity_test_profiles_entity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_entity_test_profile_buckets');
    }
};
