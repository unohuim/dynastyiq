<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Models\ImportRun;
use App\Models\Player;
use App\Services\ImportNHLPlayer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one bounded chunk of existing NHL player metadata.
 */
class RefreshNhlPlayerMetadataChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int,int> $playerIds
     */
    public function __construct(
        private array $playerIds,
        private ?int $importRunId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ImportNHLPlayer $importer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Player::query()
            ->whereIn('id', $this->playerIds)
            ->whereNotNull('nhl_id')
            ->select(['id', 'nhl_id', 'full_name', 'is_prospect'])
            ->orderBy('id')
            ->get()
            ->each(function (Player $player) use ($importer): void {
                $this->refreshPlayer($player, $importer);
            });
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['refresh-nhl-player-metadata', 'players:' . count($this->playerIds)];
    }

    private function refreshPlayer(Player $player, ImportNHLPlayer $importer): void
    {
        $name = $player->full_name ?: "NHL player {$player->nhl_id}";

        try {
            $importer->import((string) $player->nhl_id, (bool) $player->is_prospect);
            $this->importRun()?->recordProcessed('successful');
            ImportStreamEvent::dispatch('nhl', "Updated {$name}", 'output');
        } catch (Throwable $throwable) {
            Log::warning('NHL player metadata refresh failed', [
                'player_id' => $player->id,
                'nhl_player_id' => $player->nhl_id,
                'error' => $throwable->getMessage(),
            ]);

            $this->importRun()?->recordProcessed('failed');
            ImportStreamEvent::dispatch('nhl', "Failed {$name}: {$throwable->getMessage()}", 'output');
        }
    }

    private function importRun(): ?ImportRun
    {
        if ($this->importRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->importRunId);
    }
}
