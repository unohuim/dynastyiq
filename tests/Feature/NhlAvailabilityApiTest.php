<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlPlayerInjury;
use App\Models\NhlStartingGoalieObservation;
use App\Models\Player;
use App\Services\NhlInjuryImporter;
use App\Services\NhlStartingGoalieImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function availabilityToken(array $scopes = ['nhl-stats:read'], bool $revoked = false): string
{
    $token = 'availability-test-token-' . count($scopes) . ($revoked ? '-revoked' : '');
    ApiClient::query()->create([
        'name' => $token,
        'token_hash' => ApiClient::hashToken($token),
        'scopes' => $scopes,
        'revoked_at' => $revoked ? now() : null,
    ]);
    return $token;
}

function createCurrentInjury(array $overrides = []): NhlPlayerInjury
{
    return NhlPlayerInjury::query()->create(array_merge([
        'nhl_player_id' => 8470001, 'player_name' => 'Test Player', 'team_abbrev' => 'TOR',
        'position' => 'C', 'body_part' => 'lower body', 'availability' => 'out',
        'evidence_level' => 'reported', 'status_text' => 'Expected to be out',
        'anticipated_return_precision' => 'unknown', 'sources' => ['cbs'],
        'first_observed_at' => now(), 'last_observed_at' => now(), 'last_seen_at' => now(),
    ], $overrides));
}

function createGoalieObservation(array $overrides = []): NhlStartingGoalieObservation
{
    return NhlStartingGoalieObservation::query()->create(array_merge([
        'nhl_game_id' => 2026020001, 'game_date' => today(), 'team_abbrev' => 'TOR',
        'opponent_abbrev' => 'MTL', 'is_home' => true, 'nhl_player_id' => 8470002,
        'player_name' => 'Test Goalie', 'provider' => 'rotowire', 'status' => 'expected',
        'fetched_at' => now(), 'source_url' => 'https://example.test', 'raw_evidence' => [],
    ], $overrides));
}

it('rejects unauthenticated injury api requests', function (): void {
    $this->getJson('/api/nhl-injuries')->assertUnauthorized();
});

it('rejects injury api clients without the stats scope', function (): void {
    $this->withToken(availabilityToken(['nhl-reference:read']))->getJson('/api/nhl-injuries')->assertForbidden();
});

it('rejects revoked injury api clients', function (): void {
    $this->withToken(availabilityToken(['nhl-stats:read'], true))->getJson('/api/nhl-injuries')->assertForbidden();
});

it('rejects unauthenticated goalie api requests', function (): void {
    $this->getJson('/api/nhl-starting-goalies')->assertUnauthorized();
});

it('rejects goalie api clients without the stats scope', function (): void {
    $this->withToken(availabilityToken(['nhl-reference:read']))->getJson('/api/nhl-starting-goalies')->assertForbidden();
});

it('rejects revoked goalie api clients', function (): void {
    $this->withToken(availabilityToken(['nhl-stats:read'], true))->getJson('/api/nhl-starting-goalies')->assertForbidden();
});

it('returns all current injuries by default', function (): void {
    createCurrentInjury();
    $this->withToken(availabilityToken())->getJson('/api/nhl-injuries')->assertOk()->assertJsonCount(1, 'injuries');
});

it('returns an empty injury collection', function (): void {
    $this->withToken(availabilityToken())->getJson('/api/nhl-injuries')->assertOk()->assertJsonCount(0, 'injuries');
});

it('filters injuries by team', function (): void {
    createCurrentInjury();
    $this->withToken(availabilityToken())->getJson('/api/nhl-injuries?team_abbrev=MTL')->assertOk()->assertJsonCount(0, 'injuries');
});

it('filters injuries by nhl player id', function (): void {
    createCurrentInjury();
    $this->withToken(availabilityToken())->getJson('/api/nhl-injuries?nhl_player_id=8470001')->assertOk()->assertJsonPath('injuries.0.nhl_player_id', 8470001);
});

it('combines injury team and player filters', function (): void {
    createCurrentInjury();
    $this->withToken(availabilityToken())->getJson('/api/nhl-injuries?team_abbrev=TOR&nhl_player_id=8470001')->assertOk()->assertJsonCount(1, 'injuries');
});

it('defaults goalie api requests to today', function (): void {
    createGoalieObservation();
    $this->withToken(availabilityToken())->getJson('/api/nhl-starting-goalies')->assertOk()->assertJsonPath('meta.date', today()->toDateString());
});

