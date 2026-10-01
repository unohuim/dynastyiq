<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->foreignId('test_model_run_id')->nullable()->after('model_run_id')
                ->constrained('nhl_model_runs')->restrictOnDelete();
        });
        DB::table('nhl_sat_engines')->update(['test_model_run_id' => DB::raw('model_run_id')]);
    }

    public function down(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_model_run_id');
        });
    }
};
