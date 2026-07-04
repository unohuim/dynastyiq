<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('platform_league_id')->nullable()->constrained('platform_leagues')->nullOnDelete();
            $table->string('source_type', 40)->default('platform_mirror');
            $table->string('platform', 40)->nullable()->index();
            $table->string('external_draft_id', 160)->nullable();
            $table->string('name');
            $table->string('draft_type', 40)->nullable();
            $table->string('status', 32)->default('unknown')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('pick_clock_seconds')->nullable();
            $table->unsignedInteger('pause_between_picks_seconds')->nullable();
            $table->boolean('auto_pick_enabled')->default(false);
            $table->boolean('allow_trades')->default(true);
            $table->timestamp('draft_order_locked_at')->nullable();
            $table->unsignedBigInteger('current_draft_pick_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_draft_id'], 'uq_drafts_platform_external');
            $table->index(['platform_league_id', 'status'], 'idx_drafts_league_status');
            $table->index(['source_type', 'status'], 'idx_drafts_source_status');
        });

        Schema::create('draft_picks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->string('provider_pick_key', 120)->nullable();
            $table->unsignedInteger('overall_pick')->nullable();
            $table->unsignedInteger('round')->nullable();
            $table->unsignedInteger('pick')->nullable();
            $table->unsignedInteger('pick_in_round')->nullable();
            $table->foreignId('platform_team_id')->nullable()->constrained('platform_teams')->nullOnDelete();
            $table->string('provider_team_id')->nullable();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_player_id')->nullable();
            $table->foreignId('picked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 40)->default('dynastyiq')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'provider_pick_key'], 'uq_draft_picks_provider');
            $table->unique(['draft_id', 'overall_pick'], 'uq_draft_picks_overall');
            $table->index(['draft_id', 'status', 'overall_pick'], 'idx_draft_picks_status_order');
            $table->index(['source', 'provider_player_id'], 'idx_draft_picks_source_player');
        });

        Schema::table('drafts', function (Blueprint $table): void {
            $table->foreign('current_draft_pick_id', 'fk_drafts_current_pick')
                ->references('id')
                ->on('draft_picks')
                ->nullOnDelete();
        });

        Schema::create('draft_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->string('discord_channel_id')->nullable();
            $table->string('discord_channel_name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique('draft_id', 'uq_draft_notification_settings_draft');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draft_notification_settings');

        Schema::table('drafts', function (Blueprint $table): void {
            $table->dropForeign('fk_drafts_current_pick');
        });

        Schema::dropIfExists('draft_picks');
        Schema::dropIfExists('drafts');
    }
};
