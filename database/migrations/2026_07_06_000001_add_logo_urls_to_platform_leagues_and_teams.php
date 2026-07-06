<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add provider logo URLs for fantasy league and team display.
     */
    public function up(): void
    {
        Schema::table('platform_leagues', function (Blueprint $table): void {
            $table->string('logo_url')->nullable()->after('sport');
        });

        Schema::table('platform_teams', function (Blueprint $table): void {
            $table->string('logo_url')->nullable()->after('short_name');
        });
    }

    /**
     * Remove provider logo URLs.
     */
    public function down(): void
    {
        Schema::table('platform_leagues', function (Blueprint $table): void {
            $table->dropColumn('logo_url');
        });

        Schema::table('platform_teams', function (Blueprint $table): void {
            $table->dropColumn('logo_url');
        });
    }
};
