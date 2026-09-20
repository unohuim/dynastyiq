<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\AdminImportSchedule;
use App\Models\EvidenceSource;
use App\Models\NhlGame;
use App\Models\NhlCurrentLineup;
use App\Models\NhlLineupObservation;
use App\Models\NhlPlayerInjury;
use App\Models\NhlStartingGoalieObservation;
use App\Models\Player;
use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
use App\Services\NhlInjuryImporter;
use App\Services\NhlAnticipatedLineupImporter;
use App\Services\AdminImportSchedules;
use App\Services\NhlStartingGoalieImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

function createCurrentAnticipatedLineup(array $overrides = []): NhlCurrentLineup
{
    $game = NhlGame::query()->firstOrCreate(['nhl_game_id' => 2026020099], [
        'season_id' => '20262027', 'game_type' => 2, 'game_date' => today(),
        'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $source = EvidenceSource::query()->create([
        'platform' => 'x', 'name' => 'Test Reporter', 'handle' => 'testreporter',
        'canonical_url' => 'https://x.com/testreporter', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $observation = NhlLineupObservation::query()->create([
        'nhl_game_id' => $game->nhl_game_id, 'team_id' => 10, 'team_abbrev' => 'TOR',
        'source_id' => $source->id, 'post_url' => 'https://x.com/testreporter/status/1',
        'post_text' => 'Toronto lines', 'provider_published_at' => now(), 'observed_at' => now(),
        'completeness' => 'full', 'structure_hash' => str_repeat('a', 64), 'raw_evidence' => [],
    ]);
    $observation->players()->create([
        'player_id' => null, 'nhl_player_id' => 8470001, 'player_name' => 'Test Player',
        'lineup_role' => 'forward', 'line_key' => 'F1', 'slot_index' => 1, 'resolution_status' => 'resolved',
    ]);

    return NhlCurrentLineup::query()->create(array_merge([
        'nhl_game_id' => $game->nhl_game_id, 'team_id' => 10, 'team_abbrev' => 'TOR',
        'nhl_lineup_observation_id' => $observation->id, 'structure_hash' => str_repeat('a', 64),
        'evidence_status' => 'corroborated', 'source_count' => 2,
        'first_observed_at' => now()->subMinute(), 'last_observed_at' => now(),
    ], $overrides));
}

function xLineupResponse(array $observations): array
{
    \App\Models\NhlTeam::query()->updateOrCreate(
        ['abbrev' => 'TOR'],
        ['nhl_id' => 10, 'common_name' => 'Maple Leafs']
    );
    $posts = [];
    $users = [];
    foreach ($observations as $index => $observation) {
        $handle = (string) $observation['source_handle'];
        $postId = (string) ($index + 1);
        if (preg_match('/status\/(\d+)/', (string) $observation['post_url'], $match) === 1) {
            $postId = $match[1];
        }
        foreach ($observation['players'] as $player) {
            Player::query()->firstOrCreate(['full_name' => $player['name']], [
                'nhl_id' => 8490000 + abs(crc32((string) $player['name'])) % 9000,
                'first_name' => str($player['name'])->beforeLast(' ')->toString(),
                'last_name' => str($player['name'])->afterLast(' ')->toString(),
                'position' => $player['lineup_role'] === 'goalie' ? 'G' : ($player['lineup_role'] === 'defense' ? 'D' : 'C'),
                'team_abbrev' => 'TOR',
                'current_league_abbrev' => 'NHL',
            ]);
        }
        $grouped = collect($observation['players'])->groupBy('line_key');
        $lineupText = collect(['F1', 'F2', 'F3', 'F4'])->map(
            fn (string $key): string => $grouped->get($key, collect())->pluck('name')->implode(' - ')
        )->prepend('Forwards')->merge(
            collect(['D1', 'D2', 'D3'])->map(
                fn (string $key): string => $grouped->get($key, collect())->pluck('name')->implode(' - ')
            )->prepend('Defense')
        )->merge([
            'Goalies',
            $grouped->get('G', collect())->pluck('name')->implode(' - '),
        ])->filter()->implode("\n");
        $posts[] = [
            'id' => $postId,
            'text' => $observation['post_text'] === '' ? '' : $lineupText,
            'author_id' => 'author-' . $index,
            'created_at' => $observation['published_at'],
            'public_metrics' => [
                'like_count' => 10,
                'reply_count' => 2,
                'retweet_count' => 3,
                'impression_count' => 400,
            ],
        ];
        $users[] = [
            'id' => 'author-' . $index,
            'name' => $observation['source_name'],
            'username' => $handle,
            'public_metrics' => [
                'followers_count' => 1000,
                'following_count' => 100,
                'tweet_count' => 5000,
            ],
        ];
    }

    return [
        'data' => $posts,
        'includes' => ['users' => $users, 'media' => []],
    ];
}

function lineupCandidate(string $handle, string $postUrl, string $postText = 'Full practice lineup'): array
{
    $players = [];
    foreach (range(1, 12) as $index) {
        $players[] = ['name' => "Forward {$index}", 'lineup_role' => 'forward', 'line_key' => 'F' . (int) ceil($index / 3), 'slot_index' => (($index - 1) % 3) + 1, 'power_play_unit' => null, 'penalty_kill_unit' => null];
    }
    foreach (range(1, 6) as $index) {
        $players[] = ['name' => "Defense {$index}", 'lineup_role' => 'defense', 'line_key' => 'D' . (int) ceil($index / 2), 'slot_index' => (($index - 1) % 2) + 1, 'power_play_unit' => null, 'penalty_kill_unit' => null];
    }
    foreach (range(1, 2) as $index) {
        $players[] = ['name' => "Goalie {$index}", 'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => $index, 'power_play_unit' => null, 'penalty_kill_unit' => null];
    }

    return [
        'platform' => 'x', 'source_name' => "Reporter {$handle}", 'source_handle' => $handle,
        'source_url' => "https://x.com/{$handle}", 'followers' => 1000, 'following' => 100, 'post_count' => 5000,
        'post_url' => $postUrl, 'post_text' => $postText, 'published_at' => now()->toIso8601String(),
        'engagement' => ['likes' => 10, 'replies' => 2, 'reposts' => 3, 'views' => 400],
        'players' => $players,
    ];
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

it('rejects unauthenticated anticipated lineup api requests', function (): void {
    $this->getJson('/api/nhl-anticipated-lineups')->assertUnauthorized();
});

it('rejects anticipated lineup clients without the stats scope', function (): void {
    $this->withToken(availabilityToken(['nhl-reference:read']))
        ->getJson('/api/nhl-anticipated-lineups')->assertForbidden();
});

it('rejects revoked anticipated lineup api clients', function (): void {
    $this->withToken(availabilityToken(['nhl-stats:read'], true))
        ->getJson('/api/nhl-anticipated-lineups')->assertForbidden();
});

it('defaults anticipated lineup requests to today', function (): void {
    createCurrentAnticipatedLineup();
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups')
        ->assertOk()->assertJsonPath('meta.date', today()->toDateString());
});

it('returns canonical identity and source evidence for anticipated lineups', function (): void {
    createCurrentAnticipatedLineup();
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups')
        ->assertOk()
        ->assertJsonPath('anticipated_lineups.0.nhl_game_id', 2026020099)
        ->assertJsonPath('anticipated_lineups.0.team_id', 10)
        ->assertJsonPath('anticipated_lineups.0.players.0.nhl_player_id', 8470001)
        ->assertJsonPath('anticipated_lineups.0.sources.0.handle', 'testreporter');
});

it('filters anticipated lineups by game id', function (): void {
    createCurrentAnticipatedLineup();
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020099')
        ->assertOk()->assertJsonCount(1, 'anticipated_lineups');
});

it('rejects simultaneous anticipated lineup date and game filters', function (): void {
    createCurrentAnticipatedLineup();
    $this->withToken(availabilityToken())
        ->getJson('/api/nhl-anticipated-lineups?date=' . today()->toDateString() . '&nhl_game_id=2026020099')
        ->assertUnprocessable();
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

it('keeps split squad matchups as separate nhl games', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010001,
        'season_id' => '20262027',
        'game_type' => 1,
        'game_date' => '2026-09-19',
        'game_dow' => 'Saturday',
        'game_month' => 'September',
        'start_time_utc' => '2026-09-19 18:00:00',
        'away_team_abbrev' => 'MTL',
        'home_team_abbrev' => 'TOR',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010002,
        'season_id' => '20262027',
        'game_type' => 1,
        'game_date' => '2026-09-19',
        'game_dow' => 'Saturday',
        'game_month' => 'September',
        'start_time_utc' => '2026-09-19 23:00:00',
        'away_team_abbrev' => 'TOR',
        'home_team_abbrev' => 'MTL',
    ]);
    Http::fake([
        'www.rotowire.com/hockey/tables/projected-goalies.php*' => Http::response([
            [
                'hometeam' => 'TOR', 'homePlayer' => 'Toronto Goalie One', 'homeStatus' => 'Expected',
                'visitteam' => 'MTL', 'visitPlayer' => 'Montreal Goalie One', 'visitStatus' => 'Expected',
            ],
            [
                'hometeam' => 'MTL', 'homePlayer' => 'Montreal Goalie Two', 'homeStatus' => 'Expected',
                'visitteam' => 'TOR', 'visitPlayer' => 'Toronto Goalie Two', 'visitStatus' => 'Expected',
            ],
        ]),
    ]);

    $result = app(NhlStartingGoalieImporter::class)->import(Carbon::parse('2026-09-19'));

    expect($result)->toMatchArray(['observed' => 4, 'unresolved' => 4]);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026010001,
        'team_abbrev' => 'TOR',
        'player_name' => 'Toronto Goalie One',
        'is_home' => true,
    ]);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026010002,
        'team_abbrev' => 'TOR',
        'player_name' => 'Toronto Goalie Two',
        'is_home' => false,
    ]);
    $this->getJson('/starting-goalies/payload?date=2026-09-19')
        ->assertOk()
        ->assertJsonCount(4, 'starting_goalies')
        ->assertJsonCount(2, 'games')
        ->assertJsonPath('games.0.nhl_game_id', 2026010001)
        ->assertJsonPath('games.1.nhl_game_id', 2026010002);
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

it('stores two matching lineup sources as corroborated current truth', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020100, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([
            lineupCandidate('reporter_one', 'https://x.com/reporter_one/status/1'),
            lineupCandidate('reporter_two', 'https://x.com/reporter_two/status/2'),
        ])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseCount('nhl_lineup_observations', 2)
        ->assertDatabaseHas('nhl_current_lineups', [
            'nhl_game_id' => 2026020100, 'team_id' => 10,
            'evidence_status' => 'corroborated', 'source_count' => 2,
        ]);
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020100')
        ->assertOk()
        ->assertJsonPath('anticipated_lineups.0.evidence_status', 'corroborated')
        ->assertJsonPath('anticipated_lineups.0.source_count', 2)
        ->assertJsonCount(20, 'anticipated_lineups.0.players')
        ->assertJsonCount(2, 'anticipated_lineups.0.sources');
    Http::assertSentCount(2);
});

