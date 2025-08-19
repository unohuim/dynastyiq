<?php

namespace App\Jobs;

use App\Jobs\BaseNhlJob;
use App\Services\SumNhlGameUnits;


class SumNhlGameUnitsJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'sum-game-units';
    }

    protected function perform(int $gameId): int
    {
        $service = app()->make(SumNhlGameUnits::class, ['gameId' => $gameId]);
        return $service->sum();
    }
}
