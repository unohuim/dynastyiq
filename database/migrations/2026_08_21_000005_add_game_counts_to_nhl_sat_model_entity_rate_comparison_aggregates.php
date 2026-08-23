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
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            $table->unsignedInteger('train_games')->default(0)->after('matched_bucket_rows');
            $table->unsignedInteger('test_games')->default(0)->after('train_games');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            $table->dropColumn(['train_games', 'test_games']);
        });
    }
};
