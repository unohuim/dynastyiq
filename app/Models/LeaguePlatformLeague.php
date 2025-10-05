<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaguePlatformLeague extends Model
{
    protected $table = 'league_platform_league'; // <-- actual table
    public $timestamps = false;
    protected $fillable = ['league_id','platform_league_id'];
}
