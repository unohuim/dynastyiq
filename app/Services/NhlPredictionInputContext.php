<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;

/** Isolates reusable input snapshots to one prediction build and its engine delegation. */
final class NhlPredictionInputContext
{
    /** @var array<string,mixed> */
    private array $inputs = [];

    private int $depth = 0;

    /** Release every snapshot after the outer build, including failed builds. */
    public function run(Closure $build): mixed
    {
        $this->depth++;
        try {
            return $build();
        } finally {
            if (--$this->depth === 0) {
                $this->inputs = [];
            }
        }
    }

    /**
     * Remember successful input reads, including null; never cache thrown exceptions.
     *
     * @param array<int,mixed> $arguments All inputs affecting the resolved value.
     */
    public function remember(string $operation, array $arguments, Closure $resolve): mixed
    {
        if ($this->depth === 0) {
            return $resolve();
        }
        $key = $operation . ':' . hash('sha256', serialize($arguments));
        if (! array_key_exists($key, $this->inputs)) {
            $this->inputs[$key] = $this->copy($resolve());
        }

        return $this->copy($this->inputs[$key]);
    }

    /** Invalidate an input family after an intentional write during the build. */
    public function forget(string $operation): void
    {
        foreach (array_keys($this->inputs) as $key) {
            if (str_starts_with($key, $operation . ':')) {
                unset($this->inputs[$key]);
            }
        }
    }

    /** Copy nested input values so engine-specific mutation cannot affect later engines. */
    private function copy(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn (mixed $item): mixed => $this->copy($item));
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->copy($item), $value);
        }
        if ($value instanceof \stdClass) {
            $copy = clone $value;
            foreach (get_object_vars($copy) as $key => $item) {
                $copy->{$key} = $this->copy($item);
            }

            return $copy;
        }

        return $value;
    }
}
