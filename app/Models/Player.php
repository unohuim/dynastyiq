<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ['nhl_id', 'first_name', 'last_name', 'team_abbrev', 'dob', 'country_code'];
}
