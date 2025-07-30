<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\ImportNHLPlayerJob;
use App\Traits\HasAPITrait;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatches individual NHL player import jobs for a given team
 * across both the current and previous seasons, as well as team prospects.
 */
class ImportPlayersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use HasAPITrait;
    use Batchable;

    protected string $teamAbbrev;

    /**
     * Create a new job instance.
     *
     * @param string $teamAbbrev The NHL team abbreviation (e.g., TOR, BOS)
     */
    public function __construct(string $teamAbbrev)
    {
        $this->teamAbbrev = $teamAbbrev;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        [$currentSeason, $previousSeason] = $this->getSeasonIds();

        $this->importSeasonRoster($currentSeason);
        $this->importSeasonRoster($previousSeason);
        $this->importProspects();
    }

    /**
     * Determine current and previous season IDs.
     *
     * @return array{string, string}
     */
    protected function getSeasonIds(): array
    {
        $current = current_season_id(); // uses your global helper
        $year1 = substr($current, 0, 4);
        $year2 = substr($current, 4, 4);

        return [
            $current,
            ((int) $year1 - 1) . $year1,
        ];
    }

    /**
     * Get the current season ID string.
     *
     * @return string
     */
    protected function getCurrentSeasonId(): string
    {
        return $this->getSeasonIds()[0];
    }

    /**
     * Import NHL players for the specified season.
     *
     * @param string $seasonId
     * @return void
     */
    protected function importSeasonRoster(string $seasonId): void
    {
        $endpoint = $seasonId === $this->getCurrentSeasonId()
            ? 'roster_current'
            : 'roster_season';

        $params = ['teamAbbrev' => $this->teamAbbrev];

        if ($endpoint === 'roster_season') {
            $params['seasonId'] = $seasonId;
        }

        $players = $this->getAPIData('nhl', $endpoint, $params);

        $this->dispatchGroupedPlayers($players);
    }

    /**
     * Import all prospects for the current team.
     *
     * @return void
     */
    protected function importProspects(): void
    {
        $prospects = $this->getAPIData('nhl', 'prospects', ['teamAbbrev' => $this->teamAbbrev]);

        $this->dispatchGroupedPlayers($prospects, isProspect: true);
    }

    /**
     * Dispatch individual player import jobs by group (F/D/G).
     *
     * @param array<string, mixed> $data
     * @param bool $isProspect
     * @return void
     */
    protected function dispatchGroupedPlayers(array $data, bool $isProspect = false): void
    {
        foreach (['forwards', 'defensemen', 'goalies'] as $group) {
            foreach ($data[$group] ?? [] as $player) {
                ImportNHLPlayerJob::dispatch($player['id'], $isProspect);
            }
        }
    }
}
