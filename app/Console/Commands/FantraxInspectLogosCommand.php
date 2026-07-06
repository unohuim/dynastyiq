<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformLeague;
use App\Traits\HasAPITrait;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

final class FantraxInspectLogosCommand extends Command
{
    use HasAPITrait;

    protected $signature = 'fantrax:inspect-logos
        {league : Platform league database ID or Fantrax league ID}
        {--platform-id : Treat the league argument as the Fantrax provider league ID}
        {--json : Output matches as JSON}';

    protected $description = 'Inspect Fantrax league payloads for logo-like keys and image URLs.';

    /**
     * Inspect known Fantrax endpoints for logo-like values.
     */
    public function handle(): int
    {
        $league = $this->platformLeague();

        if (! $league instanceof PlatformLeague) {
            $this->error('Fantrax platform league not found.');

            return self::FAILURE;
        }

        $providerLeagueId = (string) $league->platform_league_id;
        $endpoints = ['league_info', 'team_rosters', 'standings'];
        $matches = [];

        foreach ($endpoints as $endpoint) {
            try {
                $payload = $this->getAPIData('fantrax', $endpoint, [
                    'leagueId' => $providerLeagueId,
                ]);
            } catch (RequestException $exception) {
                $matches[] = [
                    'endpoint' => $endpoint,
                    'path' => null,
                    'key' => null,
                    'value' => 'Request failed: ' . $exception->getMessage(),
                ];

                continue;
            }

            if (is_array($payload)) {
                array_push($matches, ...$this->logoMatches($payload, $endpoint));
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($matches === []) {
            $this->warn('No logo-like keys or image URLs found in inspected Fantrax payloads.');

            return self::SUCCESS;
        }

        $this->table(['Endpoint', 'Path', 'Key', 'Value'], $matches);

        return self::SUCCESS;
    }

    /**
     * Resolve the platform league argument to a Fantrax league row.
     */
    private function platformLeague(): ?PlatformLeague
    {
        $league = (string) $this->argument('league');
        $query = PlatformLeague::query()->where('platform', 'fantrax');

        if ($this->option('platform-id') || ! ctype_digit($league)) {
            return $query->where('platform_league_id', $league)->first();
        }

        return $query
            ->where(static function ($query) use ($league): void {
                $query->where('id', (int) $league)
                    ->orWhere('platform_league_id', $league);
            })
            ->first();
    }

    /**
     * Return logo-like payload values with their source paths.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array{endpoint:string,path:string,key:string,value:string}>
     */
    private function logoMatches(array $payload, string $endpoint, string $path = '$'): array
    {
        $matches = [];

        foreach ($payload as $key => $value) {
            $keyPath = $path . '.' . (string) $key;

            if (is_array($value)) {
                array_push($matches, ...$this->logoMatches($value, $endpoint, $keyPath));

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $stringValue = trim((string) $value);

            if ($stringValue === '') {
                continue;
            }

            if (! $this->looksLikeLogoField((string) $key, $stringValue)) {
                continue;
            }

            $matches[] = [
                'endpoint' => $endpoint,
                'path' => $keyPath,
                'key' => (string) $key,
                'value' => $stringValue,
            ];
        }

        return $matches;
    }

    /**
     * Determine whether a payload key or value could identify a logo asset.
     */
    private function looksLikeLogoField(string $key, string $value): bool
    {
        $normalizedKey = strtolower($key);

        if (str_contains($normalizedKey, 'logo') || str_contains($normalizedKey, 'avatar')
            || str_contains($normalizedKey, 'image') || str_contains($normalizedKey, 'icon')) {
            return true;
        }

        return preg_match('/^https?:\/\/\S+\.(png|jpe?g|webp|gif)(\?\S*)?$/i', $value) === 1;
    }
}
