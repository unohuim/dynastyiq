<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve the resumable phase and invalidate work queued before a pause. */
    public function up(): void
    {
        Schema::table('nhl_sat_engine_runs', function (Blueprint $table): void {
            $table->string('paused_status', 20)->nullable()->after('status');
            $table->unsignedInteger('work_generation')->default(1)->after('paused_status');
        });
    }

    /** Remove only the resumable-work metadata. */
    public function down(): void
    {
        Schema::table('nhl_sat_engine_runs', function (Blueprint $table): void {
            $table->dropColumn(['paused_status', 'work_generation']);
        });
    }
};
