<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable public-source observation of an anticipated NHL lineup. */
class NhlLineupObservation extends Model
{
    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'nhl_game_id' => 'integer',
        'team_id' => 'integer',
        'like_count' => 'integer',
        'reply_count' => 'integer',
        'repost_count' => 'integer',
        'view_count' => 'integer',
        'provider_published_at' => 'datetime',
        'observed_at' => 'datetime',
        'raw_evidence' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EvidenceSource::class, 'source_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(NhlLineupObservationPlayer::class);
    }
}
