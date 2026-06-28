<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('source')->index();
            $table->string('command')->nullable()->after('status');
            $table->json('options')->nullable()->after('command');
            $table->timestamp('started_at')->nullable()->after('batch_id');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->unsignedInteger('duration_seconds')->nullable()->after('finished_at');
            $table->text('error_message')->nullable()->after('duration_seconds');
            $table->json('meta')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'command',
                'options',
                'started_at',
                'finished_at',
                'duration_seconds',
                'error_message',
                'meta',
            ]);
        });
    }
};