it('returns an empty goalie collection for a date without observations', function (): void {
    $this->withToken(availabilityToken())->getJson('/api/nhl-starting-goalies?date=2000-01-01')->assertOk()->assertJsonCount(0, 'starting_goalies');
});

it('returns only the latest goalie observation per team and game', function (): void {
    createGoalieObservation(['status' => 'expected', 'fetched_at' => now()->subMinute()]);
    createGoalieObservation(['status' => 'confirmed', 'fetched_at' => now()]);
    $this->withToken(availabilityToken())->getJson('/api/nhl-starting-goalies')->assertOk()->assertJsonCount(1, 'starting_goalies')->assertJsonPath('starting_goalies.0.status', 'confirmed');
});

it('filters goalie observations by explicit date', function (): void {
    createGoalieObservation(['game_date' => '2026-10-01']);
    $this->withToken(availabilityToken())->getJson('/api/nhl-starting-goalies?date=2026-10-01')->assertOk()->assertJsonCount(1, 'starting_goalies');
});

it('rejects simultaneous goalie date and game filters', function (): void {
    $this->withToken(availabilityToken())->getJson('/api/nhl-starting-goalies?date=2026-10-01&nhl_game_id=2026020001')->assertUnprocessable();
});

it('renders the public injuries page', function (): void {
    createCurrentInjury();
    $this->get('/injuries')
        ->assertOk()
        ->assertSee('NHL Injuries')
        ->assertSee('First reported')
        ->assertSee('Last updated')
        ->assertSee('News')
        ->assertSee(route('transactions.index'))
        ->assertSee(route('starting-goalies.index'))
        ->assertSee(route('injuries.index'));
});

it('renders the public starting goalies page', function (): void {
    $this->get('/starting-goalies')->assertOk()->assertSee('Starting Goalies');
});

it('groups both starting goalies into one public game card', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026020001,
        'season_id' => '20262027',
        'game_type' => 2,
        'game_date' => '2026-10-01',
        'game_dow' => 'Thursday',
        'game_month' => 'October',
        'start_time_utc' => '2026-10-01 23:00:00',
        'away_team_abbrev' => 'MTL',
        'home_team_abbrev' => 'TOR',
    ]);
    createGoalieObservation([
        'game_date' => '2026-10-01',
        'team_abbrev' => 'MTL',
        'opponent_abbrev' => 'TOR',
        'is_home' => false,
        'player_name' => 'Away Goalie',
    ]);
    createGoalieObservation([
        'game_date' => '2026-10-01',
        'team_abbrev' => 'TOR',
        'opponent_abbrev' => 'MTL',
        'is_home' => true,
        'player_name' => 'Home Goalie',
    ]);

    $this->get('/starting-goalies?date=2026-10-01')
        ->assertOk()
        ->assertSee('MTL at TOR')
        ->assertSee('Away Goalie')
        ->assertSee('Home Goalie')
        ->assertSee('data-local-datetime', false)
        ->assertSee('Thursday, October 1, 2026');
});

it('returns the canonical utc game start with goalie payloads', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026020001,
        'season_id' => '20262027',
        'game_type' => 2,
        'game_date' => '2026-10-01',
        'game_dow' => 'Thursday',
        'game_month' => 'October',
        'start_time_utc' => '2026-10-01 23:00:00',
        'away_team_abbrev' => 'MTL',
        'home_team_abbrev' => 'TOR',
    ]);
    createGoalieObservation(['game_date' => '2026-10-01']);

    $this->withToken(availabilityToken())
        ->getJson('/api/nhl-starting-goalies?date=2026-10-01')
        ->assertOk()
        ->assertJsonPath('starting_goalies.0.start_time_utc', '2026-10-01T23:00:00+00:00')
        ->assertJsonPath('games.0.away_team_abbrev', 'MTL')
        ->assertJsonPath('games.0.home_team_abbrev', 'TOR');
});

