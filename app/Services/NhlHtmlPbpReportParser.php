<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parse NHL official HTML play-by-play reports into normalized event rows.
 */
class NhlHtmlPbpReportParser
{
    /**
     * Parse an HTML PBP report.
     *
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = [];
        $headers = [];

        foreach ($document->getElementsByTagName('tr') as $row) {
            $headerCells = $this->cells($row, 'th');

            if ($headerCells === []) {
                $headerCells = $this->headerCellsFromTdRow($row);
            }

            if ($headerCells !== []) {
                $headers = array_map(fn (array $cell): string => $this->normalizeHeader($cell['text']), $headerCells);
                continue;
            }

            $cells = $this->cells($row, 'td');

            if (count($cells) < 4) {
                continue;
            }

            $event = $this->eventFromCells($headers, $cells);

            if ($event !== null) {
                $rows[] = $event;
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        $textFlowRows = $this->parseTextFlow($document->textContent);

        return $textFlowRows !== [] ? $textFlowRows : $this->parseCollapsedTextFlow($document->textContent);
    }

    /**
     * Return normalized cells for a row.
     *
     * @return array<int,array{text:string,html:string}>
     */
    private function cells(\DOMElement $row, string $tag): array
    {
        $cells = [];

        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof \DOMElement || strtolower($cell->tagName) !== $tag) {
                continue;
            }

