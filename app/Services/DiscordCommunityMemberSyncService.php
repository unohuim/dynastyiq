<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DiscordServer;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Syncs Discord guild members into community membership records.
 */
class DiscordCommunityMemberSyncService
{
    private const DISCORD_API_BASE = 'https://discord.com/api/v10';

    /**
     * Determine whether the configured bot can access the guild.
     */
    public function botInstalled(DiscordServer $discordServer): bool
    {
        $token = $this->botToken();

        if ($token === '') {
            return false;
        }

        return $this->discord($token)
            ->get(self::DISCORD_API_BASE . '/guilds/' . $discordServer->discord_guild_id)
            ->successful();
    }

    /**
     * Sync all non-bot Discord guild members into the server's community.
     *
     * @return array{synced_count:int,created_count:int,updated_count:int,skipped_bot_count:int}
     */
    public function sync(DiscordServer $discordServer): array
    {
        $token = $this->botToken();

        if ($token === '') {
            throw new RuntimeException('Discord bot token is not configured.');
        }

        if (! $this->botInstalled($discordServer)) {
            throw new RuntimeException('DIQ bot is not installed for this Discord server.');
        }

        $created = 0;
        $updated = 0;
        $skippedBots = 0;

        foreach ($this->guildMembers($discordServer, $token) as $member) {
            $user = is_array($member['user'] ?? null) ? $member['user'] : [];

            if ((bool) ($user['bot'] ?? false)) {
                $skippedBots++;
                continue;
            }

            $result = DB::transaction(fn (): string => $this->upsertMember($discordServer, $member, $user));

            if ($result === 'created') {
                $created++;
            } else {
                $updated++;
            }
        }

        return [
            'synced_count' => $created + $updated,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_bot_count' => $skippedBots,
        ];
    }

    /**
     * Fetch all guild members using Discord pagination.
     *
     * @return iterable<int,array<string,mixed>>
     */
    private function guildMembers(DiscordServer $discordServer, string $token): iterable
    {
        $after = '0';

        do {
            $response = $this->discord($token)
                ->get(self::DISCORD_API_BASE . '/guilds/' . $discordServer->discord_guild_id . '/members', [
                    'limit' => 1000,
                    'after' => $after,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Discord member refresh failed.');
            }

            $members = $response->json();
            $members = is_array($members) ? $members : [];

            foreach ($members as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $userId = (string) data_get($member, 'user.id', '');

                if ($userId !== '') {
                    $after = $userId;
                }

                yield $member;
            }
        } while (count($members) === 1000);
    }

    /**
     * Upsert a single Discord member into community membership tables.
     *
     * @param array<string,mixed> $member
     * @param array<string,mixed> $user
     */
    private function upsertMember(DiscordServer $discordServer, array $member, array $user): string
    {
        $discordUserId = (string) ($user['id'] ?? '');

        if ($discordUserId === '') {
            return 'updated';
        }

        $membership = Membership::query()
            ->where('organization_id', $discordServer->organization_id)
            ->where('provider', 'discord')
            ->where('provider_member_id', $discordUserId)
            ->first();
        $wasRecentlyCreated = $membership === null;
        $profile = $membership?->memberProfile ?: $this->profileForDiscordUser($discordServer, $discordUserId);
        $profile->fill([
            'display_name' => $this->displayName($member, $user),
            'avatar_url' => $this->avatarUrl($user),
            'metadata' => array_filter([
                'discord_username' => $user['username'] ?? null,
                'discord_global_name' => $user['global_name'] ?? null,
                'discord_nick' => $member['nick'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
        $profile->attachExternalId('discord', $discordUserId, false);
        $profile->save();

        $social = SocialAccount::query()
            ->where('provider', 'discord')
            ->where('provider_user_id', $discordUserId)
            ->first();

        $membership = $membership ?: new Membership([
            'organization_id' => $discordServer->organization_id,
            'provider' => 'discord',
            'provider_member_id' => $discordUserId,
        ]);
        $membership->fill([
            'member_profile_id' => $profile->id,
            'status' => 'active',
            'synced_at' => now(),
            'metadata' => array_filter([
                'discord_guild_id' => $discordServer->discord_guild_id,
                'discord_server_id' => $discordServer->id,
                'discord_username' => $user['username'] ?? null,
                'discord_global_name' => $user['global_name'] ?? null,
                'discord_nick' => $member['nick'] ?? null,
                'discord_avatar' => $user['avatar'] ?? null,
                'dynastyiq_user_id' => $social?->user_id,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
        $membership->save();

        return $wasRecentlyCreated ? 'created' : 'updated';
    }

    private function profileForDiscordUser(DiscordServer $discordServer, string $discordUserId): MemberProfile
    {
        $profile = MemberProfile::query()
            ->where('organization_id', $discordServer->organization_id)
            ->where('external_ids->discord', $discordUserId)
            ->first();

        return $profile ?: new MemberProfile([
            'organization_id' => $discordServer->organization_id,
            'external_ids' => ['discord' => $discordUserId],
        ]);
    }

    /**
     * @param array<string,mixed> $member
     * @param array<string,mixed> $user
     */
    private function displayName(array $member, array $user): string
    {
        return (string) (
            $member['nick']
            ?? $user['global_name']
            ?? $user['username']
            ?? 'Discord Member'
        );
    }

    /**
     * @param array<string,mixed> $user
     */
    private function avatarUrl(array $user): ?string
    {
        $userId = (string) ($user['id'] ?? '');
        $avatar = (string) ($user['avatar'] ?? '');

        if ($userId === '' || $avatar === '') {
            return null;
        }

        $ext = str_starts_with($avatar, 'a_') ? 'gif' : 'png';

        return "https://cdn.discordapp.com/avatars/{$userId}/{$avatar}.{$ext}?size=128";
    }

    private function discord(string $token): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Bot ' . $token])
            ->acceptJson();
    }

    private function botToken(): string
    {
        return (string) config('apiurls.discord-bot.key');
    }
}
