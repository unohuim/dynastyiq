<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store publishable display snapshots independently of disposable cache. */
    public function up(): void
    {
        Schema::create('nhl_schedule_snapshots', function (Blueprint $table): void {
            $table->string('snapshot_key', 80)->primary();
            $table->string('season_id', 8)->index();
            $table->json('payload');
            $table->timestampTz('built_at');
        });
    }

    /** Remove only prepared display data, preserving source games. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_schedule_snapshots');
    }
};
