<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRanking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Scope a query to the player rankings visible to a given user (or guest).
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        // Always allow public_guest entries
        $query->where('visibility', 'public_guest');

        if ($user) {
            $query->orWhere(function (Builder $q) use ($user) {
                // Allow public_authenticated
                $q->where('visibility', 'public_authenticated')
                  // Or those they own
                  ->orWhere('author_id', $user->id)
                  // Or those in their tenant
                  ->orWhere('tenant_id', $user->tenant_id);
            });
        }

        return $query;
    }

    /**
     * The ranking profile this entry belongs to.
     */
    public function rankingProfile(): BelongsTo
    {
        return $this->belongsTo(RankingProfile::class, 'ranking_profile_id');
    }

    /**
     * The player this ranking applies to.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * The author (user) who created this ranking.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The tenant/organization context for this ranking.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }
}
