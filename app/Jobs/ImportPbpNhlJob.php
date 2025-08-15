<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportNHLPlayByPlay;

class ImportPbpNhlJob extends BaseNhlJob
{
    
    protected function stageName(): string
    {
        return 'pbp';
    }

    protected function perform(int $gameId): int
    {
        return app(ImportNHLPlayByPlay::class)->import($gameId);
    }

    
}
