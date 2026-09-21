<?php

declare(strict_types=1);

namespace App\Services;

/** Reads complete X post text and extracts ordered groups of canonical team players. */
class NhlLineupTextParser
{
    public function __construct(
        private readonly NhlLineupPlayerResolver $players,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function parse(string $text, string $teamAbbrev): array
    {
        return $this->analyze($text, $teamAbbrev)['players'];
    }

    /** @return array{players:array<int,array<string,mixed>>,matched_players:array<int,array<string,mixed>>} */
    public function analyze(string $text, string $teamAbbrev): array
    {
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
        $matchedPlayers = [];

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

            $segments = $this->structuredSegments($line);
            if (count($segments) === 3 && count($forwardGroups) < 4) {
                $group = $this->structuredGroup($segments, $teamAbbrev, 'F');
                if ($group !== null) {
                    $forwardGroups[] = $group['players'];
                    array_push($matchedPlayers, ...$group['matched']);
                    continue;
                }
            }
            if (count($segments) === 2 && ($section === 'defense' || count($forwardGroups) >= 4)) {
                $group = $this->structuredGroup($segments, $teamAbbrev, 'D');
                if ($group !== null) {
                    $defenseGroups[] = $group['players'];
                    array_push($matchedPlayers, ...$group['matched']);
                    continue;
                }
            }

            $matches = $this->players->mentions($line, $teamAbbrev);
            if ($matches === []) {
                continue;
            }
            array_push($matchedPlayers, ...$matches);

            if ($section === 'scratch') {
                array_push($scratches, ...$matches);
                continue;
            }
            $lineGoalies = array_values(array_filter(
                $matches,
                fn (array $player): bool => mb_strtoupper((string) $player['position']) === 'G'
            ));
            if ($section === 'goalie' || $lineGoalies !== []) {
                array_push($goalies, ...($section === 'goalie' ? $matches : $lineGoalies));
                $matches = array_values(array_filter(
                    $matches,
                    fn (array $player): bool => mb_strtoupper((string) $player['position']) !== 'G'
                ));
                if ($matches === []) {
                    continue;
                }
            }
            if ($section === 'defense') {
                foreach (array_chunk($matches, 2) as $group) {
                    if (count($group) === 2) {
                        $defenseGroups[] = $group;
                    }
                }
                continue;
            }
            if ($section === 'forward') {
                foreach (array_chunk($matches, 3) as $group) {
                    if (count($group) === 3) {
                        $forwardGroups[] = $group;
                    }
                }
                continue;
            }

            $defensemen = array_values(array_filter(
                $matches,
                fn (array $player): bool => mb_strtoupper((string) $player['position']) === 'D'
            ));
            $forwards = array_values(array_filter(
                $matches,
                fn (array $player): bool => in_array(
                    mb_strtoupper((string) $player['position']),
                    ['C', 'L', 'LW', 'R', 'RW', 'F'],
                    true
                )
            ));
            if (count($defensemen) >= 2) {
                foreach (array_chunk($defensemen, 2) as $group) {
                    if (count($group) === 2) {
                        $defenseGroups[] = $group;
                    }
                }
            }
            if (count($forwards) >= 3) {
                foreach (array_chunk($forwards, 3) as $group) {
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

        return [
            'players' => $result,
            'matched_players' => $this->uniquePlayers($matchedPlayers),
        ];
    }

    /** @return array<int,string> */
    private function structuredSegments(string $line): array
    {
        $segments = preg_split('/\s*[-\x{2013}\x{2014}]\s*/u', trim($line)) ?: [];

        return collect($segments)
            ->map(fn (string $segment): string => trim($segment, " \t\n\r\0\x0B|,;:"))
            ->filter(fn (string $segment): bool => $segment !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $segments
     * @return array{players:array<int,array<string,mixed>>,matched:array<int,array<string,mixed>>}|null
     */
    private function structuredGroup(
        array $segments,
        string $teamAbbrev,
        string $fallbackPosition
    ): ?array {
        $group = [];
        $matched = [];

        foreach ($segments as $segment) {
            $resolved = $this->players->resolve($segment, $teamAbbrev);
            if ($resolved !== null) {
                $player = [
                    'name' => (string) $resolved->full_name,
                    'position' => $resolved->position ? (string) $resolved->position : null,
                    'player_id' => $resolved->id,
                    'nhl_player_id' => $resolved->nhl_id,
                    'team_abbrev' => $resolved->team_abbrev,
                ];
                $group[] = $player;
                $matched[] = $player;
                continue;
            }

            $group[] = [
                'name' => $segment,
                'position' => $fallbackPosition,
                'player_id' => null,
                'nhl_player_id' => null,
                'team_abbrev' => $teamAbbrev,
            ];
        }

        return $matched === [] ? null : ['players' => $group, 'matched' => $matched];
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

    /** @param array<int,array<string,mixed>> $players @return array<int,array<string,mixed>> */
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
            'player_id' => $player['player_id'] ?? null,
            'nhl_player_id' => $player['nhl_player_id'] ?? null,
            'team_abbrev' => $player['team_abbrev'] ?? null,
        ];
    }
}