it('treats twelve forwards and six defensemen as reported without goalies', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020198, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $candidate = lineupCandidate('skaters_only', 'https://x.com/skaters_only/status/1');
    $candidate['players'] = array_values(array_filter(
        $candidate['players'],
        fn (array $player): bool => $player['lineup_role'] !== 'goalie'
    ));
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([$candidate])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10, false);

    $this->assertDatabaseHas('nhl_lineup_observations', [
        'nhl_game_id' => 2026020198,
        'team_id' => 10,
        'completeness' => 'full',
    ])->assertDatabaseHas('nhl_current_lineups', [
        'nhl_game_id' => 2026020198,
        'team_id' => 10,
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);
});

it('searches X directly once using a broad OR team query', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020196, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([
            lineupCandidate('x_reporter', 'https://x.com/x_reporter/status/1'),
        ])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10, false);

    $requests = Http::recorded()->filter(
        fn (array $record): bool => str_contains($record[0]->url(), 'api.x.com')
    )->values();
    expect($requests)->toHaveCount(1)
        ->and($requests[0][0]->url())->toContain('/2/tweets/search/recent')
        ->and($requests[0][0]['query'])->toBe('(TOR OR "Maple Leafs")')
        ->and((int) $requests[0][0]['max_results'])->toBe(10);
    $this->assertDatabaseHas('nhl_current_lineups', [
        'nhl_game_id' => 2026020196,
        'team_id' => 10,
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);
});

