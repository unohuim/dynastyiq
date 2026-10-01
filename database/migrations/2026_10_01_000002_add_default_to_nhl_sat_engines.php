<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve existing engines and nominate an existing singleton. */
    public function up(): void
    {
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
        });
        if (DB::table('nhl_sat_engines')->count() === 1) {
            DB::table('nhl_sat_engines')->update(['is_default' => true]);
        }
        DB::statement('CREATE UNIQUE INDEX nhl_sat_engines_one_default ON nhl_sat_engines (is_default) WHERE is_default = true');
    }

    /** Remove default selection without deleting saved engines. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nhl_sat_engines_one_default');
        Schema::table('nhl_sat_engines', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
