<?php
// app/Models/Organization.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'slug',
        'settings',
        'owner_user_id',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    // --- Relationships ------------------------------------------------------

    public function leagues()
    {
        return $this->belongsToMany(League::class, 'organization_leagues')
            ->withPivot(['discord_server_id', 'linked_at', 'meta'])
            ->withTimestamps();
    }


    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['settings', 'deleted_at'])
            ->withTimestamps();
    }

    /** One org -> many connected Discord servers */
    public function discordServers(): HasMany
    {
        return $this->hasMany(\App\Models\DiscordServer::class);
    }

    public function providerAccounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }

    public function memberProfiles(): HasMany
    {
        return $this->hasMany(MemberProfile::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function membershipTiers(): HasMany
    {
        return $this->hasMany(MembershipTier::class);
    }

    // --- Convenience --------------------------------------------------------

    public function commissionerToolsEnabled(): bool
    {
        return (bool) data_get($this->settings, 'commissioner_tools', false);
    }

    public function creatorToolsEnabled(): bool
    {
        return (bool) data_get($this->settings, 'creator_tools', false);
    }

    /** Enabled = settings not null. */
    public function isEnabled(): bool
    {
        return !is_null($this->settings);
    }
}
