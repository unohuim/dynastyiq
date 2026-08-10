<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Game-scoped official assignment captured from NHL right-rail context.
 */
class NhlGameOfficial extends Model
{
    public const ROLE_REFEREE = 'referee';
    public const ROLE_LINESMAN = 'linesman';

    public const SOURCE_RIGHT_RAIL = 'right-rail';

    protected $guarded = [];

    protected $casts = [
        'raw_payload' => 'array',
    ];

    /**
     * Game for this official assignment.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Name-normalized official identity.
     */
    public function official(): BelongsTo
    {
        return $this->belongsTo(NhlOfficial::class, 'nhl_official_id');
    }
}
