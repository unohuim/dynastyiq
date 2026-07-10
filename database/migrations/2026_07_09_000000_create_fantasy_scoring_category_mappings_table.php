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
        Schema::create('fantasy_scoring_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32);
            $table->string('provider_label');
            $table->text('definition')->nullable();
            $table->string('alignment_status', 32);
            $table->text('formula')->nullable();
            $table->json('required_schema_columns')->nullable();
            $table->text('unavailable_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'provider_label'], 'uq_fantasy_category_mapping_provider_label');
            $table->index(['platform', 'alignment_status'], 'ix_fantasy_category_mapping_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantasy_scoring_category_mappings');
    }
};
