<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DiscordServer;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Manage Discord server connections for a community.
 */
class DiscordServerController extends Controller
{
    /**
     * Remove a Discord server from a community and clear league associations.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        DiscordServer $discordServer
    ): JsonResponse|RedirectResponse {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless((int) $discordServer->organization_id === (int) $organization->id, 404);

        $roleLevel = (int) ($user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') ?? 0);

        abort_unless($roleLevel >= 10, 403);

        $removeMembers = $request->boolean('remove_members');
        $removedMembersCount = 0;

        DB::transaction(function () use ($organization, $discordServer, $removeMembers, &$removedMembersCount): void {
            DB::table('organization_leagues')
                ->where('organization_id', $organization->id)
                ->where('discord_server_id', $discordServer->id)
                ->update(['discord_server_id' => null]);

            if ($removeMembers) {
                $removedMembersCount = DB::table('memberships')
                    ->where('organization_id', $organization->id)
                    ->where('provider', 'discord')
                    ->where(function ($query) use ($discordServer): void {
                        $query
                            ->where('metadata->discord_server_id', $discordServer->id)
                            ->orWhere('metadata->discord_guild_id', $discordServer->discord_guild_id);
                    })
                    ->delete();
            }

            $discordServer->delete();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'discord_server' => [
                    'id' => $discordServer->id,
                    'discord_guild_id' => $discordServer->discord_guild_id,
                    'discord_guild_name' => $discordServer->discord_guild_name,
                ],
                'removed_members_count' => $removedMembersCount,
            ]);
        }

        return redirect()
            ->route('communities.index', ['active' => $organization->id])
            ->with('success', 'Discord server removed from this community.');
    }
}
