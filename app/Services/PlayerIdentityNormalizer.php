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
     * Normalize a name and remove separator spaces for punctuation-variant matching.
     */
    public function compactNormalizedName(?string $name): ?string
    {
        $normalized = $this->normalizeName($name);

        if ($normalized === null) {
            return null;
        }

        $compact = str_replace(' ', '', $normalized);

        return $compact === '' ? null : $compact;
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
     * Return configured first-name variants, including the supplied name.
     *
     * @return array<int,string>
     */
    public function firstNameVariants(?string $firstName): array
    {
        $normalized = $this->normalizeName($firstName);

        if ($normalized === null) {
            return [];
        }

        $variants = [$normalized];

        foreach ((array) config('name_variants.first_name_variants', []) as $canonical => $aliases) {
            $candidateVariants = collect([$canonical, ...((array) $aliases)])
                ->map(fn (mixed $name): ?string => $this->normalizeName(is_string($name) ? $name : null))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (in_array($normalized, $candidateVariants, true)) {
                $variants = array_values(array_unique([...$variants, ...$candidateVariants]));
                break;
            }
        }

        return $variants;
    }

    /**
     * Return normalized, explicitly configured alternate references for one canonical player.
     * Full aliases also support first-initial and surname references; callers retain team
     * preference and must reject ambiguous results. Never rewrite canonical display names.
     *
     * @return array<int,string>
     */
    public function playerNameAliasReferences(?string $canonicalName): array
    {
        $canonical = $this->normalizeName($canonicalName);
        if ($canonical === null) {
            return [];
        }

        $references = [];
        foreach ((array) config('name_variants.player_reference_aliases', []) as $name => $aliases) {
            if ($this->normalizeName((string) $name) !== $canonical) {
                continue;
            }
            foreach ((array) $aliases as $alias) {
                $normalized = $this->normalizeName(is_string($alias) ? $alias : null);
                if ($normalized !== null) {
                    $references[] = $normalized;
                }
            }
        }
        foreach ((array) config('name_variants.player_name_aliases', []) as $name => $aliases) {
            if ($this->normalizeName((string) $name) !== $canonical) {
                continue;
            }
            foreach ((array) $aliases as $alias) {
                $normalized = $this->normalizeName(is_string($alias) ? $alias : null);
                if ($normalized === null) {
                    continue;
                }
                $references[] = $normalized;
                $parts = explode(' ', $normalized, 2);
                if (count($parts) === 2) {
                    $references[] = mb_substr($parts[0], 0, 1) . ' ' . $parts[1];
                    $references[] = $parts[1];
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Determine whether two first names are compatible through exact or configured variant matching.
     */
    public function firstNamesAreCompatible(?string $firstName, ?string $otherFirstName): bool
    {
        $firstName = $this->normalizeName($firstName);
        $otherFirstName = $this->normalizeName($otherFirstName);

        if ($firstName === null || $otherFirstName === null) {
            return false;
        }

        if ($firstName === $otherFirstName) {
            return true;
        }

        if ($this->compactNormalizedName($firstName) === $this->compactNormalizedName($otherFirstName)) {
            return true;
        }

        if ($this->yiEndingVariant($firstName) === $this->yiEndingVariant($otherFirstName)) {
            return true;
        }

        return in_array($otherFirstName, $this->firstNameVariants($firstName), true);
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

    /**
     * Normalize final y/i variants without changing broader name matching rules.
     */
    private function yiEndingVariant(string $name): string
    {
        return preg_replace('/[yi]$/', '#', $name) ?? $name;
    }
}
