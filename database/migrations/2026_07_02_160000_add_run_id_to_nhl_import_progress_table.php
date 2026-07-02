<?php

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
        Schema::table('nhl_import_progress', function (Blueprint $table): void {
            $table->foreignId('run_id')
                ->nullable()
                ->after('id')
                ->constrained('nhl_game_import_runs')
                ->nullOnDelete();

            $table->index(['run_id', 'status']);
            $table->index(['run_id', 'game_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_import_progress', function (Blueprint $table): void {
            $table->dropIndex(['run_id', 'status']);
            $table->dropIndex(['run_id', 'game_date']);
            $table->dropConstrainedForeignId('run_id');
        });
    }
};
