<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Str;

/** Resolves public lineup names to canonical player identities without creating players. */
class NhlLineupPlayerResolver
{
    public function resolve(string $name, string $teamAbbrev): ?Player
    {
        $slug = Str::slug($name);
        $matches = Player::query()->whereNotNull('nhl_id')
            ->whereRaw('LOWER(full_name) = ?', [mb_strtolower(trim($name))])->get();
        if ($matches->isEmpty()) {
            $matches = Player::query()->whereNotNull('nhl_id')->get()->filter(
                fn (Player $player): bool => Str::slug((string) $player->full_name) === $slug
            );
        }

        return $matches->first(fn (Player $player): bool => mb_strtoupper((string) $player->team_abbrev) === $teamAbbrev)
            ?? ($matches->count() === 1 ? $matches->first() : null);
    }
}
