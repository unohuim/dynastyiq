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
        Schema::table('nhl_boxscores', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ev_goals_against')->default(0)->after('ev_shots_against');
            $table->unsignedSmallInteger('pp_goals_against')->default(0)->after('pp_shots_against');
            $table->unsignedSmallInteger('pk_goals_against')->default(0)->after('pk_shots_against');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_boxscores', function (Blueprint $table): void {
            $table->dropColumn([
                'ev_goals_against',
                'pp_goals_against',
                'pk_goals_against',
            ]);
        });
    }
};
