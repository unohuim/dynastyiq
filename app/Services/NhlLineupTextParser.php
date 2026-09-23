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
        $roster = $this->rosterBlock($text);

        return $roster === null
            ? ['players' => [], 'matched_players' => []]
            : $this->analyzeRoster($roster, $teamAbbrev);
    }

    /** Find exactly twelve forward names followed by six defense names before any identity lookup. */
    private function rosterBlock(string $text): ?string
    {
        $lines = preg_split('/\R/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?: [];
        $blocks = [];
        $names = [];
        $invalid = false;
        $supplemental = false;
        $grouped = null;
        $defenseStarted = false;
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $heading = $this->heading($line);
            if ($heading === 'defense') {
                $invalid = $invalid || count($names) !== 12;
                $defenseStarted = true;
                continue;
            }
            if ($heading !== null) {
                if ($names !== []) {
                    $blocks[] = [$names, $invalid, $index];
                }
                $names = [];
                $invalid = false;
                $grouped = null;
                $defenseStarted = false;
                $supplemental = in_array($heading, ['scratch', 'goalie'], true);
                continue;
            }
            if ($supplemental) {
                continue;
            }
            if (count($names) === 18) {
                $blocks[] = [$names, $invalid, $index];
                $names = [];
                $supplemental = true;
                continue;
            }
            if (count($names) === 12 && ! $defenseStarted) {
                $extraForwards = $this->rosterCells($line, 3, $grouped);
                if ($grouped === true && count($extraForwards ?? []) === 3) {
                    continue;
                }
                // With individually listed names, an explicit defense heading defines
                // the boundary; surplus forwards before it must not become defensemen.
                if ($grouped === false) {
                    $nextHeading = collect(array_slice($lines, $index))->map(fn (string $item): ?string => $this->heading(trim($item)))->filter()->first();
                    if ($nextHeading === 'defense') {
                        continue;
                    }
                }
            }
            $size = count($names) < 12 ? 3 : 2;
            // Do not reinterpret a standalone full name as two bare surnames in
            // an otherwise grouped, full-name roster.
            $fullNameStyle = $grouped === true && collect($names)->contains(fn (string $name): bool => str_contains($name, ' '));
            $cells = $this->rosterCells($line, $size, $grouped, ! $fullNameStyle);
            if ($grouped === true && count($cells ?? []) === 1) {
                continue;
            }
            if ($cells === null) {
                if ($names !== []) {
                    $blocks[] = [$names, $invalid, $index];
                }
                $names = [];
                $invalid = false;
                $grouped = null;
                $defenseStarted = false;
                continue;
            }
            $defenseStarted = $defenseStarted || count($names) >= 12;
            $boundary = count($names) < 12 ? 12 : 18;
            $grouped ??= count($cells) > 1;
            $invalid = $invalid || count($names) + count($cells) > $boundary;
            array_push($names, ...$cells);
        }
        $blocks[] = [$names, $invalid, count($lines)];
        $blocks = array_values(array_filter($blocks, fn (array $block): bool =>
            ! $block[1] && count($block[0]) === 18));
        if (count($blocks) !== 1) {
            return null;
        }
        [$names, , $end] = $blocks[0];
        $roster = ['Forwards'];
        foreach (array_chunk(array_slice($names, 0, 12), 3) as $group) {
            $roster[] = implode(' | ', $group);
        }
        $roster[] = 'Defense';
        foreach (array_chunk(array_slice($names, 12), 2) as $group) {
            $roster[] = implode(' | ', $group);
        }

        return implode("\n", array_merge($roster, array_slice($lines, $end)));
    }

    /** Read name-shaped cells, not player mentions in sentences. @return array<int,string>|null */
    private function rosterCells(string $line, int $size, ?bool $grouped = null, bool $allowLooseWords = true): ?array
    {
        $line = preg_replace('/^(?:[FD]?[0-9]+[.):]|[FD][0-9]+\s*:)\s*/iu', '', $line) ?? $line;
        $cells = preg_split('/\s+[-–—]\s*|\s*[-–—]\s+|\s*[–—,;|\t]\s*| {2,}/u', $line) ?: [];
        if (count($cells) !== $size && $grouped !== false && str_contains($line, '-')) {
            // Expand tight separators only when they produce exactly the expected group.
            // Otherwise retain in-name hyphens rather than inventing extra roster slots.
            $expanded = array_merge(...array_map(fn (string $cell): array => explode('-', $cell), $cells));
            if (count($expanded) === $size) {
                $cells = $expanded;
            }
        }
        if ($allowLooseWords && count($cells) === 1 && $grouped !== false && ! str_contains($line, '-')) {
            $words = preg_split('/\s+/u', $line) ?: [];
            if (count($words) === $size && ($size === 3 || $grouped === true) && ! preg_match('/\d/', $line)) {
                $cells = $words;
            }
        }
        $cells = array_map('trim', $cells);
        if (! in_array(count($cells), [1, $size], true)) {
            return null;
        }
        foreach ($cells as $cell) {
            if (! preg_match("/^[\pL\pN][\pL\pM\pN.'’`-]*(?: [\pL\pN][\pL\pM\pN.'’`-]*){0,3}$/u", $cell)
                || preg_match('/\b(?:is|are|was|were|with|without|will|today|tomorrow|tonight|skating|playing|lines|lineup)\b/iu', $cell)) {
                return null;
            }
        }

        return $cells;
    }

    /** Resolve names only after a complete single-post roster block has been identified. @return array<string,array> */
    private function analyzeRoster(string $text, string $teamAbbrev): array
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

            if (count($forwardGroups) === 4 && count($defenseGroups) === 3
                && ! in_array($section, ['scratch', 'goalie'], true)) {
                $tailPlayers = $this->players->mentions($line, $teamAbbrev);
                $tailGoalies = array_values(array_filter($tailPlayers,
                    fn (array $player): bool => strtoupper((string) $player['position']) === 'G'));
                array_push($goalies, ...$tailGoalies);
                array_push($matchedPlayers, ...$tailGoalies);
                continue;
            }

            $segments = $this->structuredSegments($line);
            if ($section !== 'scratch' && $section !== 'goalie' && count($segments) === 3 && count($forwardGroups) < 4) {
                $group = $this->structuredGroup($segments, $teamAbbrev, 'F');
                if ($group !== null) {
                    $forwardGroups[] = $group['players'];
                    array_push($matchedPlayers, ...$group['matched']);
                    continue;
                }
            }
            if ($section !== 'scratch' && $section !== 'goalie' && count($segments) === 2 && count($defenseGroups) < 3 && ($section === 'defense' || count($forwardGroups) >= 4)) {
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
        $segments = str_contains($line, '|')
            ? explode('|', $line)
            : (preg_split('/\s*[-\x{2013}\x{2014}]\s*/u', trim($line)) ?: []);

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
            preg_match('/^(f|forwards?|forards|forward lines?|lines?)$/u', $normalized) === 1 => 'forward',
            preg_match('/^(d|defen[cs]e|defen[cs]emen|d pairs?|pairings?)$/u', $normalized) === 1 => 'defense',
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
