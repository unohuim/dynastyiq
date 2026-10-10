<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin evaluation container; legacy experiments and revisioned initial forecast outlooks. */
class NhlNextGameEvaluation extends Model
{
    public const VERSION = 'next_game_buckets_v1';
    public const OUTLOOK_VERSION = 'next_game_outlook_v1';

    public const ACTIVE_STATUSES = ['queued', 'preparing', 'running', 'building'];

    public const STALLED_SECONDS = 180;

    protected $guarded = [];

    protected $casts = ['inputs' => 'array'];

    /** Only an unchanged checkpoint token may perform work or report its failure. */
    public function acceptsWork(?string $token): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true)
            && data_get($this->inputs, '_work.token') === $token;
    }

    /** A silent worker death is recoverable after the short work lease expires. */
    public function canResume(): bool
    {
        return $this->status === 'failed'
            || (in_array($this->status, self::ACTIVE_STATUSES, true)
                && $this->updated_at?->lt(now()->subSeconds(self::STALLED_SECONDS)));
    }
}
