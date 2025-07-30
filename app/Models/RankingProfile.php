<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RankingProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * The user who owns this profile.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The tenant/organization this profile belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    /**
     * All individual player rankings in this profile.
     */
    public function playerRankings(): HasMany
    {
        return $this->hasMany(PlayerRanking::class, 'ranking_profile_id');
    }

    /**
     * The perspectives that include this profile.
     */
    public function perspectives(): BelongsToMany
    {
        return $this->belongsToMany(
            Perspective::class,
            'perspective_ranking_profile',
            'ranking_profile_id',
            'perspective_id'
        );
    }
}
