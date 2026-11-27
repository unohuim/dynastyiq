<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create empty, pilot-enrolled maintenance goals without copying statistics. */
    public function up(): void
    {
        Schema::create('nhl_pregame_workstream_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')->constrained('nhl_model_runs')->cascadeOnDelete();
            $table->string('area', 24);
            $table->unsignedSmallInteger('worker_limit')->default(1);
            $table->foreignId('run_id')->nullable()->constrained('nhl_pregame_context_runs')->nullOnDelete();
            $table->string('context_version', 40);
            $table->string('scope_hash', 64)->nullable();
            $table->string('status', 24)->default('idle');
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamp('dirty_from')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['model_run_id', 'area'], 'nhl_pregame_goal_area_unique');
        });
    }

    /** Remove goal storage after its dependent task table has been rolled back. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_pregame_workstream_goals');
    }
};