it('deduplicates reversed provider matchups and uses the nhl schedule for goalie sides', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026020001,
        'season_id' => '20262027',
        'game_type' => 2,
        'game_date' => '2026-09-19',
        'game_dow' => 'Saturday',
        'game_month' => 'September',
        'start_time_utc' => '2026-09-19 23:00:00',
        'away_team_abbrev' => 'MTL',
        'home_team_abbrev' => 'TOR',
    ]);
    Player::query()->create([
        'nhl_id' => 8475683,
        'full_name' => 'Sergei Bobrovsky',
        'first_name' => 'Sergei',
        'last_name' => 'Bobrovsky',
        'position' => 'G',
        'is_goalie' => true,
        'team_abbrev' => 'TOR',
        'current_league_abbrev' => 'NHL',
    ]);
    Player::query()->create([
        'nhl_id' => 8484170,
        'full_name' => 'Jacob Fowler',
        'first_name' => 'Jacob',
        'last_name' => 'Fowler',
        'position' => 'G',
        'is_goalie' => false,
        'team_abbrev' => 'MTL',
        'current_league_abbrev' => 'NHL',
    ]);
    Http::fake([
        'www.rotowire.com/hockey/tables/projected-goalies.php*' => Http::response([
            [
                'hometeam' => 'TOR', 'homePlayer' => 'Sergei Bobrovsky', 'homeStatus' => 'Confirmed',
                'visitteam' => 'MTL', 'visitPlayer' => 'Jacob Fowler', 'visitStatus' => 'Expected',
            ],
            [
                'hometeam' => 'MTL', 'homePlayer' => 'Jacob Fowler', 'homeStatus' => 'Expected',
                'visitteam' => 'TOR', 'visitPlayer' => 'Sergei Bobrovsky', 'visitStatus' => 'Confirmed',
            ],
        ]),
    ]);

    $result = app(NhlStartingGoalieImporter::class)->import(Carbon::parse('2026-09-19'));

    expect($result)->toMatchArray(['observed' => 2, 'unresolved' => 0]);
    $this->assertDatabaseCount('nhl_starting_goalie_observations', 2);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026020001,
        'team_abbrev' => 'MTL',
        'player_name' => 'Jacob Fowler',
        'nhl_player_id' => 8484170,
        'is_home' => false,
    ]);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026020001,
        'team_abbrev' => 'TOR',
        'player_name' => 'Sergei Bobrovsky',
        'is_home' => true,
    ]);

    $this->get('/starting-goalies?date=2026-09-19')
        ->assertOk()
        ->assertSeeInOrder(['Away · MTL', 'Jacob Fowler', 'Home · TOR', 'Sergei Bobrovsky']);
});

it('resolves an nhl owned preseason goalie with a current ahl league assignment', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010005,
        'season_id' => '20262027',
        'game_type' => 1,
        'game_date' => '2026-09-19',
        'game_dow' => 'Saturday',
        'game_month' => 'September',
        'away_team_abbrev' => 'EDM',
        'home_team_abbrev' => 'WPG',
    ]);
    Player::query()->create([
        'nhl_id' => 8483114,
        'full_name' => 'Thomas Milic',
        'first_name' => 'Thomas',
        'last_name' => 'Milic',
        'position' => 'G',
        'is_goalie' => true,
        'team_abbrev' => 'WPG',
        'current_league_abbrev' => 'AHL',
    ]);
    Http::fake([
        'www.rotowire.com/hockey/tables/projected-goalies.php*' => Http::response([
            [
                'hometeam' => 'WPG', 'homePlayer' => 'Thomas Milic', 'homeStatus' => 'Expected',
                'visitteam' => 'EDM', 'visitPlayer' => '', 'visitStatus' => 'Unknown',
            ],
        ]),
    ]);

    $result = app(NhlStartingGoalieImporter::class)->import(Carbon::parse('2026-09-19'));

    expect($result)->toMatchArray(['observed' => 1, 'unresolved' => 0]);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026010005,
        'team_abbrev' => 'WPG',
        'player_name' => 'Thomas Milic',
        'nhl_player_id' => 8483114,
    ]);
});

it('stops the scheduled future goalie scan at the first blank provider date', function (): void {
    $this->travelTo(Carbon::parse('2026-09-19 10:00:00'));
    $responses = [[[
        'hometeam' => 'WPG', 'homePlayer' => 'Thomas Milic', 'homeStatus' => 'Expected',
        'visitteam' => 'EDM', 'visitPlayer' => '', 'visitStatus' => 'Unknown',
    ]], []];
    Http::fake(fn () => Http::response(array_shift($responses) ?? []));

    Artisan::call('nhl:import-starting-goalies', ['--future-window' => true]);

    Http::assertSentCount(2);
    $this->travelBack();
});

