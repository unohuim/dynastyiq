<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

final class NhlApiCommand extends Command
{
    private const DEFAULT_SOURCE = 'docs/integrations/nhl-responses/nhl_api_index.md';

    private const DOCS_DIR = 'docs/integrations/nhl-responses';

    private const SAMPLES_DIR = 'docs/api_responses/samples';

    private const INDEX_PATH = 'docs/integrations/nhl-responses/endpoint-index.md';

    private const API_INDEX_PATH = 'docs/integrations/nhl-responses/nhl_api_index.md';

    private const OUTPUT_PATH = 'docs/api_responses/samples/nhlApi-json.output';

    /**
     * @var array<string,string>
     */
    private const FRIENDLY_SLUGS = [
        '/v1/player/{player}/landing' => 'player-landing',
        '/v1/gamecenter/{game-id}/play-by-play' => 'game-play-by-play',
        '/v1/gamecenter/{game-id}/boxscore' => 'game-boxscore',
        '/v1/gamecenter/{game-id}/landing' => 'game-landing',
        '/v1/score/{date}' => 'schedule',
        '/v1/standings/now' => 'standings',
        '/player/{playerId}/landing' => 'player-landing',
        '/gamecenter/{gameId}/play-by-play' => 'game-play-by-play',
        '/gamecenter/{gameId}/boxscore' => 'game-boxscore',
        '/gamecenter/{gameId}/landing' => 'game-landing',
        '/score/{date}' => 'schedule',
        '/standings/now' => 'standings',
        '/roster/{teamAbbrev}/current' => 'roster-current',
        '/roster/{teamAbbrev}/{seasonId}' => 'roster-season',
        '/prospects/{teamAbbrev}' => 'prospects',
        '/draft/picks/{year}/all' => 'draft-picks',
    ];

    protected $signature = 'nhl:api
        {--source= : Local NHL API index path}
        {--endpoint= : Endpoint slug or path fragment to run}
        {--timeout=30 : HTTP timeout in seconds}';

    protected $description = 'Fetch NHL API raw sample payloads from the local NHL API index.';

