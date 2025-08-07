<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Team;
use App\Models\NhlUnitShift;


/**
 * Class NhlUnit
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $unit_type
 * @property string|null $unit_name
 * @property array|null $player_ids
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property \Illuminate\Database\Eloquent\Collection|Player[] $players
 * @property \Illuminate\Database\Eloquent\Collection|NhlUnitGameSummary[] $gameSummaries
 */
class NhlUnit extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'player_ids' => 'array',
    ];

    /**
     * The players that belong to this unit.
     *
     * @return BelongsToMany
     */
    public function players()
    {
        return $this->belongsToMany(Player::class, 'nhl_unit_players', 'unit_id', 'player_id');
    }

    /**
     * Get the game summaries for this unit.
     *
     * @return HasMany
     */
    public function gameSummaries()
    {
        return $this->hasMany(NhlUnitGameSummary::class, 'unit_id');
    }


    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }


    public function unitShifts()
    {
        return $this->hasMany(NhlUnitShift::class, 'unit_id');
    }




}