it('caps the scheduled future goalie scan at seven dates', function (): void {
    $this->travelTo(Carbon::parse('2026-09-19 10:00:00'));
    Http::fake(fn () => Http::response([[
        'hometeam' => 'WPG', 'homePlayer' => 'Thomas Milic', 'homeStatus' => 'Expected',
        'visitteam' => 'EDM', 'visitPlayer' => '', 'visitStatus' => 'Unknown',
    ]]));

    Artisan::call('nhl:import-starting-goalies', ['--future-window' => true]);

    Http::assertSentCount(7);
    $this->travelBack();
});

it('uses prior regular season goalie stats for preseason cards', function (): void {
    Player::query()->create([
        'nhl_id' => 8479001, 'full_name' => 'Preseason Goalie', 'first_name' => 'Preseason',
        'last_name' => 'Goalie', 'position' => 'G', 'is_goalie' => true,
        'team_abbrev' => 'TOR', 'current_league_abbrev' => 'NHL',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2025020001, 'season_id' => '20252026', 'game_type' => 2,
        'game_date' => '2025-10-01', 'game_dow' => 'Wednesday', 'game_month' => 'October',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010001, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2025020001, 'nhl_player_id' => 8479001, 'nhl_team_id' => 10,
        'sa' => 100, 'sv' => 91, 'ga' => 9, 'toi' => 10800,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    createGoalieObservation([
        'nhl_game_id' => 2026010001, 'game_date' => '2026-09-20',
        'nhl_player_id' => 8479001, 'player_name' => 'Preseason Goalie',
    ]);

    $this->getJson('/starting-goalies/payload?date=2026-09-20')
        ->assertOk()
        ->assertJsonPath('starting_goalies.0.season_stats.season_key', '20252026')
        ->assertJsonPath('starting_goalies.0.season_stats.games_played', 1)
        ->assertJsonPath('starting_goalies.0.season_stats.goals_against_average', 3)
        ->assertJsonPath('starting_goalies.0.season_stats.save_percentage', 0.91);

    $this->get('/starting-goalies?date=2026-09-20')
        ->assertOk()
        ->assertSee('3.00 GAA')
        ->assertSee('.910 SV%')
        ->assertSee('1 GP')
        ->assertSee('2025–26 regular season');
});

it('uses current regular season goalie stats for regular season cards', function (): void {
    Player::query()->create([
        'nhl_id' => 8479002, 'full_name' => 'Regular Goalie', 'first_name' => 'Regular',
        'last_name' => 'Goalie', 'position' => 'G', 'is_goalie' => true,
        'team_abbrev' => 'TOR', 'current_league_abbrev' => 'NHL',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026020002, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-02', 'game_dow' => 'Friday', 'game_month' => 'October',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026020003, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-01', 'game_dow' => 'Thursday', 'game_month' => 'October',
    ]);
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2026020003, 'nhl_player_id' => 8479002, 'nhl_team_id' => 10,
        'sa' => 40, 'sv' => 37, 'ga' => 3, 'toi' => 3600,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    createGoalieObservation([
        'nhl_game_id' => 2026020002, 'game_date' => '2026-10-02',
        'nhl_player_id' => 8479002, 'player_name' => 'Regular Goalie',
    ]);

    $this->getJson('/starting-goalies/payload?date=2026-10-02')
        ->assertOk()
        ->assertJsonPath('starting_goalies.0.season_stats.season_key', '20262027')
        ->assertJsonPath('starting_goalies.0.season_stats.games_played', 1)
        ->assertJsonPath('starting_goalies.0.season_stats.goals_against_average', 3)
        ->assertJsonPath('starting_goalies.0.season_stats.save_percentage', 0.925);
});

it('renders a calm fallback when goalie season stats are unavailable', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026020004, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-04', 'game_dow' => 'Sunday', 'game_month' => 'October',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    createGoalieObservation([
        'nhl_game_id' => 2026020004, 'game_date' => '2026-10-04',
        'nhl_player_id' => 8479999, 'player_name' => 'Unknown Goalie',
    ]);

    $this->get('/starting-goalies?date=2026-10-04')
        ->assertOk()
        ->assertSee('Season statistics unavailable');
});

it('returns the public injuries payload', function (): void {
    createCurrentInjury();
    $this->getJson('/injuries/payload')->assertOk()->assertJsonCount(1, 'injuries');
});

it('returns the public starting goalies payload', function (): void {
    createGoalieObservation();
    $this->getJson('/starting-goalies/payload')->assertOk()->assertJsonCount(1, 'starting_goalies');
});

