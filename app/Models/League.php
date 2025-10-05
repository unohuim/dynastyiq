<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class League extends Model
{
    protected $fillable = [
        'name',
        'sport',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function organization(): HasOneThrough
    {
        return $this->hasOneThrough(
            Organization::class,
            OrganizationLeague::class,
            'league_id',
            'id',
            'id',
            'organization_id'
        );
    }

    public function platformLeagues(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformLeague::class,
            'league_platform_league',
            'league_id',
            'platform_league_id'
        )->withPivot(['linked_at', 'status', 'meta', 'created_at', 'updated_at'])
         ->withTimestamps();
    }

    public function activePlatformLeague(): ?PlatformLeague
    {
        return $this->platformLeagues()
            ->wherePivot('status', 'active')
            ->orderByPivot('linked_at', 'desc')
            ->first();
    }

    public function primaryPlatformLeague(): ?PlatformLeague
    {
        $active = $this->activePlatformLeague();
        if ($active) {
            return $active;
        }

        $recent = $this->platformLeagues()
            ->orderByPivot('linked_at', 'desc')
            ->first();

        return $recent ?? $this->platformLeagues()->first();
    }

    public function getPlatformAttribute(): ?string
    {
        return $this->primaryPlatformLeague()?->platform;
    }

    public function getPlatformLeagueIdAttribute(): ?string
    {
        return $this->primaryPlatformLeague()?->platform_league_id;
    }

    public function getIdentifierAttribute(): string
    {
        $platform = $this->platform;
        $id = $this->platform_league_id;

        return ($platform && $id) ? "{$platform}:{$id}" : '';
    }
}
