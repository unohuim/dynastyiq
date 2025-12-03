<?php

use App\Models\MembershipTier;

test('prevents creating a second free tier for an organization', function () {
    [$user, $organization] = createCommunityUserPair();

    MembershipTier::create([
        'organization_id' => $organization->id,
        'name' => 'Free Tier',
        'amount_cents' => 0,
        'currency' => 'USD',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('communities.tiers.store', $organization), [
            'name' => 'Another Free Tier',
            'amount_cents' => 0,
            'currency' => 'USD',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['amount_cents']);
});

test('allows paid tiers even when a free tier exists', function () {
    [$user, $organization] = createCommunityUserPair();

    MembershipTier::create([
        'organization_id' => $organization->id,
        'name' => 'Free Tier',
        'amount_cents' => 0,
        'currency' => 'USD',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('communities.tiers.store', $organization), [
            'name' => 'Paid Tier',
            'amount_cents' => 500,
            'currency' => 'USD',
        ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('membership_tiers', [
        'organization_id' => $organization->id,
        'name' => 'Paid Tier',
        'amount_cents' => 500,
        'currency' => 'USD',
    ]);
});
