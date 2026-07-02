<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_unit_game_strength_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nhl_game_id')->constrained('nhl_games', 'nhl_game_id')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('nhl_units')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('team_abbrev', 10)->nullable()->index();
            $table->enum('strength', ['EV', 'PP', 'PK'])->index();
            $table->unsignedInteger('toi')->default(0);
            $table->unsignedSmallInteger('shifts')->default(0);
            $table->unsignedSmallInteger('ozs')->default(0);
            $table->unsignedSmallInteger('nzs')->default(0);
            $table->unsignedSmallInteger('dzs')->default(0);
            $table->unsignedSmallInteger('gf')->default(0);
            $table->unsignedSmallInteger('ga')->default(0);
            $table->unsignedSmallInteger('sf')->default(0);
            $table->unsignedSmallInteger('sa')->default(0);
            $table->unsignedSmallInteger('satf')->default(0);
            $table->unsignedSmallInteger('sata')->default(0);
            $table->unsignedSmallInteger('ff')->default(0);
            $table->unsignedSmallInteger('fa')->default(0);
            $table->unsignedSmallInteger('bf')->default(0);
            $table->unsignedSmallInteger('ba')->default(0);
            $table->unsignedSmallInteger('hf')->default(0);
            $table->unsignedSmallInteger('ha')->default(0);
            $table->unsignedSmallInteger('fow')->default(0);
            $table->unsignedSmallInteger('fol')->default(0);
            $table->unsignedSmallInteger('fot')->default(0);
            $table->unsignedSmallInteger('pim_f')->default(0);
            $table->unsignedSmallInteger('pim_a')->default(0);
            $table->unsignedSmallInteger('penalties_f')->default(0);
            $table->unsignedSmallInteger('penalties_a')->default(0);
            $table->timestamps();

            $table->unique(['nhl_game_id', 'unit_id', 'strength'], 'nhl_unit_game_strength_unique');
            $table->index(['nhl_game_id', 'strength']);
            $table->index(['unit_id', 'strength']);
        });

        Schema::create('nhl_player_game_strength_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nhl_game_id')->constrained('nhl_games', 'nhl_game_id')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_player_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('team_abbrev', 10)->nullable()->index();
            $table->enum('strength', ['EV', 'PP', 'PK'])->index();
            $table->unsignedInteger('toi')->default(0);
            $table->unsignedSmallInteger('shifts')->default(0);
            $table->unsignedSmallInteger('ozs')->default(0);
            $table->unsignedSmallInteger('nzs')->default(0);
            $table->unsignedSmallInteger('dzs')->default(0);
            $table->unsignedSmallInteger('gf')->default(0);
            $table->unsignedSmallInteger('ga')->default(0);
            $table->unsignedSmallInteger('sf')->default(0);
            $table->unsignedSmallInteger('sa')->default(0);
            $table->unsignedSmallInteger('satf')->default(0);
            $table->unsignedSmallInteger('sata')->default(0);
            $table->unsignedSmallInteger('ff')->default(0);
            $table->unsignedSmallInteger('fa')->default(0);
            $table->unsignedSmallInteger('bf')->default(0);
            $table->unsignedSmallInteger('ba')->default(0);
            $table->unsignedSmallInteger('hf')->default(0);
            $table->unsignedSmallInteger('ha')->default(0);
            $table->unsignedSmallInteger('fow')->default(0);
            $table->unsignedSmallInteger('fol')->default(0);
            $table->unsignedSmallInteger('fot')->default(0);
            $table->unsignedSmallInteger('pim_f')->default(0);
            $table->unsignedSmallInteger('pim_a')->default(0);
            $table->unsignedSmallInteger('penalties_f')->default(0);
            $table->unsignedSmallInteger('penalties_a')->default(0);
            $table->unsignedSmallInteger('individual_g')->default(0);
            $table->unsignedSmallInteger('individual_a')->default(0);
            $table->unsignedSmallInteger('individual_pts')->default(0);
            $table->decimal('ipp', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['nhl_game_id', 'player_id', 'strength'], 'nhl_player_game_strength_unique');
            $table->index(['nhl_player_id', 'strength']);
            $table->index(['nhl_game_id', 'strength']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_player_game_strength_summaries');
        Schema::dropIfExists('nhl_unit_game_strength_summaries');
    }
};
