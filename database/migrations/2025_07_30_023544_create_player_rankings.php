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
        Schema::create('player_rankings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ranking_profile_id')
                  ->constrained('ranking_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('player_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('score')->index();

            $table->text('description')->nullable();

            $table->enum('visibility', [
                'private',
                'public_authenticated',
                'public_guest',
            ])->default('private');

            $table->enum('sport', [
                'hockey',
                'football',
                'basketball',
            ])->default('hockey');

            $table->json('settings')->nullable();

            $table->timestamps();

            $table->unique(['ranking_profile_id', 'player_id']);
        });



    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_rankings');
    }
};
