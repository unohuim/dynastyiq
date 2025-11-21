<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        'provider_account_id',
        'event_type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'occurred_at'=> 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
