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
        Schema::table('nhl_import_progress', function (Blueprint $table): void {
            $table->string('sentry_event_id', 64)->nullable()->after('last_error');
            $table->string('failure_category', 32)->nullable()->after('sentry_event_id');
            $table->boolean('retryable')->nullable()->after('failure_category');

            $table->index(['failure_category']);
            $table->index(['retryable']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_import_progress', function (Blueprint $table): void {
            $table->dropIndex(['failure_category']);
            $table->dropIndex(['retryable']);
            $table->dropColumn(['sentry_event_id', 'failure_category', 'retryable']);
        });
    }
};
