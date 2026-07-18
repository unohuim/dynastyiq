<?php

declare(strict_types=1);

use App\Models\DiscordServer;
use App\Models\League;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\ProviderAccount;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DiscordCommunityMemberSyncService;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->create();

    $this->createOrganization = function (string $slug = 'test-org'): Organization {
        return Organization::create([
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'settings' => [],
        ]);
    };

    $this->createRole = function (string $slug, int $level): Role {
        return Role::create([
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'level' => $level,
            'is_active' => true,
        ]);
    };

    $this->attachOrganizationRole = function (User $user, Organization $organization, int $level): Role {
        $role = ($this->createRole)('role-'.$organization->id.'-'.$level.'-'.$user->id, $level);
        $user->roles()->attach($role->id, ['organization_id' => $organization->id]);

        return $role;
    };

    $this->createDiscordServer = function (Organization $organization, string $guildId = 'guild-1'): DiscordServer {
        return DiscordServer::create([
            'organization_id' => $organization->id,
            'discord_guild_id' => $guildId,
            'discord_guild_name' => 'Guild '.$guildId,
        ]);
    };

    $this->attachLeagueToDiscord = function (Organization $organization, DiscordServer $discordServer): League {
        $league = League::create([
            'name' => 'League '.$discordServer->discord_guild_id,
            'sport' => 'hockey',
        ]);

        $organization->leagues()->attach($league->id, [
            'discord_server_id' => $discordServer->id,
            'linked_at' => now(),
        ]);

        return $league;
    };

    $this->createDiscordMembership = function (
        Organization $organization,
        DiscordServer $discordServer,
        string $discordUserId
    ): Membership {
        $profile = MemberProfile::create([
            'organization_id' => $organization->id,
            'display_name' => 'Discord Member '.$discordUserId,
            'external_ids' => ['discord' => $discordUserId],
        ]);

        return Membership::create([
            'organization_id' => $organization->id,
            'member_profile_id' => $profile->id,
            'provider' => 'discord',
            'provider_member_id' => $discordUserId,
            'status' => 'active',
            'metadata' => [
                'discord_server_id' => $discordServer->id,
                'discord_guild_id' => $discordServer->discord_guild_id,
            ],
        ]);
    };
});

it('requires authentication to start discord server linking', function () {
    $organization = ($this->createOrganization)('guest-link-org');

    $response = $this->get(route('discord-server.redirect', $organization));

    $response->assertRedirect(route('login'));
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

it('rejects empty guild selections when attaching discord servers', function () {
    $organization = ($this->createOrganization)('empty-selection-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id' => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds' => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
            ],
        ])
        ->from(route('communities.index'))
        ->post(route('discord-server.attach'), [
            'guild_ids' => [],
        ]);

    $response->assertRedirect(route('communities.index', [
        'active' => $organization->id,
        'tab' => 'connections',
    ]));
    $response->assertSessionHasErrors('guild_ids');
});

it('rejects unauthorized guild selections when attaching discord servers', function () {
    $organization = ($this->createOrganization)('invalid-selection-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id' => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds' => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
            ],
        ])
        ->post(route('discord-server.attach'), [
            'guild_ids' => ['999'],
        ]);

    $response->assertRedirect(route('communities.index', [
        'active' => $organization->id,
        'tab' => 'connections',
    ]));
    $response->assertSessionHas('error', 'Invalid selection.');
    $this->assertDatabaseMissing('discord_servers', [
        'organization_id' => $organization->id,
        'discord_guild_id' => '999',
    ]);
});

it('denies non-admin users from attaching discord servers', function () {
    $organization = ($this->createOrganization)('manager-attach-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id' => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds' => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
            ],
        ])
        ->post(route('discord-server.attach'), [
            'guild_ids' => ['123'],
        ]);

    $response->assertRedirect(route('communities.index'));
    $response->assertSessionHas('error', 'Not authorized.');
    $this->assertDatabaseMissing('discord_servers', [
        'organization_id' => $organization->id,
        'discord_guild_id' => '123',
    ]);
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

