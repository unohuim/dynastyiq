<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncFantraxLeagueJob;
use App\Models\IntegrationSecret;
use App\Models\User;
use App\Models\YahooFantasyConnection;
use App\Services\ConnectFantraxUser;
use App\Services\FantasyLeagueAccess;
use App\Services\YahooFantasyLeagueService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Refresh connected fantasy leagues for all users on the scheduler.
 */
final class RefreshConnectedLeaguesCommand extends Command
{
    protected $signature = 'leagues:refresh-connected';

    protected $description = 'Refresh connected fantasy leagues and queue Fantrax league sync jobs.';

    /**
     * Refresh connected providers and dispatch league-level Fantrax sync work.
     */
    public function handle(
        YahooFantasyLeagueService $yahooLeagueService,
        ConnectFantraxUser $fantraxConnector,
        FantasyLeagueAccess $leagueAccess,
    ): int {
        $fantraxLeagueIds = collect();
        $usersProcessed = 0;
        $errors = 0;

        $this->connectedUsersQuery()->chunkById(100, function (Collection $users) use (
            $yahooLeagueService,
            $fantraxConnector,
            $leagueAccess,
            $fantraxLeagueIds,
            &$usersProcessed,
            &$errors,
        ): void {
            foreach ($users as $user) {
                if (! $user instanceof User) {
                    continue;
                }

                $usersProcessed++;
                $this->refreshYahooConnection($user, $yahooLeagueService, $errors);
                $this->refreshFantraxConnection($user, $fantraxConnector, $errors);

                $fantraxLeagueIds->push(
                    ...$leagueAccess->activeLeaguesForUser($user)
                        ->where('platform_leagues.platform', 'fantrax')
                        ->pluck('platform_leagues.id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all()
                );
            }
        });

        $queuedLeagueIds = $fantraxLeagueIds
            ->unique()
            ->values();

        foreach ($queuedLeagueIds as $leagueId) {
            SyncFantraxLeagueJob::dispatch((int) $leagueId);
        }

        $this->info(
            "Processed {$usersProcessed} connected user(s); queued {$queuedLeagueIds->count()} Fantrax league sync job(s)."
        );

        if ($errors > 0) {
            $this->warn("Encountered {$errors} provider refresh error(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Build the connected-user query for supported league providers.
     */
    private function connectedUsersQuery(): Builder
    {
        return User::query()
            ->where(function ($query): void {
                $query
                    ->whereHas('integrationSecrets', function ($secretQuery): void {
                        $secretQuery
                            ->where('provider', 'fantrax')
                            ->where('status', 'connected');
                    })
                    ->orWhereHas('yahooFantasyConnection', function ($connectionQuery): void {
                        $connectionQuery->where('status', 'connected');
                    });
            })
            ->orderBy('id');
    }

    /**
     * Refresh Yahoo league data for one connected user.
     */
    private function refreshYahooConnection(
        User $user,
        YahooFantasyLeagueService $yahooLeagueService,
        int &$errors,
    ): void {
        $connection = $user
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if (! $connection instanceof YahooFantasyConnection) {
            return;
        }

        try {
            $yahooLeagueService->syncForConnection($connection, (int) $user->id);
        } catch (\Throwable $throwable) {
            $errors++;
            $this->warn("Yahoo league refresh failed for user {$user->id}: {$throwable->getMessage()}");
        }
    }

    /**
     * Refresh Fantrax league discovery for one connected user.
     */
    private function refreshFantraxConnection(
        User $user,
        ConnectFantraxUser $fantraxConnector,
        int &$errors,
    ): void {
        $secret = $user
            ->fantraxSecret()
            ->where('status', 'connected')
            ->first();

        if (! $secret instanceof IntegrationSecret) {
            return;
        }

        try {
            $fantraxConnector->syncLeagues($user, (string) $secret->secret);
        } catch (\Throwable $throwable) {
            $errors++;
            $this->warn("Fantrax league refresh failed for user {$user->id}: {$throwable->getMessage()}");
        }
    }
}
