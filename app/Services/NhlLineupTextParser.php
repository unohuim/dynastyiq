<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Conservatively extracts ordered lineup groups from text returned by X. */
class NhlLineupTextParser
{
    /** @var Collection<int,Player>|null */
    private ?Collection $players = null;

    /** @return array<int,array<string,mixed>> */
    public function parse(string $text, string $teamAbbrev): array
    {
        $players = $this->players ??= Player::query()->whereNotNull('nhl_id')
            ->whereNotNull('full_name')
            ->get(['id', 'full_name', 'team_abbrev', 'position']);
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $decoded = preg_replace(
            '/\b(forwards?|forward lines?|defen[cs]e|defen[cs]emen|d pairs?|pairings?|goalies?|goaltenders?|scratches?|extras?)\s*:\s*/iu',
            "\n" . '$1' . "\n",
            $decoded
        ) ?? $decoded;
        $lines = preg_split('/\R/u', $decoded) ?: [];
        $section = null;
        $forwardGroups = [];
        $defenseGroups = [];
        $goalies = [];
        $scratches = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $heading = $this->heading($line);
            if ($heading !== null) {
                $section = $heading;
                continue;
            }

            $matches = $this->playersInText($line, $players, $teamAbbrev);
            if ($section !== null) {
                $delimited = $this->namesFromDelimitedLine($line);
                if (count($delimited) > count($matches)) {
                    $matches = array_map(
                        fn (string $name): array => ['name' => $name, 'position' => null],
                        $delimited
                    );
                }
            }
            if ($matches === []) {
                continue;
            }

            if ($section === 'scratch') {
                array_push($scratches, ...$matches);
                continue;
            }
            if ($section === 'goalie' || $this->allAtPosition($matches, 'G')) {
                array_push($goalies, ...$matches);
                continue;
            }
            if ($section === 'defense' || count($matches) === 2) {
                $defenseGroups[] = $matches;
                continue;
            }
            if ($section === 'forward' || count($matches) >= 3) {
                foreach (array_chunk($matches, 3) as $group) {
                    if (count($group) === 3) {
                        $forwardGroups[] = $group;
                    }
                }
            }
        }

        $result = [];
        foreach (array_slice($forwardGroups, 0, 4) as $lineIndex => $group) {
            foreach ($group as $slotIndex => $player) {
                $result[] = $this->row($player, 'forward', 'F' . ($lineIndex + 1), $slotIndex + 1);
            }
        }
        foreach (array_slice($defenseGroups, 0, 3) as $lineIndex => $group) {
            foreach (array_slice($group, 0, 2) as $slotIndex => $player) {
                $result[] = $this->row($player, 'defense', 'D' . ($lineIndex + 1), $slotIndex + 1);
            }
        }
        foreach (array_slice($this->uniquePlayers($goalies), 0, 2) as $slotIndex => $player) {
            $result[] = $this->row($player, 'goalie', 'G', $slotIndex + 1);
        }
        foreach ($this->uniquePlayers($scratches) as $slotIndex => $player) {
            $result[] = $this->row($player, 'scratch', 'SCR', $slotIndex + 1);
        }

        return $result;
    }

    private function heading(string $line): ?string
    {
        $normalized = mb_strtolower(trim($line, " \t\n\r\0\x0B:-–—|"));

        return match (true) {
            preg_match('/^(forwards?|forward lines?|lines?)$/u', $normalized) === 1 => 'forward',
            preg_match('/^(defen[cs]e|defen[cs]emen|d pairs?|pairings?)$/u', $normalized) === 1 => 'defense',
            preg_match('/^(goalies?|goaltenders?|in goal)$/u', $normalized) === 1 => 'goalie',
            preg_match('/^(scratches?|extras?)$/u', $normalized) === 1 => 'scratch',
            default => null,
        };
    }

    /** @param Collection<int,Player> $players @return array<int,array{name:string,position:string|null}> */
    private function playersInText(string $line, Collection $players, string $teamAbbrev): array
    {
        $found = [];
        foreach ($players as $player) {
            $fullName = trim((string) $player->full_name);
            $lastName = trim((string) Str::afterLast($fullName, ' '));
            $teamMatch = mb_strtoupper((string) $player->team_abbrev) === $teamAbbrev;
            $needles = [$fullName];
            if ($teamMatch && mb_strlen($lastName) >= 3) {
                $needles[] = $lastName;
            }

            foreach ($needles as $needle) {
                if (preg_match('/(?<![\pL\pN])' . preg_quote($needle, '/') . '(?![\pL\pN])/iu', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $offset = (int) $match[0][1];
                if (! isset($found[$player->id]) || $offset < $found[$player->id]['offset']) {
                    $found[$player->id] = ['offset' => $offset, 'player' => $player, 'team_match' => $teamMatch];
                }
                break;
            }
        }

        uasort($found, fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return collect($found)
            ->groupBy(fn (array $match): string => (string) $match['offset'])
            ->map(fn (Collection $matches): array => $matches->sortByDesc('team_match')->first())
            ->sortBy('offset')
            ->pluck('player')
            ->map(fn (Player $player): array => [
                'name' => (string) $player->full_name,
                'position' => $player->position ? (string) $player->position : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    private function namesFromDelimitedLine(string $line): array
    {
        $parts = preg_split('/\s+(?:-|–|—|\||\/)\s+|,\s*/u', $line) ?: [];
        if (count($parts) < 2) {
            return [];
        }

        return collect($parts)->map(function (string $name): string {
            $name = preg_replace('/https?:\/\/\S+/iu', '', $name) ?? $name;
            $name = preg_replace('/^[^\pL]+|[^\pL\pN.\' -]+$/u', '', trim($name)) ?? $name;

            return trim($name);
        })->filter(fn (string $name): bool => mb_strlen($name) >= 2
            && mb_strlen($name) <= 60
            && preg_match('/\pL/u', $name) === 1)
            ->values()
            ->all();
    }

    /** @param array<int,array{name:string,position:string|null}> $players */
    private function allAtPosition(array $players, string $position): bool
    {
        return $players !== [] && collect($players)->every(
            fn (array $player): bool => mb_strtoupper((string) $player['position']) === $position
        );
    }

    /** @param array<int,array{name:string,position:string|null}> $players @return array<int,array{name:string,position:string|null}> */
    private function uniquePlayers(array $players): array
    {
        return collect($players)->unique(fn (array $player): string => mb_strtolower($player['name']))
            ->values()->all();
    }

    /** @param array{name:string,position:string|null} $player @return array<string,mixed> */
    private function row(array $player, string $role, string $lineKey, int $slotIndex): array
    {
        return [
            'name' => $player['name'],
            'lineup_role' => $role,
            'line_key' => $lineKey,
            'slot_index' => $slotIndex,
            'power_play_unit' => null,
            'penalty_kill_unit' => null,
        ];
    }
}
