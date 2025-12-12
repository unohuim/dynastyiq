<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\ImportNHLPlayerJob;
use App\Events\ImportStreamEvent;
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
     * Teams that have relocated and the first season their new abbrev applies.
     * Format: ['OLD' => ['new' => 'NEW', 'effective' => 'YYYYYYYY']]
     */
    private const RELOCATIONS = [
        'ARI' => ['new' => 'UTA', 'effective' => '20252026'],
    ];

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
        ImportStreamEvent::dispatch('nhl', "Importing players for team {$this->teamAbbrev}", 'started');

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
        $team = $this->resolveTeamForSeason($this->teamAbbrev, $seasonId);

        ImportStreamEvent::dispatch(
            'nhl',
            "Fetching roster for {$team} season {$seasonId}",
            'started'
        );
    
        $endpoint = $seasonId === $this->getCurrentSeasonId()
            ? 'roster_current'
            : 'roster_season';
    
        $params = ['teamAbbrev' => $team];
    
        if ($endpoint === 'roster_season') {
            $params['seasonId'] = $seasonId;
        }
    
        try {
            $players = $this->getAPIData('nhl', $endpoint, $params);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response && $e->response->status() === 404) {
                // Tell the stream / batch what happened, but do NOT fail the entire import
                info("Skipping roster import for {$this->teamAbbrev} season {$seasonId} — API returned 404");
                return;
            }
    
            throw $e;
        }

        $this->dispatchGroupedPlayers($players);
    }


    /**
     * Import all prospects for the current team.
     *
     * @return void
     */
    protected function importProspects(): void
    {
        ImportStreamEvent::dispatch('nhl', "Fetching prospects for {$this->teamAbbrev}", 'started');

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


    private function resolveTeamForSeason(string $team, string $seasonId): string
    {
        if (isset(self::RELOCATIONS[$team])) {
            if ($seasonId >= self::RELOCATIONS[$team]['effective']) {
                return self::RELOCATIONS[$team]['new'];
            }
        }
    
        return $team;
    }
}
