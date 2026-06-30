<?php

declare(strict_types=1);

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
        Schema::create('nhl_game_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 24);
            $table->string('mode', 24);
            $table->string('status', 24)->default('queued');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('date_count')->default(0);
            $table->unsignedInteger('queued_jobs')->default(0);
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['action', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_game_import_runs');
    }
};