it('keeps split squad X evidence with the game whose opponent is named', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    \App\Models\NhlTeam::query()->create([
        'nhl_id' => 8, 'abbrev' => 'MTL', 'common_name' => 'Canadiens',
    ]);
    \App\Models\NhlTeam::query()->create([
        'nhl_id' => 9, 'abbrev' => 'OTT', 'common_name' => 'Senators',
    ]);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020194, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026020195, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(5), 'away_team_abbrev' => 'TOR', 'home_team_abbrev' => 'OTT',
    ]);
    $response = xLineupResponse([
        lineupCandidate('montreal_reporter', 'https://x.com/montreal_reporter/status/11'),
        lineupCandidate('ottawa_reporter', 'https://x.com/ottawa_reporter/status/12'),
    ]);
    $response['data'][0]['text'] .= "\nvs MTL";
    $response['data'][1]['text'] .= "\nvs OTT";
    Http::fake(['api.x.com/*' => Http::response($response)]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['source_handle'])->toBe('montreal_reporter');
});

it('evaluates every returned X post before selecting a lineup candidate', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = (object) [
        'nhl_game_id' => 2026020192, 'game_date' => today()->toDateString(),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $response = xLineupResponse([
        lineupCandidate('tenth_reporter', 'https://x.com/tenth_reporter/status/10'),
    ]);
    $irrelevant = collect(range(1, 9))->map(fn (int $index): array => [
        'id' => (string) $index,
        'text' => "General hockey update {$index}",
        'author_id' => 'irrelevant-author',
        'created_at' => now()->toIso8601String(),
        'public_metrics' => [],
    ])->all();
    $response['data'] = array_merge($irrelevant, $response['data']);
    Http::fake(['api.x.com/*' => Http::response($response)]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['source_handle'])->toBe('tenth_reporter')
        ->and($candidates[0]['players'])->toHaveCount(20);
});

