<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ConnectEventsToUnitShifts;

class ConnectEventsShiftUnitsNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'connect-events';
    }

    protected function perform(int $gameId): int
    {
        $service = app()->make(ConnectEventsToUnitShifts::class, ['gameId' => $gameId]);
        return $service->connect();
    }
}
