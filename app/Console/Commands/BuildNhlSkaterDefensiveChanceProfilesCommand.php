<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlSkaterDefensiveChanceProfilesJob;
use Illuminate\Console\Command;

/**
 * Queue historical skater on-ice defensive chance profile buckets.
 */
class BuildNhlSkaterDefensiveChanceProfilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:skater-defensive-chance-profiles
                            {--season=20252026 : Source season id to profile}
                            {--game-type=2 : NHL game type to profile}';

    /**
     * @var string
     */
    protected $description = 'Queue historical NHL skater defensive chance profile bucket jobs';

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

        BuildNhlSkaterDefensiveChanceProfilesJob::dispatch($seasonId, $gameType);

        $this->info("Queued skater defensive chance profiles for {$seasonId} game type {$gameType}.");

        return self::SUCCESS;
    }
}