it('recognizes target team player groups throughout complete post text', function (): void {
    foreach ([
        [8484101, 'Alpha One', 'C', 'TOR'],
        [8484102, 'Bravo Two', 'LW', 'TOR'],
        [8484103, 'Charlie Three', 'RW', 'TOR'],
        [8484104, 'Delta Four', 'D', 'TOR'],
        [8484105, 'Echo Five', 'D', 'TOR'],
        [8484191, 'Other One', 'C', 'MTL'],
        [8484192, 'Other Two', 'LW', 'MTL'],
        [8484193, 'Other Three', 'RW', 'MTL'],
    ] as [$nhlId, $name, $position, $team]) {
        Player::query()->create([
            'nhl_id' => $nhlId,
            'first_name' => str($name)->beforeLast(' ')->toString(),
            'last_name' => str($name)->afterLast(' ')->toString(),
            'full_name' => $name,
            'position' => $position,
            'team_abbrev' => $team,
            'current_league_abbrev' => 'NHL',
        ]);
    }

    $players = app(\App\Services\NhlLineupTextParser::class)->parse(
        "Practice notes follow.\nAlpha One - Bravo Two / Charlie Three looked sharp.\n"
        . "Delta Four with Echo Five on the back end.\nOther One Other Two Other Three skated earlier.",
        'TOR'
    );

    expect($players)->toHaveCount(5)
        ->and(collect($players)->where('line_key', 'F1')->pluck('name')->all())
        ->toBe(['Alpha One', 'Bravo Two', 'Charlie Three'])
        ->and(collect($players)->where('line_key', 'D1')->pluck('name')->all())
        ->toBe(['Delta Four', 'Echo Five']);
});

it('uses a complete NHL boxscore roster and does not search X', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020193, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(2), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $forwards = collect(range(1, 12))->map(fn (int $index): array => [
        'playerId' => 8481000 + $index,
        'name' => ['default' => "F. {$index}"],
        'position' => 'C',
    ])->all();
    $defense = collect(range(1, 6))->map(fn (int $index): array => [
        'playerId' => 8482000 + $index,
        'name' => ['default' => "D. {$index}"],
        'position' => 'D',
    ])->all();
    $goalies = [
        ['playerId' => 8483001, 'name' => ['default' => 'G. Starter'], 'position' => 'G', 'starter' => true],
        ['playerId' => 8483002, 'name' => ['default' => 'G. Backup'], 'position' => 'G', 'starter' => false],
    ];
    Http::fake([
        'api-web.nhle.com/*' => Http::response([
            'awayTeam' => ['abbrev' => 'MTL'],
            'homeTeam' => ['abbrev' => 'TOR'],
            'playerByGameStats' => [
                'homeTeam' => compact('forwards', 'defense', 'goalies'),
            ],
        ]),
        'api.x.com/*' => Http::response(xLineupResponse([
            lineupCandidate('should_not_run', 'https://x.com/should_not_run/status/1'),
        ])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseHas('nhl_current_lineups', [
        'nhl_game_id' => 2026020193,
        'team_id' => 10,
        'evidence_status' => 'official',
        'source_count' => 1,
    ])->assertDatabaseCount('nhl_lineup_observation_players', 20)
        ->assertDatabaseHas('nhl_starting_goalie_observations', [
            'nhl_game_id' => 2026020193,
            'nhl_player_id' => 8483001,
            'provider' => 'nhl_boxscore',
            'status' => 'confirmed',
        ]);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.com'));
});

it('does not infer special teams units from an X lineup post', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020199, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $candidate = lineupCandidate('special_teams', 'https://x.com/special_teams/status/1');
    $candidate['players'][0]['power_play_unit'] = 1;
    $candidate['players'][0]['penalty_kill_unit'] = 2;
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([$candidate])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseHas('nhl_lineup_observation_players', [
        'player_name' => 'Forward 1', 'power_play_unit' => null, 'penalty_kill_unit' => null,
    ]);
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020199')
        ->assertOk()
        ->assertJsonPath('anticipated_lineups.0.players.0.power_play_unit', null)
        ->assertJsonPath('anticipated_lineups.0.players.0.penalty_kill_unit', null);
});

it('resolves locally parsed X lineup names to canonical players', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020101, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([
            lineupCandidate('reporter_three', 'https://x.com/reporter_three/status/3'),
        ])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseCount('nhl_lineup_observation_players', 20)
        ->assertDatabaseHas('nhl_lineup_observation_players', [
            'team_id' => 10, 'team_abbrev' => 'TOR',
            'player_name' => 'Forward 1', 'line_key' => 'F1', 'slot_index' => 1,
            'resolution_status' => 'resolved',
        ]);
});

