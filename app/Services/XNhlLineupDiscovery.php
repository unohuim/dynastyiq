<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Searches X directly for recent NHL lineup posts and normalizes their public metadata. */
class XNhlLineupDiscovery
{
    public function __construct(private readonly NhlLineupTextParser $parser)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function discover(object $game, string $teamAbbrev): array
    {
        $bearerToken = (string) config('services.x.bearer_token');
        if ($bearerToken === '') {
            throw new RuntimeException('X_BEARER_TOKEN is not configured.');
        }

        $team = NhlTeam::query()->where('abbrev', $teamAbbrev)->first();
        $nickname = trim((string) ($team?->common_name ?: $this->lastWord((string) $team?->full_name)));
        if ($nickname === '') {
            throw new RuntimeException("No NHL team nickname is available for {$teamAbbrev}.");
        }

        $query = trim("{$teamAbbrev} {$nickname} starting lineup");
        $response = Http::withToken($bearerToken)
            ->acceptJson()
            ->timeout((int) config('services.x.timeout_seconds', 30))
            ->get('https://api.x.com/2/tweets/search/recent', [
                'query' => $query,
                'max_results' => 10,
                'sort_order' => 'recency',
                'tweet.fields' => 'id,text,note_tweet,author_id,created_at,public_metrics,attachments',
                'expansions' => 'author_id,attachments.media_keys',
                'user.fields' => 'id,name,username,public_metrics,url',
                'media.fields' => 'media_key,type,url,preview_image_url,alt_text',
            ])->throw();

        $payload = $response->json();
        $users = collect(data_get($payload, 'includes.users', []))->keyBy('id');
        $media = collect(data_get($payload, 'includes.media', []))->keyBy('media_key');

        DB::table('integration_api_usage_logs')->insert([
            'provider' => 'x',
            'operation' => 'nhl_lineup_discovery',
            'provider_request_id' => $response->header('x-request-id'),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'tool_calls' => 1,
            'metadata' => json_encode([
                'nhl_game_id' => $game->nhl_game_id,
                'team_abbrev' => $teamAbbrev,
                'query' => $query,
                'posts_returned' => count($payload['data'] ?? []),
            ]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return collect($payload['data'] ?? [])->map(function (array $post) use ($users, $media, $teamAbbrev): array {
            $author = $users->get((string) ($post['author_id'] ?? ''), []);
            $username = trim((string) ($author['username'] ?? ''));
            $metrics = $post['public_metrics'] ?? [];
            $attachments = collect(data_get($post, 'attachments.media_keys', []))
                ->map(fn (string $key): mixed => $media->get($key))
                ->filter()
                ->values()
                ->all();

            $postText = (string) data_get($post, 'note_tweet.text', $post['text'] ?? '');

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
                'players' => $this->parser->parse($postText, $teamAbbrev),
                'media' => $attachments,
                'provider_post_id' => (string) $post['id'],
            ];
        })->filter(fn (array $candidate): bool => $this->belongsToGame(
            (string) $candidate['post_text'],
            $game,
            $teamAbbrev
        ))->values()->all();
    }

    private function belongsToGame(string $postText, object $game, string $teamAbbrev): bool
    {
        $sameDayGames = DB::table('nhl_games')
            ->whereDate('game_date', (string) $game->game_date)
            ->where(fn ($query) => $query->where('home_team_abbrev', $teamAbbrev)
                ->orWhere('away_team_abbrev', $teamAbbrev))
            ->count();
        if ($sameDayGames < 2) {
            return true;
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

        return collect($identifiers)->contains(
            fn (string $identifier): bool => preg_match(
                '/(?<![\pL\pN])' . preg_quote($identifier, '/') . '(?![\pL\pN])/iu',
                $postText
            ) === 1
        );
    }

    private function lastWord(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];

        return (string) end($words);
    }
}
