<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable notification outbox; destinations may contain private Discord tokens. */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    protected $hidden = ['destination', 'payload', 'claim_token'];

    protected $casts = ['destination' => 'encrypted', 'payload' => 'array', 'attempts' => 'integer',
        'queued_at' => 'datetime', 'next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime',
        'claim_expires_at' => 'datetime', 'delivered_at' => 'datetime'];
}
