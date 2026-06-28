<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NHL-owned team reference data used to normalize provider team strings.
 */
class NhlTeam extends Model
{
    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int,string>
     */
    protected $guarded = [];

    /**
     * Attribute casts.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'nhl_id' => 'integer',
        'raw_payload' => 'array',
    ];
}
