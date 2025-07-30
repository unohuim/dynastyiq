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
        Schema::create('contract_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')
                  ->constrained('contracts')
                  ->cascadeOnDelete();

            // Raw API season code and human‑friendly label
            $table->unsignedInteger('season_key');       // e.g. 20252026
            $table->string('label', 7)->nullable();      // e.g. "2025-26"

            // Per‑season details
            $table->string('clause')->nullable();
            $table->unsignedBigInteger('cap_hit')->nullable();
            $table->unsignedBigInteger('aav')->nullable();
            $table->unsignedBigInteger('performance_bonuses')->nullable();
            $table->unsignedBigInteger('signing_bonuses')->nullable();
            $table->unsignedBigInteger('base_salary')->nullable();
            $table->unsignedBigInteger('total_salary')->nullable();
            $table->unsignedBigInteger('minors_salary')->nullable();

            $table->timestamps();

            // Prevent duplicate seasons on the same contract
            $table->unique(['contract_id', 'season_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_seasons');
    }
};
