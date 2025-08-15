<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MakeNhlGameShiftUnits;
use App\Jobs\BaseNhlJob;

class MakeShiftUnitsNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'shift-units';
    }

    protected function perform(int $gameId): int
    {
        return app(MakeNhlGameShiftUnits::class)->make($gameId);
    }

    public function tags(): array
    {
        return ['make-nhl-shift-units', "game-id:{$this->gameId}"];
    }
}
