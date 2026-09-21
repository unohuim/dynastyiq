<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ImportStreamEvent;
use App\Models\NhlLineupObservation;
use App\Models\NhlTeam;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;

/** Searches X directly for recent NHL lineup posts and normalizes their public metadata. */
class XNhlLineupDiscovery
{
    private ?string $streamBatchId = null;

    public function __construct(private readonly NhlLineupTextParser $parser)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function discover(object $game, string $teamAbbrev, ?string $streamBatchId = null): array
    {
        $this->streamBatchId = $streamBatchId;
        $bearerToken = (string) config('services.x.bearer_token');
        if ($bearerToken === '') {
            throw new RuntimeException('X_BEARER_TOKEN is not configured.');
        }

        $sources = DB::table('sources')
            ->join('source_scopes', 'source_scopes.source_id', '=', 'sources.id')
            ->where('sources.platform', 'x')
            ->where('source_scopes.sport', 'hockey')
            ->where('source_scopes.league', 'NHL')
            ->where('source_scopes.team_abbrev', mb_strtoupper($teamAbbrev))
            ->where('source_scopes.is_active', true)
            ->whereNotNull('sources.handle')
            ->orderBy('sources.id')
            ->get(['sources.id', 'sources.name', 'sources.handle', 'sources.platform_user_id'])
            ->unique('id');

        $states = $sources->map(fn (object $source): array => [
            'source' => $source,
            'pagination_token' => null,
            'seen_tokens' => [],
            'round' => 1,
            'exhausted' => false,
        ])->values()->all();

        while (collect($states)->contains(fn (array $state): bool => ! $state['exhausted'])) {
            foreach ($states as $index => $state) {
                if ($state['exhausted']) {
                    continue;
                }

                $page = $this->timelinePage(
                    $game,
                    $teamAbbrev,
                    $bearerToken,
                    $state['source'],
                    $state['pagination_token'],
                    $state['round']
                );
                if ($page['candidates'] !== []) {
                    return $page['candidates'];
                }

                $nextToken = $page['next_token'];
                $alreadySeen = $nextToken !== null && in_array($nextToken, $state['seen_tokens'], true);
                $states[$index]['exhausted'] = $nextToken === null || $alreadySeen;
                if ($nextToken !== null && ! $alreadySeen) {
                    $states[$index]['seen_tokens'][] = $nextToken;
                    $states[$index]['pagination_token'] = $nextToken;
                    $states[$index]['round']++;
                }
            }
        }

        return [];
    }