it('skips image-only lineup search results', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020102, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([
            lineupCandidate('image_only', 'https://x.com/image_only/status/4', ''),
        ])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseCount('nhl_lineup_observations', 0);
});

it('skips an X text post that contains no target team player group', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020104, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $response = xLineupResponse([]);
    $response['data'] = [[
        'id' => '44', 'text' => 'TOR Maple Leafs starting lineup will be posted soon.',
        'author_id' => 'author-44', 'created_at' => now()->toIso8601String(),
        'public_metrics' => [],
    ]];
    $response['includes']['users'] = [[
        'id' => 'author-44', 'name' => 'Toronto Reporter', 'username' => 'torreporter',
        'public_metrics' => [],
    ]];
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response($response),
    ]);

    $result = app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    expect($result['observed'])->toBe(0);
    $this->assertDatabaseMissing('nhl_lineup_observations', [
        'post_url' => 'https://x.com/torreporter/status/44',
    ])->assertDatabaseMissing('nhl_current_lineups', [
        'nhl_game_id' => 2026020104,
        'team_id' => 10,
    ]);
});

it('does not impose an application daily lineup search limit', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('integration_api_usage_logs')->insert([
        'provider' => 'x', 'operation' => 'nhl_lineup_discovery',
        'input_tokens' => 0, 'output_tokens' => 0, 'tool_calls' => 10000,
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $game = (object) [
        'nhl_game_id' => 2026020103, 'game_date' => today()->toDateString(),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([])),
    ]);

    app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    Http::assertSentCount(1);
});

it('queues one near-puck-drop lineup job per team and excludes later games', function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-19 16:00:00 UTC'));
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach ([[2026010100, 17], [2026010101, 21]] as [$gameId, $hour]) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse("2026-09-19 {$hour}:00:00 UTC"),
            'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
        ]);
    }
    NhlGame::query()->create([
        'nhl_game_id' => 2026010102, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-20 17:00:00 UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);

    Artisan::call('nhl:import-anticipated-lineups', ['--window' => 'within-two-hours']);

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 2);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010100 && $job->teamAbbrev === 'MTL');
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010100 && $job->teamAbbrev === 'TOR');
    $this->travelBack();
});

it('continues searching missing lineups for games that already started today', function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-19 18:00:00 UTC'));
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010109, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-19 17:00:00 UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);

    Artisan::call('nhl:import-anticipated-lineups', ['--window' => 'within-two-hours']);

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 2);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010109);
    $this->travelBack();
});

it('queues anticipated lineup searches for today and tomorrow but not later dates', function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-19 14:00:00 UTC'));
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach ([
        [2026010110, '2026-09-19', '2026-09-19 23:00:00'],
        [2026010111, '2026-09-20', '2026-09-20 23:00:00'],
        [2026010112, '2026-09-21', '2026-09-21 23:00:00'],
    ] as [$gameId, $date, $start]) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => $date, 'game_dow' => 'Saturday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse($start . ' UTC'),
            'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
        ]);
    }

    Artisan::call('nhl:import-anticipated-lineups');

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 4);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010111);
    Queue::assertNotPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010112);
    $queued = Queue::pushed(ImportNhlAnticipatedLineupTeamJob::class)->values();
    expect($queued[0]->nhlGameId)->toBe(2026010110)
        ->and($queued[1]->nhlGameId)->toBe(2026010110);
    $this->travelBack();
});

