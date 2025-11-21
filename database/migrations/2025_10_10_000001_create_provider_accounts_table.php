<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('status')->default('disconnected');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_accounts');
    }
};
