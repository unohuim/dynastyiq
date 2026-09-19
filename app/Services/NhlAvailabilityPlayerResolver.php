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
        $query = Player::query();

        if ($goalie !== true) {
            $query->where('current_league_abbrev', 'NHL');
        } else {
            $query->whereNotNull('nhl_id');
        }

        if ($teamAbbrev !== null && $teamAbbrev !== '') {
            $query->where('team_abbrev', mb_strtoupper($teamAbbrev));
        }

        if ($goalie === true) {
            $query->where(function ($goalieQuery): void {
                $goalieQuery->where('is_goalie', true)
                    ->orWhereRaw("UPPER(COALESCE(position, '')) = 'G'")
                    ->orWhereRaw("UPPER(COALESCE(pos_type, '')) = 'G'");
            });
        } elseif ($goalie === false) {
            $query->where('is_goalie', false)
                ->whereRaw("UPPER(COALESCE(position, '')) <> 'G'")
                ->whereRaw("UPPER(COALESCE(pos_type, '')) <> 'G'");
        }

        return $query->get()->first(
            fn (Player $player): bool => Str::slug((string) $player->full_name) === $normalized
        );
    }
}
