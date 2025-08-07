<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class NhlShift
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $game_id
 * @property int $player_id
 * @property int $team_id
 * @property int|null $unit_id
 * @property int $shift_number
 * @property int $period
 * @property string $start_time
 * @property string $end_time
 * @property int $start_game_seconds
 * @property int $end_game_seconds
 * @property int $seconds
 * @property string $pos_type
 * @property string $position
 * @property string $team_abbrev
 * @property string $team_name
 * @property string $first_name
 * @property string $last_name
 * @property string|null $detail_code
 * @property string|null $event_description
 * @property string|null $event_details
 * @property string|null $event_number
 * @property int|null $type_code
 * @property string|null $hex_value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property NhlGame $game
 * @property Player $player
 * @property Team $team
 * @property NhlUnit|null $unit
 */
class NhlShift extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the game associated with the shift.
     *
     * @return BelongsTo
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class);
    }

    /**
     * Get the player associated with the shift.
     *
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Get the team associated with the shift.
     *
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the unit associated with the shift.
     *
     * @return BelongsTo
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(NhlUnit::class, 'unit_id');
    }
}
