<?php

declare(strict_types=1);

use App\Models\IntegrationSecret;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\User;
use App\Models\YahooFantasyConnection;
use App\Jobs\SyncFantraxLeagueJob;
use App\Support\FantasyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('direct leagues access redirects when fantrax is not ready', function (): void {
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

test('leagues page renders when fantrax secret and active league assignment exist', function (): void {
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

test('leagues page renders when yahoo connection and active league assignment exist', function (): void {
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

test('top level league refresh queues visible fantrax leagues for current user', function (): void {
    Queue::fake([SyncFantraxLeagueJob::class]);

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

test('fantrax roster display derives eligibility from platform slot usage and sorts minors by it', function (): void {
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
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($user)->get(route('leagues.index'));

    $response->assertOk();

    $players = collect($response->viewData('teams')[0]['players']);

    expect($players->pluck('name')->all())->toBe([
        'Quinton Byfield',
        'Minor Defense',
    ]);
    expect($players->firstWhere('name', 'Quinton Byfield')['eligibility'])->toBe(['C', 'LW']);
    expect($players->firstWhere('name', 'Minor Defense')['eligibility'])->toBe(['D']);
});
