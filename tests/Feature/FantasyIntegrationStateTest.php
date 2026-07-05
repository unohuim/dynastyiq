<?php

declare(strict_types=1);

use App\Events\FantraxUserConnected;
use App\Services\ConnectFantraxUser;
use App\Models\IntegrationSecret;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\User;
use App\Models\YahooFantasyConnection;
use App\Services\FantasyIntegrationState;
use App\Support\FantasyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->attachFantasyLeague = function (User $user, string $provider): PlatformLeague {
        $league = PlatformLeague::create([
            'platform' => $provider,
            'platform_league_id' => "{$provider}-league-1",
            'name' => "{$provider} League One",
            'sport' => 'hockey',
        ]);
        $team = PlatformTeam::create([
            'platform_league_id' => $league->id,
            'platform_team_id' => "{$provider}-team-1",
            'name' => "{$provider} Team One",
        ]);
        $user->platformLeagues()->attach($league->id, [
            'team_id' => $team->id,
            'is_active' => true,
            'extras' => json_encode(['provider' => $provider]),
            'synced_at' => now(),
        ]);

        return $league;
    };
});

afterEach(function (): void {
    Mockery::close();
});

test('fantrax save returns ready integration state when active league assignment is created', function (): void {
    $user = User::factory()->create();
    $leagues = [
        [
            'leagueId' => 'league-1',
            'leagueName' => 'League One',
            'teamId' => 'team-1',
            'teamName' => 'Team One',
            'teamShortName' => 'ONE',
        ],
    ];

    $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
    $connector->shouldReceive('getAPIData')
        ->once()
        ->with('fantrax', 'user_leagues', ['userSecretId' => 'secret-key'])
        ->andReturn(['leagues' => $leagues]);

    app()->instance(ConnectFantraxUser::class, $connector);
    Event::fake([FantraxUserConnected::class]);

    $response = $this->actingAs($user)->postJson(route('integrations.fantrax.save'), [
        'fantrax_secret_key' => 'secret-key',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'integration' => [
                'provider' => FantasyProvider::FANTRAX,
                'status' => 'ready',
                'connected' => true,
                'leagues_count' => 1,
                'show_leagues' => true,
            ],
        ]);

    $this->assertDatabaseHas('platform_leagues', [
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'league-1',
    ]);
    $this->assertDatabaseHas('league_user_teams', [
        'user_id' => $user->id,
        'is_active' => true,
    ]);
});

test('fantrax secret without active league assignment does not show leagues', function (): void {
    $user = User::factory()->create();
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => FantasyProvider::FANTRAX,
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);

    $state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::FANTRAX);

    expect($state)->toBe([
        'provider' => FantasyProvider::FANTRAX,
        'status' => 'connected',
        'connected' => true,
        'leagues_count' => 0,
        'show_leagues' => false,
    ]);
});

test('fantrax active league assignment drives show leagues state when connected', function (): void {
    $user = User::factory()->create();
    IntegrationSecret::create([
        'user_id' => $user->id,
        'provider' => FantasyProvider::FANTRAX,
        'secret' => 'secret-key',
        'status' => 'connected',
    ]);
    ($this->attachFantasyLeague)($user, FantasyProvider::FANTRAX);

    $state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::FANTRAX);

    expect($state)->toBe([
        'provider' => FantasyProvider::FANTRAX,
        'status' => 'ready',
        'connected' => true,
        'leagues_count' => 1,
        'show_leagues' => true,
    ]);
});

test('active fantrax league assignment without secret does not show leagues', function (): void {
    $user = User::factory()->create();
    ($this->attachFantasyLeague)($user, FantasyProvider::FANTRAX);

    $state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::FANTRAX);

    expect($state)->toBe([
        'provider' => FantasyProvider::FANTRAX,
        'status' => 'disconnected',
        'connected' => false,
        'leagues_count' => 1,
        'show_leagues' => false,
    ]);
});

test('yahoo connection without active league assignment does not show leagues', function (): void {
    $user = User::factory()->create();
    YahooFantasyConnection::create([
        'user_id' => $user->id,
        'status' => 'connected',
        'access_token' => 'access-token',
    ]);

    $state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::YAHOO);

    expect($state)->toBe([
        'provider' => FantasyProvider::YAHOO,
        'status' => 'connected',
        'connected' => true,
        'leagues_count' => 0,
        'show_leagues' => false,
    ]);
});

test('yahoo connection with active league assignment is ready', function (): void {
    $user = User::factory()->create();
    YahooFantasyConnection::create([
        'user_id' => $user->id,
        'status' => 'connected',
        'access_token' => 'access-token',
    ]);
    ($this->attachFantasyLeague)($user, FantasyProvider::YAHOO);

    $state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::YAHOO);

    expect($state)->toBe([
        'provider' => FantasyProvider::YAHOO,
        'status' => 'ready',
        'connected' => true,
        'leagues_count' => 1,
        'show_leagues' => true,
    ]);
});
