<?php

namespace App\Services;

use App\Models\NhlGame;
use App\Models\NhlUnit;
use App\Models\NhlUnitPlayer;
use App\Models\NhlUnitShift;
use App\Models\NhlShift;
use App\Models\Player;
use Illuminate\Support\Collection;

class MakeNhlGameShiftUnits
{
    public function make(int $gameId): int
    {
        $game = NhlGame::find($gameId);
        if (!$game) {
            return 0;
        }

        $shifts = NhlShift::where('nhl_game_id', $gameId)
            ->orderBy('shift_start_seconds')
            ->get();

        if ($shifts->isEmpty()) {
            return 0;
        }

        $playerNhlIds = $shifts->pluck('nhl_player_id')->unique()->filter()->all();
        $players = Player::whereIn('nhl_id', $playerNhlIds)->get()->keyBy('nhl_id');

        $eventsByTeam = $this->buildShiftEventsByTeam($shifts);

        $eventsCount = 0;
        foreach ($eventsByTeam as $teamAbbrev => $events) {
            $this->processEventsForTeam($game, $teamAbbrev, $events, $players, $shifts);
            $eventsCount++;
        }

        return $eventsCount;
    }

    protected function buildShiftEventsByTeam(Collection $shifts): array
    {
        $eventsByTeam = [];

        foreach ($shifts as $shift) {
            $team = $shift->team_abbrev;
            if (!isset($eventsByTeam[$team])) {
                $eventsByTeam[$team] = collect();
            }

            $eventsByTeam[$team]->push([
                'time'  => (int) $shift->shift_start_seconds,
                'type'  => 'start',
                'shift' => $shift,
            ]);
            $eventsByTeam[$team]->push([
                'time'  => (int) $shift->shift_end_seconds,
                'type'  => 'end',
                'shift' => $shift,
            ]);
        }

        foreach ($eventsByTeam as $team => $events) {
            $eventsByTeam[$team] = $events->sortBy('time')->values();
        }

        return $eventsByTeam;
    }

