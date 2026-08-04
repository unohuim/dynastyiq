<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlGoalieChanceProfilesJob;
use Illuminate\Console\Command;

/**
 * Queue historical goalie chance profile buckets from scored shot-attempt facts.
 */
class BuildNhlGoalieChanceProfilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:goalie-chance-profiles
                            {--season=20252026 : Source season id to profile}
                            {--game-type=2 : NHL game type to profile}';

    /**
     * @var string
     */
    protected $description = 'Queue historical NHL goalie chance profile bucket jobs';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $seasonId = trim((string) $this->option('season'));
        $gameType = (int) $this->option('game-type');

        if ($seasonId === '') {
            $this->error('Season id is required.');

            return self::FAILURE;
        }

        BuildNhlGoalieChanceProfilesJob::dispatch($seasonId, $gameType);

        $this->info("Queued goalie chance profiles for {$seasonId} game type {$gameType}.");

        return self::SUCCESS;
    }
}
