<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fantasy platform transaction event imported from a provider league.
 */
class PlatformTransaction extends Model
{
    protected $fillable = [
        'platform_league_id',
        'platform',
        'provider_transaction_id',
        'source_key',
        'source_view',
        'transaction_type',
        'occurred_at',
        'period',
        'executed',
        'deleted',
        'status',
        'summary',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'executed' => 'boolean',
        'deleted' => 'boolean',
        'raw_payload' => 'array',
    ];

    /**
     * Parent platform league for this provider transaction.
     */
    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    /**
     * Asset movements contained in this transaction.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(PlatformTransactionEntry::class, 'platform_transaction_id')
            ->orderBy('entry_index');
    }
}
