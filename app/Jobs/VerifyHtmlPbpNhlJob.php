<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\VerifyNhlHtmlPlayByPlay;
use App\Support\NhlImportStages;

/**
 * Verify imported API PBP against the official NHL HTML PBP report.
 */
class VerifyHtmlPbpNhlJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return NhlImportStages::HTML_PBP_VERIFY;
    }

    protected function perform(int $gameId): int
    {
        return app(VerifyNhlHtmlPlayByPlay::class)->verify($gameId);
    }
}
