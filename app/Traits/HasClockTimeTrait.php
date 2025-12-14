<?php

namespace App\Traits;

use Illuminate\Support\Str;
use InvalidArgumentException;

trait HasClockTimeTrait
{
    public function getSeconds(string $clockTime): int
    {
        $parts = Str::of($clockTime)
            ->explode(':')
            ->map(fn ($value) => (int) $value)
            ->values();

        return match ($parts->count()) {
            1 => $parts[0],
            2 => ($parts[0] * 60) + $parts[1],
            3 => ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2],
            4 => ($parts[0] * 86400) + ($parts[1] * 3600) + ($parts[2] * 60) + $parts[3],
            default => throw new InvalidArgumentException('Invalid clock time format.'),
        };
    }
}
