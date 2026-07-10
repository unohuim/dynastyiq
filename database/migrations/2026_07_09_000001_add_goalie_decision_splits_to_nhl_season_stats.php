<?php

declare(strict_types=1);

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
        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            $table->unsignedSmallInteger('overtime_wins')->default(0)->after('ot_losses');
            $table->unsignedSmallInteger('shootout_wins')->default(0)->after('overtime_wins');
            $table->unsignedSmallInteger('shootout_losses')->default(0)->after('shootout_wins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            $table->dropColumn([
                'overtime_wins',
                'shootout_wins',
                'shootout_losses',
            ]);
        });
    }
};
