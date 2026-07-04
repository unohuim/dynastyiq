<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DraftQueueItem extends Model
{
    protected $fillable = [
        'draft_id',
        'user_id',
        'player_id',
        'rank',
        'notes',
        'locked_until',
    ];

    protected $casts = [
        'rank' => 'integer',
        'locked_until' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
