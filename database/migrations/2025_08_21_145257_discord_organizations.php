<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link Discord servers to organizations.
     * Constraint: many servers per org; a server belongs to exactly one org.
     */
    public function up(): void
    {
        Schema::create('discord_organizations', function (Blueprint $table) {
            $table->id();

            // FK to organizations (one org can have many servers)
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // FK to discord_servers (a server can belong to only one org)
            $table->foreignId('discord_server_id')
                  ->constrained('discord_servers')
                  ->cascadeOnDelete();

            // Optional linkage metadata
            $table->timestamp('linked_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            // Enforce one-org-per-server
            $table->unique('discord_server_id');

            // Helpful composite index for org lookups
            $table->index(['organization_id', 'discord_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_organizations');
    }
};