it('queues only the missing opponent while one team already has a reported lineup', function (): void {
    Queue::fake();
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    createCurrentAnticipatedLineup([
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);

    Artisan::call('nhl:import-anticipated-lineups');

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 1);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026020099
        && $job->teamAbbrev === 'MTL');
});

it('checks the NHL boxscore but skips X when current truth needs no search', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    createCurrentAnticipatedLineup();
    Http::fake();

    $job = new ImportNhlAnticipatedLineupTeamJob(2026020099, 'TOR', 10);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api-web.nhle.com'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.com'));
});

it('counts an empty lineup search as skipped instead of imported', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026020098, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHour(), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $run = \App\Models\ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups',
        'status' => 'working',
        'command' => 'nhl:import-anticipated-lineups',
        'total_records' => 1,
        'processed_records' => 0,
        'successful_records' => 0,
        'failed_records' => 0,
        'skipped_records' => 0,
        'started_at' => now(),
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([])),
    ]);

    $job = new ImportNhlAnticipatedLineupTeamJob(2026020098, 'TOR', 10, $run->id);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    $run->refresh();
    expect($run->successful_records)->toBe(0)
        ->and($run->failed_records)->toBe(0)
        ->and($run->skipped_records)->toBe(1);
});

it('records an actionable X credit failure on the import run', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'common_name' => 'Canadiens', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'common_name' => 'Maple Leafs', 'created_at' => now(), 'updated_at' => now()],
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026020097, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHour(), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $run = \App\Models\ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'command' => 'nhl:import-anticipated-lineups', 'total_records' => 1,
        'processed_records' => 0, 'successful_records' => 0,
        'failed_records' => 0, 'skipped_records' => 0, 'started_at' => now(),
    ]);
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response([], 402),
    ]);

    $job = new ImportNhlAnticipatedLineupTeamJob(2026020097, 'TOR', 10, $run->id);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->failed_records)->toBe(1)
        ->and($run->error_message)->toContain('credits');
});

it('checks the NHL boxscore without searching X while the opponent remains missing', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    createCurrentAnticipatedLineup([
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);
    Http::fake();

    $job = new ImportNhlAnticipatedLineupTeamJob(2026020099, 'TOR', 10);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api-web.nhle.com'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.com'));
});

it('queues tomorrow while excluding a near-puck-drop game from the outside window', function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-19 16:00:00 UTC'));
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach ([
        [2026010116, '2026-09-19', '2026-09-19 17:00:00'],
        [2026010117, '2026-09-20', '2026-09-20 17:00:00'],
    ] as [$gameId, $date, $start]) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => $date, 'game_dow' => 'Saturday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse($start . ' UTC'),
            'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
        ]);
    }

    Artisan::call('nhl:import-anticipated-lineups', ['--window' => 'outside-two-hours']);

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 2);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010117);
    Queue::assertNotPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010116);
    $this->travelBack();
});

