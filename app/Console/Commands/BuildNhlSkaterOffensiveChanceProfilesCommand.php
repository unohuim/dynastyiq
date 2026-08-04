<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlSkaterOffensiveChanceProfilesJob;
use Illuminate\Console\Command;

/**
 * Queue historical skater offensive chance profile buckets.
 */
class BuildNhlSkaterOffensiveChanceProfilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:skater-offensive-chance-profiles
                            {--season=20252026 : Source season id to profile}
                            {--game-type=2 : NHL game type to profile}';

    /**
     * @var string
     */
    protected $description = 'Queue historical NHL skater offensive chance profile bucket jobs';

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

        BuildNhlSkaterOffensiveChanceProfilesJob::dispatch($seasonId, $gameType);

        $this->info("Queued skater offensive chance profiles for {$seasonId} game type {$gameType}.");

        return self::SUCCESS;
    }
}
