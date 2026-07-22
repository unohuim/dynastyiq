<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhlGameSourceStatus extends Model
{
    public const SOURCE_PBP = 'pbp';
    public const SOURCE_BOXSCORE = 'boxscore';
    public const SOURCE_SHIFTS = 'shifts';
    public const SOURCE_RIGHT_RAIL = 'right-rail';
    public const SOURCE_HTML_PBP = 'html-pbp';
    public const SOURCE_HTML_TOI = 'html-toi';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'checked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