    public function handle(): int
    {
        $source = (string) ($this->option('source') ?: self::DEFAULT_SOURCE);
        $timeout = max(1, (int) $this->option('timeout'));

        try {
            $reference = $this->fetchReference($source, $timeout);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $endpoints = $this->discoverLocalApiIndexEndpoints($reference);

        if ($endpoints === []) {
            $this->warn('No NHL API endpoints were discovered from the local NHL API index.');

            return self::FAILURE;
        }

        $selected = $this->selectedEndpoints($endpoints);

        if ($selected === []) {
            $this->warn('No NHL API endpoints matched the requested endpoint filter.');

            return self::INVALID;
        }

        $written = [];
        $failed = [];
        $skipped = [];
        $rows = [];
        $summary = [
            'discovered' => count($selected),
            'sampled' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($selected as $endpoint) {
            $samplePath = $endpoint['sample_path'] ?? null;
            $status = 'skipped';

            if ($samplePath === null || $samplePath === '') {
                $reason = 'Missing Raw JSON path.';
                $this->warn("Skipped {$endpoint['slug']}: {$reason}");
                $skipped[] = $this->reportRow($endpoint, $samplePath ?? '', $reason);
            } elseif ($endpoint['example_url'] === null) {
                $reason = $endpoint['example_curl'] === null
                    ? 'Missing example cURL.'
                    : 'Example cURL URL is missing or contains placeholders.';
                $this->warn("Skipped {$endpoint['slug']}: {$reason}");
                $skipped[] = $this->reportRow($endpoint, $samplePath, $reason);
            } else {
                $result = $this->fetchAndWriteEndpoint($endpoint, $endpoint['example_url'], $samplePath, $timeout);
                $status = $result['status'];

                if ($status === 'sampled') {
                    $written[] = $this->reportRow($endpoint, $samplePath, 'Created or overwritten.');
                } elseif ($status === 'failed') {
                    $failed[] = $this->reportRow($endpoint, $samplePath, $result['reason']);
                }
            }

            if ($status === 'sampled') {
                $summary['sampled']++;
            } elseif ($status === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }

            $rows[] = [
                'slug' => $endpoint['slug'],
                'method' => $endpoint['method'],
                'path' => $endpoint['path'],
                'description' => $endpoint['description'],
                'params' => implode(', ', array_column($endpoint['parameters'], 'name')),
                'example_url' => $endpoint['example_url'] ?? '',
                'sample' => $samplePath ?? '',
                'status' => $status,
            ];
        }

        $this->table(
            ['Slug', 'Method', 'Path', 'Example URL', 'Sample', 'Status'],
            array_map(
                static fn (array $row): array => [
                    'slug' => $row['slug'],
                    'method' => $row['method'],
                    'path' => $row['path'],
                    'example_url' => $row['example_url'],
                    'sample' => $row['sample'],
                    'status' => $row['status'],
                ],
                $rows
            )
        );
        $this->line(
            "Discovered {$summary['discovered']} endpoint(s); "
            . "sampled {$summary['sampled']}; "
            . "failed {$summary['failed']}; "
            . "skipped {$summary['skipped']}."
        );
        $this->writeOutputReport($summary, $written, $failed, $skipped);
        $this->line('Wrote ' . self::OUTPUT_PATH);

        return self::SUCCESS;
    }

    /**
     * Fetch the endpoint reference markdown from a local file, GitHub, or a raw source URL.
     */
    private function fetchReference(string $source, int $timeout): string
    {
        if (! str_starts_with($source, 'http://') && ! str_starts_with($source, 'https://')) {
            $path = str_starts_with($source, '/') ? $source : base_path($source);

            if (! File::exists($path)) {
                throw new \RuntimeException("NHL API source file does not exist: {$source}");
            }

            $this->line("Indexed NHL API reference from {$source}.");

            return File::get($path);
        }

        $candidates = $this->sourceCandidates($source);

        foreach ($candidates as $candidate) {
            $response = Http::timeout($timeout)->accept('text/plain')->get($candidate);

            if ($response->successful() && trim($response->body()) !== '') {
                $this->line("Indexed NHL API reference from {$candidate}.");

                return $response->body();
            }
        }

        throw new \RuntimeException('Unable to fetch NHL API reference from configured source.');
    }

    /**
     * Build candidate source URLs, preferring raw README markdown for GitHub repository URLs.
     *
     * @return array<int,string>
     */
    private function sourceCandidates(string $source): array
    {
        $source = trim($source);

        if (str_contains($source, 'github.com/Zmalski/NHL-API-Reference')) {
            return [
                'https://raw.githubusercontent.com/Zmalski/NHL-API-Reference/main/README.md',
                'https://raw.githubusercontent.com/Zmalski/NHL-API-Reference/master/README.md',
                'https://github.com/Zmalski/NHL-API-Reference/raw/main/README.md',
                $source,
            ];
        }

        return [$source];
    }

    /**
     * Parse endpoint blocks from the reference text.
     *
     * @return array<int,array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * }>
     */
    private function discoverEndpoints(string $reference): array
    {
        $localEndpoints = $this->discoverLocalApiIndexEndpoints($reference);

        if ($localEndpoints !== []) {
            return $localEndpoints;
        }

        $curlEndpoints = $this->discoverCurlReferenceEndpoints($reference);

        if ($curlEndpoints !== []) {
            return $curlEndpoints;
        }

        $reference = $this->normalizeReferenceText($reference);
        $endpoints = [];

        preg_match_all('/(?:^|\n)-\s+\*\*Endpoint\*\*:\s+`(?P<path>[^`]+)`/s', $reference, $endpointMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($endpointMatches as $index => $endpointMatch) {
            $bodyStart = (int) $endpointMatch[0][1];
            $bodyEnd = isset($endpointMatches[$index + 1])
                ? (int) $endpointMatches[$index + 1][0][1]
                : strlen($reference);
            $body = substr($reference, $bodyStart, $bodyEnd - $bodyStart);
            $path = trim($endpointMatch['path'][0]);
            $heading = $this->headingForOffset($reference, $bodyStart);
            $method = $this->blockValue($body, 'Method') ?: 'GET';
            $description = $this->blockValue($body, 'Description') ?: '';
            $exampleCurl = $this->exampleCurlFromBlock($body);
            $exampleUrl = $exampleCurl ? $this->urlFromCurl($exampleCurl) : null;
            $endpoint = [
                'title' => $heading['title'] ?? $path,
                'section' => $this->sectionForOffset($reference, $bodyStart, (int) ($heading['level'] ?? 6)),
                'method' => strtoupper($method),
                'path' => $path,
                'description' => $description,
                'parameters' => $this->parametersFromBlock($body),
                'example_curl' => $exampleCurl,
                'example_url' => $exampleUrl,
                'slug' => $this->slugForPath($this->primaryEndpointPath($path)),
            ];

            $endpoints[$endpoint['path']] = $endpoint;
        }

        uasort($endpoints, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return array_values($endpoints);
    }

    /**
     * Parse endpoint blocks from the source README by treating cURL examples as authoritative.
     *
     * @return array<int,array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * }>
     */
    private function discoverCurlReferenceEndpoints(string $reference): array
    {
        $reference = str_replace(["\r\n", "\r"], "\n", html_entity_decode($reference));
        preg_match_all(
            '/curl\s+(?:-L\s+)?-X\s+GET\s+"(?P<url>[^"]+)"/i',
            $reference,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $endpoints = [];

        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $curl = 'curl -X GET "' . trim($match['url'][0]) . '"';
            $blockStart = $this->curlBlockStart($reference, $offset);
            $blockEnd = $this->curlBlockEnd($reference, $offset);
            $block = substr($reference, $blockStart, $blockEnd - $blockStart);
            $path = $this->endpointPathBeforeOffset($reference, $offset)
                ?? $this->pathFromCurlUrl($match['url'][0])
                ?? trim($match['url'][0]);
            $heading = $this->headingForOffset($reference, $offset);
            $url = $this->urlFromCurl($curl);
            $slugPath = $this->primaryEndpointPath($path);
            $slug = $this->slugForEndpoint(
                $heading['title'] ?? null,
                $slugPath,
                $this->readmeParametersFromBlock($block),
                $match['url'][0]
            );
            $endpoint = [
                'title' => $heading['title'] ?? $this->titleFromSlug($slug),
                'section' => $this->sectionForOffset($reference, $offset, (int) ($heading['level'] ?? 6)),
                'method' => 'GET',
                'path' => $path,
                'description' => $this->readmeBlockValue($block, 'Description') ?? '',
                'parameters' => $this->readmeParametersFromBlock($block),
                'example_curl' => $curl,
                'example_url' => $url,
                'slug' => $slug,
            ];

            $endpoints[] = $endpoint;
        }

        usort($endpoints, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return $endpoints;
    }

    /**
     * Find the likely start of the README block containing a cURL example.
     */
    private function curlBlockStart(string $reference, int $offset): int
    {
        $before = substr($reference, 0, $offset);
        $headingOffset = max(
            strrpos($before, "\n#### ") ?: 0,
            strrpos($before, "\n### ") ?: 0,
            strrpos($before, "\n## ") ?: 0
        );

        return $headingOffset > 0 ? $headingOffset + 1 : 0;
    }

    /**
     * Find the likely end of the README block containing a cURL example.
     */
    private function curlBlockEnd(string $reference, int $offset): int
    {
        $after = substr($reference, $offset + 1);

        if (preg_match('/\n#{2,6}\s+/', $after, $match, PREG_OFFSET_CAPTURE) === 1) {
            return $offset + 1 + (int) $match[0][1];
        }

        return strlen($reference);
    }

    /**
     * Return the nearest documented endpoint path before a cURL example.
     */
    private function endpointPathBeforeOffset(string $reference, int $offset): ?string
    {
        $before = substr($reference, max(0, $offset - 2500), min(2500, $offset));
        preg_match_all(
            '/(?:^|\n)\s*(?:[*-]\s*)?(?:\*\*)?Endpoint(?:\*\*)?:?\s+`?(?P<path>[^\n`]+)`?/i',
            $before,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            return null;
        }

        $path = trim($matches[array_key_last($matches)]['path']);

        return $path === '' ? null : $path;
    }

    /**
     * Build a provider path from a concrete cURL URL.
     */
    private function pathFromCurlUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (str_contains($path, '/stats/rest/')) {
            $path = preg_replace('#^/stats/rest#', '', $path) ?? $path;
        }

        return $query ? $path . '?' . $query : $path;
    }

    /**
     * Read a source README block value.
     */
    private function readmeBlockValue(string $block, string $label): ?string
    {
        if (preg_match('/(?:^|\n)\s*(?:[*-]\s*)?(?:\*\*)?' . preg_quote($label, '/') . '(?:\*\*)?:\s*(?P<value>.+?)(?=\n\s*(?:[*-]\s*)?(?:\*\*)?[A-Z][A-Za-z ]+(?:\*\*)?:|\n#{2,6}\s+|\z)/s', $block, $match) !== 1) {
            return null;
        }

        return trim(strip_tags($match['value']));
    }

    /**
     * Extract parameter rows from a source README block.
     *
     * @return array<int,array{name:string,detail:string}>
     */
    private function readmeParametersFromBlock(string $block): array
    {
        if (preg_match('/(?:^|\n)\s*(?:[*-]\s*)?(?:\*\*)?(?:Request )?Parameters(?:\*\*)?:\s*(?P<params>.*?)(?=\n\s*(?:[*-]\s*)?(?:\*\*)?Response(?:\*\*)?:|\n#{2,6}\s+|\z)/si', $block, $sectionMatch) !== 1) {
            return [];
        }

        preg_match_all(
            '/(?:^|\n)\s*(?:[*-]\s*)?`?(?P<name>[A-Za-z0-9_-]+)`?\s+\((?P<type>[^)]*)\)\s+-\s+(?P<detail>.+?)(?=\n\s*(?:[*-]\s*)?`?[A-Za-z0-9_-]+`?\s+\(|\n\s*(?:[*-]\s*)?(?:\*\*)?[A-Z][A-Za-z ]+(?:\*\*)?:|\z)/s',
            $sectionMatch['params'],
            $matches,
            PREG_SET_ORDER
        );

        $parameters = [];

        foreach ($matches as $match) {
            $parameters[] = [
                'name' => trim($match['name']),
                'detail' => trim($match['type'] . ' - ' . strip_tags($match['detail'])),
            ];
        }

        return $parameters;
    }

    /**
     * Parse endpoint blocks from docs/integrations/nhl-responses/nhl_api_index.md.
     *
     * @return array<int,array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string,
     *     sample_path?:string,
     *     doc_path?:string
     * }>
     */
    private function discoverLocalApiIndexEndpoints(string $reference): array
    {
        if (! str_contains($reference, 'Raw JSON:') || ! str_contains($reference, 'Usage File:')) {
            return [];
        }

        preg_match_all('/^##\s+(?P<title>.+?)$(?P<body>.*?)(?=^##\s+|\z)/ms', $reference, $matches, PREG_SET_ORDER);

        $endpoints = [];

        foreach ($matches as $match) {
            $body = trim($match['body']);
            $path = $this->localBlockValue($body, 'Endpoint');

            if ($path === null) {
                continue;
            }

            $exampleCurl = $this->localCurlFromBlock($body);
            $exampleUrl = $exampleCurl ? $this->urlFromCurl($exampleCurl) : null;
            $samplePath = $this->localBlockValue($body, 'Raw JSON');
            $docPath = $this->localBlockValue($body, 'Usage File');
            $slug = $samplePath !== null && $samplePath !== ''
                ? $this->slugFromSamplePath($samplePath)
                : $this->slugForPath($this->primaryEndpointPath($path));
            $endpoint = [
                'title' => trim($match['title']),
                'section' => ['Local NHL API Index'],
                'method' => strtoupper($this->localBlockValue($body, 'Method') ?? 'GET'),
                'path' => $path,
                'description' => $this->localBlockValue($body, 'Description') ?? '',
                'parameters' => $this->localParametersFromBlock($body),
                'example_curl' => $exampleCurl,
                'example_url' => $exampleUrl,
                'slug' => $slug,
            ];

            if ($samplePath !== null && $samplePath !== '') {
                $endpoint['sample_path'] = $samplePath;
            }

            if ($docPath !== null && $docPath !== '') {
                $endpoint['doc_path'] = $docPath;
            }

            $endpoints[$endpoint['slug']] = $endpoint;
        }

        uasort($endpoints, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return array_values($endpoints);
    }

    /**
     * Read a single-line field from a local endpoint block.
     */
    private function localBlockValue(string $block, string $label): ?string
    {
        if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $block, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        return $value === '' ? null : $value;
    }

    /**
     * Derive the endpoint slug from the local Raw JSON path.
     */
    private function slugFromSamplePath(string $samplePath): string
    {
        $filename = pathinfo($samplePath, PATHINFO_FILENAME);
        $slug = preg_replace('/^nhlApi-/', '', $filename) ?? $filename;

        return trim($slug) === '' ? 'endpoint' : $slug;
    }

    /**
     * Extract the cURL command from a local endpoint block.
     */
    private function localCurlFromBlock(string $block): ?string
    {
        if (preg_match('/^Example using cURL:\s*\R(?P<curl>curl\s+.+)$/mi', $block, $match) !== 1) {
            return null;
        }

        $curl = trim($match['curl']);

        return str_starts_with($curl, 'curl ') ? $curl : null;
    }

    /**
     * Extract parameter rows from a local endpoint block.
     *
     * @return array<int,array{name:string,detail:string}>
     */
    private function localParametersFromBlock(string $block): array
    {
        if (preg_match('/^Parameters:\s*(?P<inline>.*?)(?:\R(?P<rows>.*?))?^Response:/msi', $block, $match) !== 1) {
            return [];
        }

        $inline = trim($match['inline'] ?? '');
        $rows = trim($match['rows'] ?? '');

        if ($inline !== '' && ! str_contains(strtolower($inline), 'none documented')) {
            return array_map(
                static fn (string $name): array => ['name' => trim($name), 'detail' => 'Documented in local NHL API index.'],
                array_filter(array_map('trim', explode(',', $inline)))
            );
        }

        if ($rows === '' || str_contains(strtolower($rows), 'none documented')) {
            return [];
        }

        $parameters = [];

        foreach (preg_split('/\R/', $rows) ?: [] as $row) {
            $row = trim($row);

            if ($row === '') {
                continue;
            }

            [$name, $detail] = array_pad(explode(' - ', $row, 2), 2, '');
            $parameters[] = [
                'name' => trim($name),
                'detail' => trim($detail) === '' ? 'Documented in local NHL API index.' : trim($detail),
            ];
        }

        return $parameters;
    }

    /**
     * Return the first documented endpoint path when multiple alternatives are listed.
     */
    private function primaryEndpointPath(string $path): string
    {
        return trim(explode(';', $path)[0]);
    }

    /**
     * Find the nearest markdown heading before an endpoint block.
     *
     * @return array{level:int,title:string}|null
     */
    private function headingForOffset(string $reference, int $offset): ?array
    {
        $before = substr($reference, 0, $offset);
        preg_match_all(
            '/(?:^|\n)(#{2,6})\s+(.+?)(?=\n|$)/',
            $before,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if ($matches === []) {
            return null;
        }

        $match = $matches[array_key_last($matches)];

        return [
            'level' => strlen($match[1][0]),
            'title' => trim(strip_tags($match[2][0])),
        ];
    }

    /**
     * Normalize wrapped markdown so endpoint blocks can be parsed consistently.
     */
    private function normalizeReferenceText(string $reference): string
    {
        $reference = preg_replace('/\s+/', ' ', $reference) ?? $reference;
        $reference = preg_replace('/\s+(#{2,6}\s+)/', "\n$1", $reference) ?? $reference;
        $reference = preg_replace('/\s+(-\s+\*\*[A-Za-z ]+\*\*:)/', "\n$1", $reference) ?? $reference;
        $reference = preg_replace('/\s+(```(?:bash)?\s+curl)/i', "\n$1", $reference) ?? $reference;
        $reference = preg_replace('/(```)\s+(####\s+)/', "$1\n$2", $reference) ?? $reference;

        return $reference;
    }

    /**
     * Extract higher-level section headings before an endpoint block.
     *
     * @return array<int,string>
     */
    private function sectionForOffset(string $reference, int $offset, int $endpointHeadingLevel): array
    {
        $before = substr($reference, 0, $offset);
        preg_match_all('/(?:^|\n)(#{2,5})\s+(.+?)(?=\n|$)/', $before, $matches, PREG_SET_ORDER);
        $headings = [];

        foreach ($matches as $match) {
            $level = strlen($match[1]);

            if ($level >= $endpointHeadingLevel) {
                continue;
            }

            $headings[$level] = trim(strip_tags($match[2]));

            foreach (array_keys($headings) as $existingLevel) {
                if ($existingLevel > $level) {
                    unset($headings[$existingLevel]);
                }
            }
        }

        return array_values($headings);
    }

    /**
     * Read a single field value from an endpoint block.
     */
    private function blockValue(string $block, string $label): ?string
    {
        if (preg_match('/(?:^|\s)-\s+\*\*' . preg_quote($label, '/') . '\*\*:\s+(.+?)(?=\s+-\s+\*\*[A-Za-z ]+\*\*:|\s+######|\s+```|\z)/si', $block, $match) !== 1) {
            return null;
        }

        return trim(strip_tags($match[1]));
    }

    /**
     * Extract provider parameter rows from an endpoint block.
     *
     * @return array<int,array{name:string,detail:string}>
     */
    private function parametersFromBlock(string $block): array
    {
        if (preg_match('/-\s+\*\*(?:Request )?Parameters\*\*:\s+(?P<params>.*?)(?=\s+-\s+\*\*Response\*\*:|\s+######|\s+```|\z)/si', $block, $sectionMatch) !== 1) {
            return [];
        }

        preg_match_all('/-\s+`?([A-Za-z0-9_-]+)`?\s+\(([^)]*)\)\s+-\s+(.+?)(?=\s+-\s+`?[A-Za-z0-9_-]+`?\s+\(|\s+-\s+\*\*[A-Za-z ]+\*\*:|\s+######|\s+```|\z)/si', $sectionMatch['params'], $matches, PREG_SET_ORDER);

        $parameters = [];

        foreach ($matches as $match) {
            $parameters[] = [
                'name' => $match[1],
                'detail' => trim($match[2] . ' - ' . strip_tags($match[3])),
            ];
        }

        return $parameters;
    }

    /**
     * Extract the documented cURL line from an endpoint block.
     */
    private function exampleCurlFromBlock(string $block): ?string
    {
        if (preg_match('/curl\s+(?:-L\s+)?-X\s+GET\s+"([^"]+)"/i', $block, $match) === 1) {
            return 'curl -X GET "' . $match[1] . '"';
        }

        if (preg_match('/curl\s+"([^"]+)"/i', $block, $match) === 1) {
            return 'curl -X GET "' . $match[1] . '"';
        }

        return null;
    }

    /**
     * Extract the runnable URL from a cURL command.
     */
    private function urlFromCurl(string $curl): ?string
    {
        if (preg_match('/"(https?:\/\/[^"]+)"/', $curl, $match) === 1) {
            if (str_contains($match[1], '{') || str_contains($match[1], '}')) {
                return null;
            }

            return $match[1];
        }

        return null;
    }

    /**
     * Resolve the slug for a provider path.
     */
    private function slugForPath(string $path): string
    {
        $pathOnly = strtok($path, '?') ?: $path;
        $pathOnly = preg_replace('#^/v1/#', '/', $pathOnly) ?? $pathOnly;
        $pathOnly = str_replace('}{', '}/{', $pathOnly);

        if (isset(self::FRIENDLY_SLUGS[$pathOnly])) {
            return self::FRIENDLY_SLUGS[$pathOnly];
        }

        $slug = preg_replace('/\{([^}]+)\}/', 'by-$1', $pathOnly) ?? $pathOnly;
        $slug = strtolower(trim($slug, '/'));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'endpoint';
    }

    /**
     * Resolve a stable endpoint slug without letting concrete cURL parameter values leak into file names.
     *
     * @param array<int,array{name:string,detail:string}> $parameters
     */
    private function slugForEndpoint(?string $title, string $path, array $parameters, string $exampleUrl): string
    {
        $pathSlug = $this->slugForPath($path);

        if ($title === null || trim($title) === '') {
            return $pathSlug;
        }

        $titleSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));

        if ($titleSlug === '') {
            return $pathSlug;
        }

        $pathBase = preg_replace('/-by-.+$/', '', $pathSlug) ?? $pathSlug;

        if ($titleSlug === $pathBase || str_starts_with($pathBase, $titleSlug) || str_starts_with($titleSlug, $pathBase)) {
            return $pathSlug;
        }

        $examplePath = $this->pathFromCurlUrl($exampleUrl);
        $exampleSlug = $examplePath === null ? '' : $this->slugForPath($examplePath);
        $exampleBase = preg_replace('/-by-.+$/', '', $exampleSlug) ?? $exampleSlug;

        if (
            $exampleBase !== ''
            && (
                $titleSlug === $exampleBase
                || str_starts_with($exampleBase, $titleSlug)
                || str_starts_with($titleSlug, $exampleBase)
                || str_contains($exampleBase, $titleSlug)
            )
        ) {
            $paramSuffix = implode(
                '-',
                array_map(
                    static fn (array $parameter): string => 'by-' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $parameter['name']) ?? '', '-')),
                    $parameters
                )
            );

            return trim($titleSlug . '-' . $paramSuffix, '-');
        }

        return $pathSlug;
    }

    /**
     * Select endpoints according to command options.
     *
     * @param array<int,array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * }> $endpoints
     * @return array<int,array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * }>
     */
    private function selectedEndpoints(array $endpoints): array
    {
        $target = trim((string) ($this->option('endpoint') ?? ''));

        if ($target !== '') {
            return array_values(array_filter(
                $endpoints,
                static fn (array $endpoint): bool => $endpoint['slug'] === $target
                    || str_contains($endpoint['path'], $target)
                    || ($endpoint['example_url'] !== null && str_contains($endpoint['example_url'], $target))
            ));
        }

        return $endpoints;
    }

    /**
     * Fetch an endpoint and write sample/doc files.
     *
     * @param array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * } $endpoint
     */
    private function fetchAndWriteEndpoint(array $endpoint, string $url, string $samplePath, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->get($url);

            if (! $response->successful()) {
                $reason = "HTTP {$response->status()}";
                $this->warn("Failed {$endpoint['slug']}: {$reason}");

                return ['status' => 'failed', 'reason' => $reason];
            }

            $this->writeSample($samplePath, $response->body());
            $this->line("Wrote {$samplePath}");
        } catch (Throwable $throwable) {
            $reason = $throwable->getMessage();
            $this->warn("Failed {$endpoint['slug']}: {$reason}");

            return ['status' => 'failed', 'reason' => $reason];
        }

        return ['status' => 'sampled', 'reason' => ''];
    }

