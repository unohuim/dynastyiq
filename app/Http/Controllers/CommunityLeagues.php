<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformLeague;
use App\Traits\HasAPITrait;
use App\ViewModels\LeagueShowViewModel;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CommunityLeagues extends Controller
{
    use HasAPITrait;

    public function show(int $cId, int $lId): View
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with(['discordServers', 'leagues'])
            ->findOrFail($cId);

        $league = $community->leagues()
            ->withPivot('discord_server_id')
            ->findOrFail($lId);

        $communities = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->orderBy('organizations.name')
            ->get();

        $fantraxConnected = $user->fantraxSecret()->exists();

        $fantraxOptions = [];
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
                ->map(static function ($row): array {
                    return [
                        'name' => (string) $row->name,
                        'platform_league_id' => (string) $row->platform_league_id,
                        'sport' => (string) $row->sport,
                    ];
                })
                ->values()
                ->all();
        }

        $teams = [];
        try {
            $resp = $this->getAPIData('fantrax', 'league_info', [
                'leagueId' => $league->getPlatformLeagueIdAttribute(),
            ]);

            $apiTeams = $resp['teamInfo'] ?? [];
            $teams = array_map(static function (array $t): array {
                return [
                    'id' => (string) ($t['id'] ?? ''),
                    'name' => (string) ($t['name'] ?? ''),
                    'owner_avatar_url' => null,
                ];
            }, $apiTeams);
        } catch (RequestException $e) {
            $teams = [];
        }

        $vm = new LeagueShowViewModel(
            community: $community,
            league: $league,
            communities: $communities,
            guilds: $community->discordServers,
            teams: $teams,
            fantraxConnected: $fantraxConnected,
            fantraxOptions: $fantraxOptions,
            mobileBreakpoint: (int) config('viewports.mobile', 768)
        );

        return view('communities.leagues.show', [
            'vm' => $vm->toDto()->toArray(),
        ]);
    }
}
