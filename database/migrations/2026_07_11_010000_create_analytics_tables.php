<?php

declare(strict_types=1);

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
        Schema::create('analytics_visitors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('anonymous_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('first_path', 2048)->nullable();
            $table->string('last_path', 2048)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at'], 'ix_analytics_visitors_user_seen');
        });

        Schema::create('analytics_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_visitor_id')->constrained('analytics_visitors')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('session_uuid')->unique();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('engaged_seconds')->default(0);
            $table->string('landing_path', 2048)->nullable();
            $table->string('last_path', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at'], 'ix_analytics_sessions_user_seen');
            $table->index(['analytics_visitor_id', 'last_seen_at'], 'ix_analytics_sessions_visitor_seen');
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_visitor_id')->constrained('analytics_visitors')->cascadeOnDelete();
            $table->foreignId('analytics_session_id')->nullable()->constrained('analytics_sessions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_name', 120);
            $table->string('path', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_name', 'occurred_at'], 'ix_analytics_events_name_time');
            $table->index(['user_id', 'occurred_at'], 'ix_analytics_events_user_time');
            $table->index(['analytics_visitor_id', 'occurred_at'], 'ix_analytics_events_visitor_time');
        });

        Schema::create('analytics_identity_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_visitor_id')->constrained('analytics_visitors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 32)->default('authenticated_request');
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(['analytics_visitor_id', 'user_id'], 'uq_analytics_identity_link_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_identity_links');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_sessions');
        Schema::dropIfExists('analytics_visitors');
    }
};
