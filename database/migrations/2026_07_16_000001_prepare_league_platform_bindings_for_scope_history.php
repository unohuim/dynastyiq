<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepare league/platform bindings for scoped provider links and history.
     */
    public function up(): void
    {
        DB::table('league_platform_league')
            ->whereNull('status')
            ->update(['status' => 'active']);

        Schema::table('league_platform_league', function (Blueprint $table): void {
            $table->dropUnique('uq_league_platform_link');
            $table->dropUnique('uq_external_single_internal');

            $table->timestamp('archived_at')->nullable()->after('linked_at');

            $table->index(['league_id', 'platform_league_id', 'status'], 'ix_lpl_league_platform_status');
            $table->index(['platform_league_id', 'status'], 'ix_lpl_platform_status');
        });
    }

    /**
     * Restore the original one-provider-league-to-one-internal-league constraints.
     */
    public function down(): void
    {
        Schema::table('league_platform_league', function (Blueprint $table): void {
            $table->dropIndex('ix_lpl_league_platform_status');
            $table->dropIndex('ix_lpl_platform_status');

            $table->dropColumn('archived_at');

            $table->unique(['league_id', 'platform_league_id'], 'uq_league_platform_link');
            $table->unique('platform_league_id', 'uq_external_single_internal');
        });
    }
};
