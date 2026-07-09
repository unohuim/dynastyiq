<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Input boundary for building a season stats payload from perspective settings.
 */
final class SeasonStatsPayloadRequest
{
    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly object $perspective,
        public readonly array $settings,
        public readonly bool $canSlice,
        public readonly ?string $seasonFilter,
        public readonly string $slice,
        public readonly ?int $gameType,
        public readonly ?Request $request,
        public readonly StatsFilterSet $filterSet,
    ) {
    }
}
