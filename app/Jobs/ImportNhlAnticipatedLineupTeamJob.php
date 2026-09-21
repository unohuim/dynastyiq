<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\NhlCurrentLineup;
use App\Services\NhlAnticipatedLineupImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Imports public anticipated-lineup evidence for one NHL game team. */
class ImportNhlAnticipatedLineupTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 25;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public string $window = 'all';

    public function __construct(
        public readonly int $nhlGameId,
        public readonly string $teamAbbrev,
        public readonly int $teamId,
        public readonly ?int $importRunId = null,
        string $window = 'all',
    ) {
        $this->window = $window;
        $this->onQueue('lineups');
    }

    public function handle(NhlAnticipatedLineupImporter $importer): void
    {
        $result = 'successful';
        $errorMessage = null;
        try {
            $game = DB::table('nhl_games')->where('nhl_game_id', $this->nhlGameId)->first();
            if ($game === null || ! $this->isWithinDiscoveryWindow($game)) {
                $result = 'skipped';
            } elseif ($this->hasCompleteCurrentLineup($game)) {
                $result = 'skipped';
            } else {
                $official = $importer->importOfficial($game, $this->teamAbbrev, $this->teamId);
                if ($official['available']) {
                    $result = $official['observed'] > 0 ? 'successful' : 'skipped';
                } else {
                    $search = $this->searchDecision($game);
                    if (! $search['needed']) {
                        $result = 'skipped';
                    } else {
                        $lock = Cache::lock('nhl-lineup-x-search', 300);
                        if (! $lock->get()) {
                            $this->release(5);
                            return;
                        }

                        try {
                            $search = $this->searchDecision($game);
                            if (! $search['needed']) {
                                $result = 'skipped';
                            } else {
                                $imported = $importer->importFromX(
                                    $game,
                                    $this->teamAbbrev,
                                    $this->teamId,
                                    $this->importRunId !== null ? (string) $this->importRunId : null
                                );
                                $result = $imported['observed'] > 0 ? 'successful' : 'skipped';
                            }
                        } finally {
                            $lock->release();
                        }
                    }
                }
            }
        } catch (RequestException $exception) {
            if ($exception->response->status() === 429 && $this->attempts() < $this->tries) {
                $this->release($this->retryAfterSeconds($exception));
                return;
            }

            report($exception);
            $result = 'failed';
            $errorMessage = $this->xFailureMessage($exception);
        } catch (Throwable $throwable) {
            report($throwable);
            $result = 'failed';
            $errorMessage = $throwable->getMessage();
        }

        $this->recordTerminalResult($result, $errorMessage);
    }

    /** Record queue failures that occur outside handle, including timeouts and exhausted releases. */
    public function failed(Throwable $throwable): void
    {
        $this->recordTerminalResult('failed', $throwable->getMessage());
    }

    private function importRun(): ?ImportRun
    {
        return $this->importRunId ? ImportRun::query()->find($this->importRunId) : null;
    }

    /** Recheck game-date and lane eligibility when the queued work actually runs. */
    private function isWithinDiscoveryWindow(object $game): bool
    {
        $today = Carbon::now('America/Toronto')->startOfDay();
        $gameDate = Carbon::parse($game->game_date)->toDateString();
        if (! in_array($gameDate, [$today->toDateString(), $today->copy()->addDay()->toDateString()], true)
            || empty($game->start_time_utc)) {
            return false;
        }

        $start = Carbon::parse($game->start_time_utc, 'UTC');

        return match ($this->window) {
            'all' => true,
            'within-two-hours' => $gameDate === $today->toDateString() && $start->lte(now()->addHours(2)),
            'outside-two-hours' => $start->gt(now()->addHours(2)),
            default => false,
        };
    }

    private function hasCompleteCurrentLineup(object $game): bool
    {
        $current = NhlCurrentLineup::query()->with('observation')
            ->where('nhl_game_id', $this->nhlGameId)
            ->where('team_id', $this->teamId)
            ->first();

        return $current !== null
            && $current->observation !== null
            && $current->observation->isEligibleForGameDate((string) $game->game_date)
            && $current->hasVerifiedPlayers();
    }

    private function recordTerminalResult(string $result, ?string $errorMessage = null): void
    {
        $run = $this->importRun();
        if ($run === null || $run->status !== 'working') {
            return;
        }

        $run->recordProcessed($result);
        if ($errorMessage !== null) {
            $run->update(['error_message' => $errorMessage]);
        }
        $run->refresh();
        if (($run->processed_records ?? 0) < ($run->total_records ?? 0)) {
            return;
        }

        if (($run->failed_records ?? 0) > 0) {
            $run->markFailed((string) ($run->error_message
                ?: 'One or more anticipated-lineup team searches failed.'));
        } else {
            $run->markCompleted();
        }
    }

    /** @return array{needed:bool,opponent_reported:bool} */
    private function searchDecision(object $game): array
    {
        if ($this->hasCompleteCurrentLineup($game)) {
            return ['needed' => false, 'opponent_reported' => false];
        }

        $opponentAbbrev = $this->teamAbbrev === mb_strtoupper((string) $game->home_team_abbrev)
            ? mb_strtoupper((string) $game->away_team_abbrev)
            : mb_strtoupper((string) $game->home_team_abbrev);
        $opponentTeamId = DB::table('nhl_teams')->where('abbrev', $opponentAbbrev)->value('nhl_id');
        $opponentCurrent = $opponentTeamId === null ? null : NhlCurrentLineup::query()->with('observation')
            ->where('nhl_game_id', $this->nhlGameId)
            ->where('team_id', $opponentTeamId)
            ->first();
        if ($opponentCurrent !== null && ($opponentCurrent->observation === null
            || ! $opponentCurrent->observation->isEligibleForGameDate((string) $game->game_date))) {
            $opponentCurrent = null;
        }
        $opponentSourceCount = (int) ($opponentCurrent->source_count ?? 0);

        return [
            'needed' => true,
            'opponent_reported' => $opponentSourceCount > 0,
        ];
    }

    private function retryAfterSeconds(RequestException $exception): int
    {
        $retryAfter = $exception->response->header('Retry-After');
        if (is_numeric($retryAfter)) {
            return max(1, min(300, (int) ceil((float) $retryAfter)));
        }

        if (filled($retryAfter)) {
            try {
                $seconds = (int) ceil(now()->diffInSeconds(Carbon::parse($retryAfter), false));

                return max(1, min(300, $seconds));
            } catch (Throwable) {
            }
        }

        return min(300, 5 * (2 ** max(0, $this->attempts() - 1)));
    }

    private function xFailureMessage(RequestException $exception): string
    {
        return match ($exception->response->status()) {
            401 => 'X rejected the bearer token. Verify X_BEARER_TOKEN.',
            402 => 'X API credits are unavailable or the project spending limit was reached.',
            403 => 'The X developer app cannot access recent post search.',
            429 => 'X rate-limited the lineup search after all retries were exhausted.',
            default => sprintf('X lineup search failed with HTTP %d.', $exception->response->status()),
        };
    }
}
