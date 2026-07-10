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
        Schema::create('platform_league_scoring_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('provider_identity_key');
            $table->string('provider_category_id')->nullable();
            $table->string('provider_group')->nullable();
            $table->string('provider_code')->nullable();
            $table->string('provider_short_label')->nullable();
            $table->string('provider_label')->nullable();
            $table->string('normalized_group')->nullable();
            $table->string('normalized_short_label')->nullable();
            $table->string('normalized_label')->nullable();
            $table->decimal('value', 10, 4)->nullable();
            $table->json('position_values')->nullable();
            $table->foreignId('dictionary_mapping_id')->nullable()->constrained('fantasy_scoring_category_mappings')->nullOnDelete();
            $table->string('auto_mapping_key')->nullable();
            $table->string('manual_mapping_key')->nullable();
            $table->string('selected_mapping_key')->nullable();
            $table->string('stat_key')->nullable();
            $table->string('auto_stat_key')->nullable();
            $table->string('mapping_source', 32)->nullable();
            $table->string('alignment_status', 32)->nullable();
            $table->text('formula')->nullable();
            $table->json('required_schema_columns')->nullable();
            $table->boolean('is_supported')->default(false);
            $table->text('support_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['platform_league_id', 'provider_identity_key'],
                'uq_platform_league_scoring_category_identity'
            );
            $table->index(['platform', 'normalized_group'], 'ix_platform_league_scoring_category_group');
            $table->index(['platform_league_id', 'sort_order'], 'ix_platform_league_scoring_category_order');
            $table->index(['dictionary_mapping_id'], 'ix_platform_league_scoring_category_dictionary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_league_scoring_categories');
    }
};
