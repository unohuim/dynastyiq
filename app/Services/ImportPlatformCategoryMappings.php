<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FantasyScoringCategoryMapping;
use App\Models\ImportRun;
use InvalidArgumentException;
use RuntimeException;

class ImportPlatformCategoryMappings
{
    private const EXPECTED_HEADERS = [
        'fantrax_label',
        'definition',
        'alignment_status',
        'formula',
        'required_schema_columns',
        'unavailable_reason',
        'notes',
    ];

    /**
     * Import a platform scoring-category mapping CSV.
     *
     * @return array{imported:int,status_counts:array<string,int>}
     */
    public function import(string $platform, string $path, ?ImportRun $importRun = null): array
    {
        $resolvedPath = $this->resolvePath($path);
        $rows = $this->readRows($resolvedPath);

        $importRun?->setProgressTotal(count($rows), 'Platform category mappings');

        $statusCounts = [];

        foreach ($rows as $row) {
            $status = $row['alignment_status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            FantasyScoringCategoryMapping::query()->updateOrCreate(
                [
                    'platform' => $platform,
                    'provider_label' => $row['fantrax_label'],
                ],
                [
                    'definition' => $this->nullable($row['definition']),
                    'alignment_status' => $row['alignment_status'],
                    'formula' => $this->nullable($row['formula']),
                    'required_schema_columns' => $this->schemaColumns($row['required_schema_columns']),
                    'unavailable_reason' => $this->nullable($row['unavailable_reason']),
                    'notes' => $this->nullable($row['notes']),
                ],
            );

            $importRun?->recordProcessed();
        }

        if ($importRun !== null) {
            $meta = is_array($importRun->meta) ? $importRun->meta : [];
            $importRun->forceFill([
                'meta' => array_merge($meta, [
                    'platform' => $platform,
                    'path' => $path,
                    'status_counts' => $statusCounts,
                ]),
            ])->save();
        }

        return [
            'imported' => count($rows),
            'status_counts' => $statusCounts,
        ];
    }

    private function resolvePath(string $path): string
    {
        $resolvedPath = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);

        if (! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException("Category mapping CSV [{$path}] is not readable.");
        }

        return $resolvedPath;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open category mapping CSV [{$path}].");
        }

        $headers = fgetcsv($handle, 0, ',', '"', '');

        if ($headers !== self::EXPECTED_HEADERS) {
            fclose($handle);

            throw new InvalidArgumentException('Category mapping CSV headers do not match the expected import template.');
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($values === [null] || $values === false) {
                continue;
            }

            if (count($values) !== count(self::EXPECTED_HEADERS)) {
                fclose($handle);

                throw new InvalidArgumentException("Category mapping CSV line {$line} has an invalid column count.");
            }

            $row = array_combine(self::EXPECTED_HEADERS, array_map(
                static fn (?string $value): string => trim((string) $value),
                $values,
            ));

            if (! is_array($row) || $row['fantrax_label'] === '' || $row['alignment_status'] === '') {
                fclose($handle);

                throw new InvalidArgumentException("Category mapping CSV line {$line} is missing a label or alignment status.");
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int,string>|null
     */
    private function schemaColumns(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $column): bool => $column !== '',
        ));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
