<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Revocable public roster share link for one fantasy platform team.
 */
class PlatformTeamRosterShareLink extends Model
{
    protected $guarded = [];

    protected $casts = [
        'encrypted_token' => 'encrypted',
        'is_public' => 'boolean',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Create a high-entropy token suitable for bearer-style public roster URLs.
     */
    public static function newPlainToken(): string
    {
        return Str::random(64);
    }

    /**
     * Hash a plain token for lookup without storing it in plaintext.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Parent internal community league wrapper.
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * Parent fantasy platform league.
     */
    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class);
    }

    /**
     * Shared fantasy team.
     */
    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class);
    }

    /**
     * User who created the share link.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether the public URL should resolve.
     */
    public function isAccessible(): bool
    {
        return $this->is_public
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
