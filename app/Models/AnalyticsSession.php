<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * First-party analytics session scoped to a visitor cookie.
 */
class AnalyticsSession extends Model
{
    protected $fillable = [
        'analytics_visitor_id',
        'user_id',
        'session_uuid',
        'started_at',
        'last_seen_at',
        'ended_at',
        'engaged_seconds',
        'landing_path',
        'last_path',
        'referrer',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
        'engaged_seconds' => 'integer',
    ];

    /**
     * Visitor that owns this session.
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisitor::class, 'analytics_visitor_id');
    }

    /**
     * Linked authenticated user, when known.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Events emitted during this session.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }
}
