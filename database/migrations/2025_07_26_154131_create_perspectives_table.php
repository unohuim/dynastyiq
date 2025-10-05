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
        Schema::create('perspectives', function (Blueprint $table) {
            $table->id();

            // Human-readable title (app-level unique; no DB index as requested)
            $table->string('name');

            // URL-safe identifier; must be unique
            $table->string('slug')->unique();

            $table->foreignId('author_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('organization_id')
                  ->nullable()
                  ->constrained('organizations')
                  ->nullOnDelete();

            $table->enum('visibility', ['private', 'public_authenticated', 'public_guest'])->default('private');
            $table->enum('sport', ['hockey', 'football', 'basketball'])->default('hockey');

            // When true: show global slice control and derive rates; when false: hide slice and allow explicit rate columns
            $table->boolean('is_slicable')->default(true);

            $table->json('settings')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perspectives');
    }
};
