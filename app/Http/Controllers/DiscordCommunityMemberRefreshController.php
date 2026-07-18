<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DiscordServer;
use App\Models\Organization;
use App\Services\DiscordCommunityMemberSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DiscordCommunityMemberRefreshController extends Controller
{
    /**
     * Refresh Discord members into the community roster.
     */
    public function store(
        Request $request,
        Organization $organization,
        DiscordServer $discordServer,
        DiscordCommunityMemberSyncService $syncService
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless((int) $discordServer->organization_id === (int) $organization->id, 404);

        $roleLevel = (int) ($user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') ?? 0);

        abort_unless($roleLevel >= 10, 403);

        try {
            $summary = $syncService->sync($discordServer);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Discord members refreshed.',
            'summary' => $summary,
        ]);
    }

    /**
     * Refresh Discord members for every connected Discord server in a community.
     */
    public function storeAll(
        Request $request,
        Organization $organization,
        DiscordCommunityMemberSyncService $syncService
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user, 403);

        $roleLevel = (int) ($user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') ?? 0);

        abort_unless($roleLevel >= 10, 403);

        $servers = $organization->discordServers()->orderBy('id')->get();
        $summaries = [];
        $syncedCount = 0;

        foreach ($servers as $server) {
            try {
                $summary = $syncService->sync($server);
            } catch (RuntimeException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'summary' => [
                        'server_count' => $servers->count(),
                        'synced_count' => $syncedCount,
                        'servers' => $summaries,
                    ],
                ], 422);
            }

            $serverSyncedCount = (int) ($summary['synced_count'] ?? 0);
            $syncedCount += $serverSyncedCount;
            $summaries[] = [
                'discord_server_id' => $server->id,
                'discord_guild_id' => $server->discord_guild_id,
                'discord_guild_name' => $server->discord_guild_name,
                'synced_count' => $serverSyncedCount,
                'summary' => $summary,
            ];
        }

        return response()->json([
            'ok' => true,
            'message' => 'Discord members refreshed.',
            'summary' => [
                'server_count' => $servers->count(),
                'synced_count' => $syncedCount,
                'servers' => $summaries,
            ],
        ]);
    }
}
