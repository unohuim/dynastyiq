<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record for linking an anonymous analytics visitor to a user.
 */
class AnalyticsIdentityLink extends Model
{
    protected $fillable = [
        'analytics_visitor_id',
        'user_id',
        'method',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    /**
     * Visitor being linked.
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisitor::class, 'analytics_visitor_id');
    }

    /**
     * User being linked.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
