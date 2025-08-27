<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use App\Traits\HasAPITrait;

class SocialiteCallbackController extends Controller
{
    use HasAPITrait;

    public function __invoke()
    {
        $oauth = Socialite::driver('discord')->stateless()->user();

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


                //update user record
                $user = $existing->user()->lockForUpdate()->first();
                $newName  = $oauth->getName() ?: ($oauth->getNickname() ?: $user->name);
                $newEmail = $oauth->getEmail();

                $dirty = false;
                if ($newName && $user->name !== $newName) {
                    $user->name = $newName;
                    $dirty = true;
                }

                // Use whatever Discord provides each login; update if changed
                if ($newEmail && $user->email !== $newEmail) {
                    $user->email = $newEmail;
                    $user->email_verified_at = now();
                    $dirty = true;
                }

                if ($dirty) {
                    $user->save();
                }                


                return $existing;
            }

            $org = $this->resolveOrCreateOrganization($oauth);

            $user = $oauth->getEmail()
                ? User::where('email', $oauth->getEmail())->lockForUpdate()->first()
                : null;

            if (! $user) {
                $user = User::create([
                    'name'              => $oauth->getName() ?: ($oauth->getNickname() ?: 'User'),
                    'email'             => $oauth->getEmail() ?: (Str::uuid() . '@placeholder.local'),
                    'password'          => bcrypt(Str::random(40)),
                    'tenant_id'         => $org->id,
                    'email_verified_at' => now(),
                ]);
            } elseif (empty($user->tenant_id)) {
                $user->forceFill(['tenant_id' => $org->id])->save();
            }

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

        // Gracefully handle “not a member yet” (404) during OAuth
        session(['diq-user.connected' => false]);

        $guildId       = (string) config('services.discord.diq_guild_id');
        $discordUserId = (string) $account->provider_user_id;

        

        try {
            if ($this->isDiscordMember($guildId, $discordUserId)) {                
                session(['diq-user.connected' => true]);                
            }
            
        } catch (\Throwable $e) {
            
            // Any non-404 error: ignore for login flow; optionally log
            // report($e);
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
        
            return true; // 200
        } catch (\Illuminate\Http\Client\RequestException $e) {            
            if (optional($e->response)->status() === 404) {
                return false; // not in server yet
            }
            throw $e; // other errors bubble to caller (caught above)
        }
    }

    private function resolveOrCreateOrganization($oauth): Organization
    {
        $inviteId = session()->pull('tenant_invite_id');

        if ($inviteId) {
            $org = Organization::lockForUpdate()->find($inviteId);
            if ($org) {
                return $org;
            }
        }

        return Organization::create([
            'name'       => ($oauth->getNickname() ?: $oauth->getName() ?: 'New User') . "'s Organization",
            'short_name' => $oauth->getNickname() ?: null,
        ]);
    }
}
