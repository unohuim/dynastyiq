<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SumNHLPlayByPlay;
use App\Jobs\BaseNhlJob;


class SummarizePbpNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return 'summary';
    }

    protected function perform(int $gameId): int
    {
        return app(SumNHLPlayByPlay::class)->summarize($gameId);
    }
}
