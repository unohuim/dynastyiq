<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncFantraxLeagueJob;
use App\Services\FantasyLeagueAccess;
use App\Services\YahooFantasyLeagueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeagueController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $leagues = $leagueAccess->activeLeaguesForUser($user)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

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

    public function panel(Request $request, string $leagueId): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->firstOrFail();

        return view('leagues._panel', [
            'league' => $league,
            'teams'  => $this->teamsPayload($league),
        ]);
    }

    /**
     * Refresh Yahoo leagues, teams, owned assignments, and queued roster syncs.
     */
    public function resyncYahoo(Request $request, YahooFantasyLeagueService $leagueService): JsonResponse
    {
        $connection = $request->user()
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return response()->json([
                'message' => 'Connect Yahoo before resyncing leagues.',
            ], 409);
        }

        return response()->json([
            'message' => 'Yahoo league sync queued roster refreshes.',
            'summary' => $leagueService->syncForConnection($connection, $request->user()->id),
        ]);
    }

    /**
     * Refresh all connected fantasy leagues for the current user.
     */
    public function resync(Request $request, YahooFantasyLeagueService $leagueService): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before resyncing leagues.',
            ], 409);
        }

        $summary = [
            'yahoo' => null,
            'fantrax' => [
                'platform' => 'fantrax',
                'platform_league_ids' => [],
                'queued_job_count' => 0,
            ],
        ];

        $connection = $user
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if ($connection) {
            $summary['yahoo'] = $leagueService->syncForConnection($connection, $user->id);
        }

        $fantraxLeagueIds = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.platform', 'fantrax')
            ->pluck('platform_leagues.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        foreach ($fantraxLeagueIds as $fantraxLeagueId) {
            SyncFantraxLeagueJob::dispatch($fantraxLeagueId, $user->id);
        }

        $summary['fantrax']['platform_league_ids'] = $fantraxLeagueIds;
        $summary['fantrax']['queued_job_count'] = count($fantraxLeagueIds);

        return response()->json([
            'message' => 'League sync queued.',
            'summary' => $summary,
        ]);
    }

    public function show(Request $request, string $leagueId): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $leagues = $leagueAccess->activeLeaguesForUser($user)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $activeLeague = $leagues->firstWhere('id', (int) $leagueId);
        abort_if($activeLeague === null, 404);

        return view('leagues', [
            'leagues' => $leagues,
            'activeLeagueId' => $activeLeague->id,
            'activeLeague' => $activeLeague,
            'teams' => $this->teamsPayload($activeLeague),
        ]);
    }

    /**
     * Build the team, avatar, ownership, and roster payload for a league.
     */
    private function teamsPayload($league): array
    {
        $authId = auth()->id();
        $slotOrder = $league->rosterSlots()
            ->pluck('sort_order', 'slot')
            ->map(static fn ($value): int => (int) $value)
            ->all();

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
            ->get();

        $fantraxEligibility = $league->platform === 'fantrax'
            ? self::fantraxEligibilityByPlatformPlayerId($teams->pluck('roster')->flatten())
            : [];

        $teams = $teams
            ->map(static function ($t) use ($authId, $slotOrder, $fantraxEligibility, $league): array {
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
                    'players'           => $t->roster
                        ->map(static function ($p) use ($slotOrder, $fantraxEligibility, $league): array {
                            $slot = (string) ($p->pivot->slot ?? '');
                            $rosterStatus = (string) ($p->pivot->status ?? '');
                            $eligibility = self::normalizeEligibility($p->pivot->eligibility);
                            $platformEligibility = self::platformEligibility(
                                (string) $league->platform,
                                (string) ($p->pivot->platform_player_id ?? ''),
                                $eligibility,
                                $fantraxEligibility,
                            );
                            $displaySlot = self::displayRosterSlot(
                                $slot,
                                $rosterStatus,
                                $platformEligibility,
                                (string) ($p->position ?? ''),
                            );
                            $rosterGroup = self::isMinorRosterRow($displaySlot, $rosterStatus)
                                ? 'minor'
                                : 'active';
                            $rosterSortOrder = $rosterGroup === 'minor'
                                ? self::minorRosterPositionSortOrder($platformEligibility)
                                : ($slotOrder[$slot] ?? self::fallbackRosterSlotOrder($displaySlot));

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
                                'roster_slot'   => $displaySlot,
                                'roster_status' => $rosterStatus,
                                'roster_group'  => $rosterGroup,
                                'eligibility'   => $platformEligibility,
                                'starts_at'     => (string) $p->pivot->starts_at,
                                'ends_at'       => (string) $p->pivot->ends_at,
                                'roster_sort_order' => $rosterSortOrder,
                                'roster_group_sort_order' => $rosterGroup === 'minor' ? 1 : 0,
                                'roster_status_sort_order' => match ($rosterStatus) {
                                    'active' => 10,
                                    'bench' => 20,
                                    'ir' => 30,
                                    'na' => 40,
                                    'taxi' => 50,
                                    default => 90,
                                },
                            ];
                        })
                        ->sortBy(static fn (array $player): string => sprintf(
                            '%03d-%03d-%03d-%s',
                            $player['roster_group_sort_order'],
                            $player['roster_sort_order'],
                            $player['roster_status_sort_order'],
                            $player['name'],
                        ))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return $teams;
    }

    /**
     * Normalize roster membership eligibility into a flat list for the UI.
     */
    private static function normalizeEligibility(mixed $eligibility): array
    {
        if ($eligibility === null || $eligibility === '') {
            return [];
        }

        if (is_array($eligibility)) {
            return collect($eligibility)->flatten()->filter()->values()->all();
        }

        if (! is_string($eligibility)) {
            return [];
        }

        $value = trim($eligibility);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->flatten()->filter()->values()->all();
        }

        return collect(explode(',', $value))
            ->map(static fn (string $position): string => trim($position))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve the eligibility list that should be displayed for a platform player.
     *
     * @param array<string,array<int,string>> $fantraxEligibility
     * @param array<int,string> $rowEligibility
     *
     * @return array<int,string>
     */
    private static function platformEligibility(
        string $platform,
        string $platformPlayerId,
        array $rowEligibility,
        array $fantraxEligibility,
    ): array {
        if ($platform === 'fantrax' && $platformPlayerId !== '') {
            return $fantraxEligibility[$platformPlayerId] ?? [];
        }

        return self::realRosterPositions($rowEligibility);
    }

    /**
     * Build Fantrax eligibility from observed provider roster-slot usage.
     *
     * @param iterable<mixed> $players
     *
     * @return array<string,array<int,string>>
     */
    private static function fantraxEligibilityByPlatformPlayerId(iterable $players): array
    {
        $platformPlayerIds = collect($players)
            ->map(static fn ($player): string => (string) ($player->pivot->platform_player_id ?? ''))
            ->filter()
            ->unique()
            ->values();

        if ($platformPlayerIds->isEmpty()) {
            return [];
        }

        $eligibility = [];

        DB::table('platform_roster_memberships')
            ->select('platform_player_id', 'slot')
            ->where('platform', 'fantrax')
            ->whereIn('platform_player_id', $platformPlayerIds)
            ->whereNull('ends_at')
            ->orderBy('id')
            ->get()
            ->each(static function ($membership) use (&$eligibility): void {
                $platformPlayerId = (string) $membership->platform_player_id;
                $position = self::normalizedRosterPosition((string) $membership->slot);

                if ($position === null) {
                    return;
                }

                $eligibility[$platformPlayerId] ??= [];
                $eligibility[$platformPlayerId][] = $position;
            });

        $fallbackPositions = DB::table('fantrax_players')
            ->whereIn('fantrax_id', $platformPlayerIds)
            ->pluck('position', 'fantrax_id')
            ->map(static fn ($position): array => self::realRosterPositions(
                preg_split('/[\\/,]/', (string) $position) ?: [],
            ))
            ->all();

        return $platformPlayerIds
            ->mapWithKeys(static function (string $platformPlayerId) use ($eligibility, $fallbackPositions): array {
                $positions = self::sortRosterPositions($eligibility[$platformPlayerId] ?? []);

                if ($positions === []) {
                    $positions = self::sortRosterPositions($fallbackPositions[$platformPlayerId] ?? []);
                }

                return [$platformPlayerId => $positions];
            })
            ->all();
    }

    /**
     * Normalize a list to real hockey roster positions only.
     *
     * @param array<int,mixed> $positions
     *
     * @return array<int,string>
     */
    private static function realRosterPositions(array $positions): array
    {
        return self::sortRosterPositions(
            collect($positions)
                ->map(static fn (mixed $position): ?string => self::normalizedRosterPosition((string) $position))
                ->filter()
                ->values()
                ->all(),
        );
    }

    /**
     * Normalize one provider roster position and drop non-position containers.
     */
    private static function normalizedRosterPosition(string $position): ?string
    {
        $position = strtoupper(trim($position));

        return match ($position) {
            'C' => 'C',
            'L', 'LW' => 'LW',
            'R', 'RW' => 'RW',
            'D', 'LD', 'RD' => 'D',
            'G' => 'G',
            default => null,
        };
    }

    /**
     * Sort and de-duplicate hockey roster positions for display.
     *
     * @param array<int,string> $positions
     *
     * @return array<int,string>
     */
    private static function sortRosterPositions(array $positions): array
    {
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return collect($positions)
            ->filter(static fn (string $position): bool => isset($order[$position]))
            ->unique()
            ->sortBy(static fn (string $position): int => $order[$position])
            ->values()
            ->all();
    }

    /**
     * Choose a roster slot label for display when a provider did not store one.
     *
     * @param array<int,mixed> $eligibility
     */
    private static function displayRosterSlot(
        string $slot,
        string $status,
        array $eligibility,
        string $position,
    ): string {
        if ($slot !== '') {
            return $slot;
        }

        $statusSlot = match ($status) {
            'bench' => 'BN',
            'ir' => 'IR',
            'na' => 'NA',
            'taxi' => 'TAXI',
            default => null,
        };

        if ($statusSlot !== null) {
            return $statusSlot;
        }

        $specificEligibility = collect($eligibility)
            ->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->first(static fn (string $value): bool => $value !== '' && ! in_array($value, [
                'F',
                'UTIL',
                'UTILS',
                'UTILITY',
                'UTL',
                'W/R/T',
            ], true));

        return $specificEligibility ?: $position;
    }

    /**
     * Return the provider-neutral fallback order for leagues without roster slot settings.
     */
    private static function fallbackRosterSlotOrder(string $slot): int
    {
        $normalizedSlot = self::normalizedFallbackSlot($slot);
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'F' => 40,
            'D' => 50,
            'SKT' => 60,
            'G' => 70,
            'RES' => 80,
            'BEN' => 90,
            'IR' => 100,
            'MIN' => 110,
        ];

        return $order[$normalizedSlot] ?? 999;
    }

    /**
     * Return minor roster player ordering by actual hockey position.
     *
     * @param array<int,mixed> $eligibility
     */
    private static function minorRosterPositionSortOrder(array $eligibility): int
    {
        $positions = collect($eligibility)
            ->map(static fn (mixed $value): string => self::normalizedMinorPosition((string) $value))
            ->filter()
            ->values();

        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return $positions
            ->map(static fn (string $value): int => $order[$value] ?? 999)
            ->min() ?? 999;
    }

    /**
     * Normalize provider position variants into the minor roster ordering vocabulary.
     */
    private static function normalizedMinorPosition(string $position): string
    {
        $position = strtoupper(trim($position));

        return match ($position) {
            'L' => 'LW',
            'R' => 'RW',
            'LD', 'RD' => 'D',
            default => $position,
        };
    }

    /**
     * Normalize provider slot variants into the fallback roster ordering vocabulary.
     */
    private static function normalizedFallbackSlot(string $slot): string
    {
        $slot = strtoupper(trim($slot));

        return match ($slot) {
            'L' => 'LW',
            'R' => 'RW',
            'BN', 'BENCH' => 'BEN',
            'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
            'UTIL', 'UTILS', 'UTILITY', 'UTL', 'W/R/T' => 'F',
            default => $slot,
        };
    }

    /**
     * Determine whether a roster row belongs under the minor league separator.
     */
    private static function isMinorRosterRow(string $slot, string $status): bool
    {
        return self::normalizedFallbackSlot($slot) === 'MIN' || strtolower(trim($status)) === 'na';
    }
}
