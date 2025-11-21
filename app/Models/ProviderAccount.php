<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'provider',
        'external_id',
        'display_name',
        'status',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'webhook_secret',
        'connected_at',
        'last_synced_at',
        'last_webhook_at',
        'last_sync_error',
        'meta',
    ];

    protected $casts = [
        'scopes'          => 'array',
        'meta'            => 'array',
        'token_expires_at'=> 'datetime',
        'connected_at'    => 'datetime',
        'last_synced_at'  => 'datetime',
        'last_webhook_at' => 'datetime',
    ];

    public function patreonIdentity(): array
    {
        if ($this->provider !== 'patreon') {
            return [];
        }

        $user = $this->meta['user'] ?? [];
        $campaign = $this->meta['campaign'] ?? [];
        $team = $this->meta['team'] ?? [];

        $handle = isset($user['vanity'])
            ? '@' . ltrim((string) $user['vanity'], '@')
            : null;

        $avatar = $user['image_url']
            ?? $campaign['image_url']
            ?? null;

        return [
            'user' => $user,
            'campaign' => $campaign,
            'team' => $team,
            'avatar' => $avatar,
            'display' => array_filter([
                'name' => $campaign['name']
                    ?? $user['full_name']
                    ?? $user['vanity']
                    ?? $user['email']
                    ?? $this->display_name
                    ?? 'Patreon',
                'email' => $user['email'] ?? null,
                'handle' => $handle,
                'account_id' => $user['id'] ?? null,
                'campaign' => $campaign['name'] ?? null,
                'campaign_id' => $campaign['id'] ?? null,
            ]),
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function membershipTiers(): HasMany
    {
        return $this->hasMany(MembershipTier::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MembershipEvent::class);
    }
}
