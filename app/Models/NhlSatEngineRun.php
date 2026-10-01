<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An evaluation with frozen model identity, game selection and settings. */
class NhlSatEngineRun extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'completed_at' => 'datetime'];
    }

    /** Whether workers may still write to this run. */
    public function active(): bool
    {
        return in_array($this->status, ['queued', 'running', 'ranking'], true);
    }
}
