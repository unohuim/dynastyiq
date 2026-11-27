<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store bounded task checkpoints and execution leases independently of statistical rows. */
    public function up(): void
    {
        Schema::create('nhl_pregame_workstream_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goal_id')->constrained('nhl_pregame_workstream_goals')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('nhl_pregame_context_runs')->cascadeOnDelete();
            $table->foreignId('run_day_id')->constrained('nhl_pregame_context_run_days')->cascadeOnDelete();
            $table->unsignedInteger('nhl_game_id')->nullable();
            $table->string('unit_key', 48);
            $table->string('kind', 24)->default('game');
            $table->string('state', 24)->default('pending');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedInteger('completed_units')->default(0);
            $table->unsignedInteger('total_units')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('server')->nullable();
            $table->unsignedInteger('worker_pid')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'goal_id', 'unit_key'], 'nhl_pregame_task_unit_unique');
            $table->index(['goal_id', 'state', 'id'], 'nhl_pregame_task_claim_index');
            $table->index(['run_id', 'nhl_game_id', 'state'], 'nhl_pregame_task_game_index');
            $table->index(['state', 'lease_expires_at'], 'nhl_pregame_task_recovery_index');
        });
    }

    /** Remove operational tasks while retaining existing statistical storage. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_pregame_workstream_tasks');
    }
};
