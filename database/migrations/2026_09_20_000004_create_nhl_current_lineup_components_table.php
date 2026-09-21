<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_current_lineup_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nhl_current_lineup_id')->constrained()->cascadeOnDelete();
            $table->string('component_type', 16);
            $table->foreignId('nhl_lineup_observation_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_representative')->default(false);
            $table->timestamps();

            $table->unique(
                ['nhl_current_lineup_id', 'component_type', 'nhl_lineup_observation_id'],
                'nhl_current_lineup_component_unique'
            );
            $table->index(
                ['nhl_current_lineup_id', 'component_type', 'is_representative'],
                'nhl_current_lineup_component_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_current_lineup_components');
    }
};
