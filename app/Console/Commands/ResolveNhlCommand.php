<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Models\ImportRun;
use App\Models\Player;
use Illuminate\Console\Command;

/**
 * Queue NHL reconciliation work for existing canonical records.
 */
class ResolveNhlCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:resolve
                            {--players : Resolve canonical players without NHL ids}
                            {--import-run-id= : Internal admin import run id}';

    /**
     * @var string
     */
    protected $description = 'Queue NHL identity reconciliation tasks';

    public function handle(): int
    {
        if (! $this->option('players')) {
            $this->error('Nothing to do. Try: nhl:resolve --players');

            return self::FAILURE;
        }

        $importRun = $this->importRun();
        $players = Player::query()
            ->whereNull('nhl_id')
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'full_name']);

        $importRun?->setProgressTotal($players->count(), 'Canonical players without NHL ids');

        $queued = 0;
        $skipped = 0;

        foreach ($players as $player) {
            if (! $this->hasUsableName($player)) {
                $skipped++;
                $importRun?->recordProcessed('skipped');
                continue;
            }

            ResolveCanonicalPlayerNhlIdentityJob::dispatch($player->id);
            $queued++;
            $importRun?->recordProcessed('successful');
        }

        $importRun?->markCompleted();

        $this->info("Queued NHL player resolution for {$queued} player(s).");

        if ($skipped > 0) {
            $this->line("Skipped {$skipped} player(s) without usable name evidence.");
        }

        return self::SUCCESS;
    }

    private function importRun(): ?ImportRun
    {
        if (! $this->option('import-run-id')) {
            return null;
        }

        return ImportRun::query()->find((int) $this->option('import-run-id'));
    }

    private function hasUsableName(Player $player): bool
    {
        if (trim((string) $player->first_name) !== '' && trim((string) $player->last_name) !== '') {
            return true;
        }

        $parts = preg_split('/\s+/', trim((string) $player->full_name)) ?: [];

        return count($parts) >= 2;
    }
}
