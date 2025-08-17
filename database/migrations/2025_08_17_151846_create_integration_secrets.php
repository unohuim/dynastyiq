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
        Schema::create('integration_secrets', function (Blueprint $table) {
            $table->id();

            // link to the user
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // which integration this belongs to
            $table->enum('provider', [
                'fantrax',
                'yahoo',
                'espn',
                'rotowire',
                'discord'
            ]);

            // the secret key itself (encrypted cast in the model)
            $table->text('secret')->nullable();

            // connection status
            $table->enum('status', [
                'connected',
                'needs_setup',
                'error',
            ])->default('needs_setup');


            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_secrets');
    }
};
