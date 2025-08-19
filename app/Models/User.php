<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\League;
use App\Models\Team;
use App\Models\LeagueUserTeam;
use App\Models\RankingProfile;
use App\Models\IntegrationSecret;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'tenant_id',
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

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Leagues the user participates in (via league_user_teams).
     */
    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(League::class, 'league_user_teams', 'user_id', 'league_id')
            ->withPivot(['team_id', 'is_active', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    /**
     * Teams the user is assigned to (via league_user_teams).
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'league_user_teams', 'user_id', 'team_id')
            ->withPivot(['league_id', 'is_active', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    /**
     * User↔Team assignment rows.
     */
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
        )->withPivot(['score', 'description', 'visibility', 'settings'])
            ->withTimestamps();
    }

    /**
     * Integration secrets belonging to the user.
     */
    public function integrationSecrets()
    {
        return $this->hasMany(IntegrationSecret::class);
    }

    /**
     * Get the user's Fantrax secret (if any).
     */
    public function fantraxSecret()
    {
        return $this->hasOne(IntegrationSecret::class)->where('provider', 'fantrax');
    }

    /**
     * Check if the user is a commissioner for a given league.
     * Looks for extras.is_commish=true in league_user_teams.
     */
    public function isCommissionerForLeague(int $leagueId): bool
    {
        return LeagueUserTeam::query()
            ->where('user_id', $this->id)
            ->where('league_id', $leagueId)
            ->where('extras->is_commish', true)
            ->exists();
    }

    /**
     * Check if the user is an admin for a given league.
     * Looks for extras.is_admin=true in league_user_teams.
     */
    public function isAdminForLeague(int $leagueId): bool
    {
        return LeagueUserTeam::query()
            ->where('user_id', $this->id)
            ->where('league_id', $leagueId)
            ->where('extras->is_admin', true)
            ->exists();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('slug', $roleName)->exists();
    }

    public function hasAnyRole(array $roleNames): bool
    {
        return $this->roles()->whereIn('slug', $roleNames)->exists();
    }
}