    /**
     * Build one report row for the JSON output.
     *
     * @param array{
     *     title:string,
     *     method:string,
     *     path:string,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * } $endpoint
     * @return array<string,string>
     */
    private function reportRow(array $endpoint, string $samplePath, string $reason): array
    {
        return [
            'slug' => $endpoint['slug'],
            'title' => $endpoint['title'],
            'method' => $endpoint['method'],
            'path' => $endpoint['path'],
            'url' => $endpoint['example_url'] ?? '',
            'sample_file' => $samplePath,
            'reason' => $reason,
        ];
    }

    /**
     * Write the run report JSON file.
     *
     * @param array{discovered:int,sampled:int,failed:int,skipped:int} $summary
     * @param array<int,array<string,string>> $written
     * @param array<int,array<string,string>> $failed
     * @param array<int,array<string,string>> $skipped
     */
    private function writeOutputReport(array $summary, array $written, array $failed, array $skipped): void
    {
        File::ensureDirectoryExists(dirname(base_path(self::OUTPUT_PATH)));
        File::put(
            base_path(self::OUTPUT_PATH),
            (string) json_encode(
                [
                    'summary' => $summary,
                    'created_or_overwritten' => $written,
                    'failed' => $failed,
                    'skipped' => $skipped,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL
        );
    }

    /**
     * Write a raw sample response body.
     */
    private function writeSample(string $relativePath, string $body): void
    {
        File::ensureDirectoryExists(dirname(base_path($relativePath)));
        File::put(base_path($relativePath), $body . PHP_EOL);
    }

    /**
     * Write a same-format endpoint breakdown.
     *
     * @param array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * } $endpoint
     * @param mixed $payload
     */
    private function writeBreakdown(string $relativePath, array $endpoint, string $samplePath, mixed $payload): void
    {
        File::ensureDirectoryExists(dirname(base_path($relativePath)));
        File::put(base_path($relativePath), $this->breakdownMarkdown($endpoint, $samplePath, $payload));
    }

    /**
     * Build a markdown response breakdown scaffold from an observed payload.
     *
     * @param array{
     *     title:string,
     *     section:array<int,string>,
     *     method:string,
     *     path:string,
     *     description:string,
     *     parameters:array<int,array{name:string,detail:string}>,
     *     example_curl:string|null,
     *     example_url:string|null,
     *     slug:string
     * } $endpoint
     * @param mixed $payload
     */
    private function breakdownMarkdown(array $endpoint, string $samplePath, mixed $payload): string
    {
        $title = str($endpoint['slug'])->replace('-', ' ')->title()->toString();
        $topLevelRows = $this->topLevelRows($payload);
        $fieldRows = $this->fieldRows($payload);
        $section = $endpoint['section'] === [] ? 'Unknown' : implode(' > ', $endpoint['section']);
        $parameters = $this->parametersMarkdown($endpoint['parameters']);
        $exampleCurl = $endpoint['example_curl'] ?? 'No cURL example was documented in the source reference.';
        $description = $endpoint['description'] !== '' ? $endpoint['description'] : 'No source description was documented.';

        return <<<MARKDOWN
# NHL {$title} Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `{$endpoint['method']}` |
| Path | `{$endpoint['path']}` |
| Source Section | {$section} |
| Source Description | {$description} |
| Example cURL | `{$exampleCurl}` |

Current DynastyIQ consumers:

- None verified by this generated document.

## Source Parameters

{$parameters}

## Sample Source

- `{$samplePath}`

The sample was generated by `php artisan nhl:api` from the NHL API reference endpoint inventory.

## Purpose

Generated scaffold pending human interpretation.

## Observations For DynastyIQ

Generated scaffold pending human review for DynastyIQ source authority, import safety, and product fit.

## Top-Level Observed Shape

{$topLevelRows}

## Observed Field Inventory

{$fieldRows}

## Opportunity

- Generated scaffold pending human review for product opportunities.
- Review the raw payload before treating any field as product, import, validation, admin, or troubleshooting authority.

## Parser Contract

- Do not treat this generated document as final endpoint authority until a human reviews the raw sample.
- Do not use this endpoint as canonical identity, stats, standings, or validation authority until source precedence is documented.
- Preserve provider field names in sample docs; normalize only in implementation-specific contracts.
- Treat missing, null, or empty sections as provider data availability unless verified otherwise.

## Expected Normalized Output

No current normalized output.

## Open Verification Questions

- What exact request parameters are required for production usage?
- Does the response shape change across seasons, game types, teams, players, or playoffs?
- Which fields are source-of-truth values versus display or derived values?
- Which fields should DynastyIQ persist, display only, or ignore?

MARKDOWN;
    }

    /**
     * Build source parameter markdown.
     *
     * @param array<int,array{name:string,detail:string}> $parameters
     */
    private function parametersMarkdown(array $parameters): string
    {
        if ($parameters === []) {
            return 'No parameters documented in the source reference.';
        }

        $rows = [
            '| Name | Detail |',
            '| --- | --- |',
        ];

        foreach ($parameters as $parameter) {
            $rows[] = '| `' . $this->escapeMarkdown($parameter['name']) . '` | '
                . $this->escapeMarkdown($parameter['detail']) . ' |';
        }

        return implode(PHP_EOL, $rows);
    }

    /**
     * Build top-level shape markdown rows.
     *
     * @param mixed $payload
     */
    private function topLevelRows(mixed $payload): string
    {
        $payload = $this->unwrapPayload($payload);

        if (! is_array($payload)) {
            return '| Section | Observed Shape | Observed Values / Notes |' . PHP_EOL
                . '| --- | --- | --- |' . PHP_EOL
                . '| `$` | ' . $this->typeOf($payload) . ' | Non-object response. |';
        }

        $rows = [
            '| Section | Observed Shape | Observed Values / Notes |',
            '| --- | --- | --- |',
        ];

        foreach ($payload as $key => $value) {
            $rows[] = '| `' . $this->escapeMarkdown((string) $key) . '` | '
                . $this->escapeMarkdown($this->shapeOf($value)) . ' | '
                . $this->escapeMarkdown($this->previewValue($value)) . ' |';
        }

        return implode(PHP_EOL, $rows);
    }

    /**
     * Build observed nested field rows.
     *
     * @param mixed $payload
     */
    private function fieldRows(mixed $payload): string
    {
        $fields = $this->flattenPayload($this->unwrapPayload($payload));

        if ($fields === []) {
            return '| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |' . PHP_EOL
                . '| --- | --- | --- | --- | --- | --- |' . PHP_EOL
                . '| `$` | ' . $this->typeOf($payload) . ' | ' . $this->escapeMarkdown($this->previewValue($payload)) . ' | Generated unreviewed. | Generated unreviewed. | Generated unreviewed. |';
        }

        $rows = [
            '| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |',
            '| --- | --- | --- | --- | --- | --- |',
        ];

        foreach (array_slice($fields, 0, 200) as $field) {
            $rows[] = '| `' . $this->escapeMarkdown($field['path']) . '` | '
                . $this->escapeMarkdown($field['type']) . ' | '
                . $this->escapeMarkdown($field['value']) . ' | Generated unreviewed. | Generated unreviewed. | Generated unreviewed. |';
        }

        if (count($fields) > 200) {
            $rows[] = '| `_truncated` | note | Field inventory truncated at 200 rows. | Generated unreviewed. | Generated unreviewed. | Generated unreviewed. |';
        }

        return implode(PHP_EOL, $rows);
    }

    /**
     * Return flattened leaf and compact array-object paths.
     *
     * @param mixed $value
     * @return array<int,array{path:string,type:string,value:string}>
     */
    private function flattenPayload(mixed $value, string $path = '$', int $depth = 0): array
    {
        if ($depth > 8) {
            return [[
                'path' => $path,
                'type' => $this->shapeOf($value),
                'value' => 'Depth limit reached.',
            ]];
        }

        if (! is_array($value)) {
            return [[
                'path' => $path,
                'type' => $this->typeOf($value),
                'value' => $this->previewValue($value),
            ]];
        }

        if ($value === []) {
            return [[
                'path' => $path,
                'type' => 'empty array',
                'value' => '[]',
            ]];
        }

        if (array_is_list($value)) {
            $first = Arr::first($value);

            if (is_array($first)) {
                return $this->flattenPayload($first, $path . '[]', $depth + 1);
            }

            return [[
                'path' => $path . '[]',
                'type' => $this->shapeOf($value),
                'value' => $this->previewValue($value),
            ]];
        }

        $fields = [];

        foreach ($value as $key => $child) {
            array_push($fields, ...$this->flattenPayload($child, $path . '.' . (string) $key, $depth + 1));
        }

        return $fields;
    }

    /**
     * Use wrapped sample payload if present.
     *
     * @param mixed $payload
     */
    private function unwrapPayload(mixed $payload): mixed
    {
        if (is_array($payload) && array_key_exists('payload', $payload)) {
            return $payload['payload'];
        }

        return $payload;
    }

    /**
     * Return compact provider shape text.
     *
     * @param mixed $value
     */
    private function shapeOf(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return 'empty array';
            }

            return array_is_list($value)
                ? 'array[' . count($value) . ']'
                : 'object{' . count($value) . '}';
        }

        return $this->typeOf($value);
    }

