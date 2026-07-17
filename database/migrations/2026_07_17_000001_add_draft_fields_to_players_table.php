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
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedSmallInteger('draft_year')->nullable()->after('current_league_abbrev');
            $table->unsignedSmallInteger('draft_round')->nullable()->after('draft_year');
            $table->unsignedSmallInteger('draft_round_pick')->nullable()->after('draft_round');
            $table->unsignedSmallInteger('draft_oa')->nullable()->after('draft_round_pick');

            $table->index('draft_year', 'idx_players_draft_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropIndex('idx_players_draft_year');
            $table->dropColumn([
                'draft_year',
                'draft_round',
                'draft_round_pick',
                'draft_oa',
            ]);
        });
    }
};