it('allows tomorrow to make only the outside two hour schedule lane eligible', function (): void {
    $now = Carbon::parse('2026-09-19 16:00:00 UTC')->toImmutable();
    NhlGame::query()->create([
        'nhl_game_id' => 2026010113, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-20 17:00:00 UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $outside = new AdminImportSchedule([
        'source_key' => 'nhl-anticipated-lineups', 'lane_key' => 'outside_two_hours',
        'enabled' => true, 'lane_enabled' => true, 'interval_seconds' => 3600,
        'recurrence_mode' => 'recurring', 'daily_start_time' => '09:00:00',
        'timezone' => 'America/Toronto',
    ]);
    $within = new AdminImportSchedule([
        'source_key' => 'nhl-anticipated-lineups', 'lane_key' => 'within_two_hours',
        'enabled' => true, 'lane_enabled' => true, 'interval_seconds' => 900,
        'recurrence_mode' => 'recurring', 'daily_start_time' => '09:00:00',
        'timezone' => 'America/Toronto',
    ]);

    expect(app(AdminImportSchedules::class)->shouldDispatch($outside, $now))->toBeTrue()
        ->and(app(AdminImportSchedules::class)->shouldDispatch($within, $now))->toBeFalse();
});

it('keeps the within two hour schedule lane eligible after todays puck drop', function (): void {
    $now = Carbon::parse('2026-09-19 18:00:00 UTC')->toImmutable();
    NhlGame::query()->create([
        'nhl_game_id' => 2026010118, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-19 17:00:00 UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $within = new AdminImportSchedule([
        'source_key' => 'nhl-anticipated-lineups', 'lane_key' => 'within_two_hours',
        'enabled' => true, 'lane_enabled' => true, 'interval_seconds' => 900,
        'recurrence_mode' => 'recurring', 'daily_start_time' => '09:00:00',
        'timezone' => 'America/Toronto',
    ]);

    expect(app(AdminImportSchedules::class)->shouldDispatch($within, $now))->toBeTrue();
});

it('requests the minimum X result page for the broad team query', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    Http::fake(['api.x.com/*' => Http::response(xLineupResponse([]))]);
    $game = (object) [
        'nhl_game_id' => 2026010114, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];

    app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    Http::assertSent(fn ($request): bool => $request['query'] === '(TOR OR "Maple Leafs")'
        && (int) $request['max_results'] === 10);
});

it('derives an expected starting goalie observation from a newly observed G1', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026010115, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-20 23:00:00 UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    Player::query()->create([
        'nhl_id' => 8480001, 'first_name' => 'Goalie', 'last_name' => '1',
        'full_name' => 'Goalie 1', 'position' => 'G', 'team_abbrev' => 'TOR',
        'current_league_abbrev' => 'NHL',
    ]);
    $candidate = lineupCandidate('goalie_reporter', 'https://x.com/goalie_reporter/status/1');
    $candidate['published_at'] = '2026-09-19T19:00:00-04:00';
    Http::fake([
        'api-web.nhle.com/*' => Http::response([]),
        'api.x.com/*' => Http::response(xLineupResponse([$candidate])),
    ]);

    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);
    app(NhlAnticipatedLineupImporter::class)->import($game, 'TOR', 10);

    $this->assertDatabaseCount('nhl_starting_goalie_observations', 1)
        ->assertDatabaseHas('nhl_starting_goalie_observations', [
            'nhl_game_id' => 2026010115,
            'team_abbrev' => 'TOR',
            'opponent_abbrev' => 'MTL',
            'is_home' => true,
            'nhl_player_id' => 8480001,
            'player_name' => 'Goalie 1',
            'provider' => 'public_lineup',
            'status' => 'expected',
            'source_url' => 'https://x.com/goalie_reporter/status/1',
        ]);
    $goalie = NhlStartingGoalieObservation::query()->firstOrFail();
    expect($goalie->provider_published_at?->equalTo(Carbon::parse('2026-09-19T19:00:00-04:00')))->toBeTrue()
        ->and(data_get($goalie->raw_evidence, 'backup.player_name'))->toBe('Goalie 2');
});

it('keeps confirmed goalie evidence ahead of newer lineup-derived expectations', function (): void {
    createGoalieObservation([
        'nhl_game_id' => 2026020008, 'game_date' => '2026-09-20',
        'player_name' => 'Confirmed Goalie', 'status' => 'confirmed',
        'provider' => 'rotowire', 'fetched_at' => Carbon::parse('2026-09-20 12:00:00 UTC'),
    ]);
    createGoalieObservation([
        'nhl_game_id' => 2026020008, 'game_date' => '2026-09-20',
        'player_name' => 'Expected Goalie', 'status' => 'expected',
        'provider' => 'public_lineup', 'fetched_at' => Carbon::parse('2026-09-20 13:00:00 UTC'),
    ]);

    $this->getJson('/starting-goalies/payload?date=2026-09-20')
        ->assertOk()
        ->assertJsonCount(1, 'starting_goalies')
        ->assertJsonPath('starting_goalies.0.player_name', 'Confirmed Goalie')
        ->assertJsonPath('starting_goalies.0.status', 'confirmed');
});

it('shows every scheduled game on the public lineups page for today', function (): void {
    $this->travelTo(Carbon::parse('2026-09-19 14:00:00 UTC'));
    foreach ([2026010201, 2026010202] as $gameId) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
            'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL',
            'home_team_abbrev' => 'TOR',
        ]);
    }

    $this->get(route('lineups.index'))
        ->assertOk()
        ->assertSee('NHL Lineups')
        ->assertSee(route('lineups.show', ['nhlGameId' => 2026010201]), false)
        ->assertSee(route('lineups.show', ['nhlGameId' => 2026010202]), false);

    $this->travelBack();
});

it('filters the public lineups page by an explicit date', function (): void {
    foreach ([['2026-09-19', 2026010203], ['2026-09-20', 2026010204]] as [$date, $gameId]) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => $date, 'game_dow' => 'Sunday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse($date)->addHours(23), 'away_team_abbrev' => 'DAL',
            'home_team_abbrev' => 'STL',
        ]);
    }

    $this->get(route('lineups.index', ['date' => '2026-09-20']))
        ->assertOk()
        ->assertSee('Sunday, September 20, 2026')
        ->assertSee('#2026010204')
        ->assertDontSee('#2026010203');
});

