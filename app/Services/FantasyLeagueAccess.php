<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Resolves provider-neutral access to the fantasy Leagues experience.
 */
class FantasyLeagueAccess
{
    public function __construct(
        private readonly FantasyIntegrationState $integrationState,
    ) {
    }

    /**
     * Determine whether the user can view any fantasy leagues.
     */
    public function canViewLeagues(User $user): bool
    {
        return $this->integrationState->canShowAnyLeague($user);
    }

    /**
     * Return active leagues from every ready fantasy provider.
     */
    public function activeLeaguesForUser(User $user): BelongsToMany
    {
        $readyProviders = collect($this->integrationState->forUser($user))
            ->filter(static fn (array $state): bool => $state['show_leagues'])
            ->keys()
            ->values()
            ->all();

        return $user->platformLeagues()
            ->whereIn('platform_leagues.platform', $readyProviders)
            ->wherePivot('is_active', true);
    }
}
