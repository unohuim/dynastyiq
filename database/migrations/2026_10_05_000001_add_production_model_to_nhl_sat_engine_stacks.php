<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_engine_stacks', function (Blueprint $table): void {
            $table->foreignId('production_model_run_id')->nullable()->after('notes')
                ->constrained('nhl_model_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nhl_sat_engine_stacks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('production_model_run_id');
        });
    }
};
