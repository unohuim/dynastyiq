<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-user ordering for the Leagues list.
     */
    public function up(): void
    {
        Schema::table('league_user_teams', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('is_visible');
        });
    }

    /**
     * Remove per-user ordering for the Leagues list.
     */
    public function down(): void
    {
        Schema::table('league_user_teams', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
