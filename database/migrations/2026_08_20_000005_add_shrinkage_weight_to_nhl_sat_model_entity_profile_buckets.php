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
            if (! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'shrinkage_weight')) {
                $table->decimal('shrinkage_weight', 9, 4)
                    ->default(0)
                    ->after('confidence_score');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_profile_buckets', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'shrinkage_weight')) {
                $table->dropColumn('shrinkage_weight');
            }
        });
    }
};
