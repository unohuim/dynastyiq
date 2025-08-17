<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class FantraxTeam
 *
 * Represents a Fantrax team keyed by external IDs.
 *
 * @property int $id
 * @property string $fantrax_league_id  External Fantrax league ID
 * @property string $fantrax_team_id    External Fantrax team ID
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FantraxTeam extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'fantrax_league_id',
        'fantrax_team_id',
        'name',
    ];

    /**
     * Pivot rows linking users to this team within the same external league.
     *
     * Matches by external team id and constrains league id to avoid cross-league collisions.
     */
    public function leagueUserTeams(): HasMany
    {
        return $this->hasMany(FantraxLeagueUserTeam::class, 'fantrax_team_id', 'fantrax_team_id')
            ->whereColumn('fantrax_league_user_teams.fantrax_league_id', 'fantrax_teams.fantrax_league_id');
    }
}
