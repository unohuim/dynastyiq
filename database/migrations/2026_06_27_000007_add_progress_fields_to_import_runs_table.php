<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('total_records')->nullable()->after('duration_seconds');
            $table->unsignedInteger('processed_records')->default(0)->after('total_records');
            $table->unsignedInteger('successful_records')->default(0)->after('processed_records');
            $table->unsignedInteger('failed_records')->default(0)->after('successful_records');
            $table->unsignedInteger('skipped_records')->default(0)->after('failed_records');
            $table->string('progress_label')->nullable()->after('skipped_records');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'total_records',
                'processed_records',
                'successful_records',
                'failed_records',
                'skipped_records',
                'progress_label',
            ]);
        });
    }
};
