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
        Schema::create('nhl_goalie_workload_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('projection_version', 80);
            $table->string('source_season_id', 8);
            $table->string('target_season_id', 8);
            $table->integer('goalie_player_id');
            $table->integer('source_team_id')->nullable();
            $table->string('source_team_abbrev', 12)->nullable();
            $table->integer('target_team_id')->nullable();
            $table->string('target_team_abbrev', 12)->nullable();
            $table->string('position', 12)->nullable();
            $table->string('source_role_bucket', 32)->nullable();
            $table->string('target_role_bucket', 32)->nullable();

            $table->decimal('source_games', 8, 2)->nullable();
            $table->decimal('source_starts', 8, 2)->nullable();
            $table->decimal('source_relief_games', 8, 2)->nullable();
            $table->unsignedInteger('source_toi_seconds')->nullable();
            $table->unsignedInteger('source_sat_against')->default(0);
            $table->unsignedInteger('source_sog_against')->default(0);
            $table->unsignedInteger('source_goals_against')->default(0);
            $table->decimal('source_xga', 10, 4)->nullable();
            $table->decimal('source_xsoga', 10, 4)->nullable();
            $table->decimal('source_gsax', 10, 4)->nullable();

            $table->decimal('projected_games', 8, 2)->nullable();
            $table->decimal('projected_starts', 8, 2)->nullable();
            $table->decimal('projected_relief_games', 8, 2)->nullable();
            $table->unsignedInteger('projected_toi_seconds')->nullable();
            $table->decimal('projected_toi_hours', 10, 4)->nullable();
            $table->decimal('projected_sata', 10, 2)->nullable();
            $table->decimal('projected_soga', 10, 2)->nullable();
            $table->decimal('projected_xga', 10, 4)->nullable();
            $table->decimal('projected_ga', 10, 4)->nullable();
            $table->decimal('projected_gsax', 10, 4)->nullable();
            $table->decimal('projected_xsoga', 10, 4)->nullable();

            $table->decimal('age_adjustment_starts', 8, 2)->nullable();
            $table->decimal('role_adjustment_starts', 8, 2)->nullable();
            $table->decimal('contract_adjustment_starts', 8, 2)->nullable();
            $table->decimal('durability_adjustment_starts', 8, 2)->nullable();
            $table->unsignedInteger('contract_cap_hit')->nullable();
            $table->unsignedInteger('contract_aav')->nullable();
            $table->unsignedSmallInteger('contract_years_remaining')->nullable();
            $table->unsignedSmallInteger('team_contract_rank')->nullable();

            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('projection_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['projection_version', 'target_season_id', 'goalie_player_id'],
                'uq_nhl_goalie_workload_projection_version_player'
            );
            $table->index(['target_season_id', 'target_team_abbrev'], 'ix_nhl_goalie_workload_projection_team');
            $table->index(['source_season_id', 'target_season_id'], 'ix_nhl_goalie_workload_projection_source_target');
        });

        if (Schema::hasTable('nhl_goalie_season_projections')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('nhl_goalie_season_projections', 'source_starts') ? 'source_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'source_relief_games') ? 'source_relief_games' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'source_role_bucket') ? 'source_role_bucket' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'target_role_bucket') ? 'target_role_bucket' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'projected_toi_seconds') ? 'projected_toi_seconds' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'projected_starts') ? 'projected_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'projected_relief_games') ? 'projected_relief_games' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'age_adjustment_starts') ? 'age_adjustment_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'role_adjustment_starts') ? 'role_adjustment_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'contract_adjustment_starts') ? 'contract_adjustment_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'durability_adjustment_starts') ? 'durability_adjustment_starts' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'contract_cap_hit') ? 'contract_cap_hit' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'contract_aav') ? 'contract_aav' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'contract_years_remaining') ? 'contract_years_remaining' : null,
                Schema::hasColumn('nhl_goalie_season_projections', 'team_contract_rank') ? 'team_contract_rank' : null,
            ]));

            if ($columns !== []) {
                Schema::table('nhl_goalie_season_projections', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_goalie_workload_projections');
    }
};
