<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // e.g. notifications.discord.dm, notifications.discord.channel
            $table->string('key', 128);

            // arbitrary data (bool/string/number/object) per key
            $table->json('value')->nullable();

            $table->timestamps();

            // one value per (user, org, key)
            $table->unique(['user_id', 'key']);

            // helpful secondary index for lookups
            $table->index(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
