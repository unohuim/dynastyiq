<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store private diagnostic snapshots, independently of live engine definitions. */
    public function up(): void
    {
        Schema::create('admin_engine_game_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedTinyInteger('autosave_slot')->nullable();
            $table->uuid('session_token')->nullable();
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['user_id', 'name'], 'admin_engine_predictions_user_name_unique');
            $table->unique(['user_id', 'autosave_slot'], 'admin_engine_predictions_user_slot_unique');
        });
    }

    /** Remove only the private diagnostic snapshot store. */
    public function down(): void
    {
        Schema::dropIfExists('admin_engine_game_predictions');
    }
};