it('imports the full CBS player name from responsive duplicate links', function (): void {
    Player::query()->create([
        'nhl_id' => 8479991, 'full_name' => 'Adam Fantilli', 'first_name' => 'Adam',
        'last_name' => 'Fantilli', 'position' => 'C', 'team_abbrev' => 'CBJ',
        'current_league_abbrev' => 'NHL',
    ]);
    Http::fake([
        'www.cbssports.com/nhl/injuries/*' => Http::response('<div class="TeamName">Columbus</div><table><tbody><tr><td><a>A. Fantilli</a><a>Adam Fantilli</a></td><td>C</td><td>Wed, Sep 16</td><td>Contract Dispute</td><td>Questionable for start of season</td></tr></tbody></table>'),
        'www.rotowire.com/rss/*' => Http::response('<?xml version="1.0"?><rss><channel></channel></rss>'),
    ]);

    app(NhlInjuryImporter::class)->import();

    $this->assertDatabaseHas('nhl_player_injuries', ['player_name' => 'Adam Fantilli', 'nhl_player_id' => 8479991]);
});

it('maps CBS city headings to NHL team abbreviations', function (): void {
    Player::query()->create([
        'nhl_id' => 8479992, 'full_name' => 'Brad Marchand', 'first_name' => 'Brad',
        'last_name' => 'Marchand', 'position' => 'LW', 'team_abbrev' => 'FLA',
        'current_league_abbrev' => 'NHL',
    ]);
    Http::fake([
        'www.cbssports.com/nhl/injuries/*' => Http::response('<div class="TeamName">Florida</div><table><tbody><tr><td><a>B. Marchand</a><a>Brad Marchand</a></td><td>LW</td><td>Thu, Sep 17</td><td>Lower Body</td><td>Expected to be out until at least Nov 2</td></tr></tbody></table>'),
        'www.rotowire.com/rss/*' => Http::response('<?xml version="1.0"?><rss><channel></channel></rss>'),
    ]);

    app(NhlInjuryImporter::class)->import();

    $this->assertDatabaseHas('nhl_player_injuries', ['player_name' => 'Brad Marchand', 'team_abbrev' => 'FLA']);
});

it('advances status updated time only when normalized injury meaning changes', function (): void {
    Player::query()->create([
        'nhl_id' => 8479993, 'full_name' => 'Troy Terry', 'first_name' => 'Troy',
        'last_name' => 'Terry', 'position' => 'RW', 'team_abbrev' => 'ANA',
        'current_league_abbrev' => 'NHL',
    ]);
    $statuses = [
        'Expected to be out until at least Nov 15',
        'Expected to be out until at least Nov 15',
        'Questionable for start of season',
    ];
    Http::fake(function ($request) use (&$statuses) {
        if (str_contains($request->url(), 'cbssports.com')) {
            $status = array_shift($statuses);
            return Http::response('<div class="TeamName">Anaheim</div><table><tbody><tr><td><a>T. Terry</a><a>Troy Terry</a></td><td>RW</td><td>Thu, Sep 17</td><td>Hip</td><td>' . $status . '</td></tr></tbody></table>');
        }
        return Http::response('<?xml version="1.0"?><rss><channel></channel></rss>');
    });

    $this->travelTo(Carbon::parse('2026-09-18 10:00:00'));
    app(NhlInjuryImporter::class)->import();
    $injury = NhlPlayerInjury::query()->where('nhl_player_id', 8479993)->firstOrFail();
    $firstReported = $injury->first_observed_at;
    $firstUpdate = $injury->last_observed_at;

    $this->travelTo(Carbon::parse('2026-09-18 11:00:00'));
    app(NhlInjuryImporter::class)->import();
    $unchanged = NhlPlayerInjury::query()->where('nhl_player_id', 8479993)->firstOrFail();
    expect($unchanged->first_observed_at)->toEqual($firstReported)
        ->and($unchanged->last_observed_at)->toEqual($firstUpdate);

    $this->travelTo(Carbon::parse('2026-09-18 12:00:00'));
    app(NhlInjuryImporter::class)->import();
    $changed = NhlPlayerInjury::query()->where('nhl_player_id', 8479993)->firstOrFail();
    expect($changed->first_observed_at)->toEqual($firstReported)
        ->and($changed->last_observed_at)->not->toEqual($firstUpdate);
    $this->travelBack();
});
