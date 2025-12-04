<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->create();
});

it('denies non-admin users from starting discord server linking', function () {
    $organization = Organization::create([
        'name'  => 'Test Org',
        'slug'  => 'test-org',
        'settings' => [],
    ]);

    $role = Role::create([
        'name'      => 'Manager',
        'slug'      => 'manager',
        'level'     => 2,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->get(route('discord-server.redirect', $organization));

    $response->assertForbidden();
});

it('starts discord server linking with encrypted organization context for admins', function () {
    $organization = Organization::create([
        'name'  => 'Admin Org',
        'slug'  => 'admin-org',
        'settings' => [],
    ]);

    $role = Role::create([
        'name'      => 'Admin',
        'slug'      => 'admin',
        'level'     => 10,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['organization_id' => $organization->id]);

    $capturedState = null;

    $driver = Mockery::mock(stdClass::class);
    $driver->shouldReceive('scopes')->with(['identify', 'guilds'])->andReturnSelf();
    $driver->shouldReceive('with')->with(Mockery::on(function (array $options) use (&$capturedState) {
        $capturedState = $options['state'] ?? null;

        return ($options['prompt'] ?? '') === 'consent' && is_string($capturedState);
    }))->andReturnSelf();
    $driver->shouldReceive('redirectUrl')->with(route('discord-server.callback'))->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturnUsing(function () use (&$capturedState) {
        $target = 'https://discord.test/oauth?state='.urlencode((string) $capturedState);

        return new RedirectResponse($target);
    });

    Socialite::shouldReceive('driver')->with('discord')->andReturn($driver);

    $response = $this->actingAs($user)->get(route('discord-server.redirect', $organization));

    $response->assertRedirect();

    $targetUrl = $response->headers->get('Location');
    $query = parse_url((string) $targetUrl, PHP_URL_QUERY);
    parse_str((string) $query, $params);

    expect($capturedState)->not->toBeNull();
    expect((int) decrypt((string) ($params['state'] ?? ''))['org_id'])->toBe($organization->id);
});

it('requires authentication to attach discord servers', function () {
    $response = $this->post(route('discord-server.attach'), [
        'guild_ids' => ['123'],
    ]);

    $response->assertRedirect(route('login'));
});

it('connects allowed guilds and clears session after attach', function () {
    $organization = Organization::create([
        'name'  => 'Attach Org',
        'slug'  => 'attach-org',
        'settings' => [],
    ]);

    $role = Role::create([
        'name'      => 'Admin',
        'slug'      => 'admin-attach',
        'level'     => 10,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['organization_id' => $organization->id]);

    $response = $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id'  => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds'  => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
                ['id' => '456', 'name' => 'Other Guild', 'permissions' => '0', 'icon' => 'icon-2'],
            ],
        ])
        ->post(route('discord-server.attach'), [
            'guild_ids' => ['123'],
        ]);

    $response->assertRedirect(route('communities.index'));
    $response->assertSessionHas('success', '1 server(s) connected.');
    $response->assertSessionMissing('discord.connect.org_id');
    $response->assertSessionMissing('discord.connect.user_id');
    $response->assertSessionMissing('discord.connect.guilds');

    $this->assertDatabaseHas('discord_servers', [
        'organization_id' => $organization->id,
        'discord_guild_id' => '123',
        'discord_guild_name' => 'Allowed Guild',
    ]);
    $this->assertDatabaseMissing('discord_servers', [
        'organization_id' => $organization->id,
        'discord_guild_id' => '456',
    ]);
});
