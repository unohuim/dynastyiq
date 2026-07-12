<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Models\PlatformLeagueUserSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlatformLeagueSettingsResolver
{
    /**
     * Default platform league settings.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'custom_cap' => false,
            'salary_cap' => null,
            'fantrax_contract_code_definitions' => [],
        ];
    }

    /**
     * Resolve settings for the current user and league authority state.
     *
     * @return array{settings:array<string,mixed>,source:string,can_edit:bool,has_league_admin:bool}
     */
    public function resolve(PlatformLeague $league, ?User $user): array
    {
        $canManage = $this->canManage($league, $user);
        $hasLeagueAdmin = $canManage || $this->hasLeagueAdmin($league);

        if ($canManage || ! $user) {
            return [
                'settings' => $this->mergeDefaults($league->settings),
                'source' => $canManage ? 'league_admin' : 'league_default',
                'can_edit' => $canManage,
                'has_league_admin' => $hasLeagueAdmin,
            ];
        }

        if ($hasLeagueAdmin) {
            return [
                'settings' => $this->mergeDefaults($league->settings),
                'source' => 'league_admin',
                'can_edit' => false,
                'has_league_admin' => true,
            ];
        }

        $localSettings = PlatformLeagueUserSetting::query()
            ->where('platform_league_id', $league->id)
            ->where('user_id', $user->id)
            ->first()
            ?->settings;

        return [
            'settings' => $this->mergeDefaults($league->settings, is_array($localSettings) ? $localSettings : []),
            'source' => 'manager_local',
            'can_edit' => true,
            'has_league_admin' => false,
        ];
    }

    /**
     * Persist settings to the correct authority-owned target.
     *
     * @param array<string,mixed> $settings
     * @return array{settings:array<string,mixed>,source:string,can_edit:bool,has_league_admin:bool}
     */
    public function save(PlatformLeague $league, User $user, array $settings): array
    {
        $resolved = $this->resolve($league, $user);

        if (! $resolved['can_edit']) {
            abort(403);
        }

        if ($resolved['source'] === 'league_admin') {
            $league->forceFill(['settings' => $this->mergeDefaults($settings)])->save();
            $league->refresh();

            return $this->resolve($league, $user);
        }

        PlatformLeagueUserSetting::query()->updateOrCreate(
            [
                'platform_league_id' => $league->id,
                'user_id' => $user->id,
            ],
            [
                'settings' => $this->mergeDefaults($settings),
            ],
        );

        return $this->resolve($league, $user);
    }

    /**
     * Determine whether the user can manage shared league settings.
     */
    public function canManage(PlatformLeague $league, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasGlobalRole') && $user->hasGlobalRole('super-admin')) {
            return true;
        }

        $internalLeagueIds = $this->internalLeagueIds($league);

        if ($internalLeagueIds !== []) {
            $hasLeagueRole = DB::table('league_user_roles')
                ->where('user_id', (int) $user->id)
                ->whereIn('league_id', $internalLeagueIds)
                ->whereIn('role', ['commissioner', 'co_commissioner'])
                ->exists();

            if ($hasLeagueRole) {
                return true;
            }
        }

        return DB::table('league_user_teams')
            ->where('user_id', (int) $user->id)
            ->where('platform_league_id', (int) $league->id)
            ->where(static function ($query): void {
                $query->where('extras->is_commish', true)
                    ->orWhere('extras->is_admin', true);
            })
            ->exists();
    }

    /**
     * Determine whether any connected user has shared league settings authority.
     */
    public function hasLeagueAdmin(PlatformLeague $league): bool
    {
        $internalLeagueIds = $this->internalLeagueIds($league);

        if ($internalLeagueIds !== []) {
            $hasLeagueRole = DB::table('league_user_roles')
                ->whereIn('league_id', $internalLeagueIds)
                ->whereIn('role', ['commissioner', 'co_commissioner'])
                ->exists();

            if ($hasLeagueRole) {
                return true;
            }
        }

        return DB::table('league_user_teams')
            ->where('platform_league_id', (int) $league->id)
            ->where(static function ($query): void {
                $query->where('extras->is_commish', true)
                    ->orWhere('extras->is_admin', true);
            })
            ->exists();
    }

    /**
     * @param array<string,mixed>|null ...$settingsSets
     * @return array<string,mixed>
     */
    public function mergeDefaults(?array ...$settingsSets): array
    {
        return array_replace($this->defaults(), ...array_filter(
            $settingsSets,
            static fn (?array $settings): bool => is_array($settings),
        ));
    }

    /**
     * Return internal league ids linked to this platform league.
     *
     * @return array<int,int>
     */
    private function internalLeagueIds(PlatformLeague $league): array
    {
        return DB::table('league_platform_league')
            ->where('platform_league_id', (int) $league->id)
            ->pluck('league_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
