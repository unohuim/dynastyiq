<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\DiscordCommunityMemberSyncService;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CommunityMemberProviderRefreshController extends Controller
{
    /**
     * Refresh provider-managed community members from every connected provider.
     */
    public function store(
        Request $request,
        Organization $organization,
        DiscordCommunityMemberSyncService $discordSyncService,
        PatreonSyncService $patreonSyncService
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user, 403);

        $roleLevel = (int) ($user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') ?? 0);

        abort_unless($roleLevel >= 10, 403);

        $discordSummary = $this->refreshDiscordMembers($organization, $discordSyncService);

        if (($discordSummary['ok'] ?? true) !== true) {
            return response()->json([
                'ok' => false,
                'message' => $discordSummary['message'],
                'summary' => [
                    'discord' => $discordSummary['summary'],
                    'patreon' => $this->emptyPatreonSummary(),
                ],
            ], 422);
        }

        $patreonSummary = $this->refreshPatreonMembers($organization, $patreonSyncService);

        return response()->json([
            'ok' => true,
            'message' => 'Community members refreshed.',
            'summary' => [
                'discord' => $discordSummary['summary'],
                'patreon' => $patreonSummary,
            ],
        ]);
    }

    /**
     * Refresh members for each connected Discord server and aggregate the result.
     *
     * @return array<string, mixed>
     */
    protected function refreshDiscordMembers(
        Organization $organization,
        DiscordCommunityMemberSyncService $syncService
    ): array {
        $servers = $organization->discordServers()->orderBy('id')->get();
        $summaries = [];
        $syncedCount = 0;

        foreach ($servers as $server) {
            try {
                $summary = $syncService->sync($server);
            } catch (RuntimeException $e) {
                return [
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'summary' => [
                        'server_count' => $servers->count(),
                        'synced_count' => $syncedCount,
                        'servers' => $summaries,
                    ],
                ];
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

        return [
            'ok' => true,
            'summary' => [
                'server_count' => $servers->count(),
                'synced_count' => $syncedCount,
                'servers' => $summaries,
            ],
        ];
    }

    /**
     * Refresh Patreon members for the connected community provider account.
     *
     * @return array<string, mixed>
     */
    protected function refreshPatreonMembers(Organization $organization, PatreonSyncService $syncService): array
    {
        $account = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->first();

        if (!$account) {
            return $this->emptyPatreonSummary();
        }

        $summary = $syncService->syncProviderAccount($account);

        return [
            'connected' => true,
            'account_id' => $account->id,
            'tiers_synced' => (int) ($summary['tiers_synced'] ?? 0),
            'members_synced' => (int) ($summary['members_synced'] ?? 0),
            'summary' => $summary,
        ];
    }

    /**
     * Return a stable empty Patreon refresh summary.
     *
     * @return array<string, mixed>
     */
    protected function emptyPatreonSummary(): array
    {
        return [
            'connected' => false,
            'account_id' => null,
            'tiers_synced' => 0,
            'members_synced' => 0,
            'summary' => [],
        ];
    }
}
