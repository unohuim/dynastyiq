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
        Schema::table('nhl_goalie_season_projections', function (Blueprint $table): void {
            $table->decimal('projected_ev_sata', 10, 2)->nullable()->after('projected_xsoga');
            $table->decimal('projected_ev_soga', 10, 2)->nullable()->after('projected_ev_sata');
            $table->decimal('projected_ev_xga', 10, 4)->nullable()->after('projected_ev_soga');
            $table->decimal('projected_ev_ga', 10, 4)->nullable()->after('projected_ev_xga');
            $table->decimal('projected_ev_gsax', 10, 4)->nullable()->after('projected_ev_ga');
            $table->decimal('projected_ev_xsoga', 10, 4)->nullable()->after('projected_ev_gsax');
            $table->decimal('projected_pk_sata', 10, 2)->nullable()->after('projected_ev_xsoga');
            $table->decimal('projected_pk_soga', 10, 2)->nullable()->after('projected_pk_sata');
            $table->decimal('projected_pk_xga', 10, 4)->nullable()->after('projected_pk_soga');
            $table->decimal('projected_pk_ga', 10, 4)->nullable()->after('projected_pk_xga');
            $table->decimal('projected_pk_gsax', 10, 4)->nullable()->after('projected_pk_ga');
            $table->decimal('projected_pk_xsoga', 10, 4)->nullable()->after('projected_pk_gsax');
        });

        Schema::table('nhl_goalie_projection_chance_buckets', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_goalie_projection_bucket_version_player');
            $table->string('projection_strength', 8)->default('ev')->after('position');
            $table->unique(
                ['projection_version', 'target_season_id', 'goalie_player_id', 'projection_strength', 'matched_bucket_key'],
                'uq_nhl_goalie_projection_bucket_version_player_strength'
            );
            $table->index(['target_season_id', 'projection_strength', 'goalie_player_id'], 'ix_nhl_goalie_projection_bucket_strength');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_goalie_projection_chance_buckets', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_goalie_projection_bucket_strength');
            $table->dropUnique('uq_nhl_goalie_projection_bucket_version_player_strength');
            $table->dropColumn('projection_strength');
            $table->unique(
                ['projection_version', 'target_season_id', 'goalie_player_id', 'matched_bucket_key'],
                'uq_nhl_goalie_projection_bucket_version_player'
            );
        });

        Schema::table('nhl_goalie_season_projections', function (Blueprint $table): void {
            $table->dropColumn([
                'projected_ev_sata',
                'projected_ev_soga',
                'projected_ev_xga',
                'projected_ev_ga',
                'projected_ev_gsax',
                'projected_ev_xsoga',
                'projected_pk_sata',
                'projected_pk_soga',
                'projected_pk_xga',
                'projected_pk_ga',
                'projected_pk_gsax',
                'projected_pk_xsoga',
            ]);
        });
    }
};
