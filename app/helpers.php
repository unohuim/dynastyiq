<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;



if (! function_exists('current_season_id')) {
    function current_season_id(): string
    {
        $year = now()->year;
        $month = now()->month;

        return $month < 7
            ? ($year - 1) . $year       // e.g., June 2025 → 20242025
            : $year . ($year + 1);      // e.g., October 2025 → 20252026
    }
}




if (! function_exists('current_season_id')) {
    /**
     * Get the current hockey season ID based on the current date.
     *
     * Hockey seasons typically begin in the fall (e.g., October),
     * so the season ID switches mid-calendar year.
     *
     * Examples:
     * - January 2025 → '20242025'
     * - October 2025 → '20252026'
     *
     * @return string The current season ID in YYYYYYYY format.
     */
    function current_season_id(): string
    {
        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        return $month < 7
            ? (string) ($year - 1) . $year
            : (string) $year . ($year + 1);
    }
}



if (!function_exists('parseToiMinutes')) {
    /**
     * Convert TOI string (e.g., "3268:33") to total minutes as float.
     *
     * @param string|null $toi
     * @return float|null
     */
    function parseToiMinutes(?string $toi): ?float
    {
        if (empty($toi) || !str_contains($toi, ':')) {
            return null;
        }

        [$min, $sec] = explode(':', $toi);
        return round(((int) $min + ((int) $sec / 60)), 2);
    }
}




if (! function_exists('apiUrl')) {
    /**
     * Build a full API URL from the config file with replacements.
     *
     * @param  string              $service       The API config section (e.g., 'nhl', 'fantrax', 'capwages').
     * @param  string              $endpointKey   The specific endpoint key in the config.
     * @param  array<string,mixed> $replacements  Placeholder replacements for template values.
     * @param  array<string,mixed> $query         Optional additional query parameters.
     *
     * @return string                            The complete URL including base, endpoint, and query string.
     */
    function apiUrl(
        string $service,
        string $endpointKey,
        array $replacements = [],
        array $query = []
    ): string {
        $cfg      = config("apiurls.{$service}", []);
        $base     = rtrim((string) ($cfg['base'] ?? ''), '/');
        $template = (string) ($cfg['endpoints'][$endpointKey] ?? '');

        // Substitute placeholders in the path
        foreach ($replacements as $key => $value) {
            $template = str_replace(
                "{{$key}}",
                (string) $value,
                $template
            );
        }

        // Assemble URL
        $url = $base . '/' . ltrim($template, '/');

        // Append any manual query string
        if (! empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}





