<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FantasyScoringCategoryMapping;
use App\Models\PlatformLeague;
use App\Models\PlatformLeagueScoringCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlatformLeagueScoringCategoryService
{
    /**
     * Upsert normalized scoring category rows for a platform league.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<string,string> $manualMappings
     */
    public function sync(PlatformLeague $league, array $categories, array $manualMappings = []): void
    {
        if ($categories === [] || ! $this->tableExists()) {
            return;
        }

        $now = now();
        $dictionaryMappingsByLabel = $this->dictionaryMappingsByNormalizedLabel((string) $league->platform);
        $seen = [];

        foreach ($this->dedupe($league, $categories) as $sortOrder => $category) {
            $identityKey = $this->identityKey($league, $category);
            $manualMappingKey = $category['selected_mapping_key']
                ?? $manualMappings[$identityKey]
                ?? $manualMappings[(string) ($category['id'] ?? '')]
                ?? null;
            $manualStatKey = $this->manualStatKey($manualMappingKey);
            $manualDictionaryLabel = $this->manualDictionaryLabel($manualMappingKey, (string) $league->platform);

            if ($manualStatKey !== null) {
                $category['stat_key'] = $manualStatKey;
                $category['mapping_source'] = 'manual';
                $category['is_supported'] = true;
            }

            if ($manualDictionaryLabel !== null) {
                $category['dictionary_provider_label'] = $manualDictionaryLabel;
                $category['mapping_source'] = 'manual';
            }

            $dictionaryLabel = trim((string) ($category['dictionary_provider_label'] ?? ''));
            $dictionaryMapping = $dictionaryLabel !== ''
                ? ($dictionaryMappingsByLabel[$this->normalizeLabel($dictionaryLabel)] ?? null)
                : null;

            if ($dictionaryMapping instanceof FantasyScoringCategoryMapping) {
                $schemaColumns = is_array($dictionaryMapping->required_schema_columns)
                    ? array_values($dictionaryMapping->required_schema_columns)
                    : [];
                $category['alignment_status'] = $dictionaryMapping->alignment_status;
                $category['formula'] = $dictionaryMapping->formula;
                $category['required_schema_columns'] = $schemaColumns;
                $category['is_supported'] = in_array(
                    $dictionaryMapping->alignment_status,
                    ['direct', 'formula', 'ignored_deprecated'],
                    true,
                );
                $category['support_message'] = $this->supportMessage(
                    $dictionaryMapping->alignment_status,
                    $dictionaryMapping->unavailable_reason,
                );

                if ($dictionaryMapping->alignment_status === 'direct') {
                    $category['stat_key'] = $schemaColumns[0] ?? $this->singleStatFormula($dictionaryMapping->formula);
                }
            }

            $seen[] = $identityKey;

            PlatformLeagueScoringCategory::query()->updateOrCreate(
                [
                    'platform_league_id' => $league->id,
                    'provider_identity_key' => $identityKey,
                ],
                [
                    'platform' => (string) $league->platform,
                    'provider_category_id' => $this->nullableString($category['id'] ?? null),
                    'provider_group' => $this->providerGroup($category),
                    'provider_code' => $this->providerCode($category),
                    'provider_short_label' => $this->nullableString($category['short'] ?? null),
                    'provider_label' => $this->providerLabel($category),
                    'normalized_group' => $this->normalizeGroup($this->providerGroup($category)),
                    'normalized_short_label' => $this->normalizeLabel($category['short'] ?? null),
                    'normalized_label' => $this->normalizeLabel($this->providerLabel($category)),
                    'value' => is_numeric($category['value'] ?? null) ? (float) $category['value'] : null,
                    'position_values' => is_array($category['position_values'] ?? null)
                        ? $category['position_values']
                        : null,
                    'dictionary_mapping_id' => $dictionaryMapping?->id,
                    'auto_mapping_key' => $this->nullableString($category['auto_mapping_key'] ?? null),
                    'manual_mapping_key' => $this->nullableString($manualMappingKey),
                    'selected_mapping_key' => $this->nullableString($manualMappingKey),
                    'stat_key' => $this->nullableString($category['stat_key'] ?? null),
                    'auto_stat_key' => $this->nullableString($category['auto_stat_key'] ?? null),
                    'mapping_source' => $this->nullableString($category['mapping_source'] ?? null),
                    'alignment_status' => $this->nullableString($category['alignment_status'] ?? null),
                    'formula' => $this->nullableString($category['formula'] ?? null),
                    'required_schema_columns' => is_array($category['required_schema_columns'] ?? null)
                        ? array_values($category['required_schema_columns'])
                        : [],
                    'is_supported' => (bool) ($category['is_supported'] ?? filled($category['stat_key'] ?? null)),
                    'support_message' => $this->nullableString($category['support_message'] ?? null),
                    'raw_payload' => is_array($category['raw_payload'] ?? null)
                        ? $category['raw_payload']
                        : ['value' => $category['raw_payload'] ?? null],
                    'sort_order' => (int) ($category['scoring_order'] ?? $category['sort_order'] ?? $sortOrder + 1),
                    'updated_at' => $now,
                ],
            );
        }

        PlatformLeagueScoringCategory::query()
            ->where('platform_league_id', $league->id)
            ->whereNotIn('provider_identity_key', $seen)
            ->delete();
    }

    /**
     * Return first-class category rows when present, otherwise legacy JSON rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function payloadRows(PlatformLeague $league): array
    {
        $rows = $this->persistedRows($league);

        if ($rows->isNotEmpty()) {
            return $rows
                ->map(fn (PlatformLeagueScoringCategory $category): array => $this->payloadRow($category))
                ->values()
                ->all();
        }

        $settings = data_get($league, 'scoring_settings.categories')
            ?? data_get($league, 'scoring_settings')
            ?? data_get($league, 'settings.scoring_categories')
            ?? data_get($league, 'extras.scoring_categories')
            ?? [];

        return is_array($settings)
            ? (array_is_list($settings) ? $settings : array_values($settings))
            : [];
    }

    /**
     * @return array<string,string>
     */
    public function manualMappings(PlatformLeague $league): array
    {
        $rows = $this->persistedRows($league);

        if ($rows->isNotEmpty()) {
            return $rows
                ->mapWithKeys(static function (PlatformLeagueScoringCategory $category): array {
                    $mapping = trim((string) $category->manual_mapping_key);

                    return $mapping !== '' ? [$category->provider_identity_key => $mapping] : [];
                })
                ->all();
        }

        $mappings = data_get($league, 'scoring_settings.manual_mappings', []);

        if (! is_array($mappings)) {
            return [];
        }

        return collect($mappings)
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->filter(static fn (string $value): bool => $value !== '')
            ->all();
    }

    /**
     * Persist manual mappings on first-class rows.
     *
     * @param array<string,string> $manualMappings
     * @param array<string,array<string,mixed>> $optionsByKey
     */
    public function updateManualMappings(
        PlatformLeague $league,
        array $manualMappings,
        array $optionsByKey,
    ): bool {
        $rows = $this->persistedRows($league);

        if ($rows->isEmpty()) {
            return false;
        }

        foreach ($rows as $category) {
            $manualMappingKey = $manualMappings[$category->provider_identity_key] ?? null;
            $autoMappingKey = $category->auto_mapping_key ?: $this->autoMappingKey($this->payloadRow($category));
            $selectedMappingKey = $manualMappingKey ?: $autoMappingKey;
            $option = $selectedMappingKey !== null ? ($optionsByKey[$selectedMappingKey] ?? null) : null;

            $updates = [
                'manual_mapping_key' => $manualMappingKey,
                'selected_mapping_key' => $manualMappingKey,
                'auto_mapping_key' => $autoMappingKey,
            ];

            if (is_array($option)) {
                $updates += [
                    'stat_key' => $option['stat_key'] ?? null,
                    'mapping_source' => $manualMappingKey
                        ? 'manual'
                        : (($option['type'] ?? null) === 'dictionary' ? 'dictionary' : 'auto'),
                    'alignment_status' => $option['alignment_status'] ?? $category->alignment_status,
                    'formula' => $option['formula'] ?? $category->formula,
                    'required_schema_columns' => $option['required_schema_columns'] ?? $category->required_schema_columns ?? [],
                    'is_supported' => (bool) ($option['is_supported'] ?? false),
                    'support_message' => $option['support_message'] ?? null,
                ];

                if (($option['type'] ?? null) === 'dictionary') {
                    $updates['provider_label'] = $category->provider_label;
                }
            }

            $category->forceFill($updates)->save();
        }

        return true;
    }

    private function tableExists(): bool
    {
        return Schema::hasTable('platform_league_scoring_categories');
    }

    /**
     * @return Collection<int,PlatformLeagueScoringCategory>
     */
    private function persistedRows(PlatformLeague $league): Collection
    {
        if (! $this->tableExists()) {
            return collect();
        }

        return PlatformLeagueScoringCategory::query()
            ->where('platform_league_id', $league->id)
            ->orderBy('sort_order')
            ->orderBy('provider_label')
            ->get();
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    private function dedupe(PlatformLeague $league, array $categories): array
    {
        $rows = [];

        foreach ($categories as $category) {
            $identityKey = $this->identityKey($league, $category);
            $existing = $rows[$identityKey] ?? null;

            if ($existing === null || $this->richnessScore($category) > $this->richnessScore($existing)) {
                $rows[$identityKey] = $category;
            }
        }

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $category
     */
    private function identityKey(PlatformLeague $league, array $category): string
    {
        $id = trim((string) ($category['id'] ?? ''));

        if ($id !== '') {
            [$group, $code] = array_pad(explode(':', $id, 2), 2, '');

            return strtolower($this->normalizeGroup($group) . ':' . ($code !== '' ? $code : $id));
        }

        return strtolower($this->normalizeGroup($this->providerGroup($category)) . ':' . (
            $this->providerCode($category)
            ?: $this->providerLabel($category)
            ?: (string) $league->id
        ));
    }

    /**
     * @param array<string,mixed> $category
     */
    private function richnessScore(array $category): int
    {
        $score = 0;

        foreach (['dictionary_provider_label', 'name', 'label', 'formula', 'alignment_status'] as $key) {
            if (trim((string) ($category[$key] ?? '')) !== '') {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @param array<string,mixed> $category
     */
    private function providerGroup(array $category): ?string
    {
        $id = trim((string) ($category['id'] ?? ''));

        if (str_contains($id, ':')) {
            return explode(':', $id, 2)[0];
        }

        return $this->nullableString($category['group'] ?? null);
    }

    /**
     * @param array<string,mixed> $category
     */
    private function providerCode(array $category): ?string
    {
        $id = trim((string) ($category['id'] ?? ''));

        if (str_contains($id, ':')) {
            return explode(':', $id, 2)[1];
        }

        return $this->nullableString($category['code'] ?? $category['short'] ?? null);
    }

    /**
     * @param array<string,mixed> $category
     */
    private function providerLabel(array $category): ?string
    {
        return $this->nullableString(
            $category['dictionary_provider_label']
            ?? $category['label']
            ?? $category['name']
            ?? $category['short']
            ?? null
        );
    }

    /**
     * @return array<string,int>
     */
    /**
     * @return array<string,FantasyScoringCategoryMapping>
     */
    private function dictionaryMappingsByNormalizedLabel(string $platform): array
    {
        if (! Schema::hasTable('fantasy_scoring_category_mappings')) {
            return [];
        }

        return FantasyScoringCategoryMapping::query()
            ->where('platform', $platform)
            ->get()
            ->mapWithKeys(fn (FantasyScoringCategoryMapping $mapping): array => [
                $this->normalizeLabel($mapping->provider_label) => $mapping,
            ])
            ->all();
    }

    private function normalizeGroup(?string $group): string
    {
        $group = strtoupper(trim((string) $group));

        return match ($group) {
            'SKATING' => 'HOCKEY_SKATING',
            'GOALIE' => 'HOCKEY_GOALIE',
            default => $group !== '' ? $group : 'UNKNOWN',
        };
    }

    private function normalizeLabel(mixed $label): string
    {
        $normalized = strtolower(trim((string) $label));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return $normalized;
    }

    /**
     * @param array<string,mixed> $category
     */
    private function autoMappingKey(array $category): ?string
    {
        if (filled($category['auto_mapping_key'] ?? null)) {
            return (string) $category['auto_mapping_key'];
        }

        if (filled($category['auto_stat_key'] ?? null)) {
            return 'stat:' . $category['auto_stat_key'];
        }

        if (filled($category['stat_key'] ?? null)) {
            return 'stat:' . $category['stat_key'];
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function manualStatKey(mixed $mappingKey): ?string
    {
        $mappingKey = trim((string) $mappingKey);

        if (! str_starts_with($mappingKey, 'stat:')) {
            return null;
        }

        return $this->nullableString(substr($mappingKey, 5));
    }

    private function manualDictionaryLabel(mixed $mappingKey, string $platform): ?string
    {
        $mappingKey = trim((string) $mappingKey);
        $prefix = 'dictionary:' . $platform . ':';

        if (! str_starts_with($mappingKey, $prefix)) {
            return null;
        }

        return $this->nullableString(substr($mappingKey, strlen($prefix)));
    }

    private function singleStatFormula(?string $formula): ?string
    {
        $formula = trim((string) $formula);

        return preg_match('/^[a-z][a-z0-9_]*$/', $formula) === 1 ? $formula : null;
    }

    private function supportMessage(string $status, ?string $unavailableReason): ?string
    {
        if ($status === 'unsupported') {
            return $unavailableReason ?: 'This category is not currently supported.';
        }

        if ($status === 'planned_derivation') {
            return 'This category needs a derived DynastyIQ stat before it is fully supported.';
        }

        return null;
    }

    private function payloadRow(PlatformLeagueScoringCategory $category): array
    {
        return [
            'id' => $category->provider_identity_key,
            'label' => $category->provider_label ?? $category->provider_short_label ?? $category->provider_code,
            'name' => $category->provider_label,
            'short' => $category->provider_short_label,
            'group' => $category->normalized_group ?? $category->provider_group,
            'provider_group' => $category->provider_group,
            'normalized_group' => $category->normalized_group,
            'value' => $category->value,
            'position_values' => $category->position_values ?? [],
            'auto_stat_key' => $category->auto_stat_key,
            'auto_mapping_key' => $category->auto_mapping_key,
            'selected_mapping_key' => $category->manual_mapping_key,
            'stat_key' => $category->stat_key,
            'is_mapped' => filled($category->stat_key) || filled($category->manual_mapping_key),
            'mapping_source' => $category->mapping_source,
            'dictionary_provider_label' => $category->dictionaryMapping?->provider_label,
            'alignment_status' => $category->alignment_status,
            'formula' => $category->formula,
            'required_schema_columns' => $category->required_schema_columns ?? [],
            'is_supported' => (bool) $category->is_supported,
            'support_message' => $category->support_message,
            'sort_order' => $category->sort_order,
            'raw_payload' => $category->raw_payload,
        ];
    }
}
