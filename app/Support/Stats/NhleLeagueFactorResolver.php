<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\NhleLeagueFactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves imported league labels to versioned NHLe factor rows.
 */
final class NhleLeagueFactorResolver
{
    public const DEFAULT_SOURCE = 'nl_ice_data';

    /**
     * Runtime aliases that repair known source/import spelling gaps even before reseeding.
     *
     * @var array<string,array<int,string>>
     */
    private const RUNTIME_SOURCE_ALIASES = [
        'Allsvenskan' => ['HOCKEYALLSVENSKAN'],
        'Czech' => ['CZECHIA'],
        'Czech2' => ['CZECHIA2'],
        'Czech U20' => ['CZECHIA U20'],
        'Independent' => ['NCAA'],
        'J20 Nationell' => ['U20 NATIONELL'],
        'U20 Finland' => ['U20 SM-SARJA'],
    ];

    /**
     * @var array<string,array<string,NhleLeagueFactor>>
     */
    private array $factorMaps = [];

    /**
     * Resolve an imported league label to a source NHLe factor.
     */
    public function resolve(string $league, string $source = self::DEFAULT_SOURCE): ?NhleLeagueFactor
    {
        $normalized = $this->normalizeLeagueKey($league);
        if ($normalized === '') {
            return null;
        }

        $map = $this->factorMap($source);
        if (array_key_exists($normalized, $map)) {
            return $map[$normalized];
        }

        return $this->lowestFactor($source);
    }

    /**
     * Determine whether a league label matched source names or explicit mapped codes without fallback.
     */
    public function isExplicitlyMapped(string $league, string $source = self::DEFAULT_SOURCE): bool
    {
        $normalized = $this->normalizeLeagueKey($league);
        if ($normalized === '') {
            return false;
        }

        return array_key_exists($normalized, $this->factorMap($source));
    }

    /**
     * Return the latest source version for a factor source.
     */
    public function latestVersion(string $source = self::DEFAULT_SOURCE): ?string
    {
        if (! Schema::hasTable('nhle_league_factors')) {
            return null;
        }

        $version = NhleLeagueFactor::query()
            ->where('source', $source)
            ->max('source_version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Return latest factor rows for a source.
     *
     * @return Collection<int,NhleLeagueFactor>
     */
    public function latestFactors(string $source = self::DEFAULT_SOURCE): Collection
    {
        $version = $this->latestVersion($source);
        if ($version === null) {
            return collect();
        }

        return NhleLeagueFactor::query()
            ->where('source', $source)
            ->where('source_version', $version)
            ->get();
    }

    /**
     * Normalize league labels before explicit matching.
     */
    public function normalizeLeagueKey(string $league): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($league)) ?? '';
    }

    /**
     * @return array<string,NhleLeagueFactor>
     */
    private function factorMap(string $source): array
    {
        if (array_key_exists($source, $this->factorMaps)) {
            return $this->factorMaps[$source];
        }

        $map = [];

        foreach ($this->latestFactors($source) as $factor) {
            $keys = array_merge(
                [$factor->source_league_name],
                is_array($factor->mapped_league_codes) ? $factor->mapped_league_codes : [],
                self::RUNTIME_SOURCE_ALIASES[$factor->source_league_name] ?? [],
            );

            foreach ($keys as $key) {
                $normalized = $this->normalizeLeagueKey((string) $key);
                if ($normalized !== '') {
                    $map[$normalized] = $factor;
                }
            }
        }

        $this->factorMaps[$source] = $map;

        return $map;
    }

    private function lowestFactor(string $source): ?NhleLeagueFactor
    {
        return $this->latestFactors($source)
            ->sortBy([
                ['points_factor', 'asc'],
                ['win_shares_factor', 'asc'],
                ['source_league_name', 'asc'],
            ])
            ->first();
    }
}
