<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_leagues', function (Blueprint $table): void {
            $table->json('settings')->nullable()->after('sport');
            $table->json('scoring_settings')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('platform_leagues', function (Blueprint $table): void {
            $table->dropColumn(['settings', 'scoring_settings']);
        });
    }
};
