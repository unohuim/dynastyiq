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
        Schema::create('nhl_unit_shift_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_shift_id')->constrained('nhl_unit_shifts')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('position_code', 8)->nullable();
            $table->timestamps();

            $table->unique(['unit_shift_id', 'player_id']);
            $table->index('position_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_unit_shift_players');
    }
};
