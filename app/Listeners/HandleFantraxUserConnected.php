<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FantraxUserConnected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener that runs after a user connects Fantrax.
 * Queueable to avoid slowing down the controller response.
 */
class HandleFantraxUserConnected implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param  \App\Events\FantraxUserConnected  $event
     * @return void
     */
    public function handle(FantraxUserConnected $event): void
    {
        $user = $event->user;

        // Example: log + place for any follow-up jobs (rosters, matchups, etc.)
        Log::info('Fantrax connected for user.', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'leagues' => method_exists($user, 'fantraxLeagues')
                ? $user->fantraxLeagues()->pluck('name', 'platform_league_id')->toArray()
                : [],
        ]);

        // If/when you add follow-up jobs, dispatch them here, e.g.:
        // SyncFantraxRostersJob::dispatch($user);
        // SyncFantraxMatchupsJob::dispatch($user);
    }

    /**
     * Determine the time at which the listener should timeout.
     *
     * @return int
     */
    public function retryUntil(): int
    {
        // Optional: limit how long this listener can retry.
        return now()->addMinutes(10)->getTimestamp();
    }
}
