<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_import_schedules', function (Blueprint $table): void {
            $table->boolean('lane_enabled')->default(true)->after('enabled');
            $table->string('recurrence_mode', 20)->default('recurring')->after('interval_seconds');
            $table->time('daily_start_time')->nullable()->after('recurrence_mode');
            $table->string('timezone', 64)->nullable()->after('daily_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('admin_import_schedules', function (Blueprint $table): void {
            $table->dropColumn(['lane_enabled', 'recurrence_mode', 'daily_start_time', 'timezone']);
        });
    }
};
