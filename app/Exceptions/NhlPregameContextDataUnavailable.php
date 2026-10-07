<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Expected historical-data gap that excludes one context work item without failing its run. */
class NhlPregameContextDataUnavailable extends RuntimeException
{
}
