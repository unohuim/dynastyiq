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
        Schema::table('nhl_expected_goals_model_buckets', function (Blueprint $table): void {
            $table->decimal('confidence_score', 5, 4)
                ->nullable()
                ->after('smoothed_goal_probability');
            $table->string('confidence_bucket', 24)
                ->nullable()
                ->after('confidence_score');
            $table->decimal('shrinkage_weight', 5, 4)
                ->default(0)
                ->after('confidence_bucket');

            $table->index(
                ['expected_goals_model_id', 'confidence_bucket'],
                'ix_nhl_xg_buckets_confidence'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_expected_goals_model_buckets', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_xg_buckets_confidence');
            $table->dropColumn(['confidence_score', 'confidence_bucket', 'shrinkage_weight']);
        });
    }
};
