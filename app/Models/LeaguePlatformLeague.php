<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider binding history for an internal community-facing league wrapper.
 */
class LeaguePlatformLeague extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNLINKED = 'unlinked';

    protected $table = 'league_platform_league';

    protected $fillable = [
        'league_id',
        'platform_league_id',
        'linked_at',
        'archived_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'archived_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Internal community-facing league wrapper for this binding.
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * Provider-owned platform league for this binding.
     */
    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    /**
     * Limit the query to active provider bindings.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Limit the query to a provider scope within a platform league.
     */
    public function scopeForProviderScope(Builder $query, ?string $scopeType, ?string $scopeKey): Builder
    {
        if ($scopeType === null && $scopeKey === null) {
            return $query
                ->where(function ($inner): void {
                    $inner
                        ->whereNull('meta->scope_type')
                        ->orWhere('meta->scope_type', '');
                })
                ->where(function ($inner): void {
                    $inner
                        ->whereNull('meta->scope_key')
                        ->orWhere('meta->scope_key', '');
                });
        }

        return $query
            ->where('meta->scope_type', $scopeType)
            ->where('meta->scope_key', $scopeKey);
    }

    /**
     * Limit the query to a platform league and provider scope.
     */
    public function scopeForPlatformScope(
        Builder $query,
        int $platformLeagueId,
        ?string $scopeType,
        ?string $scopeKey
    ): Builder {
        return $query
            ->where('platform_league_id', $platformLeagueId)
            ->forProviderScope($scopeType, $scopeKey);
    }
}
