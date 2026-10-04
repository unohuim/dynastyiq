<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One engine's explicit priority inside a saved SAT engine stack. */
final class NhlSatEngineStackMember extends Model
{
    protected $guarded = ['id'];

    public function engine(): BelongsTo
    {
        return $this->belongsTo(NhlSatEngine::class);
    }

    /** The named stack that owns this membership. */
    public function stack(): BelongsTo
    {
        return $this->belongsTo(NhlSatEngineStack::class);
    }
}
