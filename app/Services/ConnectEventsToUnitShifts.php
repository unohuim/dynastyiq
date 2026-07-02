<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlUnitShift;
use App\Models\NhlUnit;
use Illuminate\Support\Facades\DB;

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

        if ($events->isEmpty()) {
            return 0;
        }

        DB::table('event_unit_shifts')
            ->whereIn('event_id', $events->pluck('id'))
            ->delete();

        $criticalStoppage = ['stoppage', 'penalty', 'goal', 'period-end', 'game-end'];
        $criticalStart = ['period-start', 'faceoff'];

        $startsBySecond = [];
        $endsBySecond = [];
        $unitShiftRows = $unitShifts->all();

        foreach ($unitShiftRows as $shift) {
            $startsBySecond[(int) $shift->start_game_seconds][] = (int) $shift->id;
            $endsBySecond[(int) $shift->end_game_seconds][] = (int) $shift->id;
        }

        $eventsCount = 0;
        $startIndex = 0;
        $activeShifts = [];
        $pivotRowsByKey = [];
        $now = now();

        foreach ($events as $event) {
            $isCriticalStoppage = in_array($event->type_desc_key, $criticalStoppage);
            $isCriticalStart = in_array($event->type_desc_key, $criticalStart);
            $eventTime = (int) $event->seconds_in_game;

            // 1. Core shifts: start < event time AND end > event time
            while (
                isset($unitShiftRows[$startIndex])
                && (int) $unitShiftRows[$startIndex]->start_game_seconds < $eventTime
            ) {
                $shift = $unitShiftRows[$startIndex];
                $activeShifts[(int) $shift->id] = $shift;
                $startIndex++;
            }

            foreach ($activeShifts as $shiftId => $shift) {
                if ((int) $shift->end_game_seconds <= $eventTime) {
                    unset($activeShifts[$shiftId]);
                    continue;
                }

                $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
            }

            // 2. If critical stoppage event, assign to shifts ending exactly at event time
            if ($isCriticalStoppage) {
                foreach ($endsBySecond[$eventTime] ?? [] as $shiftId) {
                    $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
                }
            }


            // 3. If critical start event, assign to shifts starting exactly at event time
            if ($isCriticalStart) {
                foreach ($startsBySecond[$eventTime] ?? [] as $shiftId) {
                    $this->queuePivotRow($pivotRowsByKey, (int) $event->id, (int) $shiftId, $now);
                }
            }

            $eventsCount++;
        }

        foreach (array_chunk(array_values($pivotRowsByKey), 1000) as $rows) {
            DB::table('event_unit_shifts')->insert($rows);
        }

        return $eventsCount;
    }

    /**
     * Queue a unique event/unit-shift pivot row for batched insertion.
     *
     * @param array<string,array<string,mixed>> $rows
     */
    private function queuePivotRow(array &$rows, int $eventId, int $shiftId, \DateTimeInterface $now): void
    {
        $key = $eventId . ':' . $shiftId;

        if (isset($rows[$key])) {
            return;
        }

        $rows[$key] = [
            'event_id' => $eventId,
            'unit_shift_id' => $shiftId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
