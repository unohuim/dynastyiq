<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Discard an interrupted or obsolete run's uncommitted database work. */
class NhlGameImportRunCancelled extends RuntimeException
{
}
