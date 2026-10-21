<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Signals cooperative cancellation of one pregame-context run. */
class NhlPregameContextRunStopped extends RuntimeException
{
}
