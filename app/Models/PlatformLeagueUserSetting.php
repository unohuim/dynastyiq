<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformLeagueUserSetting extends Model
{
    protected $fillable = [
        'platform_league_id',
        'user_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
