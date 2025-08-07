<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class League
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $platform
 * @property string $platform_league_id
 * @property string $name
 * @property string|null $sport
 * @property string|null $discord_server_id
 * @property array|null $draft_settings
 * @property array|null $scoring_settings
 * @property array|null $roster_settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class League extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'platform',
        'platform_league_id',
        'name',
        'sport',
        'discord_server_id',
        'draft_settings',
        'scoring_settings',
        'roster_settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'draft_settings' => 'array',
        'scoring_settings' => 'array',
        'roster_settings' => 'array',
    ];


    /**
     * The users that belong to the league.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'league_user')
            ->withPivot(['is_commish', 'is_admin'])
            ->withTimestamps();
    }
}
