<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-owned Yahoo Fantasy hockey player data.
 */
class YahooPlayer extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'yahoo_players';

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
        'player_external_identity_id' => 'integer',
        'player_id' => 'integer',
        'eligible_positions' => 'array',
        'raw_payload' => 'array',
        'imported_at' => 'datetime',
    ];

    /**
     * Linked canonical player, when available.
     *
     * @return BelongsTo<Player,YahooPlayer>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Linked provider identity row, when available.
     *
     * @return BelongsTo<PlayerExternalIdentity,YahooPlayer>
     */
    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(PlayerExternalIdentity::class, 'player_external_identity_id');
    }
}
