<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        )->withPivot(['linked_at', 'archived_at', 'status', 'meta', 'created_at', 'updated_at'])
         ->withTimestamps();
    }

    public function drafts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Draft::class,
            LeaguePlatformLeague::class,
            'league_id',
            'platform_league_id',
            'id',
            'platform_league_id',
        );
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(LeagueUserRole::class);
    }

    public function activePlatformLeague(): ?PlatformLeague
    {
        return $this->activePlatformBinding()?->platformLeague;
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

    /**
     * Return the current active provider binding for this league wrapper.
     */
    public function activePlatformBinding(): ?LeaguePlatformLeague
    {
        return LeaguePlatformLeague::query()
            ->with('platformLeague')
            ->where('league_id', $this->id)
            ->active()
            ->latest('linked_at')
            ->latest('id')
            ->first();
    }

    /**
     * Return the provider scope metadata for the active binding.
     *
     * @return array{scope_type:mixed,scope_key:mixed,scope_label:mixed}
     */
    public function activePlatformScope(): array
    {
        $meta = $this->activePlatformBinding()?->meta ?? [];

        return [
            'scope_type' => data_get($meta, 'scope_type'),
            'scope_key' => data_get($meta, 'scope_key'),
            'scope_label' => data_get($meta, 'scope_label'),
        ];
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
