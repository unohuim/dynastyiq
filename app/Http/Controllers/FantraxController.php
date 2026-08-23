<?php

namespace App\Http\Controllers;

use App\Services\ImportUserFantraxLeagues;
use App\Support\FantraxLogoBrowserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class FantraxController extends Controller
{
    /**
     * Show the authenticated user's Fantrax leagues.
     */
    public function index()
    {
        $leagues = auth()->user()?->leagues()->get() ?? collect();

        
        return view('leagues', [
            'leagues' => $leagues,
        ]);
    }

    /**
     * Initialize the Fantrax logo browser profile for super admins.
     */
    public function connectLogoBrowser(FantraxLogoBrowserProfile $profile): JsonResponse
    {
        abort_unless(auth()->user()?->hasGlobalRole('super-admin'), 403);

        $state = $profile->initialize();

        return response()->json([
            'integration' => [
                'provider' => 'fantrax_logos',
                'connected' => $state['ready'],
                'ready' => $state['ready'],
            ],
        ]);
    }

    /**
     * Import Fantrax leagues for the authenticated user.
     */
    public function importUserLeagues(ImportUserFantraxLeagues $importer): Response
    {
        $importer->import(Auth::user());

        return response('', 200);
    }

}
