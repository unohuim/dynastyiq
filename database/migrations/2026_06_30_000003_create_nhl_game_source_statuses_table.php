<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_game_source_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('source', 32);
            $table->string('status', 32);
            $table->string('reason', 120)->nullable();
            $table->text('url');
            $table->json('details')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['nhl_game_id', 'source']);
            $table->index(['status', 'source']);
            $table->index(['nhl_game_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_game_source_statuses');
    }
};
