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
        Schema::table('nhl_shot_attempts_facts', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'is_rush_attempt')) {
                $table->boolean('is_rush_attempt')->nullable()->after('is_rush');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'is_rush_sequence')) {
                $table->boolean('is_rush_sequence')->nullable()->after('is_rush_attempt');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'rush_sequence_origin_play_by_play_id')) {
                $table->foreignId('rush_sequence_origin_play_by_play_id')
                    ->nullable()
                    ->after('is_rush_sequence')
                    ->constrained('play_by_plays')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_shot_attempts_facts', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_shot_attempts_facts', 'rush_sequence_origin_play_by_play_id')) {
                $table->dropConstrainedForeignId('rush_sequence_origin_play_by_play_id');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'is_rush_sequence')) {
                $table->dropColumn('is_rush_sequence');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'is_rush_attempt')) {
                $table->dropColumn('is_rush_attempt');
            }
        });
    }
};
