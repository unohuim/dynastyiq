<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\IntegrationSecret;
use App\Traits\HasAPITrait;


class FantraxUserController extends Controller
{
    use HasAPITrait;


    /**
     * Store or update the Fantrax secret key for the authenticated user.
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'fantrax_secret_key' => 'required|string|max:255',
        ]);

        try {
            $resp = $this->getAPIData('fantrax', 'user_leagues', [
                'userSecretId' => $data['fantrax_secret_key'],
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['fantrax_secret_key' => 'Unable to reach Fantrax. Try again.']);
        }

        $leagues = $resp['leagues'] ?? [];
        if (count($leagues) === 0) {
            return back()->withErrors(['fantrax_secret_key' => 'Invalid Fantrax Secret Key.']);
        }

        // 1) Save/confirm integration
        IntegrationSecret::updateOrCreate(
            ['user_id' => Auth::id(), 'provider' => 'fantrax'],
            ['secret' => $data['fantrax_secret_key'], 'status' => 'connected']
        );

        // 2) Sync leagues + user pivots (synchronously, via service)
        app(\App\Services\FantraxLeagueService::class)
            ->upsertLeaguesForUser(Auth::user(), $leagues);

        // 3) Fire event AFTER upserts so listeners can access $user->fantraxLeagues
        \App\Events\FantraxUserConnected::dispatch(Auth::user());

        session()->put('fantrax.connected', true);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'leagues_count' => count($leagues),
                'status' => 'connected',
            ]);
        }

        return back()->with('status', 'Fantrax connected ('.count($leagues).' league(s) found).');
    }



    /**
     * Disconnect the Fantrax integration for the authenticated user.
     */
    public function disconnect()
    {
        $secret = IntegrationSecret::where('user_id', Auth::id())
            ->where('provider', 'fantrax')
            ->first();

        if ($secret) {
            $secret->delete();
        }

        return back()->with('status', 'Fantrax disconnected.');
    }
}
