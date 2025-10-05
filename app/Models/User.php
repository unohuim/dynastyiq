<?php
// app/Models/User.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_team_id',
        'profile_photo_path',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // --- Relationships ------------------------------------------------------

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['settings', 'deleted_at'])
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        // Includes global roles (organization_id NULL) and org-scoped roles.
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot(['organization_id'])
            ->withTimestamps();
    }

    public function platformLeagues(): BelongsToMany
    {
        return $this->belongsToMany(PlatformLeague::class, 'league_user_teams', 'user_id', 'platform_league_id')
            ->withPivot(['team_id', 'is_active', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'league_user_teams', 'user_id', 'team_id')
            ->withPivot(['league_id', 'is_active', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    public function leagueUserTeams()
    {
        return $this->hasMany(LeagueUserTeam::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function rankingProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            RankingProfile::class,
            'player_rankings',
            'player_id',
            'ranking_profile_id'
        )
            ->withPivot(['score', 'description', 'visibility', 'settings'])
            ->withTimestamps();
    }

    public function integrationSecrets()
    {
        return $this->hasMany(IntegrationSecret::class);
    }

    public function fantraxSecret()
    {
        return $this->hasOne(IntegrationSecret::class)->where('provider', 'fantrax');
    }

    // --- Domain helpers -----------------------------------------------------

    public function isCommissionerForLeague(int $leagueId): bool
    {
        return LeagueUserTeam::query()
            ->where('user_id', $this->id)
            ->where('league_id', $leagueId)
            ->where('extras->is_commish', true)
            ->exists();
    }

    public function isAdminForLeague(int $leagueId): bool
    {
        return LeagueUserTeam::query()
            ->where('user_id', $this->id)
            ->where('league_id', $leagueId)
            ->where('extras->is_admin', true)
            ->exists();
    }

    public function hasGlobalRole(string $slug): bool
    {
        return $this->roles()
            ->where('roles.slug', $slug)
            ->where('roles.scope', 'global')
            ->whereNull('role_user.organization_id')
            ->exists();
    }

    public function hasOrgRole(string $slug, int $organizationId): bool
    {
        return $this->roles()
            ->where('roles.slug', $slug)
            ->where('roles.scope', 'organization')
            ->where('role_user.organization_id', $organizationId)
            ->exists();
    }

    public function hasAnyOrgRole(array $slugs, int $organizationId): bool
    {
        return $this->roles()
            ->whereIn('roles.slug', $slugs)
            ->where('roles.scope', 'organization')
            ->where('role_user.organization_id', $organizationId)
            ->exists();
    }
}
