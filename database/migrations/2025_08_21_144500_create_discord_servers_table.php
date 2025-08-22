<?php
// database/migrations/2025_08_21_000000_create_discord_servers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_servers', function (Blueprint $table) {
            $table->id();

            // one org -> many discord servers
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // guild identity (unique => one org per server)
            $table->string('discord_guild_id', 32)->unique();
            $table->string('discord_guild_name')->nullable();

            // who installed (discord user id), optional
            $table->string('installed_by_discord_user_id', 32)->nullable()->index();

            // oauth tokens (optional if using Guild Install OAuth)
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // permissions integer/string from callback
            $table->string('granted_permissions')->nullable();

            // misc
            $table->json('meta')->nullable();

            $table->timestamps();

            // (redundant with unique, but keeps intent explicit)
            $table->unique(['organization_id', 'discord_guild_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_servers');
    }
};
