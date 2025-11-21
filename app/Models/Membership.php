<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'provider_account_id',
        'member_profile_id',
        'membership_tier_id',
        'provider',
        'provider_member_id',
        'status',
        'pledge_amount_cents',
        'currency',
        'started_at',
        'ended_at',
        'synced_at',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
        'synced_at'  => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function memberProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class);
    }

    public function membershipTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class);
    }
}
