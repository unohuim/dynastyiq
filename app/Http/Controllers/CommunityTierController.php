<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CommunityTierRequest;
use App\Http\Resources\MembershipTierResource;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CommunityTierController extends Controller
{
    public function index(Organization $organization)
    {
        $this->assertUserCanManage($organization);

        $tiers = $organization->membershipTiers()
            ->orderBy('name')
            ->get();

        return MembershipTierResource::collection($tiers);
    }

    public function store(CommunityTierRequest $request, Organization $organization): MembershipTierResource
    {
        $this->assertUserCanManage($organization);

        $data = $request->validated();

        $tier = $organization->membershipTiers()->create([
            'name' => $data['name'],
            'amount_cents' => $data['amount_cents'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'metadata' => [],
        ]);

        return new MembershipTierResource($tier);
    }

    public function update(
        CommunityTierRequest $request,
        Organization $organization,
        MembershipTier $membershipTier
    ): MembershipTierResource {
        $this->assertUserCanManage($organization);
        abort_if($membershipTier->organization_id !== $organization->id, 404);

        if ($membershipTier->provider_account_id) {
            abort(422, 'Provider-managed tiers cannot be edited manually.');
        }

        $data = $request->validated();

        $membershipTier->fill([
            'name' => $data['name'],
            'amount_cents' => $data['amount_cents'] ?? null,
            'currency' => $data['currency'] ?? $membershipTier->currency,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? $membershipTier->is_active,
        ])->save();

        return new MembershipTierResource($membershipTier->fresh());
    }

    public function destroy(
        Organization $organization,
        MembershipTier $membershipTier
    ): JsonResponse {
        $this->assertUserCanManage($organization);
        abort_if($membershipTier->organization_id !== $organization->id, 404);

        if ($membershipTier->provider_account_id) {
            return response()->json([
                'message' => 'Provider-managed tiers cannot be deleted manually.',
                'errors' => [
                    'provider' => ['Provider-managed tiers cannot be deleted manually.'],
                ],
            ], 422);
        }

        Membership::where('membership_tier_id', $membershipTier->id)
            ->update(['membership_tier_id' => null]);

        $membershipTier->delete();

        return response()->json(['ok' => true]);
    }

    protected function assertUserCanManage(Organization $organization): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $hasOrg = $user->organizations()->where('organizations.id', $organization->id)->exists();
        if (!$hasOrg) {
            abort(403);
        }
    }
}
