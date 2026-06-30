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
        Schema::table('nhl_boxscores', function (Blueprint $table): void {
            $table->unsignedSmallInteger('shifts')->default(0)->after('toi_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_boxscores', function (Blueprint $table): void {
            $table->dropColumn('shifts');
        });
    }
};
