
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {


    public function up(): void
    {
        Schema::create('platform_player_ids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();

            $table->enum('platform', ['fantrax', 'yahoo', 'espn'])->index();
            $table->string('platform_player_id'); // e.g., Fantrax player id
            $table->json('extras')->nullable();   // raw or provider payload

            $table->timestamps();

            $table->unique(['platform', 'platform_player_id'], 'uq_platform_player_external');
            $table->unique(['platform', 'player_id'], 'uq_platform_player_link'); // 1:1 per platform
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_player_ids');
    }
};
