<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One normalized player slot within a lineup observation. */
class NhlLineupObservationPlayer extends Model
{
    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'player_id' => 'integer',
        'nhl_player_id' => 'integer',
        'slot_index' => 'integer',
    ];
}
