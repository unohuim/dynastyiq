<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\NhlAnticipatedLineupImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Imports public anticipated-lineup evidence for one NHL game team. */
class ImportNhlAnticipatedLineupTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $nhlGameId,
        public readonly string $teamAbbrev,
        public readonly int $teamId,
        public readonly ?int $importRunId = null,
    ) {
    }

    public function handle(NhlAnticipatedLineupImporter $importer): void
    {
        $result = 'successful';
        try {
            $game = DB::table('nhl_games')->where('nhl_game_id', $this->nhlGameId)->first();
            if ($game === null) {
                $result = 'skipped';
            } else {
                $search = $this->searchDecision($game);
                if (! $search['needed']) {
                    $result = 'skipped';
                } else {
                    $importer->import(
                        $game,
                        $this->teamAbbrev,
                        $this->teamId,
                        $search['seek_corroboration']
                    );
                }
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $result = 'failed';
        }

        $run = $this->importRun();
        $run?->recordProcessed($result);
        if ($run !== null) {
            $run->refresh();
            if (($run->processed_records ?? 0) >= ($run->total_records ?? 0)) {
                if (($run->failed_records ?? 0) > 0) {
                    $run->markFailed('One or more anticipated-lineup team searches failed.');
                } else {
                    $run->markCompleted();
                }
            }
        }
    }

    private function importRun(): ?ImportRun
    {
        return $this->importRunId ? ImportRun::query()->find($this->importRunId) : null;
    }

    /** @return array{needed:bool,seek_corroboration:bool} */
    private function searchDecision(object $game): array
    {
        $ownSourceCount = (int) (DB::table('nhl_current_lineups')
            ->where('nhl_game_id', $this->nhlGameId)
            ->where('team_id', $this->teamId)
            ->value('source_count') ?? 0);
        if ($ownSourceCount >= 2) {
            return ['needed' => false, 'seek_corroboration' => false];
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
            'seek_corroboration' => $opponentSourceCount > 0,
        ];
    }
}
