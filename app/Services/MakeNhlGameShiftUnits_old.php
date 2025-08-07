<?php

namespace App\Services;

use App\Models\NhlUnit;
use App\Models\NhlUnitPlayer;
use App\Models\NhlUnitShift;
use App\Models\NhlShift;
use App\Models\Player;
use Illuminate\Support\Collection;

class MakeNhlGameShiftUnits
{
    public function make($gameId): void
    {
        $shifts = NhlShift::where('nhl_game_id', $gameId)
            ->orderBy('shift_start_seconds')
            ->get();

        if ($shifts->isEmpty()) {
            return;
        }

        $playerNhlIds = $shifts->pluck('nhl_player_id')->unique()->filter()->all();
        $players = Player::whereIn('nhl_id', $playerNhlIds)->get()->keyBy('nhl_id');

        $events = $this->buildShiftEvents($shifts);

        $this->processEvents($gameId, $events, $players);
    }

    protected function buildShiftEvents(Collection $shifts): Collection
    {
        $events = collect();

        foreach ($shifts as $shift) {
            $events->push([
                'time' => $shift->shift_start_seconds,
                'type' => 'start',
                'shift' => $shift,
            ]);
            $events->push([
                'time' => $shift->shift_end_seconds,
                'type' => 'end',
                'shift' => $shift,
            ]);
        }

        return $events->sortBy('time')->values();
    }

    protected function processEvents($gameId, Collection $events, Collection $players): void
    {
        $onIce = [
            'F' => collect(),
            'D' => collect(),
            'G' => collect(),
        ];

        $lastChangeTime = null;
        $lastUnits = [
            'F' => null,
            'D' => null,
            'G' => null,
        ];

        $activeUnitShiftIds = [
            'F' => null,
            'D' => null,
            'G' => null,
        ];

        foreach ($events as $event) {
            $time = $event['time'];
            $shift = $event['shift'];
            $player = $players->get($shift->nhl_player_id);

            if (!$player) {
                continue;
            }

            $posType = $player->pos_type ?? null;
            if (!in_array($posType, ['F', 'D', 'G'])) {
                continue;
            }

            if ($lastChangeTime !== null && $lastChangeTime !== $time) {
                foreach (['F', 'D', 'G'] as $unitType) {
                    $this->closeUnitShiftIfChanged(
                        $gameId,
                        $unitType,
                        $onIce[$unitType],
                        $lastUnits[$unitType],
                        $lastChangeTime,
                        $time,
                        $activeUnitShiftIds
                    );
                }
            }

            // Use NHL player IDs as keys here:
            if ($event['type'] === 'start') {
                $onIce[$posType][$player->nhl_id] = $player;
            } elseif ($event['type'] === 'end') {
                $onIce[$posType]->forget($player->nhl_id);
            }

            $lastChangeTime = $time;
        }

        $endTime = $events->last()['time'] ?? 0;
        foreach (['F', 'D', 'G'] as $unitType) {
            $this->closeUnitShiftIfChanged(
                $gameId,
                $unitType,
                $onIce[$unitType],
                $lastUnits[$unitType],
                $lastChangeTime,
                $endTime,
                $activeUnitShiftIds
            );
        }
    }

    protected function closeUnitShiftIfChanged(
        $gameId,
        string $unitType,
        Collection $currentPlayers,
        ?array &$lastUnit,
        int $startTime,
        int $endTime,
        array &$activeUnitShiftIds
    ): void {
        $currentPlayerIds = $currentPlayers->keys()->sort()->values()->all();

        if (empty($currentPlayerIds)) {
            if ($lastUnit && $activeUnitShiftIds[$unitType]) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $endTime);
                $activeUnitShiftIds[$unitType] = null;
                $lastUnit = null;
            }
            return;
        }

        $lineupChanged = !$lastUnit || $lastUnit['players'] !== $currentPlayerIds;

        if ($lineupChanged) {
            if ($lastUnit && $activeUnitShiftIds[$unitType]) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $startTime);
                $activeUnitShiftIds[$unitType] = null;
            }

            $unit = $this->findOrCreateUnit($unitType, $currentPlayerIds);

            $unitShift = NhlUnitShift::create([
                'unit_id' => $unit->id,
                'nhl_game_id' => $gameId,
                'period' => $this->periodFromSeconds($startTime),
                'start_time' => $this->secondsToTimeString($startTime),
                'end_time' => null,
                'start_game_seconds' => $startTime,
                'end_game_seconds' => 0,
                'seconds' => 0,
            ]);

            $activeUnitShiftIds[$unitType] = $unitShift->id;
            $lastUnit = [
                'unit' => $unit,
                'players' => $currentPlayerIds,
            ];
        }
    }

    protected function closeUnitShift(int $unitShiftId, int $endTimeSeconds): void
    {
        $unitShift = NhlUnitShift::find($unitShiftId);
        if (!$unitShift) {
            return;
        }

        $unitShift->end_game_seconds = $endTimeSeconds;
        $unitShift->seconds = max(0, $endTimeSeconds - $unitShift->start_game_seconds);
        $unitShift->end_time = $this->secondsToTimeString($endTimeSeconds);
        $unitShift->save();
    }

    
    protected function findOrCreateUnit(string $unitType, array $playerNhlIds): NhlUnit
    {
        // Find all units of this type
        $units = NhlUnit::where('unit_type', $unitType)->get();

        // Check if any unit matches exactly the player set (by NHL IDs)
        foreach ($units as $unit) {
            $unitPlayerNhlIds = $unit->players()->pluck('nhl_id')->sort()->values()->all();
            if ($unitPlayerNhlIds === $playerNhlIds) {
                return $unit;
            }
        }

        // Create new unit
        $unit = NhlUnit::create(['unit_type' => $unitType]);

        foreach ($playerNhlIds as $nhlId) {
            $player = Player::where('nhl_id', $nhlId)->first();

            if (!$player) {
                $importer = new ImportNHLPlayer();
                $importer->import($nhlId);
                $player = Player::where('nhl_id', $nhlId)->first();

                if (!$player) {
                    // Optional: log error or skip player
                    continue;
                }
            }

            NhlUnitPlayer::create([
                'unit_id' => $unit->id,
                'player_id' => $player->id, // Use internal DB PK here
            ]);
        }

        return $unit;
    }


    protected function secondsToTimeString(int $seconds): string
    {
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    protected function periodFromSeconds(int $seconds): int
    {
        return floor($seconds / 1200) + 1;
    }
}
