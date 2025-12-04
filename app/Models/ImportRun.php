<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ran_at' => 'datetime',
    ];
}
