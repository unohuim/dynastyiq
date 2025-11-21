<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformLeague;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CommunitiesController extends Controller
{
    /**
     * Display a listing of the user's enabled communities,
     * plus Fantrax connection state and selectable Fantrax leagues.
     */
    public function index(): View
    {
        $user = Auth::user();

        $hasMembershipsTable = Schema::hasTable('memberships');

        $communities = $user->organizations()
            ->whereNotNull('organizations.settings')   // enabled = settings not null
            ->whereNull('organizations.deleted_at')    // exclude soft-deleted orgs
            ->with([
                'leagues',
                'discordServers',
                'providerAccounts',
            ])
            ->when($hasMembershipsTable, function ($query) {
                $query->with([
                    'memberships' => function ($membershipQuery) {
                        $membershipQuery
                            ->where('provider', 'patreon')
                            ->with(['memberProfile', 'membershipTier'])
                            ->latest('synced_at')
                            ->latest();
                    },
                ]);
            })
            ->orderBy('organizations.name')
            ->get();

        // Fantrax: connected?
        $fantraxConnected = $user->fantraxSecret()->exists();

        // Build Fantrax options from user's ACTIVE teams in Fantrax leagues,
        // excluding any league already linked to ANY community.
        $fantraxOptions = collect();
        if ($fantraxConnected) {

            $fantraxOptions = PlatformLeague::query()
                    ->select('platform_leagues.name', 'platform_leagues.platform_league_id', 'platform_leagues.sport')
                    ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'platform_leagues.id')
                    ->where('lut.user_id', $user->id)
                    ->where('lut.is_active', true)
                    ->where('platform_leagues.platform', 'fantrax')
                    ->whereDoesntHave('league.organization')
                    ->orderBy('platform_leagues.name')
                    ->get()
                    ->unique('platform_league_id')
                    ->map(static fn ($l) => [
                        'name'               => $l->name,
                        'platform_league_id' => $l->platform_league_id,
                        'sport'              => $l->sport,
                    ])
                    ->values();
        }

        return view('communities.index', [
            'communities'       => $communities,
            'fantraxConnected'  => $fantraxConnected,
            'fantraxOptions'    => $fantraxOptions,
        ]);
    }
}
