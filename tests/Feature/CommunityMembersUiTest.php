<?php

use App\Models\Organization;
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
    $response->assertSee('x-data="communityMembersHub({', false);
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

it('registers the community members store inside the app bundle', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toMatch("/import ['\\\"]\\.\\/components\\/community-members-store['\\\"]/");
});
