<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Stat;
use App\Services\ProspectEligibilityService;
use Illuminate\Console\Command;

/**
 * Recomputes DynastyIQ prospect flags from the local prospect eligibility rules.
 */
final class RefreshNhlProspectFlagsCommand extends Command
{
    protected $signature = 'nhl:isprospects
        {--chunk=500 : Number of players to process per database chunk}';

    protected $description = 'Recompute players.is_prospect and stats.is_prospect for all NHL-linked players.';

    public function handle(ProspectEligibilityService $prospects): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $total = (int) Player::query()->whereNotNull('nhl_id')->count();

        if ($total === 0) {
            $this->info('No NHL-linked players found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $prospectCount = 0;
        $nonProspectCount = 0;
        $changedPlayers = 0;
        $updatedStatRows = 0;

        Player::query()
            ->whereNotNull('nhl_id')
            ->orderBy('id')
            ->select(['id', 'nhl_id', 'is_prospect'])
            ->chunkById($chunkSize, function ($players) use (
                $prospects,
                $total,
                &$processed,
                &$prospectCount,
                &$nonProspectCount,
                &$changedPlayers,
                &$updatedStatRows
            ): void {
                foreach ($players as $player) {
                    $isProspect = $prospects->isProspect((int) $player->nhl_id);

                    if ($isProspect) {
                        $prospectCount++;
                    } else {
                        $nonProspectCount++;
                    }

                    if ((bool) $player->is_prospect !== $isProspect) {
                        $player->forceFill(['is_prospect' => $isProspect])->save();
                        $changedPlayers++;
                    }

                    $updatedStatRows += Stat::query()
                        ->where('player_id', $player->id)
                        ->update(['is_prospect' => $isProspect]);

                    $processed++;
                }

                $this->line("Processed {$processed} / {$total} players.");
            });

        $this->info(
            "Prospect refresh processed {$processed} players; "
            . "{$prospectCount} prospects, {$nonProspectCount} non-prospects, "
            . "{$changedPlayers} player flags changed, {$updatedStatRows} stat rows updated."
        );

        return self::SUCCESS;
    }
}
