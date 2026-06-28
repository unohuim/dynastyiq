<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Real hockey player transaction history sourced from NHL-domain providers.
 */
class NhlPlayerTransaction extends Model
{
    public const SOURCE_CAPWAGES = 'capwages';

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
        'player_id' => 'integer',
        'player_external_identity_id' => 'integer',
        'transaction_date' => 'date',
        'raw_payload' => 'array',
    ];

    /**
     * Linked canonical player, when available.
     *
     * @return BelongsTo<Player,NhlPlayerTransaction>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Linked provider identity row.
     *
     * @return BelongsTo<PlayerExternalIdentity,NhlPlayerTransaction>
     */
    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(PlayerExternalIdentity::class, 'player_external_identity_id');
    }
}
