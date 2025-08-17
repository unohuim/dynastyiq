<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class FantraxLeague
 *
 * Stores internal PK plus external Fantrax league ID.
 * Relations match on the external `fantrax_league_id` string.
 */
class FantraxLeague extends Model
{
    protected $fillable = [
        'fantrax_league_id',
        'league_name',
        'draft_type',
    ];

    /**
     * Teams in this league (matched by external league id).
     */
    public function teams(): HasMany
    {
        return $this->hasMany(FantraxTeam::class, 'fantrax_league_id', 'fantrax_league_id');
    }

    /**
     * User ↔ league/team pivot rows for this league (matched by external league id).
     */
    public function leagueUserTeams(): HasMany
    {
        return $this->hasMany(FantraxLeagueUserTeam::class, 'fantrax_league_id', 'fantrax_league_id');
    }

    /**
     * Query helper for external id.
     */
    public function scopeExternal($query, string $extId)
    {
        return $query->where('fantrax_league_id', $extId);
    }
}
