<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PredictionWebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A short bounded delivery; the durable outbox owns retries and abandoned-claim recovery. */
class DeliverPredictionWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 45;

    /** Queue identifiers only; secrets and notification bodies remain in the database. */
    public function __construct(public int $deliveryId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Never run a prediction or its preparation as part of notification delivery. */
    public function handle(PredictionWebhookDelivery $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
