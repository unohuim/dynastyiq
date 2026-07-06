<?php

declare(strict_types=1);

use App\Models\IntegrationSecret;
use App\Models\League;
use App\Models\LeagueUserRole;
use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Models\YahooFantasyConnection;
use App\Jobs\SyncFantraxLeagueJob;
use App\Support\FantasyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->createConnectedUser = function (): User {
        $user = User::factory()->create();

        IntegrationSecret::create([
            'user_id' => $user->id,
            'provider' => FantasyProvider::FANTRAX,
            'secret' => 'secret-key',
            'status' => 'connected',
        ]);

        return $user;
    };

    $this->createPlatformLeagueAssignment = function (
        User $user,
        string $name,
        bool $isActive = true,
        bool $isVisible = true,
        string $provider = FantasyProvider::FANTRAX,
    ): PlatformLeague {
        $slug = str($name)->slug()->toString();
        $league = PlatformLeague::create([
            'platform' => $provider,
            'platform_league_id' => $slug,
            'name' => $name,
            'sport' => 'hockey',
        ]);
        $team = PlatformTeam::create([
            'platform_league_id' => $league->id,
            'platform_team_id' => $slug . '-team',
            'name' => $name . ' Team',
        ]);

        $user->platformLeagues()->attach($league->id, [
            'team_id' => $team->id,
            'is_active' => $isActive,
            'is_visible' => $isVisible,
            'extras' => json_encode(['provider' => $provider]),
            'synced_at' => now(),
        ]);

        return $league;
    };
});

it('direct leagues access redirects when fantrax is not ready', function (): void {
    $user = User::factory()->create();
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-1',
        'name' => 'Team One',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => 'fantrax']),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('leagues.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Connect a fantasy provider to view leagues.');
});

it('leagues page renders when fantrax secret and active league assignment exist', function (): void {
    $user = User::factory()->create();
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => 'fantrax',
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-1',
        'name' => 'Team One',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => 'fantrax']),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('leagues.index'))
        ->assertOk()
        ->assertSee('League One')
        ->assertSee('Fantrax');
});

it('leagues page renders when yahoo connection and active league assignment exist', function (): void {
    $user = User::factory()->create();
    YahooFantasyConnection::create([
        'user_id' => $user->id,
        'status' => 'connected',
        'access_token' => 'access-token',
    ]);
    $league = PlatformLeague::create([
        'platform' => FantasyProvider::YAHOO,
        'platform_league_id' => 'yahoo-league-1',
        'name' => 'Yahoo League One',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'yahoo-team-1',
        'name' => 'Yahoo Team One',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => FantasyProvider::YAHOO]),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('leagues.index'))
        ->assertOk()
        ->assertSee('Yahoo League One')
        ->assertSee('Yahoo');
});

it('top level league refresh queues fantrax leagues for super admins', function (): void {
    Queue::fake([SyncFantraxLeagueJob::class]);

    $user = User::factory()->create();
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
        'level' => 100,
        'scope' => 'global',
        'is_active' => true,
    ]);
    DB::table('role_user')->insert([
        'role_id' => $role->id,
        'user_id' => $user->id,
        'organization_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => FantasyProvider::FANTRAX,
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);
    $league = PlatformLeague::create([
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-1',
        'name' => 'Team One',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => FantasyProvider::FANTRAX]),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('leagues.resync'))
        ->assertOk()
        ->assertJsonPath('summary.fantrax.queued_job_count', 1)
        ->assertJsonPath('summary.fantrax.platform_league_ids.0', $league->id);

    Queue::assertPushed(
        SyncFantraxLeagueJob::class,
        fn (SyncFantraxLeagueJob $job): bool => $job->platformLeagueId === $league->id
            && $job->userId === $user->id,
    );
});

