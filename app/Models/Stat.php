<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Player;

class Stat extends Model
{

    protected $guarded = [];

    
    public function scopeRegularSeason($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('game_type_id', 2); // or constant
    }



    //
	/**
     * A Stat belongs to a Player.
     */
    public function player()
    {
        return $this->belongsTo(Player::class);
    }

}
