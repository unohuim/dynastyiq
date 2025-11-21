<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

it('creates an organization for users without one and returns success', function () {
    $user = User::factory()->create();

    expect($user->organizations()->count())->toBe(0);

    $response = $this->actingAs($user)->putJson(
        route('organizations.settings.update'),
        ['enabled' => true]
    );

    $response->assertOk()->assertJson([
        'ok' => true,
        'settings' => [
            'commissioner_tools' => false,
            'creator_tools' => false,
        ],
    ]);

    $organizationId = $response->json('organization.id');

    expect($organizationId)->not()->toBeNull();
    expect(\App\Models\Organization::whereKey($organizationId)->exists())->toBeTrue();
    expect($user->fresh()->organizations()->whereKey($organizationId)->exists())->toBeTrue();
});

it('rejects settings updates for unlinked organizations', function () {
    $user = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Unaffiliated Org',
        'short_name' => 'uo',
        'slug' => Str::slug('uo-' . Str::random(8)),
    ]);

    $response = $this->actingAs($user)->putJson(
        route('organizations.settings.update', ['organization' => $organization->id]),
        ['enabled' => true]
    );

    $response->assertForbidden();
});

it('clears settings when disabled', function () {
    $user = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Enabled Org',
        'short_name' => 'enabled-org',
        'slug' => Str::slug('enabled-org-' . Str::random(8)),
        'settings' => [
            'commissioner_tools' => true,
            'creator_tools' => true,
        ],
    ]);
    $user->organizations()->attach($organization->id);

    $response = $this->actingAs($user)->putJson(
        route('organizations.settings.update', ['organization' => $organization->id]),
        ['enabled' => false]
    );

    $response->assertOk()->assertJson(['settings' => null]);
    expect($organization->fresh()->settings)->toBeNull();
});

it('merges defaults with provided tool toggles when enabled', function () {
    $user = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Merging Org',
        'short_name' => 'merging-org',
        'slug' => Str::slug('merging-org-' . Str::random(8)),
        'settings' => [
            'creator_tools' => true,
        ],
    ]);
    $user->organizations()->attach($organization->id);

    $response = $this->actingAs($user)->putJson(
        route('organizations.settings.update', ['organization' => $organization->id]),
        [
            'enabled' => true,
            'commissioner_tools' => true,
        ]
    );

    $response->assertOk()->assertJson([
        'settings' => [
            'commissioner_tools' => true,
            'creator_tools' => true,
        ],
    ]);

    expect($organization->fresh()->settings)->toMatchArray([
        'commissioner_tools' => true,
        'creator_tools' => true,
    ]);
});

it('updates organization name when provided', function () {
    $user = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Original Name',
        'short_name' => 'original-name',
        'slug' => Str::slug('original-name-' . Str::random(8)),
    ]);
    $user->organizations()->attach($organization->id);

    $response = $this->actingAs($user)->putJson(
        route('organizations.settings.update', ['organization' => $organization->id]),
        [
            'enabled' => true,
            'name' => 'Updated Name',
        ]
    );

    $response->assertOk()->assertJsonPath('organization.name', 'Updated Name');
    expect($organization->fresh()->name)->toBe('Updated Name');
});
