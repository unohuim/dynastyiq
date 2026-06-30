<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlGameSummaryValidationFailed;
use App\Models\NhlGameValidation;
use App\Services\ValidateNhlGameSummary;
use App\Support\NhlImportStages;

class ValidateNhlGameSummaryJob extends BaseNhlJob
{
    protected function stageName(): string
    {
        return NhlImportStages::VALIDATE_SUMMARY;
    }

    protected function perform(int $gameId): int
    {
        $validation = app(ValidateNhlGameSummary::class)->validate($gameId);

        if ($validation->status === NhlGameValidation::STATUS_FAILED) {
            throw new NhlGameSummaryValidationFailed(
                "NHL game {$gameId} summary validation failed with {$validation->mismatch_count} deltas."
            );
        }

        return (int) $validation->mismatch_count;
    }
}