            $cells[] = [
                'text' => $this->cellText($cell),
                'html' => $this->innerHtml($cell),
            ];
        }

        return $cells;
    }

    /**
     * Some NHL reports render table headers as ordinary cells.
     *
     * @return array<int,array{text:string,html:string}>
     */
    private function headerCellsFromTdRow(\DOMElement $row): array
    {
        $cells = $this->cells($row, 'td');

        if (count($cells) < 5) {
            return [];
        }

        $text = implode(' ', array_map(fn (array $cell): string => $cell['text'], $cells));

        if (! str_contains($text, 'Game Event') && ! str_contains($text, 'On Ice')) {
            return [];
        }

        return $cells;
    }

    /**
     * Build one normalized event row.
     *
     * @param array<int,string> $headers
     * @param array<int,array{text:string,html:string}> $cells
     * @return array<string,mixed>|null
     */
    private function eventFromCells(array $headers, array $cells): ?array
    {
        $indexed = [];

        foreach ($cells as $index => $cell) {
            $key = $headers[$index] ?? (string) $index;
            $indexed[$key] = $cell;
        }

        $eventNumber = $this->firstValue($indexed, ['#', 'event_number', '0']);
        $period = $this->firstValue($indexed, ['per', 'period', '1']);
        $strength = $this->firstValue($indexed, ['str', 'strength', '2']);
        $time = $this->firstValue($indexed, ['time', 'time_elapsed', '3']);
        $elapsed = $this->firstValue($indexed, ['elapsed', '4']);
        $eventType = $this->firstValue($indexed, ['event_type', 'type', '5']);
        $description = $this->firstValue($indexed, ['description', 'desc', '6']);
        $team = $this->teamFromDescription($description);

        if ($time !== null && preg_match('/^(\d{1,2}:\d{2})\s+(\d{1,2}:\d{2})$/', $time, $timeMatches)) {
            $time = $timeMatches[1];
            $elapsed ??= $timeMatches[2];
        }

        if (! is_numeric($period) || ! preg_match('/^\d{1,2}:\d{2}$/', (string) $time)) {
            return null;
        }

        if ($this->shouldSkipReportEventType((string) $eventType)) {
            return null;
        }

        return [
            'event_number' => is_numeric($eventNumber) ? (int) $eventNumber : null,
            'period' => (int) $period,
            'strength' => $strength !== null ? (string) $strength : null,
            'time_in_period' => (string) $time,
            'elapsed' => $elapsed !== null ? (string) $elapsed : null,
            'type' => $this->normalizeEventType((string) $eventType),
            'raw_type' => (string) $eventType,
            'team' => $team !== null ? (string) $team : null,
            'description' => $description !== null ? (string) $description : null,
            'on_ice_players' => $this->onIcePlayers($indexed),
            'raw' => array_map(fn (array $cell): string => $cell['text'], $cells),
        ];
    }

    /**
     * Parse the text-flow shape used by NHL HTML reports when table cells do not align cleanly.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseTextFlow(string $text): array
    {
        $lines = array_values(array_filter(
            array_map(
                fn (string $line): string => trim(preg_replace('/\s+/', ' ', html_entity_decode($line, ENT_QUOTES | ENT_HTML5)) ?? ''),
                preg_split('/\R/', $text) ?: []
            ),
            fn (string $line): bool => $line !== ''
        ));

        $events = [];
        $awayTeam = null;
        $homeTeam = null;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            if (preg_match('/Description\s+([A-Z]{2,4})\s+On Ice\s+([A-Z]{2,4})\s+On Ice/', $lines[$index], $matches)) {
                $awayTeam = $matches[1];
                $homeTeam = $matches[2];
                continue;
            }

            if (! preg_match('/^(\d+)\s+(\d+)\s+(?:(\S+)\s+)?(\d{1,2}:\d{2})$/', $lines[$index], $startMatch)) {
                continue;
            }

            $detailIndex = $index + 1;

            if (! isset($lines[$detailIndex]) || ! preg_match('/^(\d{1,2}:\d{2})\s+([A-Z]+)\s*(.*)$/', $lines[$detailIndex], $detailMatch)) {
                continue;
            }

            $rawType = $detailMatch[2];

            if ($this->shouldSkipReportEventType($rawType)) {
                continue;
            }

            $description = trim($detailMatch[3]);
            $onIceStartIndex = $detailIndex + 1;
            $firstSweater = null;

            if (
                isset($lines[$onIceStartIndex])
                && $this->normalizePosition(strtoupper($lines[$onIceStartIndex])) !== null
                && preg_match('/^(.*?)(?:\s+)(\d{1,2})$/', $description, $sweaterMatch)
            ) {
                $description = trim($sweaterMatch[1]);
                $firstSweater = (int) $sweaterMatch[2];
            }

            [$onIcePlayers, $nextIndex] = $this->textFlowOnIcePlayers(
                $lines,
                $onIceStartIndex,
                $awayTeam,
                $homeTeam,
                $firstSweater
            );

            $events[] = [
                'event_number' => (int) $startMatch[1],
                'period' => (int) $startMatch[2],
                'strength' => isset($startMatch[3]) && $startMatch[3] !== '' ? $startMatch[3] : null,
                'time_in_period' => $startMatch[4],
                'elapsed' => $detailMatch[1],
                'type' => $this->normalizeEventType($rawType),
                'raw_type' => $rawType,
                'team' => $this->teamFromDescription($description),
                'description' => $description,
                'on_ice_players' => $onIcePlayers,
                'raw' => [$lines[$index], $lines[$detailIndex]],
            ];

            $index = max($index, $nextIndex - 1);
        }

        return $events;
    }

    /**
     * Parse NHL reports when DOM text extraction collapses table cells into one long string.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseCollapsedTextFlow(string $text): array
    {
        $normalized = trim(preg_replace(
            '/\s+/',
            ' ',
            html_entity_decode($text, ENT_QUOTES | ENT_HTML5)
        ) ?? '');

        if ($normalized === '') {
            return [];
        }

        preg_match('/Game Event Description\s+([A-Z]{2,4})\s+On Ice\s+([A-Z]{2,4})\s+On Ice/', $normalized, $teamMatches);
        $awayTeam = $teamMatches[1] ?? null;
        $homeTeam = $teamMatches[2] ?? null;

        preg_match_all(
            '/(?:^|\s)(\d+)\s+(\d+)\s+(?:(EV|PP|PK|SH)\s+)?(\d{1,2}:\d{2})\s+(\d{1,2}:\d{2})\s+([A-Z]+)\b/',
            $normalized,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $events = [];
        $eventCount = count($matches[0]);

        for ($index = 0; $index < $eventCount; $index++) {
            $rawType = $matches[6][$index][0];

            if ($this->shouldSkipReportEventType($rawType)) {
                continue;
            }

            $detailStart = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $detailEnd = $matches[0][$index + 1][1] ?? strlen($normalized);
            $remainder = trim(substr($normalized, $detailStart, $detailEnd - $detailStart));
            [$description, $onIcePlayers] = $this->collapsedEventRemainder($remainder, $awayTeam, $homeTeam);

            $events[] = [
                'event_number' => (int) $matches[1][$index][0],
                'period' => (int) $matches[2][$index][0],
                'strength' => $matches[3][$index][0] !== '' ? $matches[3][$index][0] : null,
                'time_in_period' => $matches[4][$index][0],
                'elapsed' => $matches[5][$index][0],
                'type' => $this->normalizeEventType($rawType),
                'raw_type' => $rawType,
                'team' => $this->teamFromDescription($description),
                'description' => $description,
                'on_ice_players' => $onIcePlayers,
                'raw' => [$matches[0][$index][0], $remainder],
            ];
        }

        return $events;
    }

    /**
     * Split collapsed event detail from the trailing on-ice jersey/position sequence.
     *
     * @return array{0:string,1:array<int,array{nhl_player_id:int|null,sweater_number:int|null,position_code:string|null,text:string,side:string,team_abbrev:string|null}>}
     */
    private function collapsedEventRemainder(string $remainder, ?string $awayTeam, ?string $homeTeam): array
    {
        $positionPattern = '(?:LW|RW|LD|RD|C|L|R|D|G)';
        $players = [];
        $description = $remainder;

        if (preg_match('/\s((?:\d{1,2}\s+'.$positionPattern.'\s*){1,12})$/i', $remainder, $matches)) {
            $description = trim(substr($remainder, 0, -strlen($matches[1])));

            if (preg_match_all('/(\d{1,2})\s+('.$positionPattern.')\b/i', $matches[1], $playerMatches, PREG_SET_ORDER)) {
                foreach ($playerMatches as $playerMatch) {
                    $position = $this->normalizePosition(strtoupper($playerMatch[2]));

                    if ($position === null) {
                        continue;
                    }

                    $players[] = $this->textFlowPlayer(
                        (int) $playerMatch[1],
                        $position,
                        count($players),
                        $awayTeam,
                        $homeTeam
                    );
                }
            }
        }

        return [trim($description), $players];
    }

    /**
     * Parse up to two six-player on-ice groups from text-flow report lines.
     *
     * @param array<int,string> $lines
     * @return array{0:array<int,array{nhl_player_id:int|null,sweater_number:int|null,position_code:string|null,text:string,side:string,team_abbrev:string|null}>,1:int}
     */
    private function textFlowOnIcePlayers(array $lines, int $index, ?string $awayTeam, ?string $homeTeam, ?int $firstSweater): array
    {
        $players = [];
        $pendingSweater = $firstSweater;
        $count = count($lines);

        while ($index < $count && count($players) < 12) {
            $line = trim($lines[$index]);

            if ($this->isTextFlowBoundary($line)) {
                break;
            }

            $position = $this->normalizePosition(strtoupper($line));

            if ($position !== null && $pendingSweater !== null) {
                $players[] = $this->textFlowPlayer($pendingSweater, $position, count($players), $awayTeam, $homeTeam);
                $pendingSweater = null;
                $index++;
                continue;
            }

            if (preg_match('/^\d{1,2}$/', $line)) {
                $pendingSweater = (int) $line;
                $index++;
                continue;
            }

            break;
        }

        return [$players, $index];
    }

    /**
     * Build one parsed text-flow on-ice player row.
     *
     * @return array{nhl_player_id:int|null,sweater_number:int|null,position_code:string|null,text:string,side:string,team_abbrev:string|null}
     */
    private function textFlowPlayer(int $sweater, string $position, int $playerIndex, ?string $awayTeam, ?string $homeTeam): array
    {
        $side = $playerIndex < 6 ? 'away' : 'home';

        return [
            'nhl_player_id' => null,
            'sweater_number' => $sweater,
            'position_code' => $position,
            'text' => "{$sweater} {$position}",
            'side' => $side,
            'team_abbrev' => $side === 'away' ? $awayTeam : $homeTeam,
        ];
    }

    private function isTextFlowBoundary(string $line): bool
    {
        return preg_match('/^(\d+)\s+(\d+)\s+(?:(\S+)\s+)?(\d{1,2}:\d{2})$/', $line) === 1
            || str_contains($line, 'Game Event Description')
            || str_starts_with($line, 'Copyright ')
            || in_array($line, ['VISITOR', 'HOME'], true);
    }

    /**
     * Normalize known report header labels.
     */
    private function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9#]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'ev', 'event_no', 'event_number' => 'event_number',
            'per' => 'period',
            'str' => 'strength',
            'event' => 'event_type',
            'desc' => 'description',
            'time_elapsed_game' => 'time',
            default => $normalized,
        };
    }

    /**
     * Normalize NHL HTML event labels toward API type_desc_key values.
     */
    private function normalizeEventType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace([' ', '_'], '-', $type);

        return match ($type) {
            'shot' => 'shot-on-goal',
            'miss' => 'missed-shot',
            'block', 'blocked' => 'blocked-shot',
            'hit' => 'hit',
            'give', 'giveaway' => 'giveaway',
            'take', 'takeaway' => 'takeaway',
            'faceoff', 'fac' => 'faceoff',
            'penalty', 'penl' => 'penalty',
            'delpen' => 'delayed-penalty',
            'goal' => 'goal',
            'stoppage', 'stop' => 'stoppage',
            'soc' => 'shootout-complete',
            'pstr' => 'period-start',
            'pend' => 'period-end',
            'gend' => 'game-end',
            default => $type,
        };
    }

    private function shouldSkipReportEventType(string $type): bool
    {
        return in_array(strtoupper(trim($type)), ['PGSTR', 'PGEND', 'ANTHEM', 'CHL'], true);
    }

    /**
     * Return the first available cell text for candidate keys.
     *
     * @param array<string,array{text:string,html:string}> $indexed
     * @param array<int,string> $keys
     */
    private function firstValue(array $indexed, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (($indexed[$key]['text'] ?? '') !== '') {
                return $indexed[$key]['text'];
            }
        }

        return null;
    }

    /**
     * Extract on-ice player IDs and positions when linked/player text exposes them.
     *
     * @param array<string,array{text:string,html:string}> $indexed
     * @return array<int,array{nhl_player_id:int|null,position_code:string|null,text:string,side:string}>
     */
    private function onIcePlayers(array $indexed): array
    {
        $players = [];
        $iceColumnIndex = 0;

        foreach ($indexed as $key => $cell) {
            if (! str_contains($key, 'ice')) {
                continue;
            }

            $side = str_contains($key, 'home') ? 'home' : (str_contains($key, 'away') ? 'away' : ($iceColumnIndex === 0 ? 'away' : 'home'));
            $teamAbbrev = $this->teamAbbrevFromOnIceHeader($key);
            $players = [
                ...$players,
                ...$this->playersFromOnIceCell($cell['text'], $cell['html'], $side, $teamAbbrev),
            ];
            $iceColumnIndex++;
        }

        return $players;
    }

    /**
     * Parse one on-ice cell.
     *
     * @return array<int,array{nhl_player_id:int|null,sweater_number:int|null,position_code:string|null,text:string,side:string,team_abbrev:string|null}>
     */
    private function playersFromOnIceCell(string $text, string $html, string $side, ?string $teamAbbrev): array
    {
        $ids = [];

        if (preg_match_all('#/player/(\d+)#', $html, $matches)) {
            $ids = array_map('intval', $matches[1]);
        }

        $players = [];

        preg_match_all('/#?(\d{1,2})\s+(LW|C|RW|LD|RD|G|D|L|R)\b/i', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $index => $match) {
            $position = strtoupper($match[2]);
            $sweater = (int) $match[1];

            $players[] = [
                'nhl_player_id' => $ids[$index] ?? null,
                'sweater_number' => $sweater,
                'position_code' => $this->normalizePosition($position),
                'text' => "{$sweater} {$position}",
                'side' => $side,
                'team_abbrev' => $teamAbbrev,
            ];
        }

        return $players;
    }

    private function normalizePosition(string $position): ?string
    {
        return match ($position) {
            'L' => 'LW',
            'R' => 'RW',
            'LW', 'C', 'RW', 'D', 'LD', 'RD', 'G' => $position,
            default => null,
        };
    }

    private function teamAbbrevFromOnIceHeader(string $key): ?string
    {
        if (! str_ends_with($key, '_on_ice')) {
            return null;
        }

        $team = strtoupper((string) preg_replace('/_on_ice$/', '', $key));

        return $team !== '' && ! in_array($team, ['HOME', 'AWAY'], true) ? $team : null;
    }

    private function teamFromDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return preg_match('/^([A-Z]{2,4})\b/', $description, $matches) ? $matches[1] : null;
    }

    private function innerHtml(\DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function cellText(\DOMElement $element): string
    {
        $text = $this->nodeText($element);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function nodeText(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $node->nodeValue ?? '';
        }

        if ($node instanceof \DOMElement && strtolower($node->tagName) === 'br') {
            return ' ';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= ' '.$this->nodeText($child);
        }

        return $text;
    }
}
