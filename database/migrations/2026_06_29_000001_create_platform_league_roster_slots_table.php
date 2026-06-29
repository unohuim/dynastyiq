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
        Schema::create('platform_league_roster_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')
                ->constrained('platform_leagues')
                ->cascadeOnDelete();
            $table->string('slot');
            $table->string('slot_type')->nullable();
            $table->string('position_type')->nullable();
            $table->unsignedSmallInteger('count')->default(0);
            $table->unsignedSmallInteger('sort_order');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_league_id', 'slot'], 'uq_platform_league_roster_slot');
            $table->index(['platform_league_id', 'sort_order'], 'idx_platform_league_roster_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_league_roster_slots');
    }
};
