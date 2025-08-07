<?php

namespace App\Models;
use App\Models\PlayByPlay;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NhlUnitShift extends Model
{
    protected $fillable = [
        'unit_id',
        'nhl_game_id',
        'period',
        'start_time',
        'end_time',
        'start_game_seconds',
        'end_game_seconds',
        'seconds',
        'team_id',
        'team_abbrev'
    ];

    /**
     * Get the unit this shift belongs to.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(NhlUnit::class, 'unit_id');
    }


    /**
     * The events associated with this shift.
     */
    public function events()
    {
        return $this->belongsToMany(
            PlayByPlay::class,
            'event_unit_shifts',    // Pivot table name
            'unit_shift_id',        // Foreign key on pivot for this model
            'event_id'              // Foreign key on pivot for related model
        )->orderBy('seconds_in_game')
            ->withTimestamps();
    }

}
