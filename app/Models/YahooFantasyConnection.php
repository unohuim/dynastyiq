<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable OAuth connection for a Yahoo Fantasy Sports user grant.
 */
class YahooFantasyConnection extends Model
{
    /**
     * The attributes that are not mass assignable.
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
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'connected_at' => 'datetime',
        'last_used_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Owning DynastyIQ user.
     *
     * @return BelongsTo<User,YahooFantasyConnection>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
