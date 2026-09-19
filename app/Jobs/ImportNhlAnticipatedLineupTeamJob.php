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
                $importer->import($game, $this->teamAbbrev, $this->teamId);
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
}
