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
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('slug')->unique(); // URL-safe identifier
            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users')->nullOnDelete(); // optional owner (one-per-user via unique index below)
            $table->json('settings')->nullable(); // org-wide toggles
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->unique('owner_user_id'); // one owned org per user; multiple NULLs allowed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
