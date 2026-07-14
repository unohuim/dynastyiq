<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Applies filters that require assembled stats rows.
 */
final class StatsDerivedFilterApplier
{
    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array{0:Collection<int,array<string,mixed>>,1:array{filters:array<string,mixed>},2:array<int,array<string,mixed>>}
     */
    public function apply(?Request $request, Collection $rows): array
    {
        $bounds = [
            'gp' => [
                'min' => (int) ($rows->min('gp') ?? 0),
                'max' => (int) ($rows->max('gp') ?? 0),
            ],
            'contract_value_num' => [
                'min' => (float) ($rows->min('contract_value_num') ?? 0.0),
                'max' => (float) ($rows->max('contract_value_num') ?? 0.0),
            ],
            'contract_last_year_num' => [
                'min' => (int) ($rows->min('contract_last_year_num') ?? 0),
                'max' => (int) ($rows->max('contract_last_year_num') ?? 0),
            ],
        ];

        $virtualSchema = [];
        if ($bounds['gp']['max'] > 0) {
            $virtualSchema[] = ['key' => 'gp', 'label' => 'GP', 'type' => 'number', 'bounds' => $bounds['gp'], 'step' => 1];
        }
        if ($bounds['contract_value_num']['max'] > 0) {
            $virtualSchema[] = [
                'key' => 'contract_value_num',
                'label' => 'Cap',
                'type' => 'number',
                'bounds' => [
                    'min' => (float) floor($bounds['contract_value_num']['min']),
                    'max' => (float) ceil($bounds['contract_value_num']['max']),
                ],
                'step' => 0.1,
            ];
        }
        if ($bounds['contract_last_year_num']['max'] > 0) {
            $virtualSchema[] = [
                'key' => 'contract_last_year_num',
                'label' => 'Term End',
                'type' => 'number',
                'bounds' => $bounds['contract_last_year_num'],
                'step' => 1,
            ];
        }

        if (! $request) {
            return [$rows, ['filters' => []], $virtualSchema];
        }

        $gpMin = $request->query('gp_min');
        $gpMax = $request->query('gp_max');
        $contractMin = $request->query('contract_value_num_min');
        $contractMax = $request->query('contract_value_num_max');
        $lastYearMin = $request->query('contract_last_year_num_min');
        $lastYearMax = $request->query('contract_last_year_num_max');

        $filtered = $rows->filter(function (array $row) use (
            $gpMin,
            $gpMax,
            $contractMin,
            $contractMax,
            $lastYearMin,
            $lastYearMax
        ): bool {
            if ($gpMin !== null && $row['gp'] < (int) $gpMin) {
                return false;
            }
            if ($gpMax !== null && $row['gp'] > (int) $gpMax) {
                return false;
            }
            if ($contractMin !== null && (float) $row['contract_value_num'] < (float) $contractMin) {
                return false;
            }
            if ($contractMax !== null && (float) $row['contract_value_num'] > (float) $contractMax) {
                return false;
            }
            if ($lastYearMin !== null && (int) $row['contract_last_year_num'] < (int) $lastYearMin) {
                return false;
            }
            if ($lastYearMax !== null && (int) $row['contract_last_year_num'] > (int) $lastYearMax) {
                return false;
            }

            return true;
        })->values();

        $applied = ['filters' => []];
        if ($gpMin !== null || $gpMax !== null) {
            $applied['filters']['gp'] = [
                'min' => $gpMin !== null ? (int) $gpMin : null,
                'max' => $gpMax !== null ? (int) $gpMax : null,
            ];
        }
        if ($contractMin !== null || $contractMax !== null) {
            $applied['filters']['contract_value_num'] = [
                'min' => $contractMin !== null ? (float) $contractMin : null,
                'max' => $contractMax !== null ? (float) $contractMax : null,
            ];
        }
        if ($lastYearMin !== null || $lastYearMax !== null) {
            $applied['filters']['contract_last_year_num'] = [
                'min' => $lastYearMin !== null ? (int) $lastYearMin : null,
                'max' => $lastYearMax !== null ? (int) $lastYearMax : null,
            ];
        }

        return [$filtered, $applied, $virtualSchema];
    }
}
