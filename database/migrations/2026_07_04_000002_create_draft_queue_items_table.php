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
        Schema::create('draft_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->text('notes')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'user_id', 'player_id'], 'uq_draft_queue_items_player');
            $table->unique(['draft_id', 'user_id', 'rank'], 'uq_draft_queue_items_rank');
            $table->index(['draft_id', 'user_id', 'rank'], 'idx_draft_queue_items_user_rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draft_queue_items');
    }
};
