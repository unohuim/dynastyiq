<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Models\ImportRun;
use App\Models\Player;
use App\Services\NhlPlayerIdentityLookup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcile NHL identities for existing canonical records.
 */
class ResolveNhlCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:resolve
                            {--players : Resolve canonical players without NHL ids}
                            {--inline : Resolve players immediately instead of queueing resolver jobs}
                            {--import-run-id= : Internal admin import run id}';

    /**
     * @var string
     */
    protected $description = 'Run or queue NHL identity reconciliation tasks';

    public function handle(NhlPlayerIdentityLookup $lookup): int
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

        $inline = (bool) $this->option('inline');
        $successful = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($players as $player) {
            if (! $this->hasUsableName($player)) {
                $skipped++;
                $importRun?->recordProcessed('skipped');
                continue;
            }

            if ($inline) {
                $result = $this->resolveInline((int) $player->id, $lookup);

                if ($result === 'successful') {
                    $successful++;
                } elseif ($result === 'failed') {
                    $failed++;
                } else {
                    $skipped++;
                }

                $importRun?->recordProcessed($result);
                continue;
            }

            ResolveCanonicalPlayerNhlIdentityJob::dispatch($player->id);
            $successful++;
            $importRun?->recordProcessed('successful');
        }

        $importRun?->markCompleted();

        $this->info($inline
            ? "Resolved NHL player identities for {$successful} player(s)."
            : "Queued NHL player resolution for {$successful} player(s).");

        if ($skipped > 0) {
            $this->line("Skipped {$skipped} player(s) without usable name evidence.");
        }

        if ($failed > 0) {
            $this->warn("Failed to resolve {$failed} player(s).");
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

    private function resolveInline(int $playerId, NhlPlayerIdentityLookup $lookup): string
    {
        $player = Player::query()->find($playerId);

        if (! $player || $player->nhl_id !== null) {
            return 'skipped';
        }

        try {
            $lookup->enrich($player);
        } catch (Throwable) {
            return 'failed';
        }

        $resolvedPlayer = Player::query()->find($playerId);

        if (! $resolvedPlayer || $resolvedPlayer->nhl_id !== null) {
            return 'successful';
        }

        return 'skipped';
    }
}
