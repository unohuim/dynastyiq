<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\ImportNHLPlayer;
use App\Events\ImportStreamEvent;
use App\Events\PlayersAvailable;
use App\Models\ImportRun;
use App\Models\Player;
use App\Services\PlayerIdentityNormalizer;
use App\Traits\HasAPITrait;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    public function __construct(
        string $teamAbbrev,
        string $importRunId,
        private ?int $adminImportRunId = null,
    ) {
        $this->teamAbbrev = $teamAbbrev;
        $this->importRunId = $importRunId;
    }

    public function handle(): void
    {
        \Log::info('ImportPlayersJob started', [
            'team' => $this->teamAbbrev,
            'run'  => $this->importRunId,
            'job'  => spl_object_id($this),
        ]);

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
        $normalizer = app(PlayerIdentityNormalizer::class);

        foreach (['forwards', 'defensemen', 'goalies'] as $group) {
            foreach ($data[$group] ?? [] as $player) {
                $playerId = $player['id'] ?? null;
                $fullName = ($player['firstName']['default'] ?? 'Player')
                    . ' '
                    . ($player['lastName']['default'] ?? (string) $playerId);

                $position = $player['positionCode'] ?? '';
                $this->rememberPlayerFingerprint($fullName, $position, $normalizer);

                if (! $playerId) {
                    continue;
                }

                $dedupeKey = "nhl-import:{$this->importRunId}:player:{$playerId}";

                // add() returns false if this player was already seen in this run
                if (! Cache::add($dedupeKey, true, 3500)) {
                    //\Log::info('Failed to add cache', ['player'=>$fullName]);
                    continue;
                }
                //\Log::info('Added cache', ['player'=>$fullName]);

                ImportStreamEvent::dispatch(
                    'nhl',
                    "Importing {$fullName} - {$this->teamAbbrev}, {$position}",
                    'started'
                );

                if ($this->adminImportRunId !== null) {
                    $this->incrementProgressTotal();
                    $this->importPlayerInline((string) $playerId, $isProspect);
                    continue;
                }

                ImportNHLPlayerJob::dispatch((string) $playerId, $isProspect);
            }
        }
    }

    private function importPlayerInline(string $playerId, bool $isProspect): void
    {
        $playersExistedBefore = Player::query()->exists();
        $retryDelays = $this->playerLandingRetryDelays();
        $transientFailures = 0;

        while (true) {
            try {
                (new ImportNHLPlayer())->import($playerId, $isProspect);
                $this->recordProcessedRecord('successful');
                break;
            } catch (RequestException $exception) {
                if (! $this->isTransientPlayerLandingFailure($exception)) {
                    $this->recordPlayerLandingFailure($playerId, $isProspect, $exception, 'player_landing_failures');
                    return;
                }

                if ($transientFailures < count($retryDelays)) {
                    $delaySeconds = $retryDelays[$transientFailures];
                    $transientFailures++;

                    Log::warning('NHL player landing transient failure; retrying player import', [
                        'team' => $this->teamAbbrev,
                        'nhl_player_id' => $playerId,
                        'is_prospect' => $isProspect,
                        'status' => $exception->response?->status(),
                        'attempt' => $transientFailures,
                        'delay_seconds' => $delaySeconds,
                    ]);

                    if ($delaySeconds > 0) {
                        sleep($delaySeconds);
                    }

                    continue;
                }

                $this->recordTransientPlayerLandingFailure($playerId, $isProspect, $exception, $transientFailures + 1);
                return;
            } catch (\Throwable $throwable) {
                $this->recordPlayerLandingFailure($playerId, $isProspect, $throwable, 'player_landing_failures');
                return;
            }
        }

        if (! $playersExistedBefore && Player::query()->exists()) {
            PlayersAvailable::dispatch('nhl', Player::query()->count());
        }
    }

    private function incrementProgressTotal(): void
    {
        if ($this->adminImportRunId === null) {
            return;
        }

        ImportRun::query()
            ->find($this->adminImportRunId)
            ?->incrementProgressTotal(label: 'NHL player records');

        $this->broadcastProgress();
    }

    private function recordProcessedRecord(string $result): void
    {
        if ($this->adminImportRunId === null) {
            return;
        }

        ImportRun::query()
            ->find($this->adminImportRunId)
            ?->recordProcessed($result);

        $this->broadcastProgress();
    }

    private function recordTransientPlayerLandingFailure(
        string $playerId,
        bool $isProspect,
        RequestException $exception,
        int $attempts,
    ): void {
        $this->recordProcessedRecord('failed');

        Log::warning('NHL player landing transient failure persisted; skipping player', [
            'team' => $this->teamAbbrev,
            'nhl_player_id' => $playerId,
            'is_prospect' => $isProspect,
            'status' => $exception->response?->status(),
            'attempts' => $attempts,
        ]);

        $this->appendImportRunMeta('transient_player_landing_failures', [
            'team' => $this->teamAbbrev,
            'nhl_player_id' => $playerId,
            'is_prospect' => $isProspect,
            'status' => $exception->response?->status(),
            'attempts' => $attempts,
            'error' => $exception->getMessage(),
        ]);
    }

    private function recordPlayerLandingFailure(
        string $playerId,
        bool $isProspect,
        \Throwable $throwable,
        string $metaKey,
    ): void {
        $this->recordProcessedRecord('failed');

        Log::warning('NHL player landing failure; skipping player', [
            'team' => $this->teamAbbrev,
            'nhl_player_id' => $playerId,
            'is_prospect' => $isProspect,
            'status' => $throwable instanceof RequestException ? $throwable->response?->status() : null,
            'error' => $throwable->getMessage(),
        ]);

        $this->appendImportRunMeta($metaKey, [
            'team' => $this->teamAbbrev,
            'nhl_player_id' => $playerId,
            'is_prospect' => $isProspect,
            'status' => $throwable instanceof RequestException ? $throwable->response?->status() : null,
            'error' => $throwable->getMessage(),
        ]);
    }

    /**
     * @return array<int,int>
     */
    private function playerLandingRetryDelays(): array
    {
        $delays = config('apiImportNhl.player_landing_retry_delays', [2, 5, 10]);

        if (! is_array($delays)) {
            return [2, 5, 10];
        }

        return array_values(array_map(
            static fn (mixed $delay): int => max(0, (int) $delay),
            $delays,
        ));
    }

    private function isTransientPlayerLandingFailure(RequestException $exception): bool
    {
        return in_array($exception->response?->status(), [408, 429, 500, 502, 503, 504], true);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function appendImportRunMeta(string $key, array $entry): void
    {
        if ($this->adminImportRunId === null) {
            return;
        }

        $importRun = ImportRun::query()->find($this->adminImportRunId);

        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $items = is_array($meta[$key] ?? null) ? $meta[$key] : [];
        $items[] = $entry;
        $meta[$key] = $items;

        $importRun->forceFill(['meta' => $meta])->save();
    }

    private function broadcastProgress(): void
    {
        ImportStreamEvent::dispatch(
            'nhl',
            "Processed NHL player records for {$this->teamAbbrev}",
            'progress'
        );
    }

    private function rememberPlayerFingerprint(
        string $fullName,
        ?string $position,
        PlayerIdentityNormalizer $normalizer,
    ): void {
        $normalizedName = $normalizer->normalizeName($fullName);
        $positionType = $this->positionType($position);

        if ($normalizedName === null || $positionType === null) {
            return;
        }

        Cache::put($this->fingerprintKey($normalizedName, $positionType), true, 3500);
    }

    private function fingerprintKey(string $normalizedName, string $positionType): string
    {
        return 'nhl-import:'
            . $this->importRunId
            . ':fingerprint:'
            . sha1($normalizedName . '|' . $positionType);
    }

    private function positionType(?string $position): ?string
    {
        $position = mb_strtoupper(trim((string) $position));

        return match ($position) {
            'G' => 'G',
            'D', 'LD', 'RD' => 'D',
            'F', 'C', 'L', 'R', 'LW', 'RW' => 'F',
            default => null,
        };
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
