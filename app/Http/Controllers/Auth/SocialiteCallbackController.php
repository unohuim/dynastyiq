<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteCallbackController extends Controller
{
    use HasAPITrait;

    public function __invoke()
    {
        $oauth = Socialite::driver('discord')
            ->redirectUrl($this->discordRedirectUri())
            ->stateless()
            ->user();

        $account = DB::transaction(function () use ($oauth) {
            $existing = SocialAccount::where('provider', 'discord')
                ->where('provider_user_id', (string) $oauth->getId())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->fill([
                    'email'         => $oauth->getEmail(),
                    'nickname'      => $oauth->getNickname(),
                    'name'          => $oauth->getName(),
                    'avatar'        => $oauth->getAvatar(),
                    'access_token'  => $oauth->token ?? null,
                    'refresh_token' => $oauth->refreshToken ?? null,
                    'expires_at'    => isset($oauth->expiresIn) ? now()->addSeconds($oauth->expiresIn) : null,
                ])->save();

                $user = $existing->user()->lockForUpdate()->first();

                $newName  = $oauth->getName() ?: ($oauth->getNickname() ?: $user->name);
                $newEmail = $oauth->getEmail();

                $dirty = false;
                if ($newName && $user->name !== $newName) {
                    $user->name = $newName;
                    $dirty = true;
                }
                if ($newEmail && $user->email !== $newEmail) {
                    $user->email = $newEmail;
                    $user->email_verified_at = now();
                    $dirty = true;
                }
                if ($dirty) {
                    $user->save();
                }

                // Attach invite org (no duplicate ownership)
                if ($inviteOrg = $this->consumeOrganizationInvite()) {
                    $user->organizations()->syncWithoutDetaching([$inviteOrg->id => ['settings' => null]]);
                    if (
                        empty($inviteOrg->owner_user_id)
                        && !Organization::where('owner_user_id', $user->id)->lockForUpdate()->exists()
                    ) {
                        $inviteOrg->owner_user_id = $user->id;
                        $inviteOrg->save();
                    }
                }

                return $existing;
            }

            // ---- New linkage -------------------------------------------------
            // 1) Upsert user by email (or create placeholder email once).
            $email = $oauth->getEmail() ?: (Str::uuid() . '@placeholder.local');

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $oauth->getName() ?: ($oauth->getNickname() ?: 'User'),
                    'password'          => bcrypt(Str::random(40)),
                    'email_verified_at' => $oauth->getEmail() ? now() : null,
                ]
            );

            // 2) Resolve an organization without violating one-owner-per-user.
            $org = $this->resolveOrganizationFor($user, $oauth);

            // Ensure membership on organization_user pivot.
            $user->organizations()->syncWithoutDetaching([$org->id => ['settings' => null]]);

            // 3) Upsert social account.
            return SocialAccount::updateOrCreate(
                [
                    'provider'         => 'discord',
                    'provider_user_id' => (string) $oauth->getId(),
                ],
                [
                    'user_id'       => $user->id,
                    'email'         => $oauth->getEmail(),
                    'nickname'      => $oauth->getNickname(),
                    'name'          => $oauth->getName(),
                    'avatar'        => $oauth->getAvatar(),
                    'access_token'  => $oauth->token ?? null,
                    'refresh_token' => $oauth->refreshToken ?? null,
                    'expires_at'    => isset($oauth->expiresIn) ? now()->addSeconds($oauth->expiresIn) : null,
                ]
            );
        });

        Auth::login($account->user, remember: true);

        session(['diq-user.connected' => false]);

        $guildId = (string) config('services.discord.diq_guild_id');
        $discordUserId = (string) $account->provider_user_id;

        if ($guildId !== '') {
            try {
                if ($this->isDiscordMember($guildId, $discordUserId)) {
                    session(['diq-user.connected' => true]);
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Discord guild membership check failed', [
                    'guild_id' => $guildId,
                    'discord_user_id' => $discordUserId,
                    'exception' => $e,
                ]);
            }
        }

        return redirect()->intended('/');
    }

    public function isDiscordMember(string $guildId, string $discordUserId): bool
    {
        try {
            $this->getAPIData(
                'discord-bot',
                'guild_member',
                ['guildId' => $guildId, 'discordUserId' => $discordUserId]
            );

            return true;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if (optional($e->response)->status() === 404) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Choose an org in this order:
     * 1) Session invite org (attach; set owner only if user owns none).
     * 2) Already-owned org by this user.
     * 3) First membership org.
     * 4) Create a new org (assign this user as owner).
     */
    private function resolveOrganizationFor(User $user, $oauth): Organization
    {
        if ($invite = $this->consumeOrganizationInvite()) {
            $this->maybeAssignOwnership($invite, $user);

            return $invite->lockForUpdate()->first() ?? $invite;
        }

        if ($owned = Organization::where('owner_user_id', $user->id)->lockForUpdate()->first()) {
            return $owned;
        }

        if ($member = $user->organizations()->lockForUpdate()->first()) {
            return $member;
        }

        $name = ($oauth->getNickname() ?: $oauth->getName() ?: 'New User') . "'s Organization";
        $short = $oauth->getNickname() ?: null;

        $baseSlug = Str::slug($short ?: $name);
        $slug = $this->ensureUniqueSlug($baseSlug);

        $org = Organization::firstOrCreate(
            ['slug' => $slug],
            [
                'name'       => $name,
                'short_name' => $short,
                'settings'   => ['commissioner_tools' => false, 'creator_tools' => false],
            ]
        );

        // Assign owner only if user owns none right now.
        $this->maybeAssignOwnership($org, $user);

        return $org;
    }

    private function maybeAssignOwnership(Organization $org, User $user): void
    {
        if (
            empty($org->owner_user_id)
            && !Organization::where('owner_user_id', $user->id)->lockForUpdate()->exists()
        ) {
            $org->owner_user_id = $user->id;
            $org->save();
        }
    }

    private function consumeOrganizationInvite(): ?Organization
    {
        $inviteId = session()->pull('organization_invite_id')
            ?? session()->pull('tenant_invite_id');

        if (! $inviteId) {
            return null;
        }

        return Organization::lockForUpdate()->find($inviteId);
    }

    private function ensureUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $i = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function discordRedirectUri(): string
    {
        return config('services.discord.redirect') ?: route('discord.callback');
    }
}