    /** @return array{candidates:array<int,array<string,mixed>>,next_token:?string} */
    private function timelinePage(
        object $game,
        string $teamAbbrev,
        string $bearerToken,
        object $source,
        ?string $paginationToken,
        int $round
    ): array {
        $handle = ltrim(trim((string) $source->handle), '@');
        $userId = trim((string) $source->platform_user_id);
        if ($userId === '') {
            $lookup = Http::withToken($bearerToken)
                ->acceptJson()
                ->timeout((int) config('services.x.timeout_seconds', 30))
                ->get('https://api.x.com/2/users/by/username/' . rawurlencode($handle), [
                    'user.fields' => 'id,name,username,public_metrics,url',
                ]);
            if ($lookup->status() === 404) {
                return ['candidates' => [], 'next_token' => null];
            }
            $lookup->throw();
            $userId = trim((string) data_get($lookup->json(), 'data.id'));
            if ($userId === '') {
                return ['candidates' => [], 'next_token' => null];
            }
            DB::table('sources')->where('id', $source->id)->update([
                'platform_user_id' => $userId,
                'updated_at' => now(),
            ]);
        }

        $firstPost = (($round - 1) * 5) + 1;
        $lastPost = $round * 5;
        $context = 'timeline:@' . $handle . " posts {$firstPost}-{$lastPost}";
        $this->output("{$teamAbbrev} | @{$handle} | posts {$firstPost}-{$lastPost}");
        $parameters = [
            'max_results' => 5,
            'start_time' => Carbon::parse((string) $game->game_date, 'America/Toronto')
                ->subDay()->startOfDay()->utc()->toIso8601ZuluString(),
            'tweet.fields' => 'id,text,note_tweet,author_id,created_at,public_metrics,attachments,referenced_tweets',
            'expansions' => 'attachments.media_keys',
            'media.fields' => 'media_key,type,url,preview_image_url,alt_text',
        ];
        if ($paginationToken !== null) {
            $parameters['pagination_token'] = $paginationToken;
        }
        $response = Http::withToken($bearerToken)
            ->acceptJson()
            ->timeout((int) config('services.x.timeout_seconds', 30))
            ->get("https://api.x.com/2/users/{$userId}/tweets", $parameters);
        if ($response->status() === 404) {
            return ['candidates' => [], 'next_token' => null];
        }
        $response->throw();

        return [
            'candidates' => $this->evaluateResponse(
                $response,
                $game,
                $teamAbbrev,
                $context,
                'timeline',
                [
                    (string) $userId => [
                        'id' => (string) $userId,
                        'name' => (string) $source->name,
                        'username' => $handle,
                    ],
                ]
            ),
            'next_token' => filled(data_get($response->json(), 'meta.next_token'))
                ? (string) data_get($response->json(), 'meta.next_token')
                : null,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $knownUsers
     * @return array<int,array<string,mixed>>
     */
    private function evaluateResponse(
        Response $response,
        object $game,
        string $teamAbbrev,
        string $context,
        string $requestType,
        array $knownUsers = []
    ): array {
        $payload = $response->json();
        $users = collect([...array_values($knownUsers), ...data_get($payload, 'includes.users', [])])->keyBy('id');
        $media = collect(data_get($payload, 'includes.media', []))->keyBy('media_key');

        DB::table('integration_api_usage_logs')->insert([
            'provider' => 'x',
            'operation' => 'nhl_lineup_source_timeline',
            'provider_request_id' => $response->header('x-request-id'),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'tool_calls' => 1,
            'metadata' => json_encode([
                'nhl_game_id' => $game->nhl_game_id,
                'team_abbrev' => $teamAbbrev,
                'context' => $context,
                'request_type' => $requestType,
                'posts_returned' => count($payload['data'] ?? []),
            ]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidates = collect($payload['data'] ?? [])->map(function (array $post) use ($users, $media, $teamAbbrev): array {
            $author = $users->get((string) ($post['author_id'] ?? ''), []);
            $username = trim((string) ($author['username'] ?? ''));
            $metrics = $post['public_metrics'] ?? [];
            $attachments = collect(data_get($post, 'attachments.media_keys', []))
                ->map(fn (string $key): mixed => $media->get($key))
                ->filter()
                ->values()
                ->all();

            $postText = (string) data_get($post, 'note_tweet.text', $post['text'] ?? '');
            $analysis = $this->parser->analyze($postText, $teamAbbrev);

            return [
                'platform' => 'x',
                'source_name' => (string) ($author['name'] ?? ($username ?: 'X')),
                'source_handle' => $username !== '' ? $username : null,
                'source_url' => $username !== '' ? "https://x.com/{$username}" : 'https://x.com',
                'followers' => data_get($author, 'public_metrics.followers_count'),
                'following' => data_get($author, 'public_metrics.following_count'),
                'post_count' => data_get($author, 'public_metrics.tweet_count'),
                'post_url' => $username !== ''
                    ? sprintf('https://x.com/%s/status/%s', $username, $post['id'])
                    : sprintf('https://x.com/i/status/%s', $post['id']),
                'post_text' => $postText,
                'published_at' => $post['created_at'] ?? null,
                'engagement' => [
                    'likes' => $metrics['like_count'] ?? null,
                    'replies' => $metrics['reply_count'] ?? null,
                    'reposts' => $metrics['retweet_count'] ?? null,
                    'views' => $metrics['impression_count'] ?? null,
                ],
                'players' => $analysis['players'],
                'matched_players' => $analysis['matched_players'],
                'media' => $attachments,
                'provider_post_id' => (string) $post['id'],
                'raw_post' => $post,
            ];
        })->map(function (array $candidate) use ($game, $teamAbbrev, $context, $requestType): array {
            $decision = $this->decision($candidate, $game, $teamAbbrev);
            $candidate['audit_decision'] = $decision['approved'] ? 'approved' : 'declined';
            $candidate['audit_reason'] = $decision['reason'];
            $this->writeLocalAudit($candidate, $game, $teamAbbrev, $context, $requestType);

            return $candidate;
        });
        $this->writeLocalSearchAudit($candidates->all(), $game, $teamAbbrev, $context, $requestType);

        return $candidates
            ->filter(fn (array $candidate): bool => $candidate['audit_decision'] === 'approved')
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $candidate @return array{approved:bool,reason:string} */
    private function decision(array $candidate, object $game, string $teamAbbrev): array
    {
        $publishedAt = filled($candidate['published_at'] ?? null)
            ? Carbon::parse((string) $candidate['published_at'])
            : now();
        if ($publishedAt->lt(NhlLineupObservation::evidenceCutoff((string) $game->game_date))) {
            return [
                'approved' => false,
                'reason' => 'The post predates the allowed day-before-game evidence window.',
            ];
        }

        $matchedCount = count($candidate['matched_players'] ?? []);
        if ($matchedCount === 0) {
            return ['approved' => false, 'reason' => 'No target-team players were recognized in the complete post.'];
        }
        if ($matchedCount === 1) {
            return ['approved' => false, 'reason' => 'Only one target-team player was recognized; no lineup group exists.'];
        }

        $players = collect($candidate['players'] ?? []);
        $counts = $players->countBy('lineup_role');
        $forwardCount = (int) ($counts['forward'] ?? 0);
        $defenseCount = (int) ($counts['defense'] ?? 0);
        if ($forwardCount < 12 || $defenseCount < 6) {
            return [
                'approved' => false,
                'reason' => sprintf(
                    'Incomplete lineup: parsed %d of 12 forwards and %d of 6 defensemen.',
                    $forwardCount,
                    $defenseCount
                ),
            ];
        }

        $gameDecision = $this->gameDecision((string) $candidate['post_text'], $game, $teamAbbrev);
        if (! $gameDecision['approved']) {
            return $gameDecision;
        }

        $counts = $players->countBy('lineup_role');

        return [
            'approved' => true,
            'reason' => $gameDecision['reason'] . ' ' . sprintf(
                'Parsed %d forwards, %d defensemen, and %d goalies for %s.',
                (int) ($counts['forward'] ?? 0),
                (int) ($counts['defense'] ?? 0),
                (int) ($counts['goalie'] ?? 0),
                $teamAbbrev
            ),
        ];
    }

    private function output(string $message): void
    {
        if (! app()->environment('testing')) {
            (new ConsoleOutput())->writeln('[lineups] ' . $message);
        }

        ImportStreamEvent::dispatch('nhl-anticipated-lineups', $message, 'output', $this->streamBatchId);
    }

    /** @return array{approved:bool,reason:string} */
    private function gameDecision(string $postText, object $game, string $teamAbbrev): array
    {
        $sameDayGames = DB::table('nhl_games')
            ->whereDate('game_date', (string) $game->game_date)
            ->where(fn ($query) => $query->where('home_team_abbrev', $teamAbbrev)
                ->orWhere('away_team_abbrev', $teamAbbrev))
            ->get(['nhl_game_id', 'home_team_abbrev', 'away_team_abbrev']);
        if ($sameDayGames->count() < 2) {
            return ['approved' => true, 'reason' => 'The team has one game on the target date.'];
        }

        $opponentAbbrev = $teamAbbrev === mb_strtoupper((string) $game->home_team_abbrev)
            ? mb_strtoupper((string) $game->away_team_abbrev)
            : mb_strtoupper((string) $game->home_team_abbrev);
        $opponent = NhlTeam::query()->where('abbrev', $opponentAbbrev)->first();
        $identifiers = array_filter([
            $opponentAbbrev,
            $opponent?->common_name,
            $opponent?->full_name,
            $opponent?->place_name,
        ]);

        if (collect($identifiers)->contains(
            fn (string $identifier): bool => preg_match(
                '/(?<![\pL\pN])' . preg_quote($identifier, '/') . '(?![\pL\pN])/iu',
                $postText
            ) === 1
        )) {
            return ['approved' => true, 'reason' => "The post identifies the target opponent {$opponentAbbrev}."];
        }

        foreach ($sameDayGames as $sameDayGame) {
            if ((int) $sameDayGame->nhl_game_id === (int) $game->nhl_game_id) {
                continue;
            }
            $otherOpponent = $teamAbbrev === mb_strtoupper((string) $sameDayGame->home_team_abbrev)
                ? mb_strtoupper((string) $sameDayGame->away_team_abbrev)
                : mb_strtoupper((string) $sameDayGame->home_team_abbrev);
            $otherTeam = NhlTeam::query()->where('abbrev', $otherOpponent)->first();
            $otherIdentifiers = array_filter([
                $otherOpponent,
                $otherTeam?->common_name,
                $otherTeam?->full_name,
                $otherTeam?->place_name,
            ]);
            if (collect($otherIdentifiers)->contains(fn (string $identifier): bool => preg_match(
                '/(?<![\pL\pN])' . preg_quote($identifier, '/') . '(?![\pL\pN])/iu',
                $postText
            ) === 1)) {
                return [
                    'approved' => false,
                    'reason' => "The post identifies split-squad opponent {$otherOpponent}, not {$opponentAbbrev}.",
                ];
            }
        }

        return [
            'approved' => false,
            'reason' => 'The team has multiple games on this date and the post does not identify the target matchup.',
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function writeLocalAudit(
        array $candidate,
        object $game,
        string $teamAbbrev,
        string $context,
        string $requestType
    ): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $directory = base_path('docs/troubleshooting/lineups/' . mb_strtoupper($teamAbbrev));
        File::ensureDirectoryExists($directory);
        $postId = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $candidate['provider_post_id']);
        $matchedPlayers = collect($candidate['matched_players'] ?? [])->map(
            fn (array $player): string => sprintf(
                '- %s — player_id `%s`, nhl_player_id `%s`, position `%s`, team `%s`',
                (string) $player['name'],
                (string) ($player['player_id'] ?? 'null'),
                (string) ($player['nhl_player_id'] ?? 'null'),
                (string) ($player['position'] ?? 'unknown'),
                (string) ($player['team_abbrev'] ?? 'unknown')
            )
        )->implode("\n");
        $slots = collect($candidate['players'] ?? [])->map(
            fn (array $player): string => sprintf(
                '- `%s.%d` %s (%s)',
                (string) $player['line_key'],
                (int) $player['slot_index'],
                (string) $player['name'],
                (string) $player['lineup_role']
            )
        )->implode("\n");
        $rawPost = json_encode(
            $candidate['raw_post'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $markdown = implode("\n", [
            '# X lineup post audit',
            '',
            '**Decision:** ' . ucfirst((string) $candidate['audit_decision']),
            '',
            '**Reason:** ' . (string) $candidate['audit_reason'],
            '',
            '## Search context',
            '',
            '- Team: `' . $teamAbbrev . '`',
            '- NHL game ID: `' . (string) $game->nhl_game_id . '`',
            '- Timeline page: `' . $context . '`',
            '- Request type: `' . $requestType . '`',
            '- Post: ' . (string) $candidate['post_url'],
            '- Author: ' . (string) $candidate['source_name'] . ' (`@' . (string) ($candidate['source_handle'] ?? '') . '`)',
            '- Published: ' . (string) ($candidate['published_at'] ?? 'unknown'),
            '',
            '## Complete post text',
            '',
            '````text',
            (string) $candidate['post_text'],
            '````',
            '',
            '## Matched target-team players',
            '',
            $matchedPlayers !== '' ? $matchedPlayers : '_None._',
            '',
            '## Parsed lineup slots',
            '',
            $slots !== '' ? $slots : '_None._',
            '',
            '## Raw X post JSON',
            '',
            '```json',
            $rawPost === false ? '{}' : $rawPost,
            '```',
            '',
        ]);

        File::put($directory . '/x_post_' . $postId . '.md', $markdown);
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function writeLocalSearchAudit(
        array $candidates,
        object $game,
        string $teamAbbrev,
        string $context,
        string $requestType
    ): void {
        if (! app()->environment('local')) {
            return;
        }

        $directory = base_path('docs/troubleshooting/lineups/' . mb_strtoupper($teamAbbrev));
        File::ensureDirectoryExists($directory);
        $results = collect($candidates)->values()->map(function (array $candidate, int $index): string {
            return implode("\n", [
                '## Result ' . ($index + 1),
                '',
                '- Decision: **' . ucfirst((string) $candidate['audit_decision']) . '**',
                '- Reason: ' . (string) $candidate['audit_reason'],
                '- Post: ' . (string) $candidate['post_url'],
                '- Author: ' . (string) $candidate['source_name']
                    . ' (`@' . (string) ($candidate['source_handle'] ?? '') . '`)',
                '- Published: ' . (string) ($candidate['published_at'] ?? 'unknown'),
                '',
                '````text',
                (string) $candidate['post_text'],
                '````',
                '',
            ]);
        })->implode("\n");
        $section = implode("\n", [
            '## X timeline page',
            '',
            '- Searched at: ' . now()->toIso8601String(),
            '- Team: `' . $teamAbbrev . '`',
            '- NHL game ID: `' . (string) $game->nhl_game_id . '`',
            '- Game date: `' . (string) $game->game_date . '`',
            '- Timeline page: `' . $context . '`',
            '- Request type: `' . $requestType . '`',
            '- Results returned: ' . count($candidates),
            '',
            $results !== '' ? $results : '_X returned no posts._',
            '',
        ]);

        File::append($directory . '/search.md', $section);
    }

}
