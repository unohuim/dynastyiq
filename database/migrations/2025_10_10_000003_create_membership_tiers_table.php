<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_tiers');
    }
};
