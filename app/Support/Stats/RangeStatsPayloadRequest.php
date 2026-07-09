<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Input boundary for building a date-range stats payload from perspective settings.
 */
final class RangeStatsPayloadRequest
{
    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly array $settings,
        public readonly bool $canSlice,
        public readonly string $slice,
        public readonly ?int $gameType,
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly Request $request,
        public readonly StatsFilterSet $filterSet,
    ) {
    }
}
