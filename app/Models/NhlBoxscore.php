<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhlBoxscore extends Model
{
    protected $guarded = [];

    protected $casts = [
        'toi_seconds' => 'integer',
        'faceoff_win_percentage' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
