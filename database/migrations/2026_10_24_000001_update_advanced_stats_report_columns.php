<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BACKUP = '_diq_advanced_columns_20261024';

    /** Update existing public report columns without reseeding or replacing unrelated settings. */
    public function up(): void
    {
        foreach (DB::table('perspectives')->where('visibility', 'public_guest')->whereIn('slug', ['skaters-adv', 'goalies-adv'])->get() as $report) {
            $settings = $this->settings($report->settings);
            if ($settings === null || ! is_array($settings['columns'] ?? null)) {
                continue;
            }
            $settings[self::BACKUP] ??= $settings['columns'];
            $columns = $settings['columns'];
            $additions = $report->slug === 'goalies-adv'
                ? [['key' => 'gsax', 'label' => 'GSAx', 'type' => 'float']]
                : [['key' => 'gax', 'label' => 'G−xG', 'type' => 'float'], ['key' => 'hdsat', 'label' => 'HDSAT', 'type' => 'int']];
            if ($report->slug === 'goalies-adv') {
                $columns = array_values(array_filter($columns, static fn (array $column): bool => ($column['key'] ?? '') !== 'ga_per_gp'));
            }
            foreach ($additions as $column) {
                if (! in_array($column['key'], array_column($columns, 'key'), true)) {
                    $columns[] = $column;
                }
            }
            $settings['columns'] = $columns;
            DB::table('perspectives')->where('id', $report->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }

    /** Restore only the affected column definitions, retaining unrelated current settings. */
    public function down(): void
    {
        foreach (DB::table('perspectives')->whereIn('slug', ['skaters-adv', 'goalies-adv'])->get() as $report) {
            $settings = $this->settings($report->settings);
            if ($settings === null || ! isset($settings[self::BACKUP])) {
                continue;
            }
            $settings['columns'] = $settings[self::BACKUP];
            unset($settings[self::BACKUP]);
            DB::table('perspectives')->where('id', $report->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }

    /** Decode both ordinary JSON and historical JSON-string settings. */
    private function settings(mixed $value): ?array
    {
        for ($depth = 0; is_string($value) && $depth < 3; $depth++) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : null;
    }
};
