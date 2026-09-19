<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlPlayerInjury;
use App\Models\NhlPlayerInjuryObservation;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NhlInjuryImporter
{
    private const CBS_URL = 'https://www.cbssports.com/nhl/injuries/';
    private const ROTOWIRE_URL = 'https://www.rotowire.com/rss/news.php?sport=NHL';

    private const TEAM_ABBREVIATIONS = [
        'Anaheim Ducks' => 'ANA', 'Boston Bruins' => 'BOS', 'Buffalo Sabres' => 'BUF',
        'Calgary Flames' => 'CGY', 'Carolina Hurricanes' => 'CAR', 'Chicago Blackhawks' => 'CHI',
        'Colorado Avalanche' => 'COL', 'Columbus Blue Jackets' => 'CBJ', 'Dallas Stars' => 'DAL',
        'Detroit Red Wings' => 'DET', 'Edmonton Oilers' => 'EDM', 'Florida Panthers' => 'FLA',
        'Los Angeles Kings' => 'LAK', 'Minnesota Wild' => 'MIN', 'Montreal Canadiens' => 'MTL',
        'Nashville Predators' => 'NSH', 'New Jersey Devils' => 'NJD', 'New York Islanders' => 'NYI',
        'New York Rangers' => 'NYR', 'Ottawa Senators' => 'OTT', 'Philadelphia Flyers' => 'PHI',
        'Pittsburgh Penguins' => 'PIT', 'San Jose Sharks' => 'SJS', 'Seattle Kraken' => 'SEA',
        'St. Louis Blues' => 'STL', 'Tampa Bay Lightning' => 'TBL', 'Toronto Maple Leafs' => 'TOR',
        'Utah Mammoth' => 'UTA', 'Utah Hockey Club' => 'UTA', 'Vancouver Canucks' => 'VAN',
        'Vegas Golden Knights' => 'VGK', 'Washington Capitals' => 'WSH', 'Winnipeg Jets' => 'WPG',
        'Anaheim' => 'ANA', 'Boston' => 'BOS', 'Buffalo' => 'BUF', 'Calgary' => 'CGY',
        'Carolina' => 'CAR', 'Chicago' => 'CHI', 'Colorado' => 'COL', 'Columbus' => 'CBJ',
        'Dallas' => 'DAL', 'Detroit' => 'DET', 'Edmonton' => 'EDM', 'Florida' => 'FLA',
        'Los Angeles' => 'LAK', 'Minnesota' => 'MIN', 'Montreal' => 'MTL', 'Nashville' => 'NSH',
        'New Jersey' => 'NJD', 'N.Y. Islanders' => 'NYI', 'N.Y. Rangers' => 'NYR',
        'Ottawa' => 'OTT', 'Philadelphia' => 'PHI', 'Pittsburgh' => 'PIT', 'San Jose' => 'SJS',
        'Seattle' => 'SEA', 'St. Louis' => 'STL', 'Tampa Bay' => 'TBL', 'Toronto' => 'TOR',
        'Utah' => 'UTA', 'Vancouver' => 'VAN', 'Vegas' => 'VGK', 'Washington' => 'WSH',
        'Winnipeg' => 'WPG',
    ];

    public function __construct(
        private readonly RotoWireNhlClient $rotoWire,
        private readonly CbsNhlClient $cbs,
        private readonly NhlAvailabilityPlayerResolver $players
    ) {
    }

    /** @return array{observed:int,current:int,unresolved:int} */
    public function import(): array
    {
        $fetchedAt = now();
        $rows = array_merge(
            $this->parseCbs($this->cbs->injuries(), $fetchedAt),
            $this->parseRotoWire($this->rotoWire->news(), $fetchedAt)
        );
        $observed = 0;
        $unresolved = 0;

        foreach ($rows as &$row) {
            $player = $this->players->resolve($row['player_name'], $row['team_abbrev']);
            $row['player_id'] = $player?->id;
            $row['nhl_player_id'] = $player?->nhl_id;
            $unresolved += $player === null ? 1 : 0;
            $row['meaning_hash'] = hash('sha256', json_encode([
                $row['availability'], $row['body_part'], $row['status_text'],
                $row['anticipated_return_text'], $row['anticipated_return_date'],
            ], JSON_THROW_ON_ERROR));

            $latest = NhlPlayerInjuryObservation::query()
                ->where('provider', $row['provider'])
                ->when($player, fn ($query) => $query->where('player_id', $player->id))
                ->when(! $player, fn ($query) => $query->whereNull('player_id')->where('player_name', $row['player_name']))
                ->latest('fetched_at')
                ->first();

            if ($latest?->meaning_hash !== $row['meaning_hash']) {
                NhlPlayerInjuryObservation::query()->create($row);
                $observed++;
            }
        }
        unset($row);

        $this->reconcile($rows, $fetchedAt);

        return ['observed' => $observed, 'current' => NhlPlayerInjury::query()->count(), 'unresolved' => $unresolved];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function reconcile(array $rows, Carbon $fetchedAt): void
    {
        $latest = collect($rows)
            ->unique(fn (array $row): string => $row['player_id'] !== null
                ? 'player:' . $row['player_id'] . ':' . $row['provider']
                : 'name:' . Str::slug($row['player_name']) . ':' . $row['provider'])
            ->groupBy(fn (array $row): string => $row['player_id'] !== null
                ? 'player:' . $row['player_id']
                : 'name:' . Str::slug($row['player_name']));

        $currentKeys = [];

        foreach ($latest as $providerRows) {
            $primary = $providerRows->sortByDesc(fn (array $row): int => $row['provider'] === 'cbs' ? 2 : 1)->first();
            $sources = $providerRows->pluck('provider')->unique()->values()->all();
            $evidence = count($sources) > 1 ? 'corroborated' : ($primary['provider'] === 'cbs' ? 'reported' : 'suspected');
            if ($primary['availability'] === 'available') {
                continue;
            }
            $attributes = $primary['player_id'] !== null
                ? ['player_id' => $primary['player_id']]
                : ['player_id' => null, 'player_name' => $primary['player_name']];
            $observationQuery = NhlPlayerInjuryObservation::query()
                ->when(
                    $primary['player_id'] !== null,
                    fn ($query) => $query->where('player_id', $primary['player_id']),
                    fn ($query) => $query->whereNull('player_id')->where('player_name', $primary['player_name'])
                )
                ->whereIn('provider', $sources);
            $firstObservedAt = (clone $observationQuery)->min('created_at');
            $lastObservedAt = (clone $observationQuery)->max('created_at');

            $current = NhlPlayerInjury::query()->firstOrNew($attributes);
            $current->fill([
                'nhl_player_id' => $primary['nhl_player_id'],
                'player_name' => $primary['player_name'],
                'team_abbrev' => $primary['team_abbrev'],
                'position' => $primary['position'],
                'body_part' => $primary['body_part'],
                'availability' => $primary['availability'],
                'evidence_level' => $evidence,
                'status_text' => $primary['status_text'],
                'anticipated_return_text' => $primary['anticipated_return_text'],
                'anticipated_return_date' => $primary['anticipated_return_date'],
                'anticipated_return_precision' => $primary['anticipated_return_precision'],
                'sources' => $sources,
                'first_observed_at' => $firstObservedAt ?? $current->first_observed_at ?? $fetchedAt,
                'last_observed_at' => $lastObservedAt ?? $current->last_observed_at ?? $fetchedAt,
                'last_seen_at' => $fetchedAt,
            ])->save();
            $currentKeys[] = $current->id;
        }

        NhlPlayerInjury::query()->whereNotIn('id', $currentKeys)->delete();
    }

    /** @return array<int,array<string,mixed>> */
    private function parseRotoWire(string $xml, Carbon $fetchedAt): array
    {
        $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if ($feed === false) {
            return [];
        }

        $rows = [];
        foreach ($feed->channel->item ?? [] as $item) {
            $text = trim(strip_tags((string) $item->description));
            if (! preg_match('/\b(out|injur|ir\b|ltir|day-to-day|questionable|doubtful|practice|return|available)\b/i', $text)) {
                continue;
            }
            $title = trim((string) $item->title);
            $name = trim((string) Str::before($title, ':'));
            if ($name === '' || $name === $title) {
                continue;
            }
            $availability = $this->availability($text);
            [$returnText, $returnDate, $precision] = $this->returnInfo($text, $fetchedAt);
            $rows[] = [
                'provider' => 'rotowire', 'provider_player_key' => null, 'player_name' => $name,
                'team_abbrev' => null, 'position' => null, 'body_part' => $this->bodyPart($text),
                'availability' => $availability, 'evidence_level' => 'suspected', 'status_text' => $text,
                'anticipated_return_text' => $returnText, 'anticipated_return_date' => $returnDate,
                'anticipated_return_precision' => $precision,
                'provider_published_at' => Carbon::parse((string) $item->pubDate), 'fetched_at' => $fetchedAt,
                'source_url' => (string) $item->link, 'raw_evidence' => ['title' => $title, 'description' => $text],
            ];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function parseCbs(string $html, Carbon $fetchedAt): array
    {
        $document = new DOMDocument();
        @$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            $heading = $xpath->query('preceding::*[self::h2 or self::h3 or contains(@class,"TeamName")][1]', $table)?->item(0);
            $team = $heading ? trim($heading->textContent) : null;
            foreach ($xpath->query('.//tbody/tr', $table) ?: [] as $tr) {
                $cells = [];
                $cellNodes = $xpath->query('./td', $tr);
                foreach ($cellNodes ?: [] as $cell) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
                }
                if (count($cells) < 5 || $cells[0] === '') {
                    continue;
                }
                $playerName = $cellNodes?->item(0) instanceof DOMElement
                    ? $this->cbsPlayerName($xpath, $cellNodes->item(0))
                    : $cells[0];
                $status = $cells[4];
                [$returnText, $returnDate, $precision] = $this->returnInfo($status, $fetchedAt);
                $rows[] = [
                    'provider' => 'cbs', 'provider_player_key' => null, 'player_name' => $playerName,
                    'team_abbrev' => $this->teamAbbrev($team), 'position' => $cells[1], 'body_part' => $cells[3] ?: null,
                    'availability' => $this->availability($status), 'evidence_level' => 'reported', 'status_text' => $status,
                    'anticipated_return_text' => $returnText, 'anticipated_return_date' => $returnDate,
                    'anticipated_return_precision' => $precision, 'provider_published_at' => null, 'fetched_at' => $fetchedAt,
                    'source_url' => self::CBS_URL, 'raw_evidence' => ['updated' => $cells[2], 'cells' => $cells],
                ];
            }
        }
        return $rows;
    }

    private function cbsPlayerName(DOMXPath $xpath, DOMElement $cell): string
    {
        $names = [];
        foreach ($xpath->query('.//a', $cell) ?: [] as $link) {
            $name = trim(preg_replace('/\s+/', ' ', $link->textContent) ?? '');
            if ($name !== '') {
                $names[$name] = mb_strlen($name);
            }
        }

        if ($names === []) {
            return trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
        }

        arsort($names);
        return (string) array_key_first($names);
    }

    private function availability(string $text): string
    {
        return match (true) {
            preg_match('/available|will play|healthy/i', $text) === 1 => 'available',
            preg_match('/out for the season|indefinitely|\bir\b|\bltir\b|ruled out|expected to be out/i', $text) === 1 => 'out',
            preg_match('/questionable|game-time|day-to-day|doubtful/i', $text) === 1 => 'questionable',
            default => 'unknown',
        };
    }

    /** @return array{0:?string,1:?string,2:string} */
    private function returnInfo(string $text, Carbon $now): array
    {
        if (preg_match('/(?:until|return(?:ing)?|targeting)\s+(?:at least\s+)?([A-Z][a-z]{2,8}\.?\s+\d{1,2}(?:,\s+\d{4})?)/i', $text, $match)) {
            $date = Carbon::parse($match[1], $now->timezone);
            if (! str_contains($match[1], (string) $date->year) && $date->lt($now->copy()->subMonths(2))) {
                $date->addYear();
            }
            return [$match[0], $date->toDateString(), str_contains(mb_strtolower($match[0]), 'at least') ? 'not_before' : 'exact_date'];
        }
        if (preg_match('/start of (?:the )?season/i', $text, $match)) {
            return [$match[0], null, 'season_start'];
        }
        if (preg_match('/indefinitely|out for the season/i', $text, $match)) {
            return [$match[0], null, 'indefinite'];
        }
        return [null, null, 'unknown'];
    }

    private function bodyPart(string $text): ?string
    {
        foreach (['upper body', 'lower body', 'concussion', 'knee', 'shoulder', 'ankle', 'wrist', 'hand', 'back', 'illness'] as $part) {
            if (str_contains(mb_strtolower($text), $part)) {
                return $part;
            }
        }
        return null;
    }

    private function teamAbbrev(?string $team): ?string
    {
        if ($team === null) {
            return null;
        }
        $team = trim(preg_replace('/\s+/', ' ', $team) ?? $team);
        return self::TEAM_ABBREVIATIONS[$team] ?? null;
    }
}
