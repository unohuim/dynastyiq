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
        // fantrax_leagues (external string ID)
        Schema::create('fantrax_leagues', function (Blueprint $table) {
            $table->id();
            $table->string('fantrax_league_id')->unique(); // external
            $table->string('league_name');
            $table->string('draft_type')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantrax_leagues');
    }
};
