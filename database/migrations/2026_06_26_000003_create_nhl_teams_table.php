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
        Schema::create('nhl_teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nhl_id')->unique();
            $table->string('abbrev', 10)->unique();
            $table->string('full_name')->nullable()->index();
            $table->string('common_name')->nullable()->index();
            $table->string('place_name')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_teams');
    }
};