it('validates the public lineups date filter', function (): void {
    $this->get(route('lineups.index', ['date' => 'September-19']))
        ->assertRedirect()
        ->assertSessionHasErrors('date');
});

it('shows a calm empty state when no games are scheduled for a lineup date', function (): void {
    $this->get(route('lineups.index', ['date' => '2026-09-30']))
        ->assertOk()
        ->assertSee('No NHL games are scheduled for this date.')
        ->assertSee('Return to today');
});

it('shows independent not reported statuses for teams without current lineups', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010205, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-19 23:00:00 UTC'),
        'away_team_abbrev' => 'DAL', 'home_team_abbrev' => 'STL',
    ]);

    $this->get(route('lineups.index', ['date' => '2026-09-19']))
        ->assertOk()
        ->assertSeeTextInOrder(['Away · DAL', 'Not Reported', 'Home · STL', 'Not Reported']);
});

it('shows each team current lineup status independently on a game card', function (): void {
    $current = createCurrentAnticipatedLineup(['evidence_status' => 'reported', 'source_count' => 1]);
    $source = EvidenceSource::query()->create([
        'platform' => 'x', 'name' => 'Montreal Reporter', 'handle' => 'mtlreporter',
        'canonical_url' => 'https://x.com/mtlreporter', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $observation = NhlLineupObservation::query()->create([
        'nhl_game_id' => $current->nhl_game_id, 'team_id' => 8, 'team_abbrev' => 'MTL',
        'source_id' => $source->id, 'post_url' => 'https://x.com/mtlreporter/status/2',
        'post_text' => 'Montreal lines', 'provider_published_at' => now(), 'observed_at' => now(),
        'completeness' => 'full', 'structure_hash' => str_repeat('b', 64), 'raw_evidence' => [],
    ]);
    NhlCurrentLineup::query()->create([
        'nhl_game_id' => $current->nhl_game_id, 'team_id' => 8, 'team_abbrev' => 'MTL',
        'nhl_lineup_observation_id' => $observation->id, 'structure_hash' => str_repeat('b', 64),
        'evidence_status' => 'strongly_corroborated', 'source_count' => 3,
        'first_observed_at' => now()->subHour(), 'last_observed_at' => now(),
    ]);

    $this->get(route('lineups.index', ['date' => today()->toDateString()]))
        ->assertOk()
        ->assertSeeTextInOrder(['Away · MTL', 'Strongly Corroborated', 'Home · TOR', 'Reported']);
});

it('groups current players and exposes supporting sources on lineup detail', function (): void {
    $current = createCurrentAnticipatedLineup();
    $current->observation->players()->createMany([
        [
            'player_id' => null, 'nhl_player_id' => 8470002, 'player_name' => 'Second Forward',
            'lineup_role' => 'forward', 'line_key' => 'F1', 'slot_index' => 2,
            'resolution_status' => 'unresolved',
        ],
        [
            'player_id' => null, 'nhl_player_id' => 8470003, 'player_name' => 'First Defender',
            'lineup_role' => 'defense', 'line_key' => 'D1', 'slot_index' => 1,
            'resolution_status' => 'resolved',
        ],
        [
            'player_id' => null, 'nhl_player_id' => 8470004, 'player_name' => 'Starting Goalie',
            'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => 1,
            'resolution_status' => 'resolved',
        ],
        [
            'player_id' => null, 'nhl_player_id' => 8470005, 'player_name' => 'Healthy Scratch',
            'lineup_role' => 'scratch', 'line_key' => 'SCR', 'slot_index' => 1,
            'resolution_status' => 'resolved',
        ],
    ]);

    $this->get(route('lineups.show', ['nhlGameId' => $current->nhl_game_id]))
        ->assertOk()
        ->assertSee('F1')
        ->assertSee('Second Forward')
        ->assertSee('Unresolved')
        ->assertSee('D1')
        ->assertSee('First Defender')
        ->assertSee('Starting Goalie')
        ->assertSee('Healthy Scratch')
        ->assertSee('No anticipated lineup has been reported for MTL.')
        ->assertSee('https://x.com/testreporter/status/1', false);
});

it('returns not found for an unknown public lineup game', function (): void {
    $this->get(route('lineups.show', ['nhlGameId' => 2999999999]))->assertNotFound();
});
