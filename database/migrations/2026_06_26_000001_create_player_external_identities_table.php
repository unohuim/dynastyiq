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
        Schema::create('player_external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_player_id', 120);
            $table->string('provider_slug')->nullable();
            $table->string('display_name')->nullable();
            $table->string('normalized_name')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('position', 20)->nullable();
            $table->string('team', 40)->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('match_status', 40)->default('unmatched');
            $table->unsignedTinyInteger('match_confidence')->nullable();
            $table->string('unmatched_reason', 80)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_player_id']);
            $table->index('player_id');
            $table->index(['provider', 'match_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_external_identities');
    }
};
