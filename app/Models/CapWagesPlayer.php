<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-owned CapWages player profile data.
 */
class CapWagesPlayer extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'capwages_players';

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
        'nhl_id' => 'integer',
        'jersey_number' => 'integer',
        'birth_date' => 'date',
        'height_cm' => 'integer',
        'weight_kg' => 'integer',
        'acquisition_year' => 'integer',
        'acquisition_round' => 'integer',
        'acquisition_overall_pick' => 'integer',
        'elc_signing_age' => 'integer',
        'waivers_eligibility_age' => 'integer',
        'api_last_updated' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * Linked canonical player, when available.
     *
     * @return BelongsTo<Player,CapWagesPlayer>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Linked provider identity row.
     *
     * @return BelongsTo<PlayerExternalIdentity,CapWagesPlayer>
     */
    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(PlayerExternalIdentity::class, 'player_external_identity_id');
    }
}
