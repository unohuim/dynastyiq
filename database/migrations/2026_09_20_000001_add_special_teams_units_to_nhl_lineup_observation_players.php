<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_lineup_observation_players', function (Blueprint $table): void {
            $table->unsignedTinyInteger('power_play_unit')->nullable()->after('slot_index');
            $table->unsignedTinyInteger('penalty_kill_unit')->nullable()->after('power_play_unit');
        });
    }

    public function down(): void
    {
        Schema::table('nhl_lineup_observation_players', function (Blueprint $table): void {
            $table->dropColumn(['power_play_unit', 'penalty_kill_unit']);
        });
    }
};
