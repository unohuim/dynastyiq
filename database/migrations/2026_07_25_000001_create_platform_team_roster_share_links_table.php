<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_team_roster_share_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->foreignId('platform_team_id')->constrained('platform_teams')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label');
            $table->string('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->boolean('is_public')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['platform_team_id', 'revoked_at'], 'ix_roster_share_team_revoked');
            $table->index(['platform_league_id', 'is_public', 'expires_at'], 'ix_roster_share_public_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_team_roster_share_links');
    }
};
