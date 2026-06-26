<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Normalizes provider player identity fields for matching.
 */
class PlayerIdentityNormalizer
{
    /**
     * Normalize a human name for deterministic matching.
     */
    public function normalizeName(?string $name): ?string
    {
        $name = trim((string)$name);

        if ($name === '') {
            return null;
        }

        $normalized = Str::lower(Str::ascii($name));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Build a display name from provider first and last name fields.
     */
    public function displayNameFromParts(?string $firstName, ?string $lastName): ?string
    {
        $displayName = trim(trim((string)$firstName) . ' ' . trim((string)$lastName));

        return $displayName === '' ? null : $displayName;
    }

    /**
     * Extract the default NHL localized value from a nested API field.
     *
     * @param array<string,mixed> $payload
     */
    public function nhlLocalizedDefault(array $payload, string $key): ?string
    {
        $value = data_get($payload, "{$key}.default");

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
