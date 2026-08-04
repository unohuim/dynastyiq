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
            $table->decimal('source_starts', 8, 2)->nullable()->after('source_games');
            $table->decimal('source_relief_games', 8, 2)->nullable()->after('source_starts');
            $table->string('source_role_bucket', 32)->nullable()->after('position');
            $table->string('target_role_bucket', 32)->nullable()->after('source_role_bucket');
            $table->unsignedInteger('projected_toi_seconds')->nullable()->after('projected_games');
            $table->decimal('projected_starts', 8, 2)->nullable()->after('projected_games');
            $table->decimal('projected_relief_games', 8, 2)->nullable()->after('projected_starts');
            $table->decimal('age_adjustment_starts', 8, 2)->nullable()->after('projected_xsoga');
            $table->decimal('role_adjustment_starts', 8, 2)->nullable()->after('age_adjustment_starts');
            $table->decimal('contract_adjustment_starts', 8, 2)->nullable()->after('role_adjustment_starts');
            $table->decimal('durability_adjustment_starts', 8, 2)->nullable()->after('contract_adjustment_starts');
            $table->unsignedInteger('contract_cap_hit')->nullable()->after('durability_adjustment_starts');
            $table->unsignedInteger('contract_aav')->nullable()->after('contract_cap_hit');
            $table->unsignedSmallInteger('contract_years_remaining')->nullable()->after('contract_aav');
            $table->unsignedSmallInteger('team_contract_rank')->nullable()->after('contract_years_remaining');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_goalie_season_projections', function (Blueprint $table): void {
            $table->dropColumn([
                'source_starts',
                'source_relief_games',
                'source_role_bucket',
                'target_role_bucket',
                'projected_toi_seconds',
                'projected_starts',
                'projected_relief_games',
                'age_adjustment_starts',
                'role_adjustment_starts',
                'contract_adjustment_starts',
                'durability_adjustment_starts',
                'contract_cap_hit',
                'contract_aav',
                'contract_years_remaining',
                'team_contract_rank',
            ]);
        });
    }
};
