<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlUnitShift;
use App\Models\NhlUnit;

class ConnectEventsToUnitShifts
{
    protected int $gameId;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    public function connect(): int
    {
        $unitShifts = NhlUnitShift::where('nhl_game_id', $this->gameId)
            ->orderBy('start_game_seconds')
            ->get();

        $events = PlayByPlay::where('nhl_game_id', $this->gameId)
            ->orderBy('seconds_in_game')
            ->get();

        $criticalStoppage = ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'];
        $criticalStart = ['period-start', 'faceoff'];

        

        $eventsCount = 0;
        
        foreach ($events as $event) {
            $isCriticalStoppage = in_array($event->type_desc_key, $criticalStoppage);
            $isCriticalStart = in_array($event->type_desc_key, $criticalStart);

            // 1. Core shifts: start < event time AND end > event time
            $coreShifts = $unitShifts->filter(fn($shift) =>
                $shift->start_game_seconds < $event->seconds_in_game &&
                $shift->end_game_seconds > $event->seconds_in_game
            );


            foreach ($coreShifts as $shift) {
                $shift->events()->syncWithoutDetaching($event->id);
            }

            // 2. If critical stoppage event, assign to shifts ending exactly at event time
            if ($isCriticalStoppage) {
                $criticalShifts = $unitShifts->filter(fn($shift) =>
                    $shift->end_game_seconds === $event->seconds_in_game
                );
            
                foreach ($criticalShifts as $shift) {
                    $shift->events()->syncWithoutDetaching($event->id);
                }
            }


            // 3. If critical start event, assign to shifts starting exactly at event time
            if ($isCriticalStart) {
                $startShifts = $unitShifts->filter(fn($shift) =>
                    $shift->start_game_seconds === $event->seconds_in_game
                );

                foreach ($startShifts as $shift) {
                    $shift->events()->syncWithoutDetaching($event->id);
                }
            }

            $eventsCount++;
        }

        return $eventsCount;
    }




    protected function normalizeZoneCode($event, int $referenceTeamId): ?string
    {
        $zone = $event->zone_code;

        if (is_null($zone)) {
            return null;
        }

        // If event_owner_team_id matches reference team, zone is correct
        if ($event->event_owner_team_id == $referenceTeamId) {
            return $zone;
        }

        // Otherwise, flip zone: offensive <-> defensive, neutral stays the same
        return match ($zone) {
            'O' => 'D',
            'D' => 'O',
            default => $zone,
        };
    }


    public function printEventUnitShiftCounts(): void
    {
        $events = PlayByPlay::where('nhl_game_id', $this->gameId)
            ->orderBy('seconds_in_game')
            ->get();

        $unitShifts = NhlUnitShift::where('nhl_game_id', $this->gameId)
            ->get();

        $maxEventTime = $events->max('seconds_in_game');

        foreach ($events as $event) {
            $count = $unitShifts->filter(function ($shift) use ($event, $maxEventTime) {
                return $shift->start_game_seconds <= $event->seconds_in_game
                    && (
                        ($event->seconds_in_game == $maxEventTime && $shift->end_game_seconds >= $event->seconds_in_game) ||
                        ($event->seconds_in_game != $maxEventTime && $shift->end_game_seconds > $event->seconds_in_game)
                    );
            })->count();

            if ($count != 6) {
                echo "<p>Event ID: {$event->id} - Unit Shifts Count: {$count} </p>";
            }
        }
    }


    public function showTopLine(?int $gameId = null, ?string $posType = null)
    {
        $query = NhlUnitShift::query()
            ->selectRaw('unit_id, SUM(seconds) as total_seconds')
            ->groupBy('unit_id');

        if ($gameId) {
            $query->where('nhl_game_id', $gameId);
        }

        if ($posType) {
            $query->whereHas('unit', fn($q) => $q->where('unit_type', $posType));
        }

        $unitSeconds = $query->orderByDesc('total_seconds')->get();

        // Load units with players and attach total_seconds
        $units = NhlUnit::with('players', 'events')
            ->whereIn('id', $unitSeconds->pluck('unit_id'))
            ->get()
            ->keyBy('id');

        return $unitSeconds->map(fn($item) => [
            'unit' => $units->get($item->unit_id),
            'total_seconds' => $item->total_seconds,
        ])->values();
    }

}
