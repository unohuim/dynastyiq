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
        Schema::create('event_unit_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('play_by_plays')->cascadeOnDelete();
            $table->foreignId('unit_shift_id')->constrained('nhl_unit_shifts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'unit_shift_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_unit_shifts');
    }
};
