<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized asset movement inside a fantasy platform transaction.
 */
class PlatformTransactionEntry extends Model
{
    protected $fillable = [
        'platform_transaction_id',
        'entry_index',
        'asset_type',
        'action',
        'from_platform_team_id',
        'to_platform_team_id',
        'platform_team_id',
        'player_id',
        'platform_player_identity_id',
        'provider_player_id',
        'raw_name',
        'from_slot',
        'to_slot',
        'draft_year',
        'draft_round',
        'draft_pick',
        'draft_original_team_name',
        'draft_original_team_provider_id',
        'raw_payload',
    ];

    protected $casts = [
        'entry_index' => 'integer',
        'draft_year' => 'integer',
        'draft_round' => 'integer',
        'draft_pick' => 'integer',
        'raw_payload' => 'array',
    ];

    /**
     * Parent transaction event.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PlatformTransaction::class, 'platform_transaction_id');
    }

    /**
     * Team the asset moved from, when known.
     */
    public function fromTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class, 'from_platform_team_id');
    }

    /**
     * Team the asset moved to, when known.
     */
    public function toTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class, 'to_platform_team_id');
    }

    /**
     * Provider-perspective team for claim/drop or lineup entries, when useful.
     */
    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class, 'platform_team_id');
    }

    /**
     * Matched canonical player, when known.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
