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
        if (empty($eventsByTeam)) {
            return 0;
        }

        // Build global timeline (all event times + period boundaries)
        $allTimes = collect();
        foreach ($eventsByTeam as $events) {
            $allTimes = $allTimes->merge($events->pluck('time'));
        }
        $lastTime = (int) $allTimes->max();
        foreach ([1200, 2400, 3600] as $boundary) {
            if ($boundary > 0 && $boundary <= max($lastTime, 3600)) {
                $allTimes->push($boundary);
            }
        }
        $timeline = $allTimes->unique()->sort()->values();

        $teamKeys = array_keys($eventsByTeam);

        // Per-team state
        $state = [];
        foreach ($teamKeys as $t) {
            $state[$t] = [
                'onIce' => ['F' => collect(), 'D' => collect(), 'G' => collect()],
                'lastUnits' => ['F' => null, 'D' => null, 'G' => null, 'PP' => null, 'PK' => null],
                'activeUnitShiftIds' => ['F' => null, 'D' => null, 'G' => null, 'PP' => null, 'PK' => null],
            ];
        }

        $playerTeamAbbrevs = $shifts->pluck('team_abbrev', 'nhl_player_id')->toArray();
        $eventsByTimeAndTeam = [];
        foreach ($eventsByTeam as $team => $events) {
            $eventsByTimeAndTeam[$team] = $events->groupBy('time');
        }

        foreach ($timeline as $time) {
            $isBoundary = in_array((int) $time, [1200, 2400, 3600], true);

            // Apply ENDs then STARTs for each team at this timestamp
            foreach ($teamKeys as $team) {
                $bucket = $eventsByTimeAndTeam[$team][$time] ?? collect();
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
                    if (($playerTeamAbbrevs[$player->nhl_id] ?? null) !== $team) {
                        continue;
                    }
                    $state[$team]['onIce'][$posType]->forget($player->nhl_id);
                }
            }

            foreach ($teamKeys as $team) {
                $bucket = $eventsByTimeAndTeam[$team][$time] ?? collect();
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
                    if (($playerTeamAbbrevs[$player->nhl_id] ?? null) !== $team) {
                        continue;
                    }
                    $state[$team]['onIce'][$posType][$player->nhl_id] = $player;
                }
            }



            // Decide PP/PK/EV per team by skater count (goalies excluded)
            // PP/PK only if the shorthanded side has <= 4 skaters.
            // 6v5, 5v6, etc. (empty-net) => EV (F/D/G), not PP/PK.
            foreach ($teamKeys as $team) {
                $opp = $this->otherTeam($teamKeys, $team);

                $teamSkaters = $state[$team]['onIce']['F']->count() + $state[$team]['onIce']['D']->count();
                $oppSkaters  = $opp ? ($state[$opp]['onIce']['F']->count() + $state[$opp]['onIce']['D']->count()) : $teamSkaters;

                $adv = $teamSkaters - $oppSkaters;
                $isSpecialTeams = ($adv !== 0) && (min($teamSkaters, $oppSkaters) <= 4);

                if ($isSpecialTeams && $adv > 0) {
                    // PP for $team (combine F+D). Close F/D units.
                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'F', (int)$time);
                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'D', (int)$time);
                    $state[$team]['lastUnits']['F'] = null;
                    $state[$team]['lastUnits']['D'] = null;

                    $ppPlayers = $state[$team]['onIce']['F']->merge($state[$team]['onIce']['D']);
                    $this->closeUnitShiftIfChanged($game,'PP',$ppPlayers,$state[$team]['lastUnits']['PP'],(int)$time,(int)$time,$state[$team]['activeUnitShiftIds'],$team,$isBoundary);

                    // Goalie tracked normally
                    $this->closeUnitShiftIfChanged($game,'G',$state[$team]['onIce']['G'],$state[$team]['lastUnits']['G'],(int)$time,(int)$time,$state[$team]['activeUnitShiftIds'],$team,$isBoundary);

                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'PK', (int)$time);
                    $state[$team]['lastUnits']['PK'] = null;

                } elseif ($isSpecialTeams && $adv < 0) {
                    // PK for $team
                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'F', (int)$time);
                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'D', (int)$time);
                    $state[$team]['lastUnits']['F'] = null;
                    $state[$team]['lastUnits']['D'] = null;

                    $pkPlayers = $state[$team]['onIce']['F']->merge($state[$team]['onIce']['D']);
                    $this->closeUnitShiftIfChanged($game,'PK',$pkPlayers,$state[$team]['lastUnits']['PK'],(int)$time,(int)$time,$state[$team]['activeUnitShiftIds'],$team,$isBoundary);

                    $this->closeUnitShiftIfChanged($game,'G',$state[$team]['onIce']['G'],$state[$team]['lastUnits']['G'],(int)$time,(int)$time,$state[$team]['activeUnitShiftIds'],$team,$isBoundary);

                    $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], 'PP', (int)$time);
                    $state[$team]['lastUnits']['PP'] = null;

                } else {
                    // Even-strength (incl. 6v5 empty-net): use F/D/G
                    foreach (['PP','PK'] as $st) {
                        $this->maybeCloseActive($state[$team]['activeUnitShiftIds'], $st, (int)$time);
                        $state[$team]['lastUnits'][$st] = null;
                    }
                    foreach (['F','D','G'] as $ut) {
                        $this->closeUnitShiftIfChanged($game,$ut,$state[$team]['onIce'][$ut],$state[$team]['lastUnits'][$ut],(int)$time,(int)$time,$state[$team]['activeUnitShiftIds'],$team,$isBoundary);
                    }
                }
            }

        }

        // Finalize
        $endTime = (int) $timeline->last();
        foreach ($teamKeys as $team) {
            foreach (['F', 'D', 'G', 'PP', 'PK'] as $ut) {
                if ($state[$team]['activeUnitShiftIds'][$ut]) {
                    $this->closeUnitShift($state[$team]['activeUnitShiftIds'][$ut], $endTime);
                }
            }
        }

        return count($teamKeys);
    }

    protected function otherTeam(array $teams, string $team): ?string
    {
        foreach ($teams as $t) {
            if ($t !== $team) {
                return $t;
            }
        }
        return null;
    }

    protected function maybeCloseActive(array &$activeUnitShiftIds, string $unitType, int $time): void
    {
        if (!empty($activeUnitShiftIds[$unitType])) {
            $this->closeUnitShift($activeUnitShiftIds[$unitType], $time);
            $activeUnitShiftIds[$unitType] = null;
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

    protected function closeUnitShiftIfChanged(
        NhlGame $game,
        string $unitType,
        Collection $currentPlayers,      // Collection<Player>
        ?array &$lastUnit,               // ['unit' => NhlUnit, 'players' => int[]]
        int $startTime,
        int $endTime,
        array &$activeUnitShiftIds,
        string $teamAbbrev,
        bool $forceBoundary = false
    ): void {
        $currentPlayerIds = $this->sortedIdsFromPlayers($currentPlayers);

        if (empty($currentPlayerIds)) {
            if ($lastUnit && !empty($activeUnitShiftIds[$unitType])) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $endTime);
                $activeUnitShiftIds[$unitType] = null;
                $lastUnit = null;
            }
            return;
        }

        $lineupChanged = !$lastUnit || $lastUnit['players'] !== $currentPlayerIds;

        if ($lineupChanged || $forceBoundary) {
            if ($lastUnit && !empty($activeUnitShiftIds[$unitType])) {
                $this->closeUnitShift($activeUnitShiftIds[$unitType], $startTime);
                $activeUnitShiftIds[$unitType] = null;
            }

            $unit   = $this->findOrCreateUnit($unitType, $currentPlayerIds, $teamAbbrev);
            $teamId = $teamAbbrev ? $game->getTeamIdByAbbrev($teamAbbrev) : null;

            $unitShift = NhlUnitShift::updateOrCreate(
                [
                    'unit_id'            => $unit->id,
                    'nhl_game_id'        => $game->nhl_game_id,
                    'start_game_seconds' => $startTime,
                ],
                [
                    'period'           => $this->periodFromSeconds($startTime),
                    'start_time'       => $this->secondsToTimeString($startTime),
                    'end_time'         => null,
                    'end_game_seconds' => 0,
                    'seconds'          => 0,
                    'team_id'          => $teamId,
                    'team_abbrev'      => $teamAbbrev,
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
            $existing = $unit->players()->pluck('nhl_id')->sort()->values()->all();
            if ($existing === $playerNhlIds) {
                return $unit;
            }
        }

        $unit = NhlUnit::create(['unit_type' => $unitType, 'team_abbrev' => $teamAbbrev]);

        foreach ($playerNhlIds as $nhlId) {
            $player = Player::where('nhl_id', $nhlId)->first();
            if (!$player && class_exists(\App\Services\ImportNHLPlayer::class)) {
                $importer = new \App\Services\ImportNHLPlayer();
                $importer->import($nhlId);
                $player = Player::where('nhl_id', $nhlId)->first();
            }
            if ($player) {
                NhlUnitPlayer::create(['unit_id' => $unit->id, 'player_id' => $player->id]);
            }
        }

        return $unit;
    }

    protected function sortedIdsFromPlayers(Collection $players): array
    {
        $ids = [];
        foreach ($players as $p) {
            if ($p && isset($p->nhl_id)) {
                $ids[] = (int) $p->nhl_id;
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
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
