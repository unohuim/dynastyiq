<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlOfficialSatProfilesJob;
use App\Jobs\BuildNhlStaffSatProfilesJob;
use Illuminate\Console\Command;

/**
 * Queue historical official and staff SAT profile buckets.
 */
class BuildNhlGameContextSatProfilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:game-context-sat-profiles
                            {--season=20252026 : Source season id to profile}
                            {--game-type=2 : NHL game type to profile}
                            {--only=all : Which profiles to queue: all, officials, or staff}';

    /**
     * @var string
     */
    protected $description = 'Queue historical NHL official and staff SAT profile bucket jobs';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $seasonId = trim((string) $this->option('season'));
        $gameType = (int) $this->option('game-type');
        $only = strtolower(trim((string) $this->option('only')));

        if ($seasonId === '') {
            $this->error('Season id is required.');

            return self::FAILURE;
        }

        if (! in_array($only, ['all', 'officials', 'staff'], true)) {
            $this->error('The --only option must be all, officials, or staff.');

            return self::FAILURE;
        }

        if ($only === 'all' || $only === 'officials') {
            BuildNhlOfficialSatProfilesJob::dispatch($seasonId, $gameType);
        }

        if ($only === 'all' || $only === 'staff') {
            BuildNhlStaffSatProfilesJob::dispatch($seasonId, $gameType);
        }

        $this->info("Queued {$only} game-context SAT profiles for {$seasonId} game type {$gameType}.");

        return self::SUCCESS;
    }
}
