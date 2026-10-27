<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlGame;
use App\Services\NhlPublicGamePage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Prepare one public game after committed source changes without running predictions. */
final class RefreshNhlPublicGamePageJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    /** Retain only the public game identity on the ordinary import queue. */
    public function __construct(public int $gameId)
    {
        $this->onQueue('default');
    }

    /** Coalesce pending refreshes while allowing updates during an active build. */
    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /** Refresh the current snapshot; deleted schedule rows require no work. */
    public function handle(NhlPublicGamePage $pages): void
    {
        if (NhlGame::query()->whereKey($this->gameId)->exists()) {
            $pages->prepare($this->gameId, true);
        }
    }
}
