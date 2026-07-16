<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\League;
use App\Models\LeaguePlatformLeague;
use App\Models\PlatformLeague;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Coordinates durable league wrappers and scoped provider bindings.
 */
class LeagueProviderBindingService
{
    /**
     * Return attachable provider scope options for a platform league.
     *
     * @return array<int,array{type:string|null,key:string|null,label:string}>
     */
    public function scopeOptions(PlatformLeague $platformLeague): array
    {
        $shape = data_get($platformLeague, 'settings.league_shape', []);

        if (! is_array($shape) || ($shape['player_pool_scope'] ?? null) !== 'division') {
            return [[
                'type' => null,
                'key' => null,
                'label' => 'Full league',
            ]];
        }

        $divisions = array_values(is_array($shape['divisions'] ?? null) ? $shape['divisions'] : []);

        $options = collect($divisions)
            ->map(fn (mixed $division): array => [
                'type' => 'division',
                'key' => $this->scopeKey((string) $division),
                'label' => (string) $division,
            ])
            ->filter(static fn (array $option): bool => $option['key'] !== '')
            ->values()
            ->all();

        return $options !== [] ? $options : [[
            'type' => null,
            'key' => null,
            'label' => 'Full league',
        ]];
    }

    /**
     * Attach or create an active provider binding for a league wrapper.
     *
     * @param array{type?:string|null,key?:string|null,label?:string|null} $scope
     */
    public function attach(
        ?League $league,
        PlatformLeague $platformLeague,
        array $scope = [],
        ?string $name = null,
        ?string $sport = null
    ): League {
        $scope = $this->normalizeScope($scope);
        $existingLeague = $this->activeLeagueForPlatformScope($platformLeague, $scope);

        if ($existingLeague) {
            if ($league && (int) $existingLeague->id !== (int) $league->id) {
                throw new DomainException('Selected provider scope is already linked to another league.');
            }

            return $existingLeague;
        }

        if ($league && $league->activePlatformBinding()) {
            throw new DomainException('Selected league already has an active platform link.');
        }

        $league = $league ?? League::create([
            'name' => $this->leagueName($platformLeague, $scope, $name),
            'sport' => $sport ?? $platformLeague->sport ?? 'hockey',
        ]);

        DB::table('league_platform_league')->insert([
            'league_id' => $league->id,
            'platform_league_id' => $platformLeague->id,
            'linked_at' => now(),
            'status' => LeaguePlatformLeague::STATUS_ACTIVE,
            'meta' => $this->scopeMeta($scope),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $league;
    }

    /**
     * Attach or create one league wrapper for every provider scope.
     *
     * @return array<int,\App\Models\League>
     */
    public function attachAllScopes(PlatformLeague $platformLeague, ?string $sport = null): array
    {
        return DB::transaction(function () use ($platformLeague, $sport): array {
            return collect($this->scopeOptions($platformLeague))
                ->map(fn (array $scope): League => $this->attach(
                    null,
                    $platformLeague,
                    $scope,
                    null,
                    $sport
                ))
                ->values()
                ->all();
        });
    }

    /**
     * Explicitly migrate a league wrapper to a new active provider binding.
     *
     * @param array{type?:string|null,key?:string|null,label?:string|null} $scope
     */
    public function migrate(
        League $league,
        PlatformLeague $platformLeague,
        array $scope = [],
        ?string $reason = null
    ): LeaguePlatformLeague {
        $scope = $this->normalizeScope($scope);
        $existingLeague = $this->activeLeagueForPlatformScope($platformLeague, $scope);

        if ($existingLeague && (int) $existingLeague->id !== (int) $league->id) {
            throw new DomainException('Selected provider scope is already linked to another league.');
        }

        return DB::transaction(function () use ($league, $platformLeague, $scope, $reason): LeaguePlatformLeague {
            LeaguePlatformLeague::query()
                ->where('league_id', $league->id)
                ->active()
                ->update([
                    'status' => LeaguePlatformLeague::STATUS_UNLINKED,
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            return LeaguePlatformLeague::query()->create([
                'league_id' => $league->id,
                'platform_league_id' => $platformLeague->id,
                'linked_at' => now(),
                'status' => LeaguePlatformLeague::STATUS_ACTIVE,
                'meta' => array_filter($this->scopeMetaArray($scope) + [
                    'migration_reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]);
        });
    }

    /**
     * Normalize provider scope input.
     *
     * @param array{type?:string|null,key?:string|null,label?:string|null} $scope
     * @return array{type:string|null,key:string|null,label:string|null}
     */
    public function normalizeScope(array $scope): array
    {
        $type = trim((string) ($scope['type'] ?? ''));
        $label = trim((string) ($scope['label'] ?? ''));
        $key = trim((string) ($scope['key'] ?? ''));

        if ($type === '' || $key === '') {
            return ['type' => null, 'key' => null, 'label' => null];
        }

        return [
            'type' => $type,
            'key' => $this->scopeKey($key),
            'label' => $label !== '' ? $label : $key,
        ];
    }

    /**
     * Return the active league wrapper for a platform/scope pair.
     *
     * @param array{type:string|null,key:string|null,label:string|null} $scope
     */
    public function activeLeagueForPlatformScope(PlatformLeague $platformLeague, array $scope): ?League
    {
        return $platformLeague->activeLeagueForScope($scope['type'], $scope['key']);
    }

    private function scopeKey(string $value): string
    {
        return Str::of($value)->trim()->lower()->slug('-')->toString();
    }

    /**
     * @param array{type:string|null,key:string|null,label:string|null} $scope
     */
    private function leagueName(PlatformLeague $platformLeague, array $scope, ?string $fallback): string
    {
        if ($scope['label']) {
            return $platformLeague->name . ' - ' . $scope['label'];
        }

        return $fallback ?: $platformLeague->name;
    }

    /**
     * @param array{type:string|null,key:string|null,label:string|null} $scope
     */
    private function scopeMeta(array $scope): ?string
    {
        $meta = $this->scopeMetaArray($scope);

        return $meta === [] ? null : json_encode($meta);
    }

    /**
     * @param array{type:string|null,key:string|null,label:string|null} $scope
     * @return array<string,string>
     */
    private function scopeMetaArray(array $scope): array
    {
        return array_filter([
            'scope_type' => $scope['type'],
            'scope_key' => $scope['key'],
            'scope_label' => $scope['label'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
