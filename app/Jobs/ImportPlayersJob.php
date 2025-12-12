<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Traits\HasAPITrait;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates a single NHL player import run for a team.
 *
 * Players may appear across multiple seasons or sources (prospects),
 * but each player is dispatched exactly once per import run.
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
     * Unique identifier for this import run.
     */
    protected string $importRunId;

    /**
     * Teams that have relocated and the first season their new abbrev applies.
     *
     * @var array<string, array{new: string, effective: string}>
     */
    private const RELOCATIONS = [
        'ARI' => ['new' => 'UTA', 'effective' => '20252026'],
    ];

    public function __construct(string $teamAbbrev, string $importRunId)
    {
        $this->teamAbbrev = $teamAbbrev;
        $this->importRunId = $importRunId;
    }

    public function handle(): void
    {
        ImportStreamEvent::dispatch(
            'nhl',
            "Importing players for team {$this->teamAbbrev}",
            'started'
        );

        [$currentSeason, $previousSeason] = $this->getSeasonIds();

        $this->importSeasonRoster($currentSeason);
        $this->importSeasonRoster($previousSeason);
        $this->importProspects();
    }

    /**
     * @return array{string, string}
     */
    protected function getSeasonIds(): array
    {
        $current = current_season_id();
        $year1 = substr($current, 0, 4);

        return [
            $current,
            ((int) $year1 - 1) . $year1,
        ];
    }

    protected function importSeasonRoster(string $seasonId): void
    {
        $team = $this->resolveTeamForSeason($this->teamAbbrev, $seasonId);

        ImportStreamEvent::dispatch(
            'nhl',
            "Fetching roster for {$team} season {$seasonId}",
            'started'
        );

        $endpoint = $seasonId === $this->getSeasonIds()[0]
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
                info("Skipping roster import for {$team} season {$seasonId} — API returned 404");
                return;
            }

            throw $e;
        }

        $this->dispatchGroupedPlayers($players);
    }

    protected function importProspects(): void
    {
        ImportStreamEvent::dispatch(
            'nhl',
            "Fetching prospects for {$this->teamAbbrev}",
            'started'
        );

        $prospects = $this->getAPIData(
            'nhl',
            'prospects',
            ['teamAbbrev' => $this->teamAbbrev]
        );

        $this->dispatchGroupedPlayers($prospects, true);
    }

    /**
     * Dispatch unique player import jobs by position group.
     *
     * @param array<string, mixed> $data
     * @param bool $isProspect
     */
    protected function dispatchGroupedPlayers(array $data, bool $isProspect = false): void
    {
        foreach (['forwards', 'defensemen', 'goalies'] as $group) {
            foreach ($data[$group] ?? [] as $player) {
                $playerId = $player['id'] ?? null;

                if (! $playerId) {
                    continue;
                }

                $fullName = ($player['firstName']['default'] ?? 'Player')
                    . ' '
                    . ($player['lastName']['default'] ?? (string) $playerId);

                $position = $player['positionCode'] ?? '';

                
                $dedupeKey = "nhl-import:{$this->importRunId}:player:{$playerId}";

                // add() returns false if this player was already seen in this run
                if (! Cache::add($dedupeKey, true, 3500)) {
                    \Log::info('Failed to add cache', ['player'=>$fullName]);
                    continue;
                }
                \Log::info('Added cache', ['player'=>fullName]);

                
                ImportStreamEvent::dispatch(
                    'nhl',
                    "Importing {$fullName}, {$position} - {$this->teamAbbrev}",
                    'started'
                );

                ImportNHLPlayerJob::dispatch(
                    $playerId,
                    $isProspect
                );
            }
        }
    }

    protected function resolveTeamForSeason(string $team, string $seasonId): string
    {
        if (
            isset(self::RELOCATIONS[$team]) &&
            $seasonId >= self::RELOCATIONS[$team]['effective']
        ) {
            return self::RELOCATIONS[$team]['new'];
        }

        return $team;
    }
}
