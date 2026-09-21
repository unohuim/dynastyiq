<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolves reported lineup references through one canonical, team-aware identity path. */
class NhlLineupPlayerResolver
{
    /** @var Collection<int,Player>|null */
    private ?Collection $players = null;

    public function __construct(private readonly PlayerIdentityNormalizer $normalizer)
    {
    }

    public function resolve(string $name, string $teamAbbrev): ?Player
    {
        $normalized = $this->normalizer->normalizeName($name);
        if ($normalized === null) {
            return null;
        }

        if (ctype_digit($normalized)) {
            return $this->resolveSweaterNumber((int) $normalized, $teamAbbrev);
        }

        $matches = $this->canonicalPlayers()->filter(
            fn (Player $player): bool => $this->referenceMatchesPlayer($normalized, $player)
        )->values();

        return $this->preferTeamMatch($matches, $teamAbbrev);
    }

    /** @return array<int,array<string,mixed>> */
    public function mentions(string $text, string $teamAbbrev): array
    {
        $normalizedText = $this->normalizer->normalizeName($text);
        if ($normalizedText === null) {
            return [];
        }

        $found = [];
        foreach ($this->canonicalPlayers() as $player) {
            $offset = $this->mentionOffset($normalizedText, $player);
            if ($offset === null) {
                continue;
            }

            $teamMatch = mb_strtoupper((string) $player->team_abbrev) === mb_strtoupper($teamAbbrev);
            $found[] = ['offset' => $offset, 'team_match' => $teamMatch, 'player' => $player];
        }

        return collect($found)->groupBy('offset')->map(function (Collection $matches): ?array {
            $teamMatches = $matches->where('team_match', true)->values();

            return $teamMatches->count() === 1 ? $teamMatches->first() : null;
        })->filter()->sortBy('offset')->pluck('player')->map(fn (Player $player): array => $this->playerRow($player))
            ->values()->all();
    }

    /** @return Collection<int,Player> */
    private function canonicalPlayers(): Collection
    {
        return $this->players ??= Player::query()->whereNotNull('nhl_id')->whereNotNull('full_name')
            ->get(['id', 'nhl_id', 'first_name', 'last_name', 'full_name', 'team_abbrev', 'position']);
    }

    private function referenceMatchesPlayer(string $reference, Player $player): bool
    {
        $full = $this->normalizer->normalizeName($player->full_name);
        $last = $this->normalizer->normalizeName($player->last_name);
        if ($full === null || $last === null) {
            return false;
        }

        if ($reference === $full || $reference === $last) {
            return true;
        }

        if ($this->normalizer->compactNormalizedName($reference)
            === $this->normalizer->compactNormalizedName($player->full_name)) {
            return true;
        }

        $parts = preg_split('/\s+/', $reference) ?: [];
        $fullParts = preg_split('/\s+/', $full) ?: [];
        if (count($parts) >= 2 && count($fullParts) >= 2
            && implode(' ', array_slice($parts, 1)) === $last
            && $this->normalizer->firstNamesAreCompatible($parts[0], $fullParts[0])) {
            return true;
        }

        if (count($parts) >= 2 && mb_strlen($parts[0]) === 1 && $parts[0] === mb_substr($full, 0, 1)
            && implode(' ', array_slice($parts, 1)) === $last) {
            return true;
        }

        if (! str_contains($reference, ' ') && mb_strlen($reference) >= 3) {
            return $reference === $this->initials($full)
                || (mb_strlen($reference) >= 4 && str_starts_with($last, $reference));
        }

        return false;
    }

    private function mentionOffset(string $text, Player $player): ?int
    {
        $full = $this->normalizer->normalizeName($player->full_name);
        $last = $this->normalizer->normalizeName($player->last_name);
        if ($full === null || $last === null) {
            return null;
        }

        $fullPattern = $this->namePattern($full);
        if (preg_match('/(?<![a-z0-9])' . $fullPattern . '(?![a-z0-9])/u', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $match[0][1];
        }

        $lastPattern = $this->namePattern($last);
        if (preg_match(
            '/(?<![a-z0-9])(?:(?<initial>[a-z])\s+)?' . $lastPattern . '(?![a-z0-9])/u',
            $text,
            $match,
            PREG_OFFSET_CAPTURE
        ) !== 1) {
            return null;
        }

        $reportedInitial = (string) ($match['initial'][0] ?? '');
        if ($reportedInitial !== '' && $reportedInitial !== mb_substr($full, 0, 1)) {
            return null;
        }

        return (int) $match[0][1];
    }

    /** @param Collection<int,Player> $matches */
    private function preferTeamMatch(Collection $matches, string $teamAbbrev): ?Player
    {
        $teamMatches = $matches->filter(
            fn (Player $player): bool => mb_strtoupper((string) $player->team_abbrev) === mb_strtoupper($teamAbbrev)
        )->values();

        if ($teamMatches->count() === 1) {
            return $teamMatches->first();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveSweaterNumber(int $number, string $teamAbbrev): ?Player
    {
        $playerId = DB::table('nhl_boxscores as boxscores')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'boxscores.nhl_game_id')
            ->join('nhl_teams as teams', 'teams.nhl_id', '=', 'boxscores.nhl_team_id')
            ->where('teams.abbrev', mb_strtoupper($teamAbbrev))
            ->where('boxscores.sweater_number', $number)
            ->whereNotNull('boxscores.nhl_player_id')
            ->orderByDesc('games.game_date')->orderByDesc('boxscores.nhl_game_id')
            ->value('boxscores.nhl_player_id');

        return $playerId === null ? null : Player::query()->where('nhl_id', (int) $playerId)->first();
    }

    private function initials(string $normalizedName): string
    {
        return collect(preg_split('/\s+/', $normalizedName) ?: [])->filter()
            ->map(static fn (string $part): string => mb_substr($part, 0, 1))->implode('');
    }

    private function namePattern(string $normalizedName): string
    {
        return implode('\\s*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            preg_split('/\s+/', $normalizedName) ?: []
        ));
    }

    /** @return array<string,mixed> */
    private function playerRow(Player $player): array
    {
        return [
            'name' => (string) $player->full_name,
            'position' => $player->position ? (string) $player->position : null,
            'player_id' => $player->id,
            'nhl_player_id' => $player->nhl_id,
            'team_abbrev' => $player->team_abbrev,
        ];
    }
}
