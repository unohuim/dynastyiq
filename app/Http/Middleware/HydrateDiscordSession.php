<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Http\Middleware\HydrateDiscordSession;


class HydrateDiscordSession
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $uid = Auth::id();
            $connected = Cache::get("discord:connected:{$uid}", false);
            session(['diq-user.connected' => (bool) $connected]);
        }
        return $next($request);
    }
}