it('fantrax roster display derives eligibility from platform slot usage and sorts minors by it', function (): void {
    $user = User::factory()->create();
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => FantasyProvider::FANTRAX,
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);
    $league = PlatformLeague::create([
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-1',
        'name' => 'Team One',
    ]);
    $otherLeague = PlatformLeague::create([
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'league-2',
        'name' => 'League Two',
        'sport' => 'hockey',
    ]);
    $otherTeam = PlatformTeam::create([
        'platform_league_id' => $otherLeague->id,
        'platform_team_id' => 'team-2',
        'name' => 'Team Two',
    ]);
    $thirdTeam = PlatformTeam::create([
        'platform_league_id' => $otherLeague->id,
        'platform_team_id' => 'team-3',
        'name' => 'Team Three',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => FantasyProvider::FANTRAX]),
        'synced_at' => now(),
    ]);

    $byfield = Player::create([
        'first_name' => 'Quinton',
        'last_name' => 'Byfield',
        'full_name' => 'Quinton Byfield',
        'position' => 'RW',
        'pos_type' => 'F',
        'status' => 'active',
    ]);
    $fallbackDefense = Player::create([
        'first_name' => 'Minor',
        'last_name' => 'Defense',
        'full_name' => 'Minor Defense',
        'position' => 'C',
        'pos_type' => 'F',
        'status' => 'active',
    ]);

    DB::table('fantrax_players')->insert([
        [
            'player_id' => $byfield->id,
            'fantrax_id' => 'byfield',
            'name' => 'Quinton Byfield',
            'position' => 'C',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'player_id' => $fallbackDefense->id,
            'fantrax_id' => 'fallback-defense',
            'name' => 'Minor Defense',
            'position' => 'D',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('platform_roster_memberships')->insert([
        [
            'platform_team_id' => $team->id,
            'player_id' => $byfield->id,
            'platform' => FantasyProvider::FANTRAX,
            'platform_player_id' => 'byfield',
            'slot' => 'MIN',
            'status' => 'na',
            'eligibility' => json_encode(['MIN']),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'platform_team_id' => $team->id,
            'player_id' => $fallbackDefense->id,
            'platform' => FantasyProvider::FANTRAX,
            'platform_player_id' => 'fallback-defense',
            'slot' => 'MIN',
            'status' => 'na',
            'eligibility' => json_encode(['MIN']),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'platform_team_id' => $thirdTeam->id,
            'player_id' => $byfield->id,
            'platform' => FantasyProvider::FANTRAX,
            'platform_player_id' => 'byfield',
            'slot' => 'LW',
            'status' => 'active',
            'eligibility' => null,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'platform_team_id' => $otherTeam->id,
            'player_id' => $byfield->id,
            'platform' => FantasyProvider::FANTRAX,
            'platform_player_id' => 'byfield',
            'slot' => 'C',
            'status' => 'active',
            'eligibility' => null,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($user)->get(route('leagues.players.payload', $league->id));

    $response->assertOk();

    $players = collect($response->json('teams.0.players'));

    expect($players->pluck('name')->sort()->values()->all())->toBe([
        'Minor Defense',
        'Quinton Byfield',
    ]);
    expect($players->firstWhere('name', 'Quinton Byfield')['eligibility'])->toBe(['C', 'LW']);
    expect($players->firstWhere('name', 'Minor Defense')['eligibility'])->toBe(['D']);
});

it('creating a community league assigns the acting user as league commissioner', function (): void {
    $user = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Commissioner Community',
        'short_name' => 'CC',
        'slug' => 'commissioner-community',
        'settings' => ['commissioner_tools' => true],
    ]);
    $organization->users()->attach($user->id);

    $this->actingAs($user)
        ->postJson(route('organizations.leagues.store', $organization), [
            'name' => 'Created League',
            'sport' => 'hockey',
        ])
        ->assertOk()
        ->assertJsonPath('league.name', 'Created League');

    $league = League::query()->where('name', 'Created League')->firstOrFail();

    expect(LeagueUserRole::query()
        ->where('league_id', $league->id)
        ->where('user_id', $user->id)
        ->where('role', 'commissioner')
        ->exists())->toBeTrue();
});

it('league settings are gated by league-scoped commissioner role', function (): void {
    $user = User::factory()->create();
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => FantasyProvider::FANTRAX,
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);
    $organization = Organization::create([
        'name' => 'Org Commissioner Community',
        'short_name' => 'OCC',
        'slug' => 'org-commissioner-community',
        'settings' => ['commissioner_tools' => true],
    ]);
    $organization->users()->attach($user->id);
    $role = Role::create([
        'name' => 'Commissioner',
        'slug' => 'commissioner',
        'level' => 10,
        'scope' => 'organization',
        'is_active' => true,
    ]);
    DB::table('role_user')->insert([
        'role_id' => $role->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $internalLeague = League::create([
        'name' => 'Scoped League',
        'sport' => 'hockey',
    ]);
    $organization->leagues()->attach($internalLeague->id, [
        'linked_at' => now(),
    ]);
    $league = PlatformLeague::create([
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'scoped-league',
        'name' => 'Scoped League',
        'sport' => 'hockey',
    ]);
    $internalLeague->platformLeagues()->attach($league->id, [
        'linked_at' => now(),
        'status' => 'active',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-1',
        'name' => 'Team One',
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => FantasyProvider::FANTRAX]),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('leagues.show', $league->id))
        ->assertOk()
        ->assertDontSee('League settings');

    LeagueUserRole::create([
        'league_id' => $internalLeague->id,
        'user_id' => $user->id,
        'role' => 'commissioner',
    ]);

    $this->actingAs($user)
        ->get(route('leagues.show', $league->id))
        ->assertOk()
        ->assertSee('League settings');
});

it('league commissioner backfill assigns organization owners only by default', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Backfill Community',
        'short_name' => 'BC',
        'slug' => 'backfill-community',
        'owner_user_id' => $owner->id,
        'settings' => ['commissioner_tools' => true],
    ]);
    $organization->users()->attach([$owner->id, $admin->id]);
    $role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
        'level' => 10,
        'scope' => 'organization',
        'is_active' => true,
    ]);
    DB::table('role_user')->insert([
        'role_id' => $role->id,
        'user_id' => $admin->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $league = League::create([
        'name' => 'Backfilled League',
        'sport' => 'hockey',
    ]);
    $organization->leagues()->attach($league->id, [
        'linked_at' => now(),
    ]);

    $this->artisan('leagues:backfill-commissioners')
        ->assertExitCode(0);
    $this->artisan('leagues:backfill-commissioners')
        ->assertExitCode(0);

    expect(LeagueUserRole::query()
        ->where('league_id', $league->id)
        ->where('user_id', $owner->id)
        ->where('role', 'commissioner')
        ->count())->toBe(1)
        ->and(LeagueUserRole::query()
            ->where('league_id', $league->id)
            ->where('user_id', $admin->id)
            ->where('role', 'commissioner')
            ->exists())->toBeFalse();
});

