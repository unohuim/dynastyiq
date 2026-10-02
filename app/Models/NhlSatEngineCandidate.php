<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A persisted qualification candidate from one SAT engine evaluation run. */
class NhlSatEngineCandidate extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array', 'metrics' => 'array', 'meets_targets' => 'boolean'];
    }
}
