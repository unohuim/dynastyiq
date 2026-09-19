<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_import_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key');
            $table->string('lane_key');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('interval_seconds');
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamps();
            $table->unique(['source_key', 'lane_key']);
            $table->index(['enabled', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_import_schedules');
    }
};
