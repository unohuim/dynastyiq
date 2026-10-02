<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An evaluation with frozen model identity, game selection and settings. */
class NhlSatEngineRun extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'completed_at' => 'datetime', 'work_generation' => 'integer'];
    }

    /** The persisted qualification candidates produced by this evaluation run. */
    public function candidates(): HasMany
    {
        return $this->hasMany(NhlSatEngineCandidate::class, 'run_id');
    }

    /** Whether workers may still write to this run. */
    public function active(): bool
    {
        return in_array($this->status, ['queued', 'running', 'ranking'], true);
    }

    /** Whether this run retains work that an administrator may resume. */
    public function paused(): bool
    {
        return $this->status === 'paused';
    }
}
