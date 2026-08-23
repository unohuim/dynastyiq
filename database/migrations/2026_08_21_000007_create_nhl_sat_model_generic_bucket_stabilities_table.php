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
        Schema::create('nhl_sat_model_generic_bucket_stabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->constrained('nhl_model_runs')
                ->cascadeOnDelete();
            $table->json('source_season_ids');
            $table->string('prior_season_id', 8)->nullable();
            $table->string('latest_season_id', 8)->nullable();
            $table->string('test_season_id', 8)->nullable();
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->string('profile_type', 40);
            $table->string('matched_bucket_key', 600);
            $table->unsignedTinyInteger('fallback_level')->nullable();
            $table->json('bucket_dimensions');
            $table->unsignedInteger('train_entity_count')->default(0);
            $table->unsignedInteger('prior_entity_count')->default(0);
            $table->unsignedInteger('latest_entity_count')->default(0);
            $table->unsignedInteger('test_entity_count')->default(0);
            $table->unsignedInteger('train_sat')->default(0);
            $table->unsignedInteger('prior_sat')->default(0);
            $table->unsignedInteger('latest_sat')->default(0);
            $table->unsignedInteger('test_sat')->default(0);
            $table->unsignedInteger('train_sog')->default(0);
            $table->unsignedInteger('prior_sog')->default(0);
            $table->unsignedInteger('latest_sog')->default(0);
            $table->unsignedInteger('test_sog')->default(0);
            $table->unsignedInteger('train_goals')->default(0);
            $table->unsignedInteger('prior_goals')->default(0);
            $table->unsignedInteger('latest_goals')->default(0);
            $table->unsignedInteger('test_goals')->default(0);
            $table->unsignedBigInteger('train_toi_seconds')->nullable();
            $table->unsignedBigInteger('prior_toi_seconds')->nullable();
            $table->unsignedBigInteger('latest_toi_seconds')->nullable();
            $table->unsignedBigInteger('test_toi_seconds')->nullable();
            $table->decimal('train_xsat_per_60', 12, 4)->nullable();
            $table->decimal('prior_xsat_per_60', 12, 4)->nullable();
            $table->decimal('latest_xsat_per_60', 12, 4)->nullable();
            $table->decimal('test_xsat_per_60', 12, 4)->nullable();
            $table->decimal('latest_minus_prior_xsat_per_60', 12, 4)->nullable();
            $table->decimal('test_minus_latest_xsat_per_60', 12, 4)->nullable();
            $table->decimal('test_minus_train_xsat_per_60', 12, 4)->nullable();
            $table->decimal('latest_minus_prior_xsat_rate', 12, 6)->nullable();
            $table->decimal('test_minus_latest_xsat_rate', 12, 6)->nullable();
            $table->decimal('test_minus_train_xsat_rate', 12, 6)->nullable();
            $table->string('latest_direction', 20)->nullable();
            $table->string('test_direction', 20)->nullable();
            $table->boolean('reversed_after_latest')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'profile_type', 'matched_bucket_key'],
                'uq_nhl_sat_model_generic_bucket_stability'
            );
            $table->index(['model_run_id', 'profile_type'], 'ix_nhl_sat_model_generic_bucket_stability_type');
            $table->index(['model_run_id', 'matched_bucket_key'], 'ix_nhl_sat_model_generic_bucket_stability_bucket');
            $table->index(['profile_type', 'latest_direction', 'test_direction'], 'ix_nhl_sat_model_generic_bucket_stability_direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_generic_bucket_stabilities');
    }
};
