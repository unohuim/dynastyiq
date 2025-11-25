<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'display_name',
        'avatar_url',
        'external_ids',
        'metadata',
    ];

    protected $casts = [
        'external_ids' => 'array',
        'metadata'     => 'array',
    ];

    public function getExternalId(string $provider): ?string
    {
        return data_get($this->external_ids, $provider);
    }

    public function attachExternalId(string $provider, string $externalId, bool $persist = true): void
    {
        $externalIds = (array) $this->external_ids;

        if (data_get($externalIds, $provider) === $externalId) {
            return;
        }

        data_set($externalIds, $provider, $externalId);
        $this->external_ids = $externalIds;

        if ($persist) {
            $this->save();
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
