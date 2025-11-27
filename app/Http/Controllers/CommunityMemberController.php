<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CommunityMemberRequest;
use App\Http\Resources\MembershipResource;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CommunityMemberController extends Controller
{
    public function index(Organization $organization)
    {
        $this->assertUserCanManage($organization);

        $members = $organization->memberships()
            ->with(['memberProfile', 'membershipTier', 'providerAccount'])
            ->latest()
            ->paginate(request('per_page', 10));

        return MembershipResource::collection($members);
    }

    public function store(CommunityMemberRequest $request, Organization $organization): MembershipResource
    {
        $this->assertUserCanManage($organization);

        $validated = $request->validated();
        $tierId = $validated['membership_tier_id'] ?? null;

        if ($tierId && !$organization->membershipTiers()->whereKey($tierId)->exists()) {
            abort(422, 'Tier does not belong to this community.');
        }

        $profile = MemberProfile::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => $validated['email'],
            ],
            [
                'display_name' => $validated['name'],
                'metadata' => [],
            ]
        );

        $profile->fill([
            'display_name' => $validated['name'],
            'email' => $validated['email'],
        ])->save();

        $membership = Membership::create([
            'organization_id' => $organization->id,
            'member_profile_id' => $profile->id,
            'membership_tier_id' => $tierId,
            'status' => $validated['status'] ?? 'active',
            'provider' => null,
            'metadata' => [],
        ]);

        return new MembershipResource(
            $membership->fresh(['memberProfile', 'membershipTier', 'providerAccount'])
        );
    }

    public function update(
        CommunityMemberRequest $request,
        Organization $organization,
        Membership $membership
    ): MembershipResource {
        $this->assertUserCanManage($organization);

        abort_if($membership->organization_id !== $organization->id, 404);

        if ($membership->provider_account_id) {
            abort(422, 'Provider-managed members cannot be edited manually.');
        }

        $validated = $request->validated();
        $tierId = $validated['membership_tier_id'] ?? null;

        if ($tierId && !$organization->membershipTiers()->whereKey($tierId)->exists()) {
            abort(422, 'Tier does not belong to this community.');
        }

        $profile = $membership->memberProfile;
        $profile->fill([
            'display_name' => $validated['name'],
            'email' => $validated['email'],
        ])->save();

        $membership->fill([
            'membership_tier_id' => $tierId,
            'status' => $validated['status'] ?? $membership->status,
        ])->save();

        return new MembershipResource(
            $membership->fresh(['memberProfile', 'membershipTier', 'providerAccount'])
        );
    }

    public function destroy(Organization $organization, Membership $membership): JsonResponse
    {
        $this->assertUserCanManage($organization);
        abort_if($membership->organization_id !== $organization->id, 404);

        if ($membership->provider_account_id) {
            return response()->json([
                'message' => 'Provider-managed members cannot be deleted manually.',
                'errors' => [
                    'provider' => ['Provider-managed members cannot be deleted manually.'],
                ],
            ], 422);
        }

        $membership->delete();

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
