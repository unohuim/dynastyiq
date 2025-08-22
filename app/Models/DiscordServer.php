<?php
// app/Models/DiscordServer.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscordServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'discord_guild_id',
        'discord_guild_name',
        'installed_by_discord_user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'granted_permissions',
        'meta',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'meta'             => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
