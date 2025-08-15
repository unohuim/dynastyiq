<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportNhlShifts;
use App\Jobs\BaseNhlJob;


class ImportShiftsNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'shifts';
    }

    protected function perform(int $gameId): int
    {
        return app(ImportNhlShifts::class)->import((string) $gameId);
    }
}
