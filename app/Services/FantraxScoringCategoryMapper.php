<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FantasyScoringCategoryMapping;
use Illuminate\Support\Facades\Schema;

class FantraxScoringCategoryMapper
{
    /**
     * Add dictionary mapping metadata to Fantrax scoring category rows.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    public function enrich(array $categories): array
    {
        if ($categories === [] || ! Schema::hasTable('fantasy_scoring_category_mappings')) {
            return $categories;
        }

        $mappings = $this->mappingsByLabel();

        if ($mappings === []) {
            return $categories;
        }

        return collect($categories)
            ->map(function (array $category) use ($mappings): array {
                $mapping = $this->mappingForCategory($category, $mappings);

                if ($mapping === null) {
                    $category['alignment_status'] = $category['alignment_status'] ?? null;
                    $category['formula'] = $category['formula'] ?? null;
                    $category['required_schema_columns'] = $category['required_schema_columns'] ?? [];
                    $category['is_supported'] = $category['is_supported'] ?? filled($category['stat_key'] ?? null);
                    $category['support_message'] = $category['support_message'] ?? null;

                    return $category;
                }

                $schemaColumns = is_array($mapping->required_schema_columns)
                    ? array_values($mapping->required_schema_columns)
                    : [];
                $directStatKey = $mapping->alignment_status === 'direct'
                    ? ($schemaColumns[0] ?? $this->singleStatFormula($mapping->formula))
                    : null;

                $category['dictionary_provider_label'] = $mapping->provider_label;
                $category['auto_mapping_key'] = 'dictionary:fantrax:' . $mapping->provider_label;
                $category['alignment_status'] = $mapping->alignment_status;
                $category['formula'] = $mapping->formula;
                $category['required_schema_columns'] = $schemaColumns;
                $category['unavailable_reason'] = $mapping->unavailable_reason;
                $category['support_notes'] = $mapping->notes;
                $category['is_supported'] = in_array($mapping->alignment_status, ['direct', 'formula', 'ignored_deprecated'], true);
                $category['support_message'] = $this->supportMessage($mapping->alignment_status, $mapping->unavailable_reason);

                if ($directStatKey !== null) {
                    $category['auto_stat_key'] = $directStatKey;
                    $category['stat_key'] = $directStatKey;
                    $category['mapping_source'] = 'dictionary';
                } elseif (($category['mapping_source'] ?? null) === null && $mapping->alignment_status === 'formula') {
                    $category['mapping_source'] = 'dictionary';
                }

                return $category;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,FantasyScoringCategoryMapping>
     */
    private function mappingsByLabel(): array
    {
        return FantasyScoringCategoryMapping::query()
            ->where('platform', 'fantrax')
            ->get()
            ->mapWithKeys(fn (FantasyScoringCategoryMapping $mapping): array => [
                $this->normalizeLabel($mapping->provider_label) => $mapping,
            ])
            ->all();
    }

    /**
     * @param array<string,mixed> $category
     * @param array<string,FantasyScoringCategoryMapping> $mappings
     */
    private function mappingForCategory(array $category, array $mappings): ?FantasyScoringCategoryMapping
    {
        foreach ([
            $category['name'] ?? null,
            $category['label'] ?? null,
            $category['short'] ?? null,
        ] as $label) {
            $key = $this->normalizeLabel($label);

            if ($key !== '' && isset($mappings[$key])) {
                return $mappings[$key];
            }
        }

        return null;
    }

    private function normalizeLabel(mixed $label): string
    {
        $normalized = strtolower(trim((string) $label));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return $normalized;
    }

    private function singleStatFormula(?string $formula): ?string
    {
        $formula = trim((string) $formula);

        return preg_match('/^[a-z][a-z0-9_]*$/', $formula) === 1 ? $formula : null;
    }

    private function supportMessage(string $status, ?string $unavailableReason): ?string
    {
        if ($status === 'unsupported') {
            return $unavailableReason ?: 'This Fantrax category is not currently supported.';
        }

        if ($status === 'planned_derivation') {
            return 'This Fantrax category needs a derived DynastyIQ stat before it is fully supported.';
        }

        return null;
    }
}
