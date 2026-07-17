<?php

use App\Models\Organization;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Str;

function createCommunityUserPair(): array
{
    $user = User::factory()->create();

    $organization = Organization::create([
        'name' => 'Test Community',
        'short_name' => 'Test',
        'slug' => 'test-community-' . Str::random(5),
        'settings' => ['creator_tools' => true],
        'owner_user_id' => $user->id,
    ]);

    $user->organizations()->attach($organization->id);

    return [$user, $organization];
}

it('uses the same activeTab key for tab buttons and panels', function () {
    [$user] = createCommunityUserPair();

    $response = $this->actingAs($user)->get(route('communities.index'));

    $response->assertOk();
    $response->assertSee('x-data="communityMembersHub', false);
    $response->assertSee("@click=\"activeTab = 'members'\"", false);
    $response->assertSee("x-show=\"activeTab === 'members'\"", false);
});

it('hydrates Alpine after swapping the desktop template', function () {
    [$user] = createCommunityUserPair();

    $response = $this->actingAs($user)->get(route('communities.index'));

    $response->assertOk();
    $response->assertSee('function hydrateAlpine()', false);
    $response->assertSee('window.Alpine.initTree(root);', false);
    $response->assertSee('root.appendChild(frag);', false);
});

it('shows full provider membership counts on the community home panel', function () {
    [$user, $organization] = createCommunityUserPair();
    $createMembership = function (?string $provider, int $index) use ($organization): void {
        $profile = MemberProfile::create([
            'organization_id' => $organization->id,
            'email' => sprintf('member-%s-%d@example.test', $provider ?: 'other', $index),
            'display_name' => sprintf('Member %s %d', $provider ?: 'other', $index),
            'metadata' => [],
        ]);

        Membership::create([
            'organization_id' => $organization->id,
            'member_profile_id' => $profile->id,
            'provider' => $provider,
            'provider_member_id' => $provider ? sprintf('%s-%d', $provider, $index) : null,
            'status' => 'active',
            'metadata' => [],
        ]);
    };

    foreach (range(1, 12) as $index) {
        $createMembership('discord', $index);
    }

    foreach (range(1, 2) as $index) {
        $createMembership('patreon', $index);
    }

    $createMembership(null, 1);
    $createMembership('manual', 1);

    $response = $this->actingAs($user)->get(route('communities.index'));

    $response->assertOk();
    $response->assertSeeInOrder([
        '<div class="mt-5 text-4xl font-semibold">16</div>',
        '<div class="text-2xl font-semibold">12</div>',
        'Discord',
        '<div class="text-2xl font-semibold">2</div>',
        'Patreon',
        '<div class="text-2xl font-semibold">2</div>',
        'Other',
    ], false);
});

it('registers the community members store inside the app bundle', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toMatch("/import ['\\\"]\\.\\/components\\/community-members-store['\\\"]/");
});
