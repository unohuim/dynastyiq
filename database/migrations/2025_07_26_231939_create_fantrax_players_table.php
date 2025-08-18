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
              Schema::create('fantrax_players', function (Blueprint $table): void {
                  $table->id();

                  $table->foreignId('player_id')
                      ->nullable()
                      ->constrained()
                      ->nullOnDelete();

                  $table->string('fantrax_id')->unique();
                  $table->unsignedInteger('statsinc_id')->nullable();
                  $table->unsignedInteger('rotowire_id')->nullable();
                  $table->string('sport_radar_id', 255)->nullable();
                  $table->string('team')->nullable();
                  $table->string('name')->nullable();
                  $table->string('position')->nullable();
                  $table->json('raw_meta')->nullable();
                  $table->timestamps();
              });
      }

    public function down(): void
    {
        Schema::dropIfExists('fantrax_players');
    }
};
