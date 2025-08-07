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
        Schema::create('nhl_unit_shifts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('team_abbrev')->nullable()->index();

            $table->foreignId('unit_id')
                  ->constrained('nhl_units')
                  ->cascadeOnDelete();

            $table->unsignedBigInteger('nhl_game_id');
            $table->foreign('nhl_game_id')
                  ->references('nhl_game_id')
                  ->on('nhl_games')
                  ->cascadeOnDelete();

            $table->integer('period');

            $table->string('start_time');
            $table->string('end_time')->nullable();
            $table->integer('start_game_seconds');
            $table->integer('end_game_seconds');
            $table->integer('seconds')->default(0);

            $table->enum('starting_zone', ['O', 'N', 'D'])->nullable();
            $table->enum('ending_zone', ['O', 'N', 'D'])->nullable();

            $table->boolean('is_faceoff')->nullable();

            $table->timestamps();

            $table->index('unit_id');
            $table->index('nhl_game_id');

            $table->unique(['unit_id', 'nhl_game_id', 'start_game_seconds']);
        });



    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_unit_shifts');
    }
};
