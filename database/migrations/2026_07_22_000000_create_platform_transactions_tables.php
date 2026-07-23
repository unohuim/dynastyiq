<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('provider_transaction_id')->nullable();
            $table->string('source_key');
            $table->string('source_view', 40);
            $table->string('transaction_type', 80);
            $table->timestamp('occurred_at')->nullable();
            $table->string('period', 64)->nullable();
            $table->boolean('executed')->nullable();
            $table->boolean('deleted')->default(false);
            $table->string('status', 80)->nullable();
            $table->text('summary')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_league_id', 'source_key'], 'uq_platform_transaction_source');
            $table->index(['platform_league_id', 'source_view', 'occurred_at'], 'ix_platform_transaction_view_time');
            $table->index(['platform_league_id', 'transaction_type', 'occurred_at'], 'ix_platform_transaction_type_time');
            $table->index('provider_transaction_id', 'ix_platform_transaction_provider_id');
        });

        Schema::create('platform_transaction_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_transaction_id')->constrained('platform_transactions')->cascadeOnDelete();
            $table->unsignedInteger('entry_index');
            $table->string('asset_type', 40);
            $table->string('action', 40);
            $table->foreignId('from_platform_team_id')->nullable()->constrained('platform_teams')->nullOnDelete();
            $table->foreignId('to_platform_team_id')->nullable()->constrained('platform_teams')->nullOnDelete();
            $table->foreignId('platform_team_id')->nullable()->constrained('platform_teams')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('platform_player_identity_id')->nullable()->constrained('platform_player_ids')->nullOnDelete();
            $table->string('provider_player_id')->nullable();
            $table->string('raw_name')->nullable();
            $table->string('from_slot')->nullable();
            $table->string('to_slot')->nullable();
            $table->unsignedSmallInteger('draft_year')->nullable();
            $table->unsignedTinyInteger('draft_round')->nullable();
            $table->unsignedSmallInteger('draft_pick')->nullable();
            $table->string('draft_original_team_name')->nullable();
            $table->string('draft_original_team_provider_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_transaction_id', 'entry_index'], 'uq_platform_transaction_entry_order');
            $table->index('from_platform_team_id', 'ix_platform_transaction_entry_from_team');
            $table->index('to_platform_team_id', 'ix_platform_transaction_entry_to_team');
            $table->index('platform_team_id', 'ix_platform_transaction_entry_team');
            $table->index('player_id', 'ix_platform_transaction_entry_player');
            $table->index('platform_player_identity_id', 'ix_platform_transaction_entry_platform_player');
            $table->index(['asset_type', 'action'], 'ix_platform_transaction_entry_asset_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_transaction_entries');
        Schema::dropIfExists('platform_transactions');
    }
};
