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
        Schema::table('fantrax_draft_picks', function (Blueprint $table): void {
            $table->timestamp('announced_at')->nullable()->after('detected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fantrax_draft_picks', function (Blueprint $table): void {
            $table->dropColumn('announced_at');
        });
    }
};
