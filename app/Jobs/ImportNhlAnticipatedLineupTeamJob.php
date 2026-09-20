<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Models\ImportRun;
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
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/** Imports public anticipated-lineup evidence for one NHL game team. */
class ImportNhlAnticipatedLineupTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 25;

    public function __construct(
        public readonly int $nhlGameId,
        public readonly string $teamAbbrev,
        public readonly int $teamId,
        public readonly ?int $importRunId = null,
    ) {
        $this->onQueue('lineups');
    }

    public function handle(NhlAnticipatedLineupImporter $importer): void
    {
        $this->output("{$this->teamAbbrev} | game {$this->nhlGameId} | audit started");
        $result = 'successful';
        $errorMessage = null;
        try {
            $game = DB::table('nhl_games')->where('nhl_game_id', $this->nhlGameId)->first();
            if ($game === null) {
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

        $run = $this->importRun();
        $run?->recordProcessed($result);
        $this->output(match ($result) {
            'successful' => "{$this->teamAbbrev} | game {$this->nhlGameId} | full lineup imported",
            'failed' => "{$this->teamAbbrev} | game {$this->nhlGameId} | audit failed: {$errorMessage}",
            default => "{$this->teamAbbrev} | game {$this->nhlGameId} | no new full lineup imported",
        });
        if ($run !== null) {
            if ($errorMessage !== null) {
                $run->update(['error_message' => $errorMessage]);
            }
            $run->refresh();
            if (($run->processed_records ?? 0) >= ($run->total_records ?? 0)) {
                if (($run->failed_records ?? 0) > 0) {
                    $run->markFailed((string) ($run->error_message
                        ?: 'One or more anticipated-lineup team searches failed.'));
                } else {
                    $run->markCompleted();
                }
            }
        }
    }

    private function output(string $message): void
    {
        if (! app()->environment('testing')) {
            (new ConsoleOutput())->writeln('[lineups] ' . $message);
        }

        ImportStreamEvent::dispatch(
            'nhl-anticipated-lineups',
            $message,
            'output',
            $this->importRunId !== null ? (string) $this->importRunId : null
        );
    }

    private function importRun(): ?ImportRun
    {
        return $this->importRunId ? ImportRun::query()->find($this->importRunId) : null;
    }

    /** @return array{needed:bool,opponent_reported:bool} */
    private function searchDecision(object $game): array
    {
        $ownCurrent = DB::table('nhl_current_lineups')
            ->where('nhl_game_id', $this->nhlGameId)
            ->where('team_id', $this->teamId)
            ->first(['source_count', 'evidence_status']);
        if (($ownCurrent->evidence_status ?? null) === 'official') {
            return ['needed' => false, 'opponent_reported' => false];
        }
        $ownSourceCount = (int) ($ownCurrent->source_count ?? 0);
        if ($ownSourceCount >= 2) {
            return ['needed' => false, 'opponent_reported' => false];
        }

        $opponentAbbrev = $this->teamAbbrev === mb_strtoupper((string) $game->home_team_abbrev)
            ? mb_strtoupper((string) $game->away_team_abbrev)
            : mb_strtoupper((string) $game->home_team_abbrev);
        $opponentTeamId = DB::table('nhl_teams')->where('abbrev', $opponentAbbrev)->value('nhl_id');
        $opponentSourceCount = $opponentTeamId === null ? 0 : (int) (DB::table('nhl_current_lineups')
            ->where('nhl_game_id', $this->nhlGameId)
            ->where('team_id', $opponentTeamId)
            ->value('source_count') ?? 0);

        return [
            'needed' => $ownSourceCount === 0 || $opponentSourceCount > 0,
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
