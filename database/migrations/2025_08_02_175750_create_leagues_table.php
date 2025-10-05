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

        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // no global UNIQUE (see note below)
            $table->string('sport')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('name');            // fast lookups; avoid global uniqueness
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