    protected function processEventsForTeam(
        NhlGame $game,
        string $teamAbbrev,
        Collection $events,
        Collection $players,
        Collection $shifts
    ): void {
        $onIce = ['F' => collect(), 'D' => collect(), 'G' => collect()];
        $lastUnits = ['F' => null, 'D' => null, 'G' => null];
        $activeUnitShiftIds = ['F' => null, 'D' => null, 'G' => null];

        $playerTeamAbbrevs = $shifts->pluck('team_abbrev', 'nhl_player_id')->toArray();

        // Group by timestamp; inject period boundaries (1200, 2400, 3600 if applicable)
        $grouped = $events->groupBy('time');
        $lastTime = (int) ($events->last()['time'] ?? 0);
        foreach ([1200, 2400, 3600] as $boundary) {
            if ($boundary > 0 && $boundary <= max($lastTime, 3600)) {
                if (!isset($grouped[$boundary])) {
                    $grouped[$boundary] = collect();
                }
                $grouped[$boundary]->push(['time' => $boundary, 'type' => 'boundary']);
            }
        }

        // Sort timestamps numerically
        $grouped = collect($grouped)->sortKeys()->map(fn($c) => $c->values());

        foreach ($grouped as $time => $bucket) {
            $isBoundary = $bucket->contains(fn($e) => ($e['type'] ?? null) === 'boundary');

            // 1) apply ENDs
            foreach ($bucket as $event) {
                if (($event['type'] ?? null) !== 'end') {
                    continue;
                }
                $shift  = $event['shift'];
                $player = $players->get($shift->nhl_player_id);
                if (!$player) {
                    continue;
                }
                $posType = $player->pos_type ?? null;
                if (!in_array($posType, ['F', 'D', 'G'], true)) {
                    continue;
                }
                if (($playerTeamAbbrevs[$player->nhl_id] ?? null) !== $teamAbbrev) {
                    continue;
                }
                $onIce[$posType]->forget($player->nhl_id);
            }

            // 2) apply STARTs
            foreach ($bucket as $event) {
                if (($event['type'] ?? null) !== 'start') {
                    continue;
                }
                $shift  = $event['shift'];
                $player = $players->get($shift->nhl_player_id);
                if (!$player) {
                    continue;
                }
                $posType = $player->pos_type ?? null;
                if (!in_array($posType, ['F', 'D', 'G'], true)) {
                    continue;
                }
                if (($playerTeamAbbrevs[$player->nhl_id] ?? null) !== $teamAbbrev) {
                    continue;
                }
                $onIce[$posType][$player->nhl_id] = $player;
            }

            // 3) boundary: force close/open at T to split periods
            foreach (['F', 'D', 'G'] as $unitType) {
                $this->closeUnitShiftIfChanged(
                    $game,
                    $unitType,
                    $onIce[$unitType],
                    $lastUnits[$unitType],
                    (int) $time,
                    (int) $time,
                    $activeUnitShiftIds,
                    $playerTeamAbbrevs,
                    $teamAbbrev,
                    $isBoundary // force split at period boundaries
                );
            }
        }

        // Finalize any open units at final timestamp
        $endTime = (int) ($events->last()['time'] ?? 0);
        foreach (['F', 'D', 'G'] as $unitType) {
            if ($activeUnitShiftIds[$unitType]) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $endTime);
            }
        }
    }

    protected function closeUnitShiftIfChanged(
        NhlGame $game,
        string $unitType,
        Collection $currentPlayers,
        ?array &$lastUnit,
        int $startTime,
        int $endTime,
        array &$activeUnitShiftIds,
        array $playerTeamAbbrevs,
        string $teamAbbrev,
        bool $forceBoundary = false
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

        if ($lineupChanged || $forceBoundary) {
            if ($lastUnit && $activeUnitShiftIds[$unitType]) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $startTime);
                $activeUnitShiftIds[$unitType] = null;
            }

            $unit = $this->findOrCreateUnit($unitType, $currentPlayerIds, $teamAbbrev);
            $teamId = $teamAbbrev ? $game->getTeamIdByAbbrev($teamAbbrev) : null;

            $unitShift = NhlUnitShift::updateOrCreate(
                [
                    'unit_id'            => $unit->id,
                    'nhl_game_id'        => $game->nhl_game_id,
                    'start_game_seconds' => $startTime,
                ],
                [
                'period'             => $this->periodFromSeconds($startTime),
                'start_time'         => $this->secondsToTimeString($startTime),
                'end_time'           => null,
                'end_game_seconds'   => 0,
                'seconds'            => 0,
                'team_id'            => $teamId,
                'team_abbrev'        => $teamAbbrev,
                ]
            );

            $activeUnitShiftIds[$unitType] = $unitShift->id;
            $lastUnit = ['unit' => $unit, 'players' => $currentPlayerIds];
        }
    }

    protected function closeUnitShift(int $unitShiftId, int $endTimeSeconds): void
    {
        $unitShift = NhlUnitShift::find($unitShiftId);
        if (!$unitShift) {
            return;
        }

        // Cap to period end (max 1200 seconds span)
        $periodEnd = $this->periodEndFromSeconds($unitShift->start_game_seconds);
        $end = min($endTimeSeconds, $periodEnd);

        $unitShift->end_game_seconds = $end;
        $unitShift->seconds = max(0, $end - $unitShift->start_game_seconds);
        $unitShift->end_time = $this->secondsToTimeString($end);
        $unitShift->save();
    }

    protected function findOrCreateUnit(string $unitType, array $playerNhlIds, ?string $teamAbbrev): NhlUnit
    {
        $units = NhlUnit::where('unit_type', $unitType)
            ->when($teamAbbrev, fn($q) => $q->where('team_abbrev', $teamAbbrev))
            ->get();

        foreach ($units as $unit) {
            $unitPlayerNhlIds = $unit->players()->pluck('nhl_id')->sort()->values()->all();
            if ($unitPlayerNhlIds === $playerNhlIds) {
                return $unit;
            }
        }

        $unit = NhlUnit::create(['unit_type' => $unitType, 'team_abbrev' => $teamAbbrev]);

        foreach ($playerNhlIds as $nhlId) {
            $player = Player::where('nhl_id', $nhlId)->first();
            if (!$player) {
                $importer = new ImportNHLPlayer();
                $importer->import($nhlId);
                $player = Player::where('nhl_id', $nhlId)->first();
                if (!$player) {
                    continue;
                }
            }
            NhlUnitPlayer::create(['unit_id' => $unit->id, 'player_id' => $player->id]);
        }

        return $unit;
    }

    protected function secondsToTimeString(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    protected function periodFromSeconds(int $seconds): int
    {
        return intdiv($seconds, 1200) + 1;
    }

    protected function periodEndFromSeconds(int $seconds): int
    {
        return intdiv($seconds, 1200) * 1200 + 1200;
    }
}
