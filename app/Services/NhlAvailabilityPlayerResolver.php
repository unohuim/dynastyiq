<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Str;

class NhlAvailabilityPlayerResolver
{
    public function resolve(string $name, ?string $teamAbbrev = null, ?bool $goalie = null): ?Player
    {
        $normalized = Str::slug($name);
        $query = Player::query()->where('current_league_abbrev', 'NHL');

        if ($teamAbbrev !== null && $teamAbbrev !== '') {
            $query->where('team_abbrev', mb_strtoupper($teamAbbrev));
        }

        if ($goalie !== null) {
            $query->where('is_goalie', $goalie);
        }

        return $query->get()->first(
            fn (Player $player): bool => Str::slug((string) $player->full_name) === $normalized
        );
    }
}
