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
        Schema::create('nhl_units', function (Blueprint $table) {
            $table->id();
            $table->string('team_abbrev', 10)->nullable()->index();
            $table->enum('unit_type', ['F', 'D', 'G', 'PP', 'PK']); // enforce allowed values
            
            $table->timestamps();

            $table->index('unit_type');
        });                                 

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_units');
    }
};
