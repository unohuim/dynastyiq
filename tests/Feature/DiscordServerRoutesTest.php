<?php

use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\Role;
use App\Models\SocialAccount;
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

it('returns external fantrax league ids for discord user team links', function () {
    $viewer = User::factory()->create();
    $target = User::factory()->create();

    SocialAccount::create([
        'provider' => 'discord',
        'provider_user_id' => 'viewer-discord-id',
        'user_id' => $viewer->id,
    ]);
    SocialAccount::create([
        'provider' => 'discord',
        'provider_user_id' => 'target-discord-id',
        'user_id' => $target->id,
    ]);

    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => '2k8tsy4imo2wkl7j',
        'name' => 'Shared Fantrax League',
        'sport' => 'hockey',
    ]);
    $viewerTeam = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'viewer-team',
        'name' => 'Viewer Team',
    ]);
    $targetTeam = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'target-team',
        'name' => 'Target Team',
    ]);

    $viewer->platformLeagues()->attach($league->id, [
        'team_id' => $viewerTeam->id,
        'is_active' => true,
        'is_visible' => true,
        'extras' => json_encode(['provider' => 'fantrax']),
        'synced_at' => now(),
    ]);
    $target->platformLeagues()->attach($league->id, [
        'team_id' => $targetTeam->id,
        'is_active' => true,
        'is_visible' => true,
        'extras' => json_encode(['provider' => 'fantrax']),
        'synced_at' => now(),
    ]);

    $this->getJson('/api/discord/users/target-discord-id?viewer_discord_id=viewer-discord-id')
        ->assertOk()
        ->assertJsonPath('shared_count', 1)
        ->assertJsonPath('teams.0.platform_league_id', $league->id)
        ->assertJsonPath('teams.0.league_id', '2k8tsy4imo2wkl7j')
        ->assertJsonPath('teams.0.team_id', 'target-team')
        ->assertJsonPath('teams.0.team_name', 'Target Team');
});
