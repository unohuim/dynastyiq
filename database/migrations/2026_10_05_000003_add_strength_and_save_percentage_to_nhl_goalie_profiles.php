<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_model_entity_profile_buckets', function (Blueprint $table): void {
            $table->string('strength', 8)->default('all')->after('profile_type');
            $table->decimal('source_save_percentage', 9, 4)->nullable()->after('source_gsax_per_100_xga');
            $table->dropUnique('uq_nhl_sat_model_entity_profile');
            $table->unique(
                ['model_run_id', 'profile_type', 'strength', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_entity_profile_strength'
            );
        });

        Schema::table('nhl_sat_model_entity_test_profile_buckets', function (Blueprint $table): void {
            $table->string('strength', 8)->default('all')->after('profile_type');
            $table->decimal('source_save_percentage', 9, 4)->nullable()->after('source_gsax_per_100_xga');
            $table->dropUnique('uq_nhl_sat_model_entity_test_profile');
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'strength', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_entity_test_profile_strength'
            );
        });

        Schema::table('nhl_sat_model_entity_rate_comparison_buckets', function (Blueprint $table): void {
            $table->string('strength', 8)->default('all')->after('profile_type');
            $table->decimal('train_save_percentage', 9, 4)->nullable()->after('test_gsax_per_100_xga');
            $table->decimal('test_save_percentage', 9, 4)->nullable()->after('train_save_percentage');
            $table->decimal('save_percentage_drift', 9, 4)->nullable()->after('test_save_percentage');
            $table->dropUnique('uq_nhl_sat_model_rate_compare_bucket');
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'strength', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_rate_compare_bucket_strength'
            );
        });

        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            $table->string('strength', 8)->default('all')->after('profile_type');
            $table->decimal('train_save_percentage', 9, 4)->nullable()->after('test_gsax_per_100_xga');
            $table->decimal('test_save_percentage', 9, 4)->nullable()->after('train_save_percentage');
            $table->decimal('save_percentage_drift', 9, 4)->nullable()->after('test_save_percentage');
            $table->dropUnique('uq_nhl_sat_model_rate_compare_aggregate');
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'strength', 'entity_key'],
                'uq_nhl_sat_model_rate_compare_aggregate_strength'
            );
        });
    }

    public function down(): void
    {
        foreach ([
            'nhl_sat_model_entity_rate_comparison_buckets',
            'nhl_sat_model_entity_rate_comparison_aggregates',
        ] as $tableName) {
            DB::table($tableName)->where('strength', '!=', 'all')->delete();
        }

        foreach ([
            'nhl_sat_model_entity_profile_buckets',
            'nhl_sat_model_entity_test_profile_buckets',
        ] as $tableName) {
            DB::table($tableName)->where('strength', '!=', 'all')->delete();
        }

        Schema::table('nhl_sat_model_entity_profile_buckets', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_sat_model_entity_profile_strength');
            $table->dropColumn(['strength', 'source_save_percentage']);
            $table->unique(
                ['model_run_id', 'profile_type', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_entity_profile'
            );
        });

        Schema::table('nhl_sat_model_entity_test_profile_buckets', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_sat_model_entity_test_profile_strength');
            $table->dropColumn(['strength', 'source_save_percentage']);
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_entity_test_profile'
            );
        });

        Schema::table('nhl_sat_model_entity_rate_comparison_buckets', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_sat_model_rate_compare_bucket_strength');
            $table->dropColumn(['strength', 'train_save_percentage', 'test_save_percentage', 'save_percentage_drift']);
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key', 'matched_bucket_key'],
                'uq_nhl_sat_model_rate_compare_bucket'
            );
        });

        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_sat_model_rate_compare_aggregate_strength');
            $table->dropColumn(['strength', 'train_save_percentage', 'test_save_percentage', 'save_percentage_drift']);
            $table->unique(
                ['model_run_id', 'test_season_id', 'profile_type', 'entity_key'],
                'uq_nhl_sat_model_rate_compare_aggregate'
            );
        });
    }
};
