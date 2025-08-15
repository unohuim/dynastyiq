<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportNhlBoxscore;

class ImportBoxscoreNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'boxscore';
    }

    protected function perform(int $gameId): int
    {
        return app(ImportNhlBoxscore::class)->import($gameId);
    }

}
