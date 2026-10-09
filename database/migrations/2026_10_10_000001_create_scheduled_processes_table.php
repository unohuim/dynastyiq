<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Persist process timing independently from provider-import schedules. */
    public function up(): void
    {
        Schema::create('scheduled_processes', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('start_time', 5)->default('03:50');
            $table->string('timezone')->default('America/Toronto');
            $table->unsignedSmallInteger('frequency_hours')->default(24);
            $table->json('settings');
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        DB::table('scheduled_processes')->insert([
            'key' => 'nhl-game-discovery', 'enabled' => true, 'start_time' => '03:50',
            'timezone' => 'America/Toronto', 'frequency_hours' => 24,
            'settings' => json_encode(['days_back' => 3]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Remove only the process scheduling ledger. */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_processes');
    }
};