it('league commissioner backfill can include organization admins when requested', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $organization = Organization::create([
        'name' => 'Backfill Admin Community',
        'short_name' => 'BAC',
        'slug' => 'backfill-admin-community',
        'owner_user_id' => $owner->id,
        'settings' => ['commissioner_tools' => true],
    ]);
    $organization->users()->attach([$owner->id, $admin->id]);
    $role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
        'level' => 10,
        'scope' => 'organization',
        'is_active' => true,
    ]);
    DB::table('role_user')->insert([
        'role_id' => $role->id,
        'user_id' => $admin->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $league = League::create([
        'name' => 'Backfilled Admin League',
        'sport' => 'hockey',
    ]);
    $organization->leagues()->attach($league->id, [
        'linked_at' => now(),
    ]);

    $this->artisan('leagues:backfill-commissioners', ['--include-org-admins' => true])
        ->assertExitCode(0);

    expect(LeagueUserRole::query()
        ->where('league_id', $league->id)
        ->where('user_id', $owner->id)
        ->where('role', 'commissioner')
        ->exists())->toBeTrue()
        ->and(LeagueUserRole::query()
            ->where('league_id', $league->id)
            ->where('user_id', $admin->id)
            ->where('role', 'commissioner')
            ->exists())->toBeTrue();
});

it('leagues page left list excludes user hidden leagues', function (): void {
    $user = ($this->createConnectedUser)();
    $visibleLeague = ($this->createPlatformLeagueAssignment)($user, 'Visible League');
    $hiddenLeague = ($this->createPlatformLeagueAssignment)($user, 'Hidden League', isVisible: false);

    $response = $this->actingAs($user)->get(route('leagues.index'));

    $response->assertOk()
        ->assertSee('data-panel-url="' . route('leagues.panel', $visibleLeague->id) . '"', false)
        ->assertDontSee('data-panel-url="' . route('leagues.panel', $hiddenLeague->id) . '"', false);
});

it('leagues page left list excludes hidden leagues for super admins', function (): void {
    $user = ($this->createConnectedUser)();
    $role = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
        'level' => 100,
        'scope' => 'global',
        'is_active' => true,
    ]);
    DB::table('role_user')->insert([
        'role_id' => $role->id,
        'user_id' => $user->id,
        'organization_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $visibleLeague = ($this->createPlatformLeagueAssignment)($user, 'Visible League');
    $hiddenLeague = ($this->createPlatformLeagueAssignment)($user, 'Hidden League', isVisible: false);

    $response = $this->actingAs($user)->get(route('leagues.index'));

    $response->assertOk()
        ->assertSee('data-panel-url="' . route('leagues.panel', $visibleLeague->id) . '"', false)
        ->assertDontSee('data-panel-url="' . route('leagues.panel', $hiddenLeague->id) . '"', false);
});