    /**
     * Return provider value type.
     *
     * @param mixed $value
     */
    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'decimal',
            is_string($value) => 'string',
            is_null($value) => 'null',
            default => get_debug_type($value),
        };
    }

    /**
     * Return a compact observed value preview.
     *
     * @param mixed $value
     */
    private function previewValue(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return 'Empty.';
            }

            if (array_is_list($value)) {
                return count($value) . ' row(s).';
            }

            return 'Keys: ' . implode(', ', array_slice(array_map('strval', array_keys($value)), 0, 8));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        $string = (string) $value;

        return mb_strlen($string) > 80 ? mb_substr($string, 0, 77) . '...' : $string;
    }

    /**
     * Escape markdown table cell content.
     */
    private function escapeMarkdown(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    /**
     * Write the endpoint index.
     *
     * @param array<int,array{
     *     slug:string,
     *     method:string,
     *     path:string,
     *     description:string,
     *     params:string,
     *     example_url:string,
     *     sample:string,
     *     doc:string,
     *     status:string
     * }> $rows
     */
    private function writeIndex(array $rows): void
    {
        File::ensureDirectoryExists(dirname(base_path(self::INDEX_PATH)));

        $lines = [
            '# NHL API Endpoint Index',
            '',
            'Generated by `php artisan nhl:api` from the NHL API reference.',
            '',
            '| Endpoint | Method | Path | Description | Params | Example URL | Sample File | Breakdown File | Status |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| `' . $this->escapeMarkdown($row['slug']) . '` | '
                . '`' . $this->escapeMarkdown($row['method']) . '` | '
                . '`' . $this->escapeMarkdown($row['path']) . '` | '
                . $this->escapeMarkdown($row['description']) . ' | '
                . $this->escapeMarkdown($row['params']) . ' | '
                . ($row['example_url'] === '' ? '' : '`' . $this->escapeMarkdown($row['example_url']) . '`') . ' | '
                . '`' . $this->escapeMarkdown($row['sample']) . '` | '
                . '`' . $this->escapeMarkdown($row['doc']) . '` | '
                . $this->escapeMarkdown($row['status']) . ' |';
        }

        File::put(base_path(self::INDEX_PATH), implode(PHP_EOL, $lines) . PHP_EOL);
        File::put(base_path(self::API_INDEX_PATH), $this->apiIndexMarkdown($rows));
    }

    /**
     * Build the narrative endpoint index matching the source-reference block format.
     *
     * @param array<int,array{
     *     slug:string,
     *     method:string,
     *     path:string,
     *     description:string,
     *     params:string,
     *     example_url:string,
     *     sample:string,
     *     doc:string,
     *     status:string
     * }> $rows
     */
    private function apiIndexMarkdown(array $rows): string
    {
        $lines = [
            '# NHL API Index',
            '',
            'Source reference: https://github.com/Zmalski/NHL-API-Reference',
            '',
            'This file mirrors the source-reference endpoint format and adds DynastyIQ file targets for each endpoint.',
            '',
        ];

        foreach ($rows as $row) {
            $lines[] = '## ' . $this->titleFromSlug($row['slug']);
            $lines[] = '';
            $lines[] = 'Endpoint: ' . $row['path'];
            $lines[] = 'Method: ' . $row['method'];
            $lines[] = 'Description: ' . ($row['description'] === '' ? 'No description provided in source reference.' : $row['description']);
            $lines[] = 'Parameters: ' . ($row['params'] === '' ? 'None documented.' : $row['params']);
            $lines[] = 'Response: JSON format';
            $lines[] = 'Example using cURL:';
            $lines[] = $row['example_url'] === ''
                ? 'No runnable cURL example is available from the source reference.'
                : 'curl -X GET "' . $row['example_url'] . '"';
            $lines[] = 'Raw JSON: ' . $row['sample'];
            $lines[] = 'Usage File: ' . $row['doc'];
            $lines[] = 'Local Status: ' . $row['status'];
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Convert an endpoint slug into a readable heading.
     */
    private function titleFromSlug(string $slug): string
    {
        return str($slug)
            ->replace('-', ' ')
            ->title()
            ->toString();
    }

}
