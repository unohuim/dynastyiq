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
        Schema::create('nhl_sat_model_entity_rate_comparison_buckets', function (Blueprint $table): void {
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
            $table->string('matched_bucket_key', 600);
            $table->json('bucket_dimensions')->nullable();
            $table->boolean('is_other_bucket')->default(false);
            $table->unsignedInteger('train_sat')->default(0);
            $table->unsignedInteger('train_sog')->default(0);
            $table->unsignedInteger('train_goals')->default(0);
            $table->unsignedInteger('test_sat')->default(0);
            $table->unsignedInteger('test_sog')->default(0);
            $table->unsignedInteger('test_goals')->default(0);
            $table->decimal('train_profile_share', 9, 6)->default(0);
            $table->decimal('test_profile_share', 9, 6)->nullable();
            $table->decimal('share_drift', 9, 6)->nullable();
            $table->decimal('share_drift_rate', 12, 6)->nullable();
            $table->decimal('train_xsat_per_60', 12, 4)->nullable();
            $table->decimal('projected_xsat_per_60', 12, 4)->nullable();
            $table->decimal('test_xsat_per_60', 12, 4)->nullable();
            $table->decimal('xsat_drift', 12, 4)->nullable();
            $table->decimal('xsat_drift_rate', 12, 6)->nullable();
            $table->decimal('xsat_error', 12, 4)->nullable();
            $table->decimal('xsat_error_rate', 12, 6)->nullable();
            $table->decimal('train_xsog_per_60', 12, 4)->nullable();
            $table->decimal('projected_xsog_per_60', 12, 4)->nullable();
            $table->decimal('test_xsog_per_60', 12, 4)->nullable();
            $table->decimal('xsog_drift', 12, 4)->nullable();
            $table->decimal('xsog_drift_rate', 12, 6)->nullable();
            $table->decimal('xsog_error', 12, 4)->nullable();
            $table->decimal('xsog_error_rate', 12, 6)->nullable();
            $table->decimal('train_xg_per_60', 12, 4)->nullable();
            $table->decimal('projected_xg_per_60', 12, 4)->nullable();
            $table->decimal('test_xg_per_60', 12, 4)->nullable();
            $table->decimal('xg_drift', 12, 4)->nullable();
            $table->decimal('xg_drift_rate', 12, 6)->nullable();
            $table->decimal('xg_error', 12, 4)->nullable();
            $table->decimal('xg_error_rate', 12, 6)->nullable();
            $table->decimal('confidence_score', 9, 4)->default(0);
            $table->decimal('shrinkage_weight', 9, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_rate_compare_bucket'
            );
            $table->index(['model_run_id', 'profile_type'], 'ix_nhl_sat_model_rate_compare_bucket_type');
            $table->index(['profile_type', 'entity_id'], 'ix_nhl_sat_model_rate_compare_bucket_entity');
        });

        Schema::create('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
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
            $table->unsignedInteger('bucket_rows')->default(0);
            $table->unsignedInteger('matched_bucket_rows')->default(0);
            $table->unsignedInteger('train_sat')->default(0);
            $table->unsignedInteger('train_sog')->default(0);
            $table->unsignedInteger('train_goals')->default(0);
            $table->unsignedInteger('test_sat')->default(0);
            $table->unsignedInteger('test_sog')->default(0);
            $table->unsignedInteger('test_goals')->default(0);
            $table->decimal('train_profile_share', 9, 6)->default(0);
            $table->decimal('test_profile_share', 9, 6)->nullable();
            $table->decimal('share_drift', 9, 6)->nullable();
            $table->decimal('share_drift_rate', 12, 6)->nullable();
            $table->decimal('train_xsat_per_60', 12, 4)->nullable();
            $table->decimal('projected_xsat_per_60', 12, 4)->nullable();
            $table->decimal('test_xsat_per_60', 12, 4)->nullable();
            $table->decimal('xsat_drift', 12, 4)->nullable();
            $table->decimal('xsat_drift_rate', 12, 6)->nullable();
            $table->decimal('xsat_error', 12, 4)->nullable();
            $table->decimal('xsat_error_rate', 12, 6)->nullable();
            $table->decimal('train_xsog_per_60', 12, 4)->nullable();
            $table->decimal('projected_xsog_per_60', 12, 4)->nullable();
            $table->decimal('test_xsog_per_60', 12, 4)->nullable();
            $table->decimal('xsog_drift', 12, 4)->nullable();
            $table->decimal('xsog_drift_rate', 12, 6)->nullable();
            $table->decimal('xsog_error', 12, 4)->nullable();
            $table->decimal('xsog_error_rate', 12, 6)->nullable();
            $table->decimal('train_xg_per_60', 12, 4)->nullable();
            $table->decimal('projected_xg_per_60', 12, 4)->nullable();
            $table->decimal('test_xg_per_60', 12, 4)->nullable();
            $table->decimal('xg_drift', 12, 4)->nullable();
            $table->decimal('xg_drift_rate', 12, 6)->nullable();
            $table->decimal('xg_error', 12, 4)->nullable();
            $table->decimal('xg_error_rate', 12, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key'],
                'uq_nhl_sat_model_rate_compare_aggregate'
            );
            $table->index(['model_run_id', 'profile_type'], 'ix_nhl_sat_model_rate_compare_agg_type');
            $table->index(['profile_type', 'entity_id'], 'ix_nhl_sat_model_rate_compare_agg_entity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_entity_rate_comparison_aggregates');
        Schema::dropIfExists('nhl_sat_model_entity_rate_comparison_buckets');
    }
};
