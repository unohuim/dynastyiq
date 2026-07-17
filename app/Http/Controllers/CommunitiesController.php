<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\MembershipResource;
use App\Http\Resources\MembershipTierResource;
use App\Models\Draft;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Services\LeagueProviderBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CommunitiesController extends Controller
{
    /**
     * Display a listing of the user's enabled communities,
     * plus Fantrax connection state and selectable Fantrax leagues.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $bindingService = app(LeagueProviderBindingService::class);

        $hasMembershipsTable = Schema::hasTable('memberships');

        $communities = $user->organizations()
            ->whereNotNull('organizations.settings')   // enabled = settings not null
            ->whereNull('organizations.deleted_at')    // exclude soft-deleted orgs
            ->with([
                'leagues',
                'discordServers',
                'providerAccounts',
            ])
            ->when($hasMembershipsTable, function ($query) {
                $query->with([
                    'memberships' => function ($membershipQuery) {
                        $membershipQuery
                            ->where('provider', 'patreon')
                            ->with(['memberProfile', 'membershipTier'])
                            ->latest('synced_at')
                            ->latest();
                    },
                ]);
            })
            ->orderBy('organizations.name')
            ->get();

        $activeOrganizationId = $request->integer('active') ?: session()->pull('active_organization_id');
        $activeCommunity = $communities
            ->firstWhere('id', $activeOrganizationId)
            ?? $communities->first();

        $currentOrg = $activeCommunity;

        // Fantrax: connected?
        $fantraxConnected = $user->fantraxSecret()->exists();

        // Build Fantrax options from user's ACTIVE teams in Fantrax leagues,
        // excluding any league already linked to ANY community.
        $fantraxOptions = collect();
        if ($fantraxConnected) {

            $fantraxOptions = PlatformLeague::query()
                    ->select('platform_leagues.*')
                    ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'platform_leagues.id')
                    ->where('lut.user_id', $user->id)
                    ->where('lut.is_active', true)
                    ->where('platform_leagues.platform', 'fantrax')
                    ->orderBy('platform_leagues.name')
                    ->get()
                    ->unique('platform_league_id')
                    ->map(fn (PlatformLeague $l) => [
                        'name'               => $l->name,
                        'platform_league_id' => $l->platform_league_id,
                        'sport'              => $l->sport,
                        'scope_options'      => $bindingService->scopeOptions($l),
                    ])
                    ->values();
        }

        $initialMembersQuery = $currentOrg
            ? $currentOrg->memberships()
                ->with(['memberProfile', 'membershipTier', 'providerAccount'])
                ->latest()
            : Membership::query()->latest();

        $initialMembers = MembershipResource::collection(
            $initialMembersQuery->paginate(10)
        )->response()->getData(true);

        $membershipProviderCounts = [
            'discord' => 0,
            'patreon' => 0,
            'other' => 0,
        ];

        if ($currentOrg) {
            $membershipProviderCounts = [
                'discord' => (int) $currentOrg->memberships()
                    ->where('provider', 'discord')
                    ->count(),
                'patreon' => (int) $currentOrg->memberships()
                    ->where('provider', 'patreon')
                    ->count(),
                'other' => (int) $currentOrg->memberships()
                    ->where(function ($query): void {
                        $query->whereNull('provider')
                            ->orWhereNotIn('provider', ['discord', 'patreon']);
                    })
                    ->count(),
            ];
        }

        $initialTiers = MembershipTierResource::collection(
            $currentOrg
                ? $currentOrg->membershipTiers()->orderBy('name')->get()
                : MembershipTier::query()->get()
        )->resolve();
        $communityDraftingRows = $this->communityDraftingRows($currentOrg);

        return view('communities.index', [
            'communities'       => $communities,
            'activeCommunity'   => $activeCommunity,
            'fantraxConnected'  => $fantraxConnected,
            'fantraxOptions'    => $fantraxOptions,
            'initialMembers'    => $initialMembers,
            'initialTiers'      => $initialTiers,
            'membershipProviderCounts' => $membershipProviderCounts,
            'communityDraftingRows' => $communityDraftingRows,
        ]);
    }

    /**
     * Build local draft status rows for the community home page.
     *
     * @return array<int,array<string,string>>
     */
    private function communityDraftingRows(?Organization $organization): array
    {
        if (! $organization instanceof Organization) {
            return [];
        }

        return $organization->leagues()
            ->with(['platformLeagues.drafts' => static function ($query): void {
                $query->latest('updated_at');
            }])
            ->orderBy('leagues.name')
            ->get()
            ->map(function ($league): array {
                $platformLeague = $league->platformLeagues
                    ->first(static fn (PlatformLeague $platformLeague): bool => (string) ($platformLeague->pivot?->status ?? '') === 'active')
                    ?? $league->platformLeagues->first();
                $draft = $this->currentDraftForPlatformLeague($platformLeague);
                $status = $this->draftStatusDisplay($draft);
                $scopeLabel = (string) data_get($this->pivotMeta($platformLeague), 'scope_label', '');
                $platform = $platformLeague instanceof PlatformLeague
                    ? ucfirst((string) $platformLeague->platform)
                    : 'League';

                return [
                    'name' => (string) $league->name,
                    'context' => $scopeLabel !== '' ? $platform . ' / ' . $scopeLabel : $platform,
                    'status' => $status['label'],
                    'detail' => $status['detail'],
                    'tone' => $status['tone'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Return decoded provider binding metadata for a platform league relation.
     *
     * @return array<string,mixed>
     */
    private function pivotMeta(?PlatformLeague $platformLeague): array
    {
        $meta = $platformLeague?->pivot?->meta ?? [];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Return the most relevant local draft row for a platform league.
     */
    private function currentDraftForPlatformLeague(?PlatformLeague $platformLeague): ?Draft
    {
        if (! $platformLeague instanceof PlatformLeague) {
            return null;
        }

        return $platformLeague->drafts
            ->sortByDesc(static fn (Draft $draft): int => $draft->updated_at?->getTimestamp() ?? 0)
            ->firstWhere('source_type', 'platform_mirror')
            ?? $platformLeague->drafts
                ->sortByDesc(static fn (Draft $draft): int => $draft->updated_at?->getTimestamp() ?? 0)
                ->first();
    }

    /**
     * Format local draft status for the community homepage.
     *
     * @return array{label:string,detail:string,tone:string}
     */
    private function draftStatusDisplay(?Draft $draft): array
    {
        if (! $draft instanceof Draft) {
            return [
                'label' => 'No draft',
                'detail' => '',
                'tone' => 'slate',
            ];
        }

        $status = strtolower((string) $draft->status);

        if (in_array($status, ['live', 'running'], true)) {
            return [
                'label' => 'Live',
                'detail' => '',
                'tone' => 'green',
            ];
        }

        if (in_array($status, ['complete', 'completed'], true)) {
            return [
                'label' => 'Complete',
                'detail' => '',
                'tone' => 'slate',
            ];
        }

        if ($status === 'scheduled') {
            return [
                'label' => 'Scheduled',
                'detail' => $draft->starts_at?->format('M j') ?? '',
                'tone' => 'blue',
            ];
        }

        return [
            'label' => $status !== '' ? ucfirst($status) : 'Unknown',
            'detail' => '',
            'tone' => 'slate',
        ];
    }
}
