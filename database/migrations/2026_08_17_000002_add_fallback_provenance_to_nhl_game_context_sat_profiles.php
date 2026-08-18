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
        Schema::table('nhl_official_sat_profile_buckets', function (Blueprint $table): void {
            $table->string('prior_bucket_key', 600)->nullable();
            $table->unsignedTinyInteger('prior_fallback_level')->nullable();
            $table->unsignedInteger('prior_sat')->default(0);
            $table->unsignedInteger('prior_weight_sat')->default(0);
            $table->decimal('shrinkage_weight', 5, 4)->default(0);
            $table->index(['source_season_id', 'shrinkage_weight'], 'ix_nhl_official_sat_profile_shrinkage');
        });

        Schema::table('nhl_staff_sat_profile_buckets', function (Blueprint $table): void {
            $table->string('prior_bucket_key', 600)->nullable();
            $table->unsignedTinyInteger('prior_fallback_level')->nullable();
            $table->unsignedInteger('prior_sat')->default(0);
            $table->unsignedInteger('prior_weight_sat')->default(0);
            $table->decimal('shrinkage_weight', 5, 4)->default(0);
            $table->index(['source_season_id', 'shrinkage_weight'], 'ix_nhl_staff_sat_profile_shrinkage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_staff_sat_profile_buckets', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_staff_sat_profile_shrinkage');
            $table->dropColumn([
                'prior_bucket_key',
                'prior_fallback_level',
                'prior_sat',
                'prior_weight_sat',
                'shrinkage_weight',
            ]);
        });

        Schema::table('nhl_official_sat_profile_buckets', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_official_sat_profile_shrinkage');
            $table->dropColumn([
                'prior_bucket_key',
                'prior_fallback_level',
                'prior_sat',
                'prior_weight_sat',
                'shrinkage_weight',
            ]);
        });
    }
};
