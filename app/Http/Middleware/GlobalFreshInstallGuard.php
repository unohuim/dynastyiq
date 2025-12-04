<?php

namespace App\Http\Middleware;

use App\Services\PlatformState;
use Closure;
use Illuminate\Http\Request;

class GlobalFreshInstallGuard
{
    public function __construct(private PlatformState $platformState)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->platformState->seeded()) {
            return response(view('admin.fresh-install'), 503);
        }

        return $next($request);
    }
}
