<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Current evidence-backed anticipated lineup projection. */
class NhlCurrentLineup extends Model
{
    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'nhl_game_id' => 'integer',
        'team_id' => 'integer',
        'source_count' => 'integer',
        'first_observed_at' => 'datetime',
        'last_observed_at' => 'datetime',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(NhlLineupObservation::class, 'nhl_lineup_observation_id');
    }

    /** Return observations supporting the current forward and defense groups. */
    public function components(): HasMany
    {
        return $this->hasMany(NhlCurrentLineupComponent::class);
    }
}
