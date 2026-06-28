<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_player_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')
                ->nullable()
                ->constrained('players')
                ->nullOnDelete();
            $table->foreignId('player_external_identity_id')
                ->nullable()
                ->constrained('player_external_identities')
                ->nullOnDelete();
            $table->string('source', 40);
            $table->string('source_key')->unique();
            $table->string('source_transaction_id')->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('transaction_type', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('from_team')->nullable();
            $table->string('to_team')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('player_id');
            $table->index('player_external_identity_id');
            $table->index(['source', 'transaction_date']);
            $table->index(['source', 'transaction_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_player_transactions');
    }
};
