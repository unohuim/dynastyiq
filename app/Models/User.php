<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use App\Models\RankingProfile;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    public function socialAccounts()
    {
        return $this->hasMany(\App\Models\SocialAccount::class);
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
     * The leagues that the user belongs to.
     */
    public function leagues()
    {
        return $this->belongsToMany(League::class, 'league_user')
            ->withPivot(['is_commish', 'is_admin'])
            ->withTimestamps();
    }

    /**
     * Check if the user is a commissioner for a given league.
     */
    public function isCommissionerForLeague(int $leagueId): bool
    {
        return $this->leagues()
            ->where('league_id', $leagueId)
            ->wherePivot('is_commish', true)
            ->exists();
    }

    /**
     * Check if the user is an admin for a given league.
     */
    public function isAdminForLeague(int $leagueId): bool
    {
        return $this->leagues()
            ->where('league_id', $leagueId)
            ->wherePivot('is_admin', true)
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
