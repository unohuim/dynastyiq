<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\FantraxUserConnected;
use App\Models\IntegrationSecret;
use App\Services\FantraxLeagueService;
use App\Traits\HasAPITrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

class FantraxUserController extends Controller
{
    use HasAPITrait;

    /**
     * Store or update the Fantrax secret key for the authenticated user.
     */
    public function save(Request $request): RedirectResponse|JsonResponse
    {

        $data = $request->validate([
            'fantrax_secret_key' => 'required|string|max:255',
        ]);


        try {
            $resp = $this->getAPIData('fantrax', 'user_leagues', [
                'userSecretId' => $data['fantrax_secret_key']
            ]);
        } catch (RequestException $e) {

            return $this->respondError($request, 'Unable to reach Fantrax. Try again.');
        }


        $leagues = $resp['leagues'] ?? [];
        if (count($leagues) === 0) {
            return $this->respondError($request, 'Invalid Fantrax Secret Key.');
        }


        // 1) Save/confirm integration
        IntegrationSecret::updateOrCreate(
            ['user_id' => Auth::id(), 'provider' => 'fantrax'],
            ['secret' => $data['fantrax_secret_key'], 'status' => 'connected']
        );

        // 2) Sync unified Leagues/Teams and user↔team assignments
        app(FantraxLeagueService::class)->upsertLeaguesForUser(Auth::user(), $leagues);

        // 3) Notify listeners
        FantraxUserConnected::dispatch(Auth::user());

        session()->put('fantrax.connected', true);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'leagues_count' => count($leagues),
                'status' => 'connected',
            ]);
        }

        return back()->with('status', 'Fantrax connected (' . count($leagues) . ' league(s) found).');
    }

    /**
     * Disconnect the Fantrax integration for the authenticated user.
     */
    public function disconnect(): RedirectResponse
    {
        $secret = IntegrationSecret::where('user_id', Auth::id())
            ->where('provider', 'fantrax')
            ->first();

        if ($secret) {
            $secret->delete();
        }

        return back()->with('status', 'Fantrax disconnected.');
    }

    private function respondError(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => false, 'error' => $message], 422);
        }

        return back()->withErrors(['fantrax_secret_key' => $message]);
    }
}
