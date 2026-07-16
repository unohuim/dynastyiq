<?php

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
        Schema::create('nhle_league_factors', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('source_version', 16);
            $table->string('model_name', 120);
            $table->string('model_window', 120);
            $table->string('source_league_name', 120);
            $table->json('mapped_league_codes')->nullable();
            $table->decimal('points_factor', 5, 2);
            $table->decimal('win_shares_factor', 5, 2);
            $table->string('source_url', 500);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['source', 'source_version', 'source_league_name'],
                'uq_nhle_factors_source_version_league'
            );
            $table->index(['source', 'source_version'], 'idx_nhle_factors_source_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhle_league_factors');
    }
};
