<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\FantasyProvider;
use InvalidArgumentException;

/**
 * Builds provider-neutral fantasy connection and readiness payloads.
 */
class FantasyIntegrationState
{
    /**
     * Build one provider's fantasy integration state payload for a user.
     *
     * @return array{provider:string,status:string,connected:bool,leagues_count:int,show_leagues:bool}
     */
    public function forProvider(User $user, string $provider): array
    {
        $this->assertSupportedProvider($provider);

        $connected = $this->connected($user, $provider);
        $leaguesCount = $this->activeLeagueCount($user, $provider);
        $showLeagues = $connected && $leaguesCount > 0;

        return [
            'provider' => $provider,
            'status' => $this->status($connected, $showLeagues),
            'connected' => $connected,
            'leagues_count' => $leaguesCount,
            'show_leagues' => $showLeagues,
        ];
    }

    /**
     * Build fantasy integration state payloads for every supported league provider.
     *
     * @return array<string,array{provider:string,status:string,connected:bool,leagues_count:int,show_leagues:bool}>
     */
    public function forUser(User $user): array
    {
        $states = [];

        foreach (FantasyProvider::leagueProviders() as $provider) {
            $states[$provider] = $this->forProvider($user, $provider);
        }

        return $states;
    }

    /**
     * Determine whether any supported fantasy provider can show leagues.
     */
    public function canShowAnyLeague(User $user): bool
    {
        foreach ($this->forUser($user) as $state) {
            if ($state['show_leagues']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count active platform leagues for one provider.
     */
    private function activeLeagueCount(User $user, string $provider): int
    {
        return $user->platformLeagues()
            ->where('platform_leagues.platform', $provider)
            ->wherePivot('is_active', true)
            ->distinct()
            ->count('platform_leagues.id');
    }

    /**
     * Determine whether the user's provider credential or grant is connected.
     */
    private function connected(User $user, string $provider): bool
    {
        return match ($provider) {
            FantasyProvider::FANTRAX => $user->fantraxSecret()->exists(),
            FantasyProvider::YAHOO => $user->yahooFantasyConnection()
                ->where('status', 'connected')
                ->exists(),
            default => false,
        };
    }

    /**
     * Derive the user-facing connection status.
     */
    private function status(bool $connected, bool $showLeagues): string
    {
        if (! $connected) {
            return 'disconnected';
        }

        return $showLeagues ? 'ready' : 'connected';
    }

    /**
     * Ensure the provider participates in the shared fantasy league flow.
     */
    private function assertSupportedProvider(string $provider): void
    {
        if (! in_array($provider, FantasyProvider::leagueProviders(), true)) {
            throw new InvalidArgumentException("Unsupported fantasy provider [{$provider}].");
        }
    }
}
