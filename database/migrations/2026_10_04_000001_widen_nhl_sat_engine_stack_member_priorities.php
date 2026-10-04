<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Allow persistent stack membership to grow beyond the original tiny-integer cap. */
    public function up(): void
    {
        Schema::table('nhl_sat_engine_stack_members', function (Blueprint $table): void {
            $table->unsignedInteger('priority')->change();
        });
    }

    /** Restore the original column shape if this migration is rolled back before oversized stacks exist. */
    public function down(): void
    {
        Schema::table('nhl_sat_engine_stack_members', function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority')->change();
        });
    }
};
