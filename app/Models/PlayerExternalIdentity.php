<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-sourced player identity used for import matching.
 */
class PlayerExternalIdentity extends Model
{
    public const PROVIDER_NHL = 'nhl';
    public const PROVIDER_FANTRAX = 'fantrax';
    public const PROVIDER_CAPWAGES = 'capwages';
    public const PROVIDER_ELITEPROSPECTS = 'eliteprospects';

    public const STATUS_MATCHED = 'matched';
    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_CONFLICT = 'conflict';

    public const REASON_NO_CANONICAL_PLAYER = 'no_canonical_player';
    public const REASON_MISSING_PROVIDER_PLAYER_ID = 'missing_provider_player_id';
    public const REASON_INSUFFICIENT_IDENTITY_DATA = 'insufficient_identity_data';
    public const REASON_MULTIPLE_CANDIDATES = 'multiple_candidates';
    public const REASON_PROVIDER_PAYLOAD_MISSING_NAME = 'provider_payload_missing_name';

    /**
     * The attributes that are mass assignable.
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
        'birthdate' => 'date',
        'raw_payload' => 'array',
        'match_confidence' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Canonical player linked to this provider identity.
     *
     * @return BelongsTo<Player,PlayerExternalIdentity>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Scope identities by provider.
     *
     * @param Builder<PlayerExternalIdentity> $query
     * @param string $provider
     * @return Builder<PlayerExternalIdentity>
     */
    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
