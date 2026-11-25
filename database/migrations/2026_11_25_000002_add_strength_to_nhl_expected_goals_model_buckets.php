<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve combined buckets while allowing independent strength samples.
     */
    public function up(): void
    {
        Schema::table('nhl_expected_goals_model_buckets', function (Blueprint $table): void {
            $table->enum('strength', ['all', 'ev', 'pp', 'pk'])->default('all');
            $table->dropUnique('uq_nhl_xg_buckets_model_key');
            $table->unique(
                ['expected_goals_model_id', 'bucket_key', 'strength'],
                'uq_nhl_xg_buckets_model_key_strength'
            );
        });
    }

    /**
     * Restore the original key only when no strength-specific data would be lost.
     */
    public function down(): void
    {
        if (DB::table('nhl_expected_goals_model_buckets')->where('strength', '!=', 'all')->exists()) {
            throw new \RuntimeException(
                'Cannot remove bucket strength while strength-specific probability rows exist.'
            );
        }

        Schema::table('nhl_expected_goals_model_buckets', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_xg_buckets_model_key_strength');
            $table->unique(['expected_goals_model_id', 'bucket_key'], 'uq_nhl_xg_buckets_model_key');
            $table->dropColumn('strength');
        });
    }
};