it('does not import discord members when attaching allowed guilds', function () {
    $organization = ($this->createOrganization)('no-import-attach-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $syncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $syncService->shouldNotReceive('sync');
    $this->app->instance(DiscordCommunityMemberSyncService::class, $syncService);

    $response = $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id' => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds' => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
            ],
        ])
        ->post(route('discord-server.attach'), [
            'guild_ids' => ['123'],
        ]);

    $response->assertRedirect(route('communities.index'));
    $response->assertSessionHas('success', '1 server(s) connected.');
    $response->assertSessionMissing('error');

    $this->assertDatabaseHas('discord_servers', [
        'organization_id' => $organization->id,
        'discord_guild_id' => '123',
    ]);
    $this->assertDatabaseMissing('memberships', [
        'organization_id' => $organization->id,
        'provider' => 'discord',
    ]);
});

it('stores discord guild icon metadata when attaching allowed guilds', function () {
    $organization = ($this->createOrganization)('icon-attach-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $this->actingAs($user)
        ->withSession([
            'discord.connect.org_id' => $organization->id,
            'discord.connect.user_id' => 'discord-user-1',
            'discord.connect.guilds' => [
                ['id' => '123', 'name' => 'Allowed Guild', 'permissions' => '8', 'icon' => 'icon-1'],
            ],
        ])
        ->post(route('discord-server.attach'), [
            'guild_ids' => ['123'],
        ]);

    $server = DiscordServer::query()->where('discord_guild_id', '123')->firstOrFail();

    expect($server->meta)->toBe(['icon' => 'icon-1']);
});

it('requires authentication to refresh discord community members', function () {
    $organization = ($this->createOrganization)('guest-refresh-org');
    $discordServer = ($this->createDiscordServer)($organization);

    $response = $this->post(route('organizations.discord-servers.members.refresh', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertRedirect(route('login'));
});

it('denies non-admin users from refreshing discord community members', function () {
    $organization = ($this->createOrganization)('manager-refresh-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertForbidden();
});

it('returns not found when refreshing a discord server from another community', function () {
    $organization = ($this->createOrganization)('refresh-scope-org');
    $otherOrganization = ($this->createOrganization)('refresh-other-org');
    $discordServer = ($this->createDiscordServer)($otherOrganization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertNotFound();
});

it('refreshes discord community members for admins', function () {
    $organization = ($this->createOrganization)('admin-refresh-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $syncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $syncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($discordServer)))
        ->andReturn(['synced_count' => 3]);
    $this->app->instance(DiscordCommunityMemberSyncService::class, $syncService);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('summary.synced_count', 3);
});

it('requires authentication to refresh all discord community members', function () {
    $organization = ($this->createOrganization)('guest-refresh-all-org');

    $response = $this->post(route('organizations.discord-servers.members.refresh-all', [
        'organization' => $organization,
    ]));

    $response->assertRedirect(route('login'));
});

it('denies non-admin users from refreshing all discord community members', function () {
    $organization = ($this->createOrganization)('manager-refresh-all-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh-all', [
        'organization' => $organization,
    ]));

    $response->assertForbidden();
});

it('refreshes all discord community servers for admins and returns an aggregate summary', function () {
    $organization = ($this->createOrganization)('admin-refresh-all-org');
    $firstServer = ($this->createDiscordServer)($organization, 'guild-1');
    $secondServer = ($this->createDiscordServer)($organization, 'guild-2');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $syncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $syncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($firstServer)))
        ->andReturn(['synced_count' => 2]);
    $syncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($secondServer)))
        ->andReturn(['synced_count' => 3]);
    $this->app->instance(DiscordCommunityMemberSyncService::class, $syncService);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh-all', [
        'organization' => $organization,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('summary.server_count', 2)
        ->assertJsonPath('summary.synced_count', 5)
        ->assertJsonPath('summary.servers.0.discord_server_id', $firstServer->id)
        ->assertJsonPath('summary.servers.1.discord_server_id', $secondServer->id);
});

it('returns an empty aggregate summary when refreshing all discord members without connected servers', function () {
    $organization = ($this->createOrganization)('empty-refresh-all-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $syncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $syncService->shouldNotReceive('sync');
    $this->app->instance(DiscordCommunityMemberSyncService::class, $syncService);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh-all', [
        'organization' => $organization,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('summary.server_count', 0)
        ->assertJsonPath('summary.synced_count', 0)
        ->assertJsonPath('summary.servers', []);
});

it('returns the sync error when refreshing all discord members fails for a server', function () {
    $organization = ($this->createOrganization)('error-refresh-all-org');
    ($this->createDiscordServer)($organization, 'guild-1');
    ($this->createDiscordServer)($organization, 'guild-2');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $syncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $syncService
        ->shouldReceive('sync')
        ->once()
        ->andReturn(['synced_count' => 2]);
    $syncService
        ->shouldReceive('sync')
        ->once()
        ->andThrow(new \RuntimeException('Discord API unavailable.'));
    $this->app->instance(DiscordCommunityMemberSyncService::class, $syncService);

    $response = $this->actingAs($user)->postJson(route('organizations.discord-servers.members.refresh-all', [
        'organization' => $organization,
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Discord API unavailable.')
        ->assertJsonPath('summary.server_count', 2)
        ->assertJsonPath('summary.synced_count', 2);
});

it('requires authentication to refresh community members from connected providers', function () {
    $organization = ($this->createOrganization)('guest-provider-refresh-org');

    $response = $this->post(route('organizations.members.refresh', [
        'organization' => $organization,
    ]));

    $response->assertRedirect(route('login'));
});

it('denies non-admin users from refreshing community members from connected providers', function () {
    $organization = ($this->createOrganization)('manager-provider-refresh-org');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)->postJson(route('organizations.members.refresh', [
        'organization' => $organization,
    ]));

    $response->assertForbidden();
});

it('refreshes discord and patreon community members for admins', function () {
    $organization = ($this->createOrganization)('admin-provider-refresh-org');
    $firstServer = ($this->createDiscordServer)($organization, 'guild-1');
    $secondServer = ($this->createDiscordServer)($organization, 'guild-2');
    $patreonAccount = ProviderAccount::create([
        'organization_id' => $organization->id,
        'provider' => 'patreon',
        'external_id' => 'campaign-1',
        'display_name' => 'Patreon Campaign',
        'status' => 'connected',
        'access_token' => 'token-1',
    ]);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $discordSyncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $discordSyncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($firstServer)))
        ->andReturn(['synced_count' => 2]);
    $discordSyncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($secondServer)))
        ->andReturn(['synced_count' => 3]);
    $this->app->instance(DiscordCommunityMemberSyncService::class, $discordSyncService);

    $patreonSyncService = Mockery::mock(PatreonSyncService::class);
    $patreonSyncService
        ->shouldReceive('syncProviderAccount')
        ->once()
        ->with(Mockery::on(fn (ProviderAccount $account): bool => $account->is($patreonAccount)))
        ->andReturn(['tiers_synced' => 2, 'members_synced' => 4]);
    $this->app->instance(PatreonSyncService::class, $patreonSyncService);

    $response = $this->actingAs($user)->postJson(route('organizations.members.refresh', [
        'organization' => $organization,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('summary.discord.server_count', 2)
        ->assertJsonPath('summary.discord.synced_count', 5)
        ->assertJsonPath('summary.discord.servers.0.discord_server_id', $firstServer->id)
        ->assertJsonPath('summary.discord.servers.1.discord_server_id', $secondServer->id)
        ->assertJsonPath('summary.patreon.connected', true)
        ->assertJsonPath('summary.patreon.account_id', $patreonAccount->id)
        ->assertJsonPath('summary.patreon.tiers_synced', 2)
        ->assertJsonPath('summary.patreon.members_synced', 4);
});

it('refreshes discord community members without requiring a patreon connection', function () {
    $organization = ($this->createOrganization)('discord-only-provider-refresh-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $discordSyncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $discordSyncService
        ->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn (DiscordServer $server): bool => $server->is($discordServer)))
        ->andReturn(['synced_count' => 6]);
    $this->app->instance(DiscordCommunityMemberSyncService::class, $discordSyncService);

    $patreonSyncService = Mockery::mock(PatreonSyncService::class);
    $patreonSyncService->shouldNotReceive('syncProviderAccount');
    $this->app->instance(PatreonSyncService::class, $patreonSyncService);

    $response = $this->actingAs($user)->postJson(route('organizations.members.refresh', [
        'organization' => $organization,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('summary.discord.server_count', 1)
        ->assertJsonPath('summary.discord.synced_count', 6)
        ->assertJsonPath('summary.patreon.connected', false)
        ->assertJsonPath('summary.patreon.account_id', null)
        ->assertJsonPath('summary.patreon.members_synced', 0);
});

it('returns the discord sync error before refreshing patreon community members', function () {
    $organization = ($this->createOrganization)('provider-refresh-error-org');
    ($this->createDiscordServer)($organization, 'guild-1');
    ProviderAccount::create([
        'organization_id' => $organization->id,
        'provider' => 'patreon',
        'external_id' => 'campaign-1',
        'display_name' => 'Patreon Campaign',
        'status' => 'connected',
        'access_token' => 'token-1',
    ]);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $discordSyncService = Mockery::mock(DiscordCommunityMemberSyncService::class);
    $discordSyncService
        ->shouldReceive('sync')
        ->once()
        ->andThrow(new \RuntimeException('Discord API unavailable.'));
    $this->app->instance(DiscordCommunityMemberSyncService::class, $discordSyncService);

    $patreonSyncService = Mockery::mock(PatreonSyncService::class);
    $patreonSyncService->shouldNotReceive('syncProviderAccount');
    $this->app->instance(PatreonSyncService::class, $patreonSyncService);

    $response = $this->actingAs($user)->postJson(route('organizations.members.refresh', [
        'organization' => $organization,
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Discord API unavailable.')
        ->assertJsonPath('summary.discord.server_count', 1)
        ->assertJsonPath('summary.patreon.connected', false);
});

it('redirects discord bot installs through the configured install url with callback state', function () {
    $organization = ($this->createOrganization)('bot-install-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    config([
        'services.discord.diq_install_url' => 'https://discord.com/oauth2/authorize?client_id=client-1&scope=bot',
    ]);

    $response = $this->actingAs($user)->get(route('organizations.discord-servers.bot.install', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    $query = parse_url($location, PHP_URL_QUERY);
    parse_str((string) $query, $params);

    expect($location)->toStartWith('https://discord.com/oauth2/authorize?')
        ->and($params['client_id'] ?? null)->toBe('client-1')
        ->and($params['scope'] ?? null)->toBe('bot')
        ->and($params['redirect_uri'] ?? null)->toBe(route('discord-server.bot-installed.callback'));

    $state = decrypt((string) ($params['state'] ?? ''));
    expect((int) $state['organization_id'])->toBe($organization->id)
        ->and((int) $state['discord_server_id'])->toBe($discordServer->id)
        ->and((int) $state['user_id'])->toBe($user->id);
});

it('denies non-admin users from starting discord bot installs', function () {
    $organization = ($this->createOrganization)('bot-install-denied-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)->get(route('organizations.discord-servers.bot.install', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertForbidden();
});

it('returns not found when starting a discord bot install for another community server', function () {
    $organization = ($this->createOrganization)('bot-install-scope-org');
    $otherOrganization = ($this->createOrganization)('bot-install-other-org');
    $discordServer = ($this->createDiscordServer)($otherOrganization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->get(route('organizations.discord-servers.bot.install', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertNotFound();
});

it('renders discord bot install callback data for the opener window', function () {
    $organization = ($this->createOrganization)('bot-installed-callback-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $state = encrypt([
        'organization_id' => $organization->id,
        'discord_server_id' => $discordServer->id,
    ]);

    $response = $this->get(route('discord-server.bot-installed.callback', [
        'state' => $state,
    ]));

    $response->assertOk()
        ->assertSee('data-discord-bot-installed-callback', false)
        ->assertSee('data-organization-id="'.$organization->id.'"', false)
        ->assertSee('data-discord-server-id="'.$discordServer->id.'"', false);
});

it('returns installed discord bot status when the bot can access the guild', function () {
    $organization = ($this->createOrganization)('bot-status-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    Http::fake([
        'https://discord.com/api/v10/guilds/guild-1' => Http::response(['id' => 'guild-1'], 200),
    ]);

    $response = $this->actingAs($user)->getJson(route('organizations.discord-servers.bot.status', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('installed', true)
        ->assertJsonPath('discord_server.id', $discordServer->id);
});

it('returns missing discord bot status when the bot cannot access the guild', function () {
    $organization = ($this->createOrganization)('bot-status-missing-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    Http::fake([
        'https://discord.com/api/v10/guilds/guild-1' => Http::response([], 404),
    ]);

    $response = $this->actingAs($user)->getJson(route('organizations.discord-servers.bot.status', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('installed', false);
});

it('requires authentication to remove discord servers from communities', function () {
    $organization = ($this->createOrganization)('guest-remove-org');
    $discordServer = ($this->createDiscordServer)($organization);

    $response = $this->delete(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertRedirect(route('login'));
});

it('denies non-admin users from removing discord servers from communities', function () {
    $organization = ($this->createOrganization)('manager-remove-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 2);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertForbidden();
    $this->assertDatabaseHas('discord_servers', [
        'id' => $discordServer->id,
    ]);
});

it('returns not found when removing a discord server from another community', function () {
    $organization = ($this->createOrganization)('remove-scope-org');
    $otherOrganization = ($this->createOrganization)('remove-other-org');
    $discordServer = ($this->createDiscordServer)($otherOrganization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertNotFound();
    $this->assertDatabaseHas('discord_servers', [
        'id' => $discordServer->id,
    ]);
});

it('removes discord servers and clears associated community league discord links', function () {
    $organization = ($this->createOrganization)('admin-remove-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $league = ($this->attachLeagueToDiscord)($organization, $discordServer);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('discord_server.id', $discordServer->id);

    $this->assertDatabaseMissing('discord_servers', [
        'id' => $discordServer->id,
    ]);
    $this->assertDatabaseHas('organization_leagues', [
        'organization_id' => $organization->id,
        'league_id' => $league->id,
        'discord_server_id' => null,
    ]);
});

it('keeps discord memberships when removing only the discord server from a community', function () {
    $organization = ($this->createOrganization)('keep-members-remove-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $membership = ($this->createDiscordMembership)($organization, $discordServer, 'discord-user-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]), [
        'remove_members' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('removed_members_count', 0);

    $this->assertDatabaseMissing('discord_servers', [
        'id' => $discordServer->id,
    ]);
    $this->assertDatabaseHas('memberships', [
        'id' => $membership->id,
    ]);
});

it('removes discord memberships from that discord server when requested', function () {
    $organization = ($this->createOrganization)('remove-members-org');
    $discordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $otherDiscordServer = ($this->createDiscordServer)($organization, 'guild-2');
    $removedMembership = ($this->createDiscordMembership)($organization, $discordServer, 'discord-user-1');
    $keptMembership = ($this->createDiscordMembership)($organization, $otherDiscordServer, 'discord-user-2');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]), [
        'remove_members' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('removed_members_count', 1);

    $this->assertDatabaseMissing('memberships', [
        'id' => $removedMembership->id,
    ]);
    $this->assertDatabaseHas('memberships', [
        'id' => $keptMembership->id,
    ]);
});

it('removes retained discord memberships after the same guild is reconnected with a new server row', function () {
    $organization = ($this->createOrganization)('reconnected-remove-members-org');
    $originalDiscordServer = ($this->createDiscordServer)($organization, 'guild-1');
    $retainedMembership = ($this->createDiscordMembership)($organization, $originalDiscordServer, 'discord-user-1');
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $originalDiscordServer,
    ]), [
        'remove_members' => false,
    ])->assertOk();

    $this->assertDatabaseHas('memberships', [
        'id' => $retainedMembership->id,
    ]);

    $reconnectedDiscordServer = ($this->createDiscordServer)($organization, 'guild-1');

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $reconnectedDiscordServer,
    ]), [
        'remove_members' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('removed_members_count', 1);

    $this->assertDatabaseMissing('memberships', [
        'id' => $retainedMembership->id,
    ]);
});

it('does not remove manual or patreon members when removing discord members for a server', function () {
    $organization = ($this->createOrganization)('keep-non-discord-members-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $discordMembership = ($this->createDiscordMembership)($organization, $discordServer, 'discord-user-1');
    $manualProfile = MemberProfile::create([
        'organization_id' => $organization->id,
        'display_name' => 'Manual Member',
    ]);
    $manualMembership = Membership::create([
        'organization_id' => $organization->id,
        'member_profile_id' => $manualProfile->id,
        'provider' => null,
        'status' => 'active',
    ]);
    $patreonProfile = MemberProfile::create([
        'organization_id' => $organization->id,
        'display_name' => 'Patreon Member',
    ]);
    $patreonMembership = Membership::create([
        'organization_id' => $organization->id,
        'member_profile_id' => $patreonProfile->id,
        'provider' => 'patreon',
        'provider_member_id' => 'patreon-user-1',
        'status' => 'active',
        'metadata' => [
            'discord_server_id' => $discordServer->id,
        ],
    ]);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->deleteJson(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]), [
        'remove_members' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('removed_members_count', 1);

    $this->assertDatabaseMissing('memberships', [
        'id' => $discordMembership->id,
    ]);
    $this->assertDatabaseHas('memberships', [
        'id' => $manualMembership->id,
    ]);
    $this->assertDatabaseHas('memberships', [
        'id' => $patreonMembership->id,
    ]);
});

it('redirects after removing discord servers from communities through a browser request', function () {
    $organization = ($this->createOrganization)('browser-remove-org');
    $discordServer = ($this->createDiscordServer)($organization);
    $user = User::factory()->create();
    ($this->attachOrganizationRole)($user, $organization, 10);

    $response = $this->actingAs($user)->delete(route('organizations.discord-servers.destroy', [
        'organization' => $organization,
        'discordServer' => $discordServer,
    ]));

    $response->assertRedirect(route('communities.index', ['active' => $organization->id]));
    $response->assertSessionHas('success', 'Discord server removed from this community.');
});

it('redirects invalid discord server callback state with an error', function () {
    $response = $this->get(route('discord-server.callback', [
        'code' => 'oauth-code',
        'state' => 'not-encrypted',
    ]));

    $response->assertRedirect(route('communities.index'));
    $response->assertSessionHas('error', 'Invalid state.');
});

it('redirects discord server callback when the organization no longer exists', function () {
    $response = $this->get(route('discord-server.callback', [
        'code' => 'oauth-code',
        'state' => encrypt(['org_id' => 999999]),
    ]));

    $response->assertRedirect(route('communities.index'));
    $response->assertSessionHas('error', 'Organization not found.');
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
