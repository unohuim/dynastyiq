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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id');
            $table->string('detail_code')->nullable();
            $table->string('duration')->nullable();
            $table->integer('seconds')->default(0);
            $table->string('start_time');
            $table->string('end_time');
            $table->integer('start_game_seconds');
            $table->integer('end_game_seconds');
            $table->integer('period');
            $table->string('pos_type');
            $table->string('position');
            $table->foreignId('player_id');
            $table->foreignId('team_id');
            $table->string('team_abbrev');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('event_description')->nullable();
            $table->string('event_details')->nullable();
            $table->string('event_number');
            $table->string('hex_value');
            $table->integer('shift_number');
            $table->string('team_name');
            $table->integer('type_code');

            $table->timestamps();
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
