<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve the discovery that supplied an engine's adopted settings. */
    public function up(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->foreignId('discovery_run_id')->nullable()->after('test_model_run_id')
                ->constrained('nhl_sat_engine_runs')->nullOnDelete();
        });
    }

    /** Remove discovery provenance without affecting engines or evaluation evidence. */
    public function down(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discovery_run_id');
        });
    }
};
