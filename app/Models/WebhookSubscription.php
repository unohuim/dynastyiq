<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Global partner webhook configuration; inventory mutations never dispatch delivery work. */
class WebhookSubscription extends Model
{
    public const EVENTS = ['prediction.available' => 'New Prediction Available'];

    protected $fillable = ['api_client_id', 'name', 'receiving_url', 'events', 'is_enabled', 'signing_secret'];

    protected $hidden = ['signing_secret'];

    protected $casts = ['events' => 'array', 'is_enabled' => 'boolean', 'signing_secret' => 'encrypted'];

    /** Inspect the newest delivery without exposing destination tokens or request bodies. */
    public function latestDelivery(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany();
    }

    /** Associate global configuration with its partner identity, never its access token. */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }
}
