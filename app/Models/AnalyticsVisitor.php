<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * First-party anonymous browser visitor that can later be linked to a user.
 */
class AnalyticsVisitor extends Model
{
    protected $fillable = [
        'anonymous_id',
        'user_id',
        'first_seen_at',
        'last_seen_at',
        'first_path',
        'last_path',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Linked authenticated user, when known.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Browser sessions created for this visitor.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AnalyticsSession::class);
    }

    /**
     * Events emitted by this visitor.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }
}
