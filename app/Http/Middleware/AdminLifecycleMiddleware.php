<?php

namespace App\Http\Middleware;

use App\Services\PlatformState;
use Closure;
use Illuminate\Http\Request;

class AdminLifecycleMiddleware
{
    public function __construct(private PlatformState $platformState)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $seeded = $this->platformState->seeded();

        if (! $seeded) {
            return response("Seeder diagnostics only. Fresh install detected.", 503);
        }

        return $next($request);
    }
}
