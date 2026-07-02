<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshNhlPlayerLandingJob;
use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Models\Player;
use App\Services\NhlPlayerIdentityLookup;
use Closure;

/**
 * Queues NHL identity resolution when canonical player evidence changes.
 */
class PlayerNhlIdentityObserver
{
    private static int $landingRefreshSuppressionDepth = 0;

    public function __construct(private readonly NhlPlayerIdentityLookup $lookup)
    {
    }

    /**
     * Run a callback without queuing an NHL landing refresh from player saves.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function withoutLandingRefresh(Closure $callback): mixed
    {
        self::$landingRefreshSuppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$landingRefreshSuppressionDepth--;
        }
    }

    /**
     * Queue NHL identity work for newly created canonical players.
     */
    public function created(Player $player): void
    {
        if ($this->queueLandingRefreshIfEligible($player)) {
            return;
        }

        $this->queueResolutionIfEligible($player);
    }

    /**
     * Queue NHL identity resolution when player identity evidence changes.
     */
    public function updated(Player $player): void
    {
        if ($player->wasChanged('nhl_id') && $this->queueLandingRefreshIfEligible($player)) {
            return;
        }

        if (! $player->wasChanged([
            'nhl_id',
            'first_name',
            'last_name',
            'full_name',
            'position',
            'pos_type',
        ])) {
            return;
        }

        $this->queueResolutionIfEligible($player);
    }

    private function queueLandingRefreshIfEligible(Player $player): bool
    {
        if (self::$landingRefreshSuppressionDepth > 0 || $player->nhl_id === null || $player->nhl_id === '') {
            return false;
        }

        RefreshNhlPlayerLandingJob::dispatch((int) $player->nhl_id);

        return true;
    }

    private function queueResolutionIfEligible(Player $player): void
    {
        if ($player->nhl_id !== null || ! $this->lookup->hasLookupEvidence($player)) {
            return;
        }

        ResolveCanonicalPlayerNhlIdentityJob::dispatch($player->id);
    }
}
