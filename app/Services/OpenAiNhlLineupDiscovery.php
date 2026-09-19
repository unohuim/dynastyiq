<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Performs one bounded text-only web discovery request for an NHL team lineup. */
class OpenAiNhlLineupDiscovery
{
    /** @return array<int,array<string,mixed>> */
    public function discover(object $game, string $teamAbbrev, array $knownSources = []): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $maxToolCalls = max(1, (int) config('services.openai.lineup_max_tool_calls', 6));
        $usedToday = (int) DB::table('integration_api_usage_logs')
            ->where('provider', 'openai')
            ->where('operation', 'nhl_lineup_discovery')
            ->where('occurred_at', '>=', today())
            ->sum('tool_calls');
        if ($usedToday + $maxToolCalls > (int) config('services.openai.lineup_daily_search_limit', 200)) {
            throw new RuntimeException('The daily OpenAI lineup web-search limit has been reached.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout_seconds', 120))
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model', 'gpt-5.6-terra'),
                'instructions' => $this->instructions(),
                'input' => $this->prompt($game, $teamAbbrev, $knownSources),
                'tools' => [['type' => 'web_search']],
                'max_tool_calls' => $maxToolCalls,
                'max_output_tokens' => (int) config('services.openai.lineup_max_output_tokens', 6000),
                'text' => ['format' => $this->responseFormat()],
                'include' => ['web_search_call.action.sources'],
                'store' => false,
            ])->throw();

        $payload = $response->json();
        $toolCalls = collect($payload['output'] ?? [])->where('type', 'web_search_call')->count();
        DB::table('integration_api_usage_logs')->insert([
            'provider' => 'openai',
            'operation' => 'nhl_lineup_discovery',
            'provider_request_id' => $payload['id'] ?? null,
            'input_tokens' => (int) data_get($payload, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($payload, 'usage.output_tokens', 0),
            'tool_calls' => $toolCalls,
            'metadata' => json_encode(['nhl_game_id' => $game->nhl_game_id, 'team_abbrev' => $teamAbbrev]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $decoded = json_decode($this->outputText($payload), true, 512, JSON_THROW_ON_ERROR);

        return collect($decoded['observations'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && trim((string) ($row['post_text'] ?? '')) !== '')
            ->values()
            ->all();
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You extract anticipated NHL lineups from recent public web posts. Use only text visibly attributed to the cited post. Never infer a lineup from an image, roster page, depth chart, or your own hockey knowledge. Skip image-only posts. A source is an account or publisher, not each post. Prefer posts published today by reporters observing morning skate or team practice. Find up to three independent sources, checking the supplied known sources first and also trying at least one source outside that list. Return only posts that actually state a lineup or an ordered player list. Preserve the exact post URL, post text, publication timestamp, author identity, and engagement values when the search result exposes them. Map twelve ordered forwards in consecutive groups of three to F1-F4. Map six ordered defensemen in consecutive pairs to D1-D3. Use G for goalies and SCR for scratches. Within each group, slot_index starts at one. Do not manufacture missing players or engagement values.
PROMPT;
    }

    /** @param array<int,array<string,mixed>> $knownSources */
    private function prompt(object $game, string $teamAbbrev, array $knownSources): string
    {
        $opponent = $teamAbbrev === mb_strtoupper((string) $game->home_team_abbrev)
            ? mb_strtoupper((string) $game->away_team_abbrev)
            : mb_strtoupper((string) $game->home_team_abbrev);

        return sprintf(
            'Find text-based anticipated lineup posts for %s for NHL game %s versus %s on %s. Scheduled start UTC: %s. Previously useful sources: %s.',
            $teamAbbrev,
            $game->nhl_game_id,
            $opponent,
            $game->game_date,
            $game->start_time_utc,
            json_encode($knownSources, JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<string,mixed> */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'nhl_lineup_observations',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['observations'],
                'properties' => [
                    'observations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['platform', 'source_name', 'source_handle', 'source_url', 'followers', 'following', 'post_count', 'post_url', 'post_text', 'published_at', 'engagement', 'players'],
                            'properties' => [
                                'platform' => ['type' => 'string'],
                                'source_name' => ['type' => 'string'],
                                'source_handle' => ['type' => ['string', 'null']],
                                'source_url' => ['type' => 'string'],
                                'followers' => ['type' => ['integer', 'null']],
                                'following' => ['type' => ['integer', 'null']],
                                'post_count' => ['type' => ['integer', 'null']],
                                'post_url' => ['type' => 'string'],
                                'post_text' => ['type' => 'string'],
                                'published_at' => ['type' => ['string', 'null']],
                                'engagement' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['likes', 'replies', 'reposts', 'views'],
                                    'properties' => [
                                        'likes' => ['type' => ['integer', 'null']],
                                        'replies' => ['type' => ['integer', 'null']],
                                        'reposts' => ['type' => ['integer', 'null']],
                                        'views' => ['type' => ['integer', 'null']],
                                    ],
                                ],
                                'players' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'required' => ['name', 'lineup_role', 'line_key', 'slot_index'],
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'lineup_role' => ['type' => 'string', 'enum' => ['forward', 'defense', 'goalie', 'scratch']],
                                            'line_key' => ['type' => 'string', 'enum' => ['F1', 'F2', 'F3', 'F4', 'D1', 'D2', 'D3', 'G', 'SCR']],
                                            'slot_index' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function outputText(array $payload): string
    {
        foreach ($payload['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    return (string) ($content['text'] ?? '');
                }
            }
        }

        throw new RuntimeException('OpenAI returned no structured lineup output.');
    }
}
