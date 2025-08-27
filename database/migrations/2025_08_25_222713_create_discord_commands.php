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
        Schema::create('discord_commands', function (Blueprint $table) {
            // Identity
            $table->string('command_slug', 120)->primary(); // PK must exist before we can FK to it
            $table->string('name', 80);
            $table->string('parent_slug', 120)->nullable(); // will be FK later

            // Behavior
            $table->text('description')->nullable();
            $table->enum('handler_kind', ['route','service','job']);
            $table->string('handler_ref');
            $table->enum('http_method', ['GET','POST'])->nullable();
            $table->text('usage')->nullable();
            $table->string('link_path', 255)->nullable();
            $table->text('brand_hint')->nullable();

            // Params / defaults
            $table->json('param_keys')->nullable();
            $table->json('enum_options')->nullable();
            $table->boolean('has_defaults')->default(false);
            $table->json('defaults')->nullable();
            $table->json('allowed_overrides')->nullable();
            $table->unsignedSmallInteger('max_sorts')->default(1);

            // Access / flags
            $table->string('auth_scope', 32)->default('user');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();

            $table->index(['parent_slug','name']);
        });

        // Add the self-referencing FK *after* table exists
        Schema::table('discord_commands', function (Blueprint $table) {
            $table->foreign('parent_slug')
                  ->references('command_slug')
                  ->on('discord_commands')
                  ->nullOnDelete();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_commands');
    }
};
