<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised after validation deltas have been persisted for a game summary.
 */
class NhlGameSummaryValidationFailed extends RuntimeException
{
}
