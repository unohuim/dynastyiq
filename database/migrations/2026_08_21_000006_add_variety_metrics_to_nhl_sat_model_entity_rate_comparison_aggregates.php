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
            $table->unsignedInteger('train_active_bucket_count')->default(0)->after('test_games');
            $table->unsignedInteger('last_active_bucket_count')->default(0)->after('train_active_bucket_count');
            $table->unsignedInteger('test_active_bucket_count')->default(0)->after('last_active_bucket_count');
            $table->decimal('train_top_3_bucket_share', 9, 6)->nullable()->after('test_active_bucket_count');
            $table->decimal('last_top_3_bucket_share', 9, 6)->nullable()->after('train_top_3_bucket_share');
            $table->decimal('test_top_3_bucket_share', 9, 6)->nullable()->after('last_top_3_bucket_share');
            $table->decimal('train_other_share', 9, 6)->nullable()->after('test_top_3_bucket_share');
            $table->decimal('last_other_share', 9, 6)->nullable()->after('train_other_share');
            $table->decimal('test_other_share', 9, 6)->nullable()->after('last_other_share');
            $table->decimal('train_bucket_entropy', 12, 6)->nullable()->after('test_other_share');
            $table->decimal('last_bucket_entropy', 12, 6)->nullable()->after('train_bucket_entropy');
            $table->decimal('test_bucket_entropy', 12, 6)->nullable()->after('last_bucket_entropy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            $table->dropColumn([
                'train_active_bucket_count',
                'last_active_bucket_count',
                'test_active_bucket_count',
                'train_top_3_bucket_share',
                'last_top_3_bucket_share',
                'test_top_3_bucket_share',
                'train_other_share',
                'last_other_share',
                'test_other_share',
                'train_bucket_entropy',
                'last_bucket_entropy',
                'test_bucket_entropy',
            ]);
        });
    }
};