it('leagues options drawer includes user hidden leagues', function (): void {
    $user = ($this->createConnectedUser)();
    ($this->createPlatformLeagueAssignment)($user, 'Visible League');
    $hiddenLeague = ($this->createPlatformLeagueAssignment)($user, 'Hidden League', isVisible: false);

    $this->actingAs($user)
        ->get(route('leagues.index'))
        ->assertOk()
        ->assertSee('Hidden League')
        ->assertSee('data-league-visibility-url="' . route('leagues.visibility.update', $hiddenLeague->id) . '"', false)
        ->assertSee('data-league-visible="false"', false);
});

it('visible league access filters hidden leagues without removing active access', function (): void {
    $user = ($this->createConnectedUser)();
    $visibleLeague = ($this->createPlatformLeagueAssignment)($user, 'Visible League');
    $hiddenLeague = ($this->createPlatformLeagueAssignment)($user, 'Hidden League', isVisible: false);
    $access = app(\App\Services\FantasyLeagueAccess::class);

    expect($access->activeLeaguesForUser($user)->pluck('platform_leagues.id')->all())
        ->toContain($visibleLeague->id, $hiddenLeague->id)
        ->and($access->visibleLeaguesForUser($user)->pluck('platform_leagues.id')->all())
        ->toContain($visibleLeague->id)
        ->not->toContain($hiddenLeague->id);
});

it('league visibility endpoint hides a current user league', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Toggle League');

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => false])
        ->assertOk()
        ->assertJsonPath('league_id', $league->id)
        ->assertJsonPath('is_visible', false);

    expect(DB::table('league_user_teams')
        ->where('user_id', $user->id)
        ->where('platform_league_id', $league->id)
        ->value('is_visible'))->toBe(0);
});

it('league visibility endpoint supports native form fallback submissions', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Fallback League');

    $this->actingAs($user)
        ->post(route('leagues.visibility.update', $league->id), [
            '_method' => 'PUT',
            'is_visible' => '0',
        ])
        ->assertRedirect(route('leagues.index'))
        ->assertSessionHas('status', 'League hidden from your list.');

    $this->assertDatabaseHas('league_user_teams', [
        'user_id' => $user->id,
        'platform_league_id' => $league->id,
        'is_visible' => false,
    ]);
});

it('league visibility endpoint shows a current user league', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Toggle League', isVisible: false);

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => true])
        ->assertOk()
        ->assertJsonPath('league_id', $league->id)
        ->assertJsonPath('is_visible', true);

    expect(DB::table('league_user_teams')
        ->where('user_id', $user->id)
        ->where('platform_league_id', $league->id)
        ->value('is_visible'))->toBe(1);
});

it('league visibility endpoint requires boolean visibility', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Toggle League');

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_visible']);
});

it('league visibility endpoint rejects leagues outside the current user assignments', function (): void {
    $user = ($this->createConnectedUser)();
    $otherUser = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($otherUser, 'Other League');

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => false])
        ->assertNotFound();
});

it('league visibility endpoint rejects inactive current user assignments', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Inactive League', isActive: false);

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => false])
        ->assertNotFound();
});

it('league visibility endpoint rejects users without a ready provider', function (): void {
    $user = User::factory()->create();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Unready League');

    $this->actingAs($user)
        ->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => false])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Connect a fantasy provider before updating league visibility.');
});

it('league visibility endpoint requires authentication', function (): void {
    $user = ($this->createConnectedUser)();
    $league = ($this->createPlatformLeagueAssignment)($user, 'Auth League');

    $this->putJson(route('leagues.visibility.update', $league->id), ['is_visible' => false])
        ->assertUnauthorized();
});

it('non super admins cannot refresh leagues through the refresh endpoint', function (): void {
    $user = ($this->createConnectedUser)();
    ($this->createPlatformLeagueAssignment)($user, 'Refresh League');

    $this->actingAs($user)
        ->postJson(route('leagues.resync'))
        ->assertForbidden();
});
