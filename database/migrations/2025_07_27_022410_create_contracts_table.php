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
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Top‑level contract info
            $table->string('contract_type');
            $table->string('contract_length')->nullable();
            $table->unsignedBigInteger('contract_value')->nullable();
            $table->string('expiry_status')->nullable();
            $table->string('signing_team')->nullable();
            $table->date('signing_date')->nullable();
            $table->string('signed_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
