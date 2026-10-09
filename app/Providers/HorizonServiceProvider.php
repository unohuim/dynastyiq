<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Restrict Horizon to its dedicated, Nginx Basic Auth-protected production host.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    private const DASHBOARD_HOST = 'horizon-diq.on-forge.com';

    /**
     * Authorize the dashboard and its API without Horizon's local bypass.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => $this->allowsDashboard($request)
            && Gate::forUser($request->user())->check('viewHorizon', [$request]));
    }

    /**
     * Permit Laravel guests only behind the dedicated host's Nginx protection.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null, ?Request $request = null): bool =>
            $this->allowsDashboard($request ?? $this->app['request']));
    }

    /**
     * Enforce the boundary even when another gate hook grants a user access.
     */
    private function allowsDashboard(Request $request): bool
    {
        // A host check is not authentication. Nginx must protect the entire site,
        // including Horizon's API; Laravel cannot verify that server configuration.
        return $this->app->environment('production')
            && $request->getHost() === self::DASHBOARD_HOST;
    }
}
