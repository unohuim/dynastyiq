<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->decimal('estimated_cost_usd', 12, 6)->default(0)->after('error_message');
        });

        DB::table('import_runs')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(250, function ($runs): void {
                foreach ($runs as $run) {
                    $meta = is_string($run->meta) ? json_decode($run->meta, true) : (array) $run->meta;
                    $micros = (int) ($meta['x_estimated_cost_micros'] ?? 0);
                    if ($micros <= 0) {
                        continue;
                    }

                    DB::table('import_runs')->where('id', $run->id)->update([
                        'estimated_cost_usd' => number_format($micros / 1_000_000, 6, '.', ''),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn('estimated_cost_usd');
        });
    }
};
