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
     * Manager-local planning keys that may overlay shared league settings.
     *
     * @var array<int,string>
     */
    private const MANAGER_PLANNING_KEYS = [
        'cap_limits_by_season',
        'cap_adjustments_by_team',
    ];

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
            'cap_limits_by_season' => [],
            'cap_adjustments_by_team' => [],
            'max_active_buyouts' => null,
            'max_active_retentions' => null,
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
            $localSettings = $this->managerLocalSettings($league, $user);

            return [
                'settings' => $this->mergeDefaults(
                    $league->settings,
                    $this->managerPlanningOverrides($localSettings),
                ),
                'source' => 'league_admin',
                'can_edit' => false,
                'has_league_admin' => true,
            ];
        }

        $localSettings = $this->managerLocalSettings($league, $user);

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
     * Persist manager-owned planning fields without changing shared league settings.
     *
     * @param array<string,mixed> $settings
     * @return array{settings:array<string,mixed>,source:string,can_edit:bool,has_league_admin:bool}
     */
    public function saveManagerPlanning(PlatformLeague $league, User $user, array $settings): array
    {
        $existing = $this->managerLocalSettings($league, $user);
        $planningSettings = array_intersect_key($settings, array_flip(self::MANAGER_PLANNING_KEYS));

        PlatformLeagueUserSetting::query()->updateOrCreate(
            [
                'platform_league_id' => $league->id,
                'user_id' => $user->id,
            ],
            [
                'settings' => array_replace(is_array($existing) ? $existing : [], $planningSettings),
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

    /**
     * Return a manager's local settings for the platform league.
     *
     * @return array<string,mixed>
     */
    private function managerLocalSettings(PlatformLeague $league, ?User $user): array
    {
        if (! $user) {
            return [];
        }

        $settings = PlatformLeagueUserSetting::query()
            ->where('platform_league_id', $league->id)
            ->where('user_id', $user->id)
            ->first()
            ?->settings;

        return is_array($settings) ? $settings : [];
    }

    /**
     * Keep manager planning overlays scoped to explicitly allowed keys.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function managerPlanningOverrides(array $settings): array
    {
        return array_intersect_key($settings, array_flip(self::MANAGER_PLANNING_KEYS));
    }
}
