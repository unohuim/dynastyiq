<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_sat_model_entity_rate_projection_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->constrained('nhl_model_runs')
                ->cascadeOnDelete();
            $table->string('profile_type', 40);
            $table->string('entity_key', 120);
            $table->integer('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->string('entity_role', 40)->nullable();
            $table->string('team_context', 20)->nullable();
            $table->string('situation', 12);
            $table->string('age_group', 12)->nullable();
            $table->string('sat_momentum_bucket', 32)->nullable();
            $table->string('hdsat_momentum_bucket', 32)->nullable();
            $table->string('toi_momentum_bucket', 32)->nullable();
            $table->string('sh_regression_bucket', 32)->nullable();
            $table->decimal('s1_gp', 12, 4)->nullable();
            $table->decimal('s2_gp', 12, 4)->nullable();
            $table->decimal('train_gp_per_season', 12, 4)->nullable();
            $table->decimal('projected_gp', 12, 4)->nullable();
            $table->unsignedInteger('s1_toi_seconds')->nullable();
            $table->unsignedInteger('s2_toi_seconds')->nullable();
            $table->unsignedInteger('train_toi_seconds')->nullable();
            $table->decimal('s1_toi_per_gp', 12, 4)->nullable();
            $table->decimal('s2_toi_per_gp', 12, 4)->nullable();
            $table->decimal('train_toi_per_gp', 12, 4)->nullable();
            $table->decimal('projected_toi_per_gp', 12, 4)->nullable();
            $table->unsignedInteger('s1_sat')->nullable();
            $table->unsignedInteger('s2_sat')->nullable();
            $table->unsignedInteger('train_sat')->nullable();
            $table->decimal('s1_sat_per_gp', 12, 4)->nullable();
            $table->decimal('s2_sat_per_gp', 12, 4)->nullable();
            $table->decimal('train_sat_per_gp', 12, 4)->nullable();
            $table->decimal('projected_sat_per_gp', 12, 4)->nullable();
            $table->decimal('s1_sat_per_60', 12, 4)->nullable();
            $table->decimal('s2_sat_per_60', 12, 4)->nullable();
            $table->decimal('train_sat_per_60', 12, 4)->nullable();
            $table->decimal('projected_sat_per_60', 12, 4)->nullable();
            $table->decimal('projected_sat_season', 12, 4)->nullable();
            $table->unsignedInteger('s1_hdsat')->nullable();
            $table->unsignedInteger('s2_hdsat')->nullable();
            $table->unsignedInteger('train_hdsat')->nullable();
            $table->decimal('s1_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('s2_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('train_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('projected_hdsat_per_gp', 12, 4)->nullable();
            $table->decimal('s1_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('s2_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('train_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('projected_hdsat_per_60', 12, 4)->nullable();
            $table->decimal('s1_hdsat_sat_rate', 12, 6)->nullable();
            $table->decimal('s2_hdsat_sat_rate', 12, 6)->nullable();
            $table->decimal('train_hdsat_sat_rate', 12, 6)->nullable();
            $table->decimal('projected_hdsat_sat_rate', 12, 6)->nullable();
            $table->decimal('projected_hdsat_season', 12, 4)->nullable();
            $table->unsignedInteger('s1_sog')->nullable();
            $table->unsignedInteger('s2_sog')->nullable();
            $table->unsignedInteger('train_sog')->nullable();
            $table->unsignedInteger('s1_goals')->nullable();
            $table->unsignedInteger('s2_goals')->nullable();
            $table->unsignedInteger('train_goals')->nullable();
            $table->decimal('s1_sh_pct', 12, 6)->nullable();
            $table->decimal('s2_sh_pct', 12, 6)->nullable();
            $table->decimal('train_sh_pct', 12, 6)->nullable();
            $table->string('formula_version', 80);
            $table->string('formula_segment', 120);
            $table->json('metadata')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'profile_type', 'entity_key', 'situation'],
                'uq_nhl_sat_model_rate_projection_split'
            );
            $table->index(['model_run_id', 'profile_type', 'situation'], 'ix_nhl_sat_model_rate_projection_split_type');
            $table->index(['profile_type', 'entity_id', 'situation'], 'ix_nhl_sat_model_rate_projection_split_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_entity_rate_projection_splits');
    }
};
