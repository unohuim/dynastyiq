<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create global partner subscription inventory without scheduling deliveries. */
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('receiving_url');
            $table->json('events');
            $table->boolean('is_enabled')->default(false)->index();
            $table->text('signing_secret');
            $table->timestamps();
        });
    }

    /** Remove only the subscription inventory owned by this migration. */
    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
