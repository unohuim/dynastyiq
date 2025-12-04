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
        $initialized = $this->platformState->initialized();
        $upToDate = $this->platformState->upToDate();

        if (! $seeded) {
            return response("Seeder diagnostics only. Fresh install detected.", 503);
        }

        if ($seeded && ! $initialized) {
            if (! $request->routeIs('admin.initialize.*')) {
                return redirect()->route('admin.initialize.index');
            }
        }

        if ($initialized && ! $upToDate && ! $request->isMethod('get')) {
            return response('Platform is read-only until scheduling catches up.', 423);
        }

        return $next($request);
    }
}
