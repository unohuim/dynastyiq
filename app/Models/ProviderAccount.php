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

        $identity = $this->meta['identity'] ?? [];
        $campaign = $this->meta['campaign'] ?? [];

        $handle = isset($identity['vanity'])
            ? '@' . ltrim((string) $identity['vanity'], '@')
            : null;

        $avatar = $identity['image_url']
            ?? $campaign['image_url']
            ?? null;
        $campaignName = $campaign['summary'] ?? null;

        $identityDisplay = $identity['full_name']
            ?? ($identity['vanity'] ?? null)
            ?? null;

        $displayName = $campaignName
            ?? $this->display_name
            ?? $identityDisplay
            ?? 'Patreon Creator';

        return [
            'identity' => $identity,
            'campaign' => $campaign,
            'avatar' => $avatar,
            'display' => array_filter([
                'name' => $displayName,
                'email' => $identity['email'] ?? null,
                'handle' => $handle,
                'account_id' => $identity['id'] ?? null,
                'campaign' => $campaignName,
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
