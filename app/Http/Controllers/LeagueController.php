<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // add

final class LeagueController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $leagues = $user?->platformLeagues()
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get() ?? collect();

        $activeLeagueId = $request->integer('active') ?? ($leagues->first()->id ?? null);
        $activeLeague = $activeLeagueId
            ? $leagues->firstWhere('id', $activeLeagueId)
            : $leagues->first();

        $teams = $activeLeague ? $this->teamsPayload($activeLeague) : [];

        return view('leagues', [
            'leagues' => $leagues,
            'activeLeagueId' => $activeLeague?->id,
            'activeLeague' => $activeLeague,
            'teams' => $teams,
        ]);
    }

    public function panel(Request $request, string $leagueId): View
    {
        $league = $request->user()
            ->platformLeagues()
            ->where('platform_leagues.id', $leagueId)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->firstOrFail();

        return view('leagues._panel', [
            'league' => $league,
            'teams'  => $this->teamsPayload($league),
        ]);
    }

    public function show(Request $request, string $leagueId): View
    {
        $user = $request->user();

        $leagues = $user?->platformLeagues()
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get() ?? collect();

        $activeLeague = $leagues->firstWhere('id', (int) $leagueId);
        abort_if($activeLeague === null, 404);

        return view('leagues', [
            'leagues' => $leagues,
            'activeLeagueId' => $activeLeague->id,
            'activeLeague' => $activeLeague,
            'teams' => $this->teamsPayload($activeLeague),
        ]);
    }

    /** ---- DRY helper: builds teams+avatars+rosters once ---- */
    private function teamsPayload($league): array
    {
        $authId = auth()->id();

        $teams = $league->teams()
            ->select('id', 'platform_team_id', 'name')
            ->with([
                'roster' => static function ($q): void {
                    $q->select(
                        'players.id',
                        'players.full_name',
                        'players.first_name',
                        'players.last_name',
                        'players.position',
                        'players.pos_type',
                        'players.dob',
                        'players.team_abbrev',
                        'players.is_goalie',
                        'players.status'
                    )->withPivot(['platform', 'platform_player_id', 'slot', 'status', 'eligibility', 'starts_at', 'ends_at']);
                },
                'users' => static function ($q): void {
                    $q->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts:id,user_id,avatar']);
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(static function ($t) use ($authId): array {
                // default avatar per TEAM name
                $defaultAvatar = config('ui.default_team_avatar')
                    ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->name) . '&background=E5E7EB&color=111827&size=64';

                $ownerAvatar = $defaultAvatar;
                foreach ($t->users as $u) {
                    $avatar = optional($u->socialAccounts->first())->avatar;
                    if (filled($avatar)) {
                        $ownerAvatar = (string) $avatar;
                        break;
                    }
                }

                $ownerIds = $t->users->pluck('id')->map(static fn ($v) => (int) $v)->values()->all();
                $ownedByMe = $authId ? in_array((int) $authId, $ownerIds, true) : false;

                return [
                    'id'                => (string) $t->platform_team_id,
                    'name'              => (string) $t->name,
                    'owner_avatar_url'  => $ownerAvatar,
                    'owned_by_me'       => $ownedByMe,
                    'owner_user_ids'    => $ownerIds,
                    'players'           => $t->roster->map(static function ($p): array {
                        return [
                            'id'            => (int) $p->id,
                            'first_name'    => (string) ($p->first_name ?? ''),
                            'last_name'     => (string) ($p->last_name ?? ''),
                            'name'          => (string) ($p->full_name ?? trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''))),
                            'position'      => (string) ($p->position ?? ''),
                            'age'           => $p->age(),
                            'pos_type'      => (string) ($p->pos_type ?? ''),
                            'team_abbrev'   => (string) ($p->team_abbrev ?? ''),
                            'is_goalie'     => (bool) $p->is_goalie,
                            'status'        => (string) $p->status,
                            'roster_slot'   => $p->pivot->slot,
                            'roster_status' => $p->pivot->status,
                            'eligibility'   => $p->pivot->eligibility,
                            'starts_at'     => (string) $p->pivot->starts_at,
                            'ends_at'       => (string) $p->pivot->ends_at,
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();

        return $teams;
    }


}
