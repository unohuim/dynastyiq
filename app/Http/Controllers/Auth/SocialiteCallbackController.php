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

class SocialiteCallbackController extends Controller
{
    public function __invoke()
    {
        $oauth = Socialite::driver('discord')->stateless()->user();

        $account = DB::transaction(function () use ($oauth) {
            // 0) If this Discord account already exists, update tokens & return it.
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

                return $existing;
            }

            // 1) Resolve/create Organization FIRST
            $org = $this->resolveOrCreateOrganization($oauth);

            // 2) Find or create User (attach tenant_id = org id)
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

            // 3) Upsert SocialAccount (MUST include user_id)
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
        return redirect()->intended('/');
    }

    private function resolveOrCreateOrganization($oauth): Organization
    {
        // Use and clear a tenant invite if present
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
