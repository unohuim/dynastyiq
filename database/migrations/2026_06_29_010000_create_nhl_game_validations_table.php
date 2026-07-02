<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_game_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhl_game_id')->constrained('nhl_games', 'nhl_game_id')->cascadeOnDelete();
            $table->string('validation_type')->default('summary_boxscore');
            $table->enum('status', [
                'approved',
                'failed',
                'accepted_exception',
            ]);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['nhl_game_id', 'validation_type']);
            $table->index(['status', 'checked_at']);
        });

        Schema::create('nhl_game_validation_deltas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_id')->constrained('nhl_game_validations')->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->nullable()->index();
            $table->string('field');
            $table->string('boxscore_value')->nullable();
            $table->string('summary_value')->nullable();
            $table->decimal('delta', 12, 3)->nullable();
            $table->enum('severity', [
                'error',
                'warning',
            ])->default('error');
            $table->timestamps();

            $table->index(['validation_id', 'nhl_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_game_validation_deltas');
        Schema::dropIfExists('nhl_game_validations');
    }
};
