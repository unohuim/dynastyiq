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
        Schema::create('players', function (Blueprint $table) {
            $table->id();

            // External IDs (not actual foreign keys)
            $table->unsignedBigInteger('nhl_id')->nullable()->unique()->index();
            $table->unsignedBigInteger('nhl_team_id')->nullable()->index();
            

            // Name and personal info
            $table->string('full_name')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob')->nullable();
            $table->string('country_code')->nullable();

            // Hockey-specific info
            $table->boolean('is_prospect')->default(false);
            $table->boolean('is_goalie')->default(false); // skater vs goalie
            $table->string('position')->nullable(); // e.g. "C", "RW", "G"
            $table->string('pos_type')->nullable(); // e.g. "F", "D", "G"
            $table->string('team_abbrev')->nullable(); // e.g. "TBL"
            $table->string('current_league_abbrev')->nullable(); // e.g. "NHL"

            // Physical attributes
            $table->enum('shoots', ['R', 'L'])->nullable();
            $table->string('height')->nullable(); // format: "6'2"
            $table->unsignedSmallInteger('weight')->nullable(); // lbs

            // Images
            $table->text('head_shot_url')->nullable();
            $table->text('hero_image_url')->nullable();

            // Optional player status: "active", "free_agent", "retired"
            $table->string('status')->default('active');

            // Optional JSON blob for external metadata (EP, Fantrax, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
