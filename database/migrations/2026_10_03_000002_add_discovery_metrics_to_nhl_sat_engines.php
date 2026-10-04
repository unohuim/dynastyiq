<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->foreignId('discovery_candidate_id')->nullable()->after('discovery_run_id')
                ->constrained('nhl_sat_engine_candidates')->nullOnDelete();
            $table->decimal('discovery_win_pct', 8, 4)->nullable()->after('discovery_candidate_id');
            $table->decimal('discovery_coverage_pct', 8, 4)->nullable()->after('discovery_win_pct');
        });
    }

    public function down(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discovery_candidate_id');
            $table->dropColumn(['discovery_win_pct', 'discovery_coverage_pct']);
        });
    }
};
