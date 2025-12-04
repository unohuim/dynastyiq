<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $hasRole = $user->roles()->where('slug', 'super-admin')->exists()
            || $user->roles()->where('level', '>=', 99)->exists();

        abort_unless($hasRole, 403);

        return $next($request);
    }
}
