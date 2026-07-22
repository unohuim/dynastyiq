<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds shiftchart-derived unit shifts and event links for one imported NHL game.
 */
class RebuildNhlEventShiftLinks
{
    public function __construct(
        private readonly ImportNhlShifts $shiftImporter,
        private readonly MakeNhlGameShiftUnits $unitMaker
    ) {
    }

    /**
     * Re-import raw shifts, rebuild unit shifts, and reconnect PBP events.
     *
     * @return array{shifts_count:int,unit_shifts_count:int,events_count:int}
     */
    public function rebuild(int $gameId): array
    {
        DB::table('event_unit_shifts')
            ->whereIn('unit_shift_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();

        DB::table('event_unit_shifts')
            ->whereIn('event_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('play_by_plays')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();

        DB::table('nhl_unit_shift_players')
            ->whereIn('unit_shift_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();

        DB::table('nhl_unit_shifts')
            ->where('nhl_game_id', $gameId)
            ->delete();

        DB::table('nhl_shifts')
            ->where('nhl_game_id', $gameId)
            ->delete();

        $shiftsCount = $this->shiftImporter->import((string) $gameId);
        $unitShiftsCount = $this->unitMaker->make($gameId);
        $eventsCount = app()->make(ConnectEventsToUnitShifts::class, ['gameId' => $gameId])->connect();

        return [
            'shifts_count' => $shiftsCount,
            'unit_shifts_count' => $unitShiftsCount,
            'events_count' => $eventsCount,
        ];
    }
}
