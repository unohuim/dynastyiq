<?php

namespace App\Traits;
use Illuminate\Support\Str;

trait HasClockTimeTrait
{
    public function getSeconds(string $clock_time)
    {
        $time_array = Str::of($clock_time)->explode(':');
        return ((int) $time_array->first() * 60) + (int)$time_array->last();
    }
}
