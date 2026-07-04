<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Draft extends Model
{
    protected $fillable = [
        'organization_id',
        'platform_league_id',
        'source_type',
        'platform',
        'external_draft_id',
        'name',
        'draft_type',
        'status',
        'starts_at',
        'completed_at',
        'pick_clock_seconds',
        'pause_between_picks_seconds',
        'auto_pick_enabled',
        'allow_trades',
        'draft_order_locked_at',
        'current_draft_pick_id',
        'settings',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'completed_at' => 'datetime',
        'pick_clock_seconds' => 'integer',
        'pause_between_picks_seconds' => 'integer',
        'auto_pick_enabled' => 'boolean',
        'allow_trades' => 'boolean',
        'draft_order_locked_at' => 'datetime',
        'settings' => 'array',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function picks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(DraftQueueItem::class);
    }

    public function currentPick(): BelongsTo
    {
        return $this->belongsTo(DraftPick::class, 'current_draft_pick_id');
    }

    public function notificationSettings(): HasOne
    {
        return $this->hasOne(DraftNotificationSetting::class);
    }
}
