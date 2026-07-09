<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\PlatformLeague;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Normalized request context for a stats payload.
 */
final class StatsQueryContext
{
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?PlatformLeague $league,
        public readonly ?object $perspective,
        public readonly string $requestedPerspective,
        public readonly ?string $season,
        public readonly string $slice,
        public readonly int $gameType,
        public readonly string $period,
        public readonly ?string $columnGroup,
        public readonly bool $draftContext,
        public readonly string $resource,
    ) {
    }

    public static function fromRequest(
        Request $request,
        ?PlatformLeague $league = null,
        ?object $perspective = null,
        string $defaultPerspective = ''
    ): self {
        $slice = (string) $request->input('slice', 'total');
        $gameType = (int) $request->input('game_type', 2);
        $columnGroup = trim((string) $request->query('column_group', ''));
        $season = $request->input('season_id', $request->input('season'));

        return new self(
            $request->user(),
            $league,
            $perspective,
            (string) $request->query('perspective', $defaultPerspective),
            is_string($season) ? $season : null,
            in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
            in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
            (string) $request->input('period', 'season'),
            $columnGroup !== '' ? $columnGroup : null,
            $request->boolean('draft_context'),
            (string) $request->input('resource', 'players'),
        );
    }

    public function withPerspective(object $perspective): self
    {
        return new self(
            $this->user,
            $this->league,
            $perspective,
            $this->requestedPerspective,
            $this->season,
            $this->slice,
            $this->gameType,
            $this->period,
            $this->columnGroup,
            $this->draftContext,
            $this->resource,
        );
    }
}
