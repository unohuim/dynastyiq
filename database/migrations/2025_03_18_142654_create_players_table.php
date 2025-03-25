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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhl_id')->nullable();
            $table->foreignId('nhl_team_id')->nullable();
            $table->foreignId('yahoo_id')->nullable();
            $table->foreignId('fantrax_id')->nullable();
            $table->foreignId('ep_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_prospect')->default(0);
            $table->string('position');
            $table->string('pos_type');
            $table->string('team_abbrev')->nullable();
            $table->string('current_league_abbrev');
            $table->string('dob');
            $table->string('country_code');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
