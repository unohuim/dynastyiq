<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\NhleLeagueFactor;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Applies opt-in NHLe adjustments to prospect stat payload rows.
 */
final class NhleProspectLens
{
    private const ADJUSTED_KEYS = [
        'g',
        'a',
        'pts',
        'g_per_gp',
        'a_per_gp',
        'pts_per_gp',
    ];

    public function __construct(private readonly NhleLeagueFactorResolver $resolver)
    {
    }

    /**
     * Apply NHLe factors to skater prospect rows when explicitly enabled.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function apply(array $payload, bool $enabled): array
    {
        $payload['settings'] = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['settings']['nhleLens'] = $enabled;

        if (! $enabled || (string) ($payload['meta']['leagueProspectMode'] ?? '') !== 'skaters') {
            $payload['meta']['nhle'] = ['active' => false];

            return $payload;
        }

        $factors = $this->resolver->latestFactors();
        if ($factors->isEmpty()) {
            $payload['meta']['nhle'] = [
                'active' => false,
                'reason' => 'no_factors',
            ];

            return $payload;
        }

        $matchedRows = 0;
        $unmatchedLeagues = [];

        $payload['data'] = collect($payload['data'] ?? [])
            ->map(function ($row) use (&$matchedRows, &$unmatchedLeagues) {
                if (! is_array($row)) {
                    return $row;
                }

                $league = (string) ($row['league'] ?? $row['league_abbrev'] ?? '');
                $factor = $this->resolver->resolve($league);
                if (trim($league) !== '' && ! $this->resolver->isExplicitlyMapped($league)) {
                    $unmatchedLeagues[$league] = ($unmatchedLeagues[$league] ?? 0) + 1;
                }

                if (! $factor instanceof NhleLeagueFactor) {
                    $row['nhle_factor'] = null;
                    $row['nhle_unmapped'] = true;

                    return $row;
                }

                $multiplier = (float) $factor->points_factor;
                $matchedRows++;
                $row['nhle_factor'] = $multiplier;
                $row['nhle_source_league'] = $factor->source_league_name;
                $row['nhle_unmapped'] = trim($league) !== '' && ! $this->resolver->isExplicitlyMapped($league);

                foreach (self::ADJUSTED_KEYS as $key) {
                    $this->adjustKey($row, $key, $multiplier);
                }

                return $row;
            })
            ->values()
            ->all();

        $firstFactor = $factors->first();
        $this->writeUnmatchedLeagueTroubleshooting($unmatchedLeagues, $firstFactor);
        $payload['meta']['nhle'] = [
            'active' => true,
            'source' => NhleLeagueFactorResolver::DEFAULT_SOURCE,
            'source_version' => $firstFactor?->source_version,
            'model_name' => $firstFactor?->model_name,
            'model_window' => $firstFactor?->model_window,
            'matched_rows' => $matchedRows,
            'unmatched_leagues' => array_values(array_keys($unmatchedLeagues)),
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function adjustKey(array &$row, string $key, float $multiplier): void
    {
        $value = $this->numericValue($row[$key] ?? null);
        if ($value === null) {
            return;
        }

        $adjusted = round($value * $multiplier, 3);
        $row[$key] = $adjusted;

        if (is_array($row['stats'] ?? null) && array_key_exists($key, $row['stats'])) {
            $row['stats'][$key] = $adjusted;
        }
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = str_replace([',', '$', '%'], '', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @param array<string,int> $unmatchedLeagues
     */
    private function writeUnmatchedLeagueTroubleshooting(array $unmatchedLeagues, ?NhleLeagueFactor $factor): void
    {
        if ($unmatchedLeagues === []) {
            return;
        }

        try {
            $path = base_path('troubleshooting/nhle/unmatched_leagues.md');
            $existing = File::exists($path) ? (string) File::get($path) : '';
            $seen = $this->existingTroubleshootingKeys($existing);
            $lines = [];

            foreach ($unmatchedLeagues as $league => $count) {
                $normalized = $this->resolver->normalizeLeagueKey($league);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }

                $lines[] = sprintf(
                    '| %s | %s | %s | %d | %s | %s |',
                    now('UTC')->toDateTimeString(),
                    str_replace('|', '/', $league),
                    $normalized,
                    $count,
                    NhleLeagueFactorResolver::DEFAULT_SOURCE,
                    (string) ($factor?->source_version ?? ''),
                );
            }

            if ($lines === []) {
                return;
            }

            File::ensureDirectoryExists(dirname($path));
            if ($existing === '') {
                $existing = "# Unmatched NHLe Leagues\n\n"
                    ."| First Seen UTC | League Label | Normalized Label | Payload Rows | Source | Source Version |\n"
                    ."| --- | --- | --- | ---: | --- | --- |\n";
            } elseif (! str_ends_with($existing, "\n")) {
                $existing .= "\n";
            }

            File::put($path, $existing.implode("\n", $lines)."\n");
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array<string,true>
     */
    private function existingTroubleshootingKeys(string $contents): array
    {
        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $columns = array_values(array_filter(
                array_map('trim', explode('|', $line)),
                static fn (string $column): bool => $column !== '',
            ));

            if (count($columns) < 3 || $columns[0] === 'First Seen UTC' || str_starts_with($columns[0], '---')) {
                continue;
            }

            $keys[$columns[2]] = true;
        }

        return $keys;
    }
}
