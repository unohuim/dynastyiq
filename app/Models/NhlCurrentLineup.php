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

    /** Verify the actual selected groups, not the stored evidence-status label. */
    public function hasVerifiedPlayers(): bool
    {
        $this->loadMissing('observation.players', 'components.observation.players');
        $components = $this->components->where('is_representative', true);
        $players = $components->isEmpty()
            ? collect($this->observation?->players ?? [])
            : collect($components->firstWhere('component_type', 'forwards')?->observation?->players ?? [])
                ->where('lineup_role', 'forward')
                ->concat(collect($components->firstWhere('component_type', 'defense')?->observation?->players ?? [])
                    ->where('lineup_role', 'defense'));

        return app(\App\Services\NhlLineupPlayerResolver::class)->verifiedLineupIds($players->values()->toArray()) !== null;
    }
}
