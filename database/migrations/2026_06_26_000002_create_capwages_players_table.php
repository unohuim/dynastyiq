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
        Schema::create('capwages_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_external_identity_id')
                ->nullable()
                ->constrained('player_external_identities')
                ->nullOnDelete();
            $table->foreignId('player_id')
                ->nullable()
                ->constrained('players')
                ->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name')->nullable();
            $table->string('team')->nullable();
            $table->string('position', 20)->nullable();
            $table->string('league_status', 40)->nullable();
            $table->unsignedBigInteger('nhl_id')->nullable()->index();
            $table->unsignedSmallInteger('jersey_number')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('nationality', 40)->nullable();
            $table->string('hand', 40)->nullable();
            $table->string('height_imperial', 40)->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->string('weight_imperial', 40)->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->string('acquisition_method', 80)->nullable();
            $table->string('acquisition_details')->nullable();
            $table->unsignedSmallInteger('acquisition_year')->nullable();
            $table->unsignedTinyInteger('acquisition_round')->nullable();
            $table->unsignedSmallInteger('acquisition_overall_pick')->nullable();
            $table->string('acquisition_draft_team', 40)->nullable();
            $table->unsignedTinyInteger('elc_signing_age')->nullable();
            $table->unsignedTinyInteger('waivers_eligibility_age')->nullable();
            $table->timestamp('api_last_updated')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('player_external_identity_id');
            $table->index('player_id');
            $table->index(['league_status', 'team', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capwages_players');
    }
};
