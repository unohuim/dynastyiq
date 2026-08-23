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
        Schema::table('nhl_sat_model_entity_profile_buckets', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'source_toi_seconds')) {
                $table->unsignedInteger('source_toi_seconds')
                    ->nullable()
                    ->after('source_profile_share');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'source_xsat_per_60')) {
                $table->decimal('source_xsat_per_60', 12, 4)
                    ->nullable()
                    ->after('source_toi_seconds');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'source_xsog_per_60')) {
                $table->decimal('source_xsog_per_60', 12, 4)
                    ->nullable()
                    ->after('source_xsat_per_60');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'source_xg_per_60')) {
                $table->decimal('source_xg_per_60', 12, 4)
                    ->nullable()
                    ->after('source_xsog_per_60');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_profile_buckets', function (Blueprint $table): void {
            foreach ([
                'source_xg_per_60',
                'source_xsog_per_60',
                'source_xsat_per_60',
                'source_toi_seconds',
            ] as $column) {
                if (Schema::hasColumn('nhl_sat_model_entity_profile_buckets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
