<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\PlatformTeam;
use App\Models\PlatformTeamRosterShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Manage commissioner-created public roster share links.
 */
class PlatformTeamRosterShareLinkController extends Controller
{
    /**
     * List share links for one team.
     */
    public function index(Request $request, int $cId, int $lId, PlatformTeam $team): JsonResponse
    {
        $league = $this->authorizedLeague($request, $cId, $lId, $team);

        return response()->json([
            'links' => $league->rosterShareLinks()
                ->where('platform_team_id', $team->id)
                ->latest()
                ->get()
                ->map(fn (PlatformTeamRosterShareLink $link): array => $this->linkPayload($link))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Create a private share link for one team.
     */
    public function store(Request $request, int $cId, int $lId, PlatformTeam $team): JsonResponse
    {
        $league = $this->authorizedLeague($request, $cId, $lId, $team);
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
        ]);
        $token = PlatformTeamRosterShareLink::newPlainToken();
        $label = trim((string) ($validated['label'] ?? ''));

        $link = PlatformTeamRosterShareLink::create([
            'league_id' => $league->id,
            'platform_league_id' => $team->platform_league_id,
            'platform_team_id' => $team->id,
            'created_by' => $request->user()?->id,
            'label' => $label !== '' ? $label : 'Roster share',
            'token_hash' => PlatformTeamRosterShareLink::hashToken($token),
            'encrypted_token' => $token,
            'is_public' => false,
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json($this->linkPayload($link), 201);
    }

    /**
     * Update label or public state for one share link.
     */
    public function update(Request $request, int $cId, int $lId, PlatformTeam $team, PlatformTeamRosterShareLink $shareLink): JsonResponse
    {
        $this->authorizedLeague($request, $cId, $lId, $team);
        abort_unless((int) $shareLink->platform_team_id === (int) $team->id, 404);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('label', $validated)) {
            $label = trim((string) $validated['label']);
            $shareLink->label = $label !== '' ? $label : $shareLink->label;
        }

        if (array_key_exists('is_public', $validated)) {
            $shareLink->is_public = (bool) $validated['is_public'];
        }

        $shareLink->save();

        return response()->json($this->linkPayload($shareLink->refresh()));
    }

    /**
     * Revoke a share link.
     */
    public function destroy(Request $request, int $cId, int $lId, PlatformTeam $team, PlatformTeamRosterShareLink $shareLink): JsonResponse
    {
        $this->authorizedLeague($request, $cId, $lId, $team);
        abort_unless((int) $shareLink->platform_team_id === (int) $team->id, 404);

        $shareLink->forceFill([
            'is_public' => false,
            'revoked_at' => now(),
        ])->save();

        return response()->json($this->linkPayload($shareLink->refresh()));
    }

    private function authorizedLeague(Request $request, int $communityId, int $leagueId, PlatformTeam $team): League
    {
        $user = $request->user();
        $community = $user?->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->findOrFail($communityId);
        $league = $community->leagues()->findOrFail($leagueId);
        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        abort_unless($platformLeague !== null && (int) $team->platform_league_id === (int) $platformLeague->id, 404);
        abort_unless(Gate::allows('refresh-leagues') || $user?->isCommissionerForLeague((int) $league->id), 403);

        return $league;
    }

    /**
     * @return array<string,mixed>
     */
    private function linkPayload(PlatformTeamRosterShareLink $link): array
    {
        $token = (string) $link->encrypted_token;

        return [
            'id' => (int) $link->id,
            'label' => (string) $link->label,
            'is_public' => (bool) $link->is_public,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'url' => route('shared.rosters.show', ['token' => $token]),
            'copy_enabled' => $link->isAccessible(),
        ];
    }
}
