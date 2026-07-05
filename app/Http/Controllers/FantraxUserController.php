<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IntegrationSecret;
use App\Services\ConnectFantraxUser;
use App\Services\FantasyIntegrationState;
use App\Support\FantasyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FantraxUserController extends Controller
{
    /**
     * Store or update the Fantrax secret key for the authenticated user.
     */
    public function save(Request $request, ConnectFantraxUser $connector): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'fantrax_secret_key' => 'required|string|max:255',
        ]);

        try {
            $result = $connector->connect(Auth::user(), $data['fantrax_secret_key']);
        } catch (RuntimeException $e) {
            return $this->respondError($request, $e->getMessage());
        }

        session()->put('fantrax.connected', true);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'integration' => app(FantasyIntegrationState::class)
                    ->forProvider(Auth::user(), FantasyProvider::FANTRAX),
            ]);
        }

        $leagueCount = $result['league_count'];

        return back()->with([
            'status' => 'Fantrax connected (' . $leagueCount . ' league(s) found).',
            'success' => 'Fantrax connected (' . $leagueCount . ' league(s) found).',
        ]);
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

        DB::table('league_user_teams')
            ->where('user_id', Auth::id())
            ->whereIn(
                'platform_league_id',
                DB::table('platform_leagues')
                    ->select('id')
                    ->where('platform', 'fantrax'),
            )
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        return back()->with([
            'status' => 'Fantrax disconnected.',
            'success' => 'Fantrax disconnected.',
        ]);
    }

    private function respondError(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => false, 'error' => $message], 422);
        }

        return back()
            ->withErrors(['fantrax_secret_key' => $message])
            ->with('error', $message);
    }
}
