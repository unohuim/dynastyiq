<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REPAIR_MARKER = 'goalie_flag_repaired_20260918';

    public function up(): void
    {
        DB::table('players')
            ->where('is_goalie', false)
            ->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(position, '')) = 'G'")
                    ->orWhereRaw("UPPER(COALESCE(pos_type, '')) = 'G'");
            })
            ->orderBy('id')
            ->chunkById(100, function ($players): void {
                foreach ($players as $player) {
                    $meta = json_decode((string) ($player->meta ?? ''), true);
                    $meta = is_array($meta) ? $meta : [];
                    $meta[self::REPAIR_MARKER] = true;

                    DB::table('players')->where('id', $player->id)->update([
                        'is_goalie' => true,
                        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('players')
            ->where('is_goalie', true)
            ->orderBy('id')
            ->chunkById(100, function ($players): void {
                foreach ($players as $player) {
                    $meta = json_decode((string) ($player->meta ?? ''), true);

                    if (! is_array($meta) || ($meta[self::REPAIR_MARKER] ?? false) !== true) {
                        continue;
                    }

                    unset($meta[self::REPAIR_MARKER]);

                    DB::table('players')->where('id', $player->id)->update([
                        'is_goalie' => false,
                        'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
