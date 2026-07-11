<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * First-party analytics event emitted by browser-side UI tracking.
 */
class AnalyticsEvent extends Model
{
    protected $fillable = [
        'analytics_visitor_id',
        'analytics_session_id',
        'user_id',
        'event_name',
        'path',
        'referrer',
        'properties',
        'occurred_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Visitor that emitted this event.
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisitor::class, 'analytics_visitor_id');
    }

    /**
     * Session that emitted this event.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSession::class, 'analytics_session_id');
    }

    /**
     * Linked authenticated user, when known.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
