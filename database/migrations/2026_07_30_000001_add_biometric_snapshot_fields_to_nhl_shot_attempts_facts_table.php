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
            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_height_inches')) {
                $table->unsignedSmallInteger('shooter_height_inches')->nullable()->after('shooter_shoots');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_weight_lbs')) {
                $table->unsignedSmallInteger('shooter_weight_lbs')->nullable()->after('shooter_height_inches');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_age_years')) {
                $table->decimal('shooter_age_years', 5, 2)->nullable()->after('shooter_weight_lbs');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_height_inches')) {
                $table->unsignedSmallInteger('goalie_height_inches')->nullable()->after('goalie_catches');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_weight_lbs')) {
                $table->unsignedSmallInteger('goalie_weight_lbs')->nullable()->after('goalie_height_inches');
            }

            if (! Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_age_years')) {
                $table->decimal('goalie_age_years', 5, 2)->nullable()->after('goalie_weight_lbs');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_shot_attempts_facts', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_age_years')) {
                $table->dropColumn('goalie_age_years');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_weight_lbs')) {
                $table->dropColumn('goalie_weight_lbs');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_height_inches')) {
                $table->dropColumn('goalie_height_inches');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_age_years')) {
                $table->dropColumn('shooter_age_years');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_weight_lbs')) {
                $table->dropColumn('shooter_weight_lbs');
            }

            if (Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_height_inches')) {
                $table->dropColumn('shooter_height_inches');
            }
        });
    }
};
