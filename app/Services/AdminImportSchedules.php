<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminImportSchedule;
use Illuminate\Support\Collection;

class AdminImportSchedules
{
    public const DEFINITIONS = [
        'nhl-starting-goalies' => ['today' => 900, 'future' => 3600],
        'nhl-injuries' => ['current' => 900],
        'nhl-anticipated-lineups' => ['within_two_hours' => 900, 'outside_two_hours' => 3600],
    ];

    /** @return Collection<int, AdminImportSchedule> */
    public function forSource(string $sourceKey): Collection
    {
        $definitions = self::DEFINITIONS[$sourceKey] ?? null;
        abort_unless($definitions, 404);

        return collect($definitions)->map(function (int $seconds, string $lane) use ($sourceKey): AdminImportSchedule {
            return AdminImportSchedule::query()->firstOrCreate(
                ['source_key' => $sourceKey, 'lane_key' => $lane],
                ['enabled' => false, 'interval_seconds' => $seconds]
            );
        })->values();
    }

    /** @return array<string,mixed> */
    public function payload(string $sourceKey): array
    {
        $rows = $this->forSource($sourceKey);

        return [
            'enabled' => $rows->every(fn (AdminImportSchedule $row): bool => $row->enabled),
            'lanes' => $rows->mapWithKeys(fn (AdminImportSchedule $row): array => [$row->lane_key => [
                'interval_seconds' => $row->interval_seconds,
                'last_dispatched_at' => $row->last_dispatched_at?->toIso8601String(),
                'next_due_at' => $row->next_due_at?->toIso8601String(),
            ]])->all(),
        ];
    }

    /** @param array<string,int> $intervals */
    public function update(string $sourceKey, bool $enabled, array $intervals): array
    {
        $rows = $this->forSource($sourceKey);
        foreach ($rows as $row) {
            $seconds = $intervals[$row->lane_key] ?? $row->interval_seconds;
            $row->update([
                'enabled' => $enabled,
                'interval_seconds' => $seconds,
                'next_due_at' => $enabled ? ($row->next_due_at ?? now()) : null,
            ]);
        }

        return $this->payload($sourceKey);
    }
}
