<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_scopes', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('team_abbrev');
            $table->index(['sport', 'league', 'team_abbrev', 'is_active'], 'source_scope_discovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('source_scopes', function (Blueprint $table): void {
            $table->dropIndex('source_scope_discovery_index');
            $table->dropColumn('is_active');
        });
    }
};
