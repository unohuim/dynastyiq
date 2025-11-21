<?php

namespace App\Console\Commands;

use App\Models\PlayByPlay;
use App\Services\ShotGeometryService;
use Illuminate\Console\Command;

class BackfillShotGeometryCommand extends Command
{
    protected $signature = 'nhl:backfill-shot-geometry {--game-id=} {--chunk=500}';

    protected $description = 'Compute shot distance and angle for shot-attempt plays missing geometry.';

    public function __construct(private readonly ShotGeometryService $shotGeometryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $gameId = $this->option('game-id');
        $chunkSize = (int)($this->option('chunk') ?? 500);
        $updated = 0;
        $skipped = 0;

        $query = PlayByPlay::query()
            ->where(function ($builder) {
                $builder->whereNull('shot_distance')->orWhereNull('shot_angle');
            });

        if ($gameId) {
            $query->where('nhl_game_id', $gameId);
        }

        $query->orderBy('id')->chunkById($chunkSize, function ($plays) use (&$updated, &$skipped) {
            $plays->load(['game:id,nhl_game_id,home_team_id,away_team_id']);

            foreach ($plays as $play) {
                if (!$this->shotGeometryService->isShotAttempt($play->type_desc_key, $play->shot_type)) {
                    $skipped++;
                    continue;
                }

                $geometry = $this->shotGeometryService->computeFromPlay($play, $play->game);

                if (!$geometry) {
                    $skipped++;
                    continue;
                }

                $play->shot_distance = $geometry['distance'];
                $play->shot_angle = $geometry['angle'];
                $play->save();
                $updated++;
            }
        });

        $this->info("Updated {$updated} plays. Skipped {$skipped}.");

        return Command::SUCCESS;
    }
}
