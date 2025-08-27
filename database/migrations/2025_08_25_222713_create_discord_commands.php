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
            $table->string('command_slug', 120)->primary();      // e.g. "diq", "stats", "stats:onice"
            $table->string('name', 80);                          // display/label
            $table->string('parent_slug', 120)->nullable();      // hierarchy
            $table->foreign('parent_slug')
                  ->references('command_slug')
                  ->on('discord_commands')
                  ->nullOnDelete();

            // Behavior
            $table->text('description')->nullable();             // help text
            $table->enum('handler_kind', ['route','service','job']);
            $table->string('handler_ref');                       // route name, service class, job
            $table->enum('http_method', ['GET','POST'])->nullable();
            $table->text('usage')->nullable();                   // multi-line: run + help
            $table->string('link_path', 255)->nullable();        // clean app path, e.g. "/stats/onice"
            $table->text('brand_hint')->nullable();              // e.g. "Powered by DynastyIQ — dynastyiq.com"

            // Params / defaults (Discord-scope)
            $table->json('param_keys')->nullable();              // e.g. ["resource","period","slice","limit","page"]
            $table->json('enum_options')->nullable();            // e.g. {"resource":["player","unit","team"],"period":["season","last30","lastweek","thisweek","range"],"slice":["total","p60","pgp"]}

            // Options / defaults
            $table->boolean('has_defaults')->default(false);     // can missing params be filled?
            $table->json('defaults')->nullable();                // {resource:"player",period:"season",slice:"total"}
            $table->json('allowed_overrides')->nullable();       // ["resource","period","slice","sort"]
            $table->unsignedSmallInteger('max_sorts')->default(1);

            // Access / flags
            $table->string('auth_scope', 32)->default('user');   // user/admin
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();

            $table->index(['parent_slug','name']);
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
