<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteCallbackController extends Controller
{
    public function __invoke()
    {
        $oauth = Socialite::driver('discord')->stateless()->user();

        $account = SocialAccount::where('provider','discord')
            ->where('provider_user_id',(string)$oauth->getId())
            ->first();

        if (! $account) {
            $user = $oauth->getEmail()
                ? User::where('email',$oauth->getEmail())->first()
                : null;

            if (! $user) {
                $org = $this->resolveOrCreateOrganization($oauth);

                $user = User::create([
                    'name'              => $oauth->getName() ?: ($oauth->getNickname() ?: 'User'),
                    'email'             => $oauth->getEmail() ?: Str::uuid().'@placeholder.local',
                    'password'          => bcrypt(Str::random(40)),
                    'tenant_id'         => $org->id,      // required
                    'email_verified_at' => now(),
                ]);
            } elseif (empty($user->tenant_id)) {
                $user->forceFill(['tenant_id' => $this->resolveOrCreateOrganization($oauth)->id])->save();
            }

            $account = new SocialAccount([
                'provider'         => 'discord',
                'provider_user_id' => (string)$oauth->getId(),
                'email'            => $oauth->getEmail(),
                'nickname'         => $oauth->getNickname(),
                'name'             => $oauth->getName(),
                'avatar'           => $oauth->getAvatar(),
                'access_token'     => $oauth->token ?? null,
                'refresh_token'    => $oauth->refreshToken ?? null,
                'expires_at'       => isset($oauth->expiresIn) ? now()->addSeconds($oauth->expiresIn) : null,
            ]);

            $account->user()->associate($user);
            $account->save();
        } else {
            $account->update([
                'email'         => $oauth->getEmail(),
                'nickname'      => $oauth->getNickname(),
                'name'          => $oauth->getName(),
                'avatar'        => $oauth->getAvatar(),
                'access_token'  => $oauth->token ?? null,
                'refresh_token' => $oauth->refreshToken ?? null,
                'expires_at'    => isset($oauth->expiresIn) ? now()->addSeconds($oauth->expiresIn) : null,
            ]);
        }

        Auth::login($account->user, remember: true);
        return redirect()->intended('/');
    }

    private function resolveOrCreateOrganization($oauth): Organization
    {
        if (session()->has('tenant_invite_id')) {
            if ($org = Organization::find(session('tenant_invite_id'))) {
                return $org;
            }
        }

        return Organization::create([
            'name'       => ($oauth->getNickname() ?: $oauth->getName() ?: 'New User')."'s Organization",
            'short_name' => $oauth->getNickname() ?: null,
        ]);
    }
}
