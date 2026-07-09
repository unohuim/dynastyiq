<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Http\Request;

/**
 * Parsed stats filter selections from an HTTP request.
 */
final class StatsFilterSet
{
    /**
     * @param array<int,string> $positions
     * @param array<int,string> $positionTypes
     * @param array<int,string> $teams
     * @param array<int,string> $leagues
     * @param array<string,array{min:float|null,max:float|null}> $numericRanges
     */
    public function __construct(
        public readonly array $positions,
        public readonly array $positionTypes,
        public readonly array $teams,
        public readonly array $leagues,
        public readonly array $numericRanges,
    ) {
    }

    public static function fromRequest(?Request $request): self
    {
        if (! $request) {
            return new self([], [], [], [], []);
        }

        return new self(
            self::stringList($request->query('pos', [])),
            self::stringList($request->query('pos_type', [])),
            self::stringList($request->query('team', [])),
            self::stringList($request->query('league', [])),
            self::numericRanges($request->query()),
        );
    }

    /**
     * @return array{pos:array<int,string>,pos_type:array<int,string>}
     */
    public function positionEcho(): array
    {
        return [
            'pos' => $this->positions,
            'pos_type' => $this->positionTypes,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => trim((string) $item),
                (array) ($value ?? []),
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,array{min:float|null,max:float|null}>
     */
    private static function numericRanges(array $query): array
    {
        $ranges = [];

        foreach ($query as $key => $value) {
            if (! is_scalar($value) || ! preg_match('/^(.*)_(min|max)$/', (string) $key, $matches)) {
                continue;
            }

            $baseKey = (string) $matches[1];
            $bound = (string) $matches[2];
            $ranges[$baseKey] ??= ['min' => null, 'max' => null];
            $ranges[$baseKey][$bound] = is_numeric($value) ? (float) $value : null;
        }

        return $ranges;
    }
}
