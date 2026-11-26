<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Persist revision-owned notifications before publishing work to the queue. */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prediction_revision_id')->constrained('nhl_game_prediction_revisions')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('transport', 16);
            $table->text('destination');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('last_error', 250)->nullable();
            $table->string('remote_message_id', 40)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_subscription_id', 'prediction_revision_id', 'event'], 'webhook_delivery_revision_unique');
            $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_due_index');
        });
    }

    /** Remove delivery history without modifying predictions or subscriptions. */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
