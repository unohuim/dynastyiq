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
            $table->string('name');

            $table->foreignId('author_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('organizations')
                  ->nullOnDelete();

            $table->enum('visibility', ['private', 'public_authenticated', 'public_guest'])->default('private');
            $table->enum('sport', ['hockey', 'football', 'basketball'])->default('hockey');
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
