<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_engine_stacks', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX nhl_sat_engine_stacks_one_default ON nhl_sat_engine_stacks (is_default) WHERE is_default');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nhl_sat_engine_stacks_one_default');
        Schema::table('nhl_sat_engine_stacks', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
