<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Field-level delta between computed NHL game summary and official boxscore values.
 */
class NhlGameValidationDelta extends Model
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    protected $guarded = [];

    protected $casts = [
        'delta' => 'float',
    ];

    public function validation(): BelongsTo
    {
        return $this->belongsTo(NhlGameValidation::class, 'validation_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'nhl_player_id', 'nhl_id');
    }
}
