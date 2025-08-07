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
        Schema::create('nhl_shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('nhl_game_id');
            $table->foreign('nhl_game_id')
                  ->references('nhl_game_id')
                  ->on('nhl_games')
                  ->cascadeOnDelete();

            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();

            $table->integer('shift_number');
            $table->integer('period');
            $table->string('start_time');
            $table->string('end_time');
            $table->string('duration')->nullable(); // raw duration string
            $table->integer('shift_start_seconds');
            $table->integer('shift_end_seconds');
            $table->integer('shift_duration_seconds')->nullable(); // calculated duration in seconds

            $table->string('pos_type')->nullable();
            $table->string('position')->nullable();

            $table->string('team_abbrev');
            $table->string('team_name');

            $table->string('first_name');
            $table->string('last_name');

            $table->string('detail_code')->nullable();
            $table->string('event_description')->nullable();
            $table->string('event_details')->nullable();
            $table->string('event_number')->nullable();
            $table->integer('type_code')->nullable();

            $table->string('hex_value')->nullable();

            $table->foreignId('unit_id')->nullable()->constrained('nhl_units')->nullOnDelete();

            $table->timestamps();

            $table->index('nhl_game_id');
            $table->index('unit_id');
        });





        // Schema::create('shifts', function (Blueprint $table) {
        //     $table->id();
        //     $table->foreignId('game_id');
        //     $table->string('detail_code')->nullable();
        //     $table->string('duration')->nullable();
        //     $table->integer('seconds')->default(0);
        //     $table->string('start_time');
        //     $table->string('end_time');
        //     $table->integer('start_game_seconds');
        //     $table->integer('end_game_seconds');
        //     $table->integer('period');
        //     $table->string('pos_type');
        //     $table->string('position');
        //     $table->foreignId('player_id');
        //     $table->foreignId('team_id');
        //     $table->string('team_abbrev');
        //     $table->string('first_name');
        //     $table->string('last_name');
        //     $table->string('event_description')->nullable();
        //     $table->string('event_details')->nullable();
        //     $table->string('event_number')->nullable();
        //     $table->string('hex_value')->nullable();
        //     $table->integer('shift_number');
        //     $table->string('team_name');
        //     $table->integer('type_code')->nullable();

        //     $table->timestamps();
        // });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
