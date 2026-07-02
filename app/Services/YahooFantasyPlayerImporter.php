<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\YahooFantasyConnection;
use App\Models\YahooPlayer;
use RuntimeException;
use SimpleXMLElement;

/**
 * Imports Yahoo Fantasy hockey player collection pages into provider-owned storage.
 */
class YahooFantasyPlayerImporter
{
    public function __construct(
        private readonly PlayerIdentityResolver $resolver,
        private readonly YahooFantasyClient $client,
    ) {
    }

    /**
     * Import Yahoo Fantasy hockey players using a persisted Yahoo connection.
     *
     * @return array<string,mixed>
     */
    public function import(YahooFantasyConnection $connection, int $limit = 1000, int $pageSize = 25): array
    {
        $limit = max(1, min($limit, (int) config('yahoo.fantasy.players_import_max', 2000)));
        $pageSize = max(1, min($pageSize, 25));

        $gameXml = $this->client->fantasyXmlForConnection($connection, 'game/'.config('yahoo.fantasy.game_code', 'nhl'));
        $gameKey = $this->firstText($gameXml, 'game_key');

        if ($gameKey === null) {
            throw new RuntimeException('Yahoo Fantasy API game response did not include a game key.');
        }

        $imported = 0;
        $start = 0;
        $players = [];

        while ($imported < $limit) {
            $count = min($pageSize, $limit - $imported);
            $page = $this->importPage($connection, $gameKey, $start, $count);

            if ($page['page_count'] === 0) {
                break;
            }

            $players = array_merge($players, $page['players']);
            $imported += $page['imported'];

            if ($page['page_count'] < $count) {
                break;
            }

            $start += $count;
        }

        return [
            'game' => [
                'game_key' => $gameKey,
                'game_id' => $this->firstText($gameXml, 'game_id'),
                'code' => $this->firstText($gameXml, 'code'),
                'name' => $this->firstText($gameXml, 'name'),
                'season' => $this->firstText($gameXml, 'season'),
            ],
            'requested_limit' => $limit,
            'page_size' => $pageSize,
            'imported' => $imported,
            'total_yahoo_players' => YahooPlayer::query()->count(),
            'players' => collect($players)
                ->take(5)
                ->map(fn (YahooPlayer $player): array => [
                    'player_key' => $player->player_key,
                    'yahoo_player_id' => $player->yahoo_player_id,
                    'full_name' => $player->full_name,
                    'editorial_team_abbr' => $player->editorial_team_abbr,
                    'display_position' => $player->display_position,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Resolve the current Yahoo Fantasy hockey game key for the configured game code.
     */
    public function gameKey(YahooFantasyConnection $connection): string
    {
        $gameXml = $this->client->fantasyXmlForConnection($connection, 'game/'.config('yahoo.fantasy.game_code', 'nhl'));
        $gameKey = $this->firstText($gameXml, 'game_key');

        if ($gameKey === null) {
            throw new RuntimeException('Yahoo Fantasy API game response did not include a game key.');
        }

        return $gameKey;
    }

    /**
     * Import one Yahoo Fantasy players collection page.
     *
     * @return array{
     *     start: int,
     *     count: int,
     *     page_count: int,
     *     imported: int,
     *     skipped: int,
     *     players: array<int,YahooPlayer>
     * }
     */
    public function importPage(YahooFantasyConnection $connection, string $gameKey, int $start, int $count): array
    {
        $start = max(0, $start);
        $count = max(1, min($count, 25));
        $path = $this->collectionPath("game/{$gameKey}/players", [
            'start' => $start,
            'count' => $count,
        ]);

        $playersXml = $this->client->fantasyXmlForConnection($connection, $path);
        $pagePlayers = $playersXml->xpath('//*[local-name()="player"]') ?: [];
        $players = [];
        $imported = 0;
        $skipped = 0;

        foreach ($pagePlayers as $playerXml) {
            if (! $playerXml instanceof SimpleXMLElement) {
                $skipped++;
                continue;
            }

            $payload = $this->playerPayload($gameKey, $playerXml);

            if ($payload['player_key'] === null || $payload['yahoo_player_id'] === null) {
                $skipped++;
                continue;
            }

            $player = YahooPlayer::updateOrCreate(
                ['player_key' => $payload['player_key']],
                [
                    'game_key' => $gameKey,
                    'yahoo_player_id' => $payload['yahoo_player_id'],
                    'full_name' => $payload['full_name'],
                    'first_name' => $payload['first_name'],
                    'last_name' => $payload['last_name'],
                    'editorial_team_abbr' => $payload['editorial_team_abbr'],
                    'display_position' => $payload['display_position'],
                    'eligible_positions' => $payload['eligible_positions'],
                    'raw_payload' => $payload['raw_payload'],
                    'imported_at' => now(),
                ],
            );
            $identity = $this->resolver->upsertYahooIdentity($player);
            $identity = $this->resolver->resolveNonAuthorityIdentity($identity);

            $player->update([
                'player_external_identity_id' => $identity->id,
                'player_id' => $identity->player_id,
            ]);

            $players[] = $player;
            $imported++;
        }

        return [
            'start' => $start,
            'count' => $count,
            'page_count' => count($pagePlayers),
            'imported' => $imported,
            'skipped' => $skipped,
            'players' => $players,
        ];
    }

    /**
     * Build a Yahoo collection path with semicolon-delimited parameters.
     *
     * @param array<string,int|string> $parameters
     */
    private function collectionPath(string $path, array $parameters): string
    {
        $segments = [];

        foreach ($parameters as $key => $value) {
            $segments[] = $key.'='.rawurlencode((string) $value);
        }

        return $path.';'.implode(';', $segments);
    }

    /**
     * Extract a normalized Yahoo player payload from XML.
     *
     * @return array<string,mixed>
     */
    private function playerPayload(string $gameKey, SimpleXMLElement $player): array
    {
        return [
            'game_key' => $gameKey,
            'player_key' => $this->firstText($player, 'player_key'),
            'yahoo_player_id' => $this->firstText($player, 'player_id'),
            'full_name' => $this->firstText($player, 'full'),
            'first_name' => $this->firstText($player, 'first'),
            'last_name' => $this->firstText($player, 'last'),
            'editorial_team_abbr' => $this->firstText($player, 'editorial_team_abbr'),
            'display_position' => $this->firstText($player, 'display_position'),
            'eligible_positions' => $this->eligiblePositions($player),
            'raw_payload' => $this->xmlToArray($player),
        ];
    }

    /**
     * Return the first descendant text value matching a local XML element name.
     */
    private function firstText(SimpleXMLElement $xml, string $localName): ?string
    {
        $nodes = $xml->xpath('.//*[local-name()="'.$localName.'"]') ?: [];
        $value = trim((string) ($nodes[0] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Return Yahoo eligible positions for one player.
     *
     * @return array<int,string>
     */
    private function eligiblePositions(SimpleXMLElement $player): array
    {
        $nodes = $player->xpath('.//*[local-name()="eligible_positions"]/*[local-name()="position"]') ?: [];

        return collect($nodes)
            ->map(static fn (SimpleXMLElement $node): string => trim((string) $node))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Convert Yahoo XML into an auditable provider payload array.
     *
     * @return array<string,mixed>
     */
    private function xmlToArray(SimpleXMLElement $xml): array
    {
        $encoded = json_encode($xml, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
