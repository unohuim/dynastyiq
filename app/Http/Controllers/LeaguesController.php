<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\LeagueUserRole;
use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Services\LeagueProviderBindingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaguesController extends Controller
{
    /**
     * Create, update, or attach a league to a community.
     */
    public function store(
        Request $request,
        Organization $organization,
        LeagueProviderBindingService $bindings,
        ?League $league = null
    ): JsonResponse {
        $data = $request->validate([
            'name' => [$league ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'sport' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'required_with:platform_league_id', Rule::in(['fantrax', 'yahoo', 'espn'])],
            'platform_league_id' => ['nullable', 'required_with:platform', 'string', 'max:255'],
            'provider_scope_type' => ['nullable', 'string', 'max:50'],
            'provider_scope_key' => ['nullable', 'string', 'max:255'],
            'provider_scope_label' => ['nullable', 'string', 'max:255'],
            'provider_scope_mode' => ['nullable', Rule::in(['single', 'all'])],
            'discord_server_id' => [
                'nullable',
                Rule::exists('discord_servers', 'id')->where('organization_id', $organization->id),
            ],
        ]);

        $createdLeague = false;

        if (! empty($data['platform']) && ! empty($data['platform_league_id'])) {
            $platformLeague = PlatformLeague::firstOrCreate(
                [
                    'platform'           => $data['platform'],
                    'platform_league_id' => (string) $data['platform_league_id'],
                ],
                [
                    'name'      => $data['name'] ?? $league->name,
                    'sport'     => $data['sport'] ?? 'hockey',
                    'synced_at' => now(),
                ]
            );

            if (($data['provider_scope_mode'] ?? 'single') === 'all') {
                if ($league) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'All scopes can only be added when creating community leagues.',
                    ], 422);
                }

                try {
                    $attached = DB::transaction(function () use ($bindings, $platformLeague, $organization, $data, $request): array {
                        $leagues = $bindings->attachAllScopes(
                            $platformLeague,
                            $data['sport'] ?? null
                        );

                        return collect($leagues)
                            ->map(fn (League $scopedLeague): League => $this->attachLeagueToOrganization(
                                $scopedLeague,
                                $organization,
                                $data,
                                $request
                            ))
                            ->all();
                    });
                } catch (DomainException $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => $e->getMessage(),
                    ], 422);
                }

                return response()->json([
                    'ok' => true,
                    'league_count' => count($attached),
                    'leagues' => collect($attached)->map(static fn (League $attachedLeague): array => [
                        'id' => $attachedLeague->id,
                        'name' => $attachedLeague->name,
                    ])->values()->all(),
                ]);
            }

            $createdLeague = $league === null;

            try {
                $league = $bindings->attach(
                    $league,
                    $platformLeague,
                    [
                        'type' => $data['provider_scope_type'] ?? null,
                        'key' => $data['provider_scope_key'] ?? null,
                        'label' => $data['provider_scope_label'] ?? null,
                    ],
                    $data['name'] ?? null,
                    $data['sport'] ?? null
                );
            } catch (DomainException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        } else {
            $createdLeague = $league === null;
            $league = $league ?? League::create([
                'name'  => $data['name'],
                'sport' => $data['sport'] ?? 'hockey',
            ]);

            if (array_key_exists('name', $data)) {
                $league->name = $data['name'];
                $league->save();
            }
        }

        try {
            $this->attachLeagueToOrganization($league, $organization, $data, $request, $createdLeague);
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
            ],
        ]);
    }

    /**
     * Attach or refresh a league/community association.
     *
     * @param array<string,mixed> $data
     */
    private function attachLeagueToOrganization(
        League $league,
        Organization $organization,
        array $data,
        Request $request,
        bool $createdLeague = false
    ): League {
        $attachedOrganizationId = DB::table('organization_leagues')
            ->where('league_id', $league->id)
            ->value('organization_id');

        if ($attachedOrganizationId && (int) $attachedOrganizationId !== (int) $organization->id) {
            throw new DomainException('Selected league is already linked to another community.');
        }

        $wasAttachedToOrganization = $organization->leagues()->whereKey($league->id)->exists();
        $pivot = ['linked_at' => now()];

        if (array_key_exists('discord_server_id', $data)) {
            $pivot['discord_server_id'] = $data['discord_server_id'];
        }

        $wasAttachedToOrganization
            ? $organization->leagues()->updateExistingPivot($league->id, $pivot)
            : $organization->leagues()->attach($league->id, $pivot);

        if (($createdLeague || ! $wasAttachedToOrganization) && $request->user()) {
            LeagueUserRole::query()->firstOrCreate([
                'league_id' => $league->id,
                'user_id' => $request->user()->id,
                'role' => 'commissioner',
            ]);
        }

        return $league;
    }

    /**
     * Move an existing community league wrapper to a new active provider binding.
     */
    public function migrateProviderBinding(
        Request $request,
        Organization $organization,
        League $league,
        LeagueProviderBindingService $bindings
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user && $user->organizations()->whereKey($organization->id)->exists(),
            403
        );

        abort_unless(
            $organization->leagues()->whereKey($league->id)->exists(),
            404
        );

        $data = $request->validate([
            'platform' => ['required', Rule::in(['fantrax', 'yahoo', 'espn'])],
            'platform_league_id' => ['required', 'string', 'max:255'],
            'provider_scope_type' => ['nullable', 'string', 'max:50'],
            'provider_scope_key' => ['nullable', 'string', 'max:255'],
            'provider_scope_label' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $platformLeague = PlatformLeague::query()
            ->providerPair($data['platform'], (string) $data['platform_league_id'])
            ->firstOrFail();

        try {
            $binding = $bindings->migrate(
                $league,
                $platformLeague,
                [
                    'type' => $data['provider_scope_type'] ?? null,
                    'key' => $data['provider_scope_key'] ?? null,
                    'label' => $data['provider_scope_label'] ?? null,
                ],
                $data['reason'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'binding' => [
                'id' => $binding->id,
                'league_id' => $binding->league_id,
                'platform_league_id' => $binding->platform_league_id,
                'status' => $binding->status,
                'meta' => $binding->meta,
            ],
        ]);
    }

    /**
     * Disassociate a league from a community without deleting the league record.
     */
    public function destroy(Request $request, Organization $organization, League $league): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->organizations()->whereKey($organization->id)->exists(),
            403
        );

        abort_unless(
            $organization->leagues()->whereKey($league->id)->exists(),
            404
        );

        DB::transaction(function () use ($organization, $league): void {
            $organization->leagues()->detach($league->id);

            if (! $organization->owner_user_id) {
                return;
            }

            LeagueUserRole::query()
                ->where('league_id', $league->id)
                ->where('user_id', $organization->owner_user_id)
                ->whereIn('role', ['commissioner', 'co_commissioner'])
                ->delete();
        });

        return response()->json([
            'ok' => true,
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
            ],
        ]);
    }
}
