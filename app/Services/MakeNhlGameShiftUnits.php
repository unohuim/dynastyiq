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
    public function make(int $gameId): void
    {
        $game = NhlGame::find($gameId);
        if (!$game) {
            return;
        }


        $shifts = NhlShift::where('nhl_game_id', $gameId)
            ->orderBy('shift_start_seconds')
            ->get();

        if ($shifts->isEmpty()) {
            return;
        }

        $playerNhlIds = $shifts->pluck('nhl_player_id')->unique()->filter()->all();
        $players = Player::whereIn('nhl_id', $playerNhlIds)->get()->keyBy('nhl_id');

        $eventsByTeam = $this->buildShiftEventsByTeam($shifts);

        foreach ($eventsByTeam as $teamAbbrev => $events) {
            $this->processEventsForTeam($game, $teamAbbrev, $events, $players, $shifts);
        }
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
                'time' => $shift->shift_start_seconds,
                'type' => 'start',
                'shift' => $shift,
            ]);
            $eventsByTeam[$team]->push([
                'time' => $shift->shift_end_seconds,
                'type' => 'end',
                'shift' => $shift,
            ]);
        }

        foreach ($eventsByTeam as $team => $events) {
            $eventsByTeam[$team] = $events->sortBy('time')->values();
        }

        return $eventsByTeam;
    }

    protected function processEventsForTeam(NhlGame $game, string $teamAbbrev, Collection $events, Collection $players, Collection $shifts): void
    {
        $onIce = [
            'F' => collect(),
            'D' => collect(),
            'G' => collect(),
        ];

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

        $playerTeamAbbrevs = $shifts->pluck('team_abbrev', 'nhl_player_id')->toArray();

        $lastChangeTime = null;

        foreach ($events as $event) {
            $time = $event['time'];
            $shift = $event['shift'];
            $player = $players->get($shift->nhl_player_id);


            if (!$player) {
                echo("<p>player is null</p>");
                continue;
            }


            $posType = $player->pos_type ?? null;
            if (!in_array($posType, ['F', 'D', 'G'])) {
                continue;
            }


            // Ignore players not on this team (sanity check)
            $playerTeam = $playerTeamAbbrevs[$player->nhl_id] ?? null;
            if ($playerTeam !== $teamAbbrev) {
                echo("<p>teamAbbrev is null</p>");
                continue;
            }

            // echo("<p>" . $teamAbbrev . "; time: " . $time . "; player:  "  . $player->nhl_id . "(" . $player->last_name . ", " . $player->first_name 

            //     . ") " . $event['type'] . ""

            //     . "</p>");


            if ($lastChangeTime !== null && $lastChangeTime !== $time) {
                foreach (['F', 'D', 'G'] as $unitType) {
                    $this->closeUnitShiftIfChanged(
                        $game,
                        $unitType,
                        $onIce[$unitType],
                        $lastUnits[$unitType],
                        $lastChangeTime,
                        $time,
                        $activeUnitShiftIds,
                        $playerTeamAbbrevs,
                        $teamAbbrev
                    );
                }
            }

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
                $game,
                $unitType,
                $onIce[$unitType],
                $lastUnits[$unitType],
                $lastChangeTime,
                $endTime,
                $activeUnitShiftIds,
                $playerTeamAbbrevs,
                $teamAbbrev
            );
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
        string $teamAbbrev
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

            $unit = $this->findOrCreateUnit($unitType, $currentPlayerIds, $teamAbbrev);

            $teamId = $teamAbbrev ? $game->getTeamIdByAbbrev($teamAbbrev) : null;

            $unitShift = NhlUnitShift::create([
                'unit_id' => $unit->id,
                'nhl_game_id' => $game->nhl_game_id,
                'period' => $this->periodFromSeconds($startTime),
                'start_time' => $this->secondsToTimeString($startTime),
                'end_time' => null,
                'start_game_seconds' => $startTime,
                'end_game_seconds' => 0,
                'seconds' => 0,
                'team_id' => $teamId,
                'team_abbrev' => $teamAbbrev,
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

    protected function findOrCreateUnit(string $unitType, array $playerNhlIds, ?string $teamAbbrev): NhlUnit
    {
        $units = NhlUnit::where('unit_type', $unitType)
            ->when($teamAbbrev, fn ($query) => $query->where('team_abbrev', $teamAbbrev))
            ->get();

        foreach ($units as $unit) {
            $unitPlayerNhlIds = $unit->players()->pluck('nhl_id')->sort()->values()->all();
            if ($unitPlayerNhlIds === $playerNhlIds) {
                return $unit;
            }
        }

        $unit = NhlUnit::create([
            'unit_type' => $unitType,
            'team_abbrev' => $teamAbbrev,
        ]);

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

            NhlUnitPlayer::create([
                'unit_id' => $unit->id,
                'player_id' => $player->id,
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
