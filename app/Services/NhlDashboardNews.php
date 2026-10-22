<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Read and normalize the NHL's public editorial feeds for dashboard cards. */
class NhlDashboardNews
{
    /** Return a small cached editorial feed, retaining the last successful result on failure. */
    public function feed(string $type = 'stories'): array
    {
        $type = $type === 'videos' ? 'videos' : 'stories';
        $key = 'nhl:dashboard:news:v1:'.$type;

        return Cache::remember($key, 300, function () use ($key, $type): array {
            try {
                $response = Http::connectTimeout(3)->timeout(8)->acceptJson()
                    ->get(rtrim((string) config('apiurls.nhl_content.base'), '/').'/'.$type, ['context.slug' => 'nhl'])
                    ->throw()->json();
                if (! is_array($response) || ! is_array($response['items'] ?? null)) {
                    throw new \UnexpectedValueException('The NHL editorial feed did not contain an items list.');
                }
                $items = collect($response['items'])->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row): ?array => $this->item($row, $type))
                    ->filter()->unique('id')->unique('url')->sortByDesc('published_at')->values()->take(25)->all();
                $result = ['items' => $items, 'unavailable' => false, 'updated_at' => now()->toIso8601String()];
                Cache::put($key.':last-good', $result, 86400);

                return $result;
            } catch (Throwable $exception) {
                report($exception);

                return [...Cache::get($key.':last-good', ['items' => [], 'updated_at' => null]), 'unavailable' => true];
            }
        });
    }

    /** Preserve provider text and images while restricting navigation to NHL-owned content. */
    private function item(array $row, string $type): ?array
    {
        $slug = (string) ($row['slug'] ?? '');
        $title = trim(html_entity_decode(strip_tags((string) ($row['headline'] ?? $row['title'] ?? '')), ENT_QUOTES | ENT_HTML5));
        if ($title === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,500}$/i', $slug) !== 1) {
            return null;
        }
        try {
            $published = Carbon::parse($row['contentDate'] ?? throw new \UnexpectedValueException('Missing article date.'));
        } catch (Throwable) {
            return null;
        }
        if ($published->isFuture()) {
            return null;
        }
        $template = data_get($row, 'thumbnail.templateUrl');
        $image = is_string($template) ? str_replace('{formatInstructions}', 't_ratio16_9-size50', $template) : null;
        if (! is_string($image) || ! str_starts_with($image, 'https://media.d3.nhle.com/')) {
            $image = null;
        }
        $tags = collect($row['tags'] ?? [])->pluck('neutralSlug')->filter()->all();
        $category = collect($tags)->contains(fn ($tag): bool => str_contains((string) $tag, 'fantasy')) ? 'Fantasy'
            : (collect($tags)->contains(fn ($tag): bool => str_contains((string) $tag, 'recap')) ? 'Game recap' : 'NHL News');

        return [
            'id' => (string) ($row['_entityId'] ?? $slug), 'title' => $title,
            'summary' => trim(html_entity_decode(strip_tags((string) ($row['summary'] ?? '')), ENT_QUOTES | ENT_HTML5)),
            'url' => 'https://www.nhl.com/'.($type === 'videos' ? 'video' : 'news').'/'.$slug,
            'image_url' => $image, 'published_at' => $published->toIso8601String(),
            'category' => $category, 'source' => 'NHL.com',
        ];
    }
}
