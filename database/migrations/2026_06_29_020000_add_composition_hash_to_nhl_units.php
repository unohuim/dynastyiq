<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_units', function (Blueprint $table): void {
            $table->string('composition_hash', 64)->nullable()->after('unit_type');
            $table->json('composition_player_ids')->nullable()->after('composition_hash');
            $table->unique(['team_abbrev', 'unit_type', 'composition_hash'], 'nhl_units_composition_unique');
        });
    }

    public function down(): void
    {
        Schema::table('nhl_units', function (Blueprint $table): void {
            $table->dropUnique('nhl_units_composition_unique');
            $table->dropColumn(['composition_hash', 'composition_player_ids']);
        });
    }
};
