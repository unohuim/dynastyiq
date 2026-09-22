<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\AdminImportSchedule;
use App\Events\ImportStreamEvent;
use App\Models\EvidenceSource;
use App\Models\ImportRun;
use App\Models\NhlGame;
use App\Models\NhlCurrentLineup;
use App\Models\NhlLineupObservation;
use App\Models\NhlPlayerInjury;
use App\Models\NhlStartingGoalieObservation;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
use App\Services\NhlInjuryImporter;
use App\Services\NhlAnticipatedLineupImporter;
use App\Services\AdminImportSchedules;
use App\Services\NhlStartingGoalieImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
});

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
    foreach (range(1, 18) as $index) {
        $forward = $index <= 12;
        $offset = $forward ? $index - 1 : $index - 13;
        $size = $forward ? 3 : 2;
        $player = Player::query()->create([
            'nhl_id' => 8470000 + $index, 'full_name' => 'Test Player ' . $index,
            'first_name' => 'Test', 'last_name' => 'Player ' . $index,
            'position' => $forward ? 'C' : 'D', 'team_abbrev' => 'TOR',
        ]);
        $observation->players()->create([
            'player_id' => $player->id, 'nhl_player_id' => $player->nhl_id,
            'player_name' => $player->full_name, 'lineup_role' => $forward ? 'forward' : 'defense',
            'line_key' => ($forward ? 'F' : 'D') . ((int) floor($offset / $size) + 1),
            'slot_index' => ($offset % $size) + 1, 'resolution_status' => 'resolved',
        ]);
    }

    return NhlCurrentLineup::query()->create(array_merge([
        'nhl_game_id' => $game->nhl_game_id, 'team_id' => 10, 'team_abbrev' => 'TOR',
        'nhl_lineup_observation_id' => $observation->id, 'structure_hash' => str_repeat('a', 64),
        'evidence_status' => 'corroborated', 'source_count' => 2,
        'first_observed_at' => now()->subMinute(), 'last_observed_at' => now(),
    ], $overrides));
}

function createTimelineSource(
    string $teamAbbrev = 'TOR',
    int $teamId = 10,
    string $handle = 'timeline_test_source',
    string $platformUserId = 'timeline-test-user'
): int {
    DB::table('sources')->updateOrInsert(
        ['platform' => 'x', 'handle' => $handle],
        [
            'name' => str($handle)->replace('_', ' ')->title()->toString(),
            'platform_user_id' => $platformUserId,
            'canonical_url' => 'https://x.com/' . $handle,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    $sourceId = (int) DB::table('sources')
        ->where('platform', 'x')
        ->where('handle', $handle)
        ->value('id');
    DB::table('source_scopes')->updateOrInsert(
        ['source_id' => $sourceId, 'sport' => 'hockey', 'league' => 'NHL', 'team_id' => $teamId],
        ['team_abbrev' => $teamAbbrev, 'created_at' => now(), 'updated_at' => now()]
    );

    return $sourceId;
}

function xLineupResponse(array $observations): array
{
    \App\Models\NhlTeam::query()->updateOrCreate(
        ['abbrev' => 'TOR'],
        ['nhl_id' => 10, 'common_name' => 'Maple Leafs']
    );
    createTimelineSource();
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

it('uses verified identities consistently for reporting predictions and discovery skips', function (string $scenario, bool $reportable): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00 America/Toronto'));
    $current = createCurrentAnticipatedLineup(['evidence_status' => 'reported', 'source_count' => 1]);
    $players = $current->observation->players();
    if (in_array($scenario, ['F1', 'D1', 'F4', 'D3', 'whole F4', 'whole D3'], true)) {
        $line = str_replace('whole ', '', $scenario);
        $players->where('line_key', $line)
            ->when(! str_starts_with($scenario, 'whole '), fn ($query) => $query->where('slot_index', 1))
            ->update(['player_id' => null, 'nhl_player_id' => null, 'resolution_status' => 'unresolved']);
    } elseif ($scenario === 'duplicate') {
        $first = $players->where('line_key', 'D3')->where('slot_index', 1)->first();
        $current->observation->players()->where('line_key', 'D3')->where('slot_index', 2)
            ->update(['player_id' => $first->player_id, 'nhl_player_id' => $first->nhl_player_id]);
    } elseif ($scenario === 'goalie as defense') {
        Player::query()->where('nhl_id', 8470017)->update(['position' => 'G']);
    } elseif ($scenario === 'unknown canonical id') {
        $players->where('line_key', 'F1')->where('slot_index', 1)
            ->update(['player_id' => null, 'nhl_player_id' => 9999999]);
    }
    $rows = $current->observation->players()->get()->toArray();
    expect($current->fresh()->hasVerifiedPlayers())->toBe($reportable);
    $predictionGate = new ReflectionMethod(\App\Services\NhlGamePredictionPayload::class, 'resolvedSkaterIds');
    expect($predictionGate->invoke(app(\App\Services\NhlGamePredictionPayload::class), ['players' => $rows]) !== null)
        ->toBe($reportable);

    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020099')
        ->assertOk()->assertJsonCount($reportable ? 1 : 0, 'anticipated_lineups');
    $this->get(route('games.show', ['nhlGameId' => 2026020099]))
        ->assertOk()->assertInertia(fn (Assert $page) => $reportable
            ? $page->where('game.home.lineup.evidence_status', 'reported')
            : $page->where('game.home.lineup', null));

    $importer = Mockery::mock(NhlAnticipatedLineupImporter::class);
    if ($reportable) {
        $importer->shouldNotReceive('importOfficial');
    } else {
        $importer->shouldReceive('importOfficial')->once()->andReturn(['available' => true, 'observed' => 0]);
    }
    $importer->shouldNotReceive('importFromX');
    (new ImportNhlAnticipatedLineupTeamJob(2026020099, 'TOR', 10))->handle($importer);
    $this->travelBack();
})->with([
    'verified lineup' => ['valid', true],
    'unknown first-line forward' => ['F1', false],
    'unknown first-pair defender' => ['D1', false],
    'depth forward fallback' => ['F4', true],
    'depth defender fallback' => ['D3', true],
    'no fourth-line peer' => ['whole F4', false],
    'no third-pair peer' => ['whole D3', false],
    'duplicate depth identity' => ['duplicate', false],
    'goalie is not a defender' => ['goalie as defense', false],
    'unverified NHL id' => ['unknown canonical id', false],
]);

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
        ->assertJsonPath('anticipated_lineups.0.players.0.nhl_player_id', 8470013)
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

it('stops source timeline reads after the first accepted lineup', function (): void {
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
        ->and($requests[0][0]->url())->toContain('/2/users/timeline-test-user/tweets')
        ->and((int) $requests[0][0]['max_results'])->toBe(5);
    $this->assertDatabaseHas('nhl_current_lineups', [
        'nhl_game_id' => 2026020196,
        'team_id' => 10,
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);
});

it('reads stored team scoped X source timelines', function (): void {
    config([
        'services.x.bearer_token' => 'test-key',
        'services.x.post_read_cost_usd' => 0.005,
    ]);
    $run = \App\Models\ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'started_at' => now(), 'ran_at' => now(),
    ]);
    $otherRun = \App\Models\ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'started_at' => now()->subMinute(), 'ran_at' => now()->subMinute(),
    ]);
    $sourceId = DB::table('sources')->insertGetId([
        'platform' => 'x',
        'name' => 'Toronto Maple Leafs',
        'handle' => 'MapleLeafs',
        'platform_user_id' => '12345',
        'canonical_url' => 'https://x.com/MapleLeafs',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('source_scopes')->insert([
        'source_id' => $sourceId,
        'sport' => 'hockey',
        'league' => 'NHL',
        'team_id' => 10,
        'team_abbrev' => 'TOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026020197, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => today(), 'game_dow' => today()->format('l'), 'game_month' => today()->format('F'),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $response = xLineupResponse([
        lineupCandidate('MapleLeafs', 'https://x.com/MapleLeafs/status/1'),
    ]);
    $response['data'][0]['author_id'] = '12345';
    Http::fake(['api.x.com/*' => Http::response($response)]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR', (string) $run->id);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(1)
        ->and($requests[0][0]->url())->toContain('/2/users/12345/tweets')
        ->and((int) $requests[0][0]['max_results'])->toBe(5)
        ->and($requests[0][0]['start_time'])->not->toBeNull()
        ->and($candidates)->toHaveCount(1);
    expect($run->refresh()->meta)
        ->toMatchArray([
            'x_posts_viewed' => 1,
            'x_post_read_cost_usd' => 0.005,
        ]);
    expect((float) $run->estimated_cost_usd)->toBe(0.005);
    $usage = DB::table('integration_api_usage_logs')->latest('id')->first();
    expect(data_get(json_decode((string) $usage->metadata, true), 'import_run_id'))->toBe($run->id);

    app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR', (string) $run->id);
    expect($run->refresh()->meta)
        ->toMatchArray(['x_posts_viewed' => 2])
        ->and((float) $run->estimated_cost_usd)->toBe(0.01)
        ->and($otherRun->refresh()->meta)->toBeNull();
});

it('resolves and caches an X user id before reading a stored source timeline', function (): void {
    config([
        'services.x.bearer_token' => 'test-key',
        'services.x.post_read_cost_usd' => 0.005,
        'services.x.user_read_cost_usd' => 0.01,
    ]);
    $run = \App\Models\ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'started_at' => now(), 'ran_at' => now(),
    ]);
    $sourceId = DB::table('sources')->insertGetId([
        'platform' => 'x', 'name' => 'Timeline Reporter', 'handle' => 'timeline_reporter',
        'canonical_url' => 'https://x.com/timeline_reporter', 'first_seen_at' => now(),
        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('source_scopes')->insert([
        'source_id' => $sourceId, 'sport' => 'hockey', 'league' => 'NHL',
        'team_id' => 10, 'team_abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $game = (object) [
        'nhl_game_id' => 2026020199, 'game_date' => today()->toDateString(),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $timeline = xLineupResponse([
        lineupCandidate('timeline_reporter', 'https://x.com/timeline_reporter/status/15'),
    ]);
    $timeline['data'][0]['author_id'] = '9988';
    Http::fake([
        'api.x.com/2/users/by/username/*' => Http::response([
            'data' => ['id' => '9988', 'name' => 'Timeline Reporter', 'username' => 'timeline_reporter'],
        ]),
        'api.x.com/2/users/9988/tweets*' => Http::response($timeline),
    ]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR', (string) $run->id);

    expect($candidates)->toHaveCount(1)
        ->and(DB::table('sources')->where('id', $sourceId)->value('platform_user_id'))->toBe('9988')
        ->and($run->refresh()->meta)->toMatchArray([
            'x_posts_viewed' => 1,
            'x_user_reads' => 1,
        ])
        ->and((float) $run->estimated_cost_usd)->toBe(0.015);
    Http::assertSentCount(2);
});

it('seeds candidate X timeline sources for the first four configured teams', function (): void {
    foreach ([
        'CAR' => ['Canes', 'RyanHenkel_', 'WaltRuff'],
        'FLA' => ['FlaPanthers', 'JamesonCoop', 'GeorgeRichards'],
        'UTA' => ['utahmammoth', 'UtahMammoth_PR', 'houston_brogan'],
        'COL' => ['Avalanche', 'evanrawal', 'DNVR_Avalanche'],
    ] as $teamAbbrev => $handles) {
        $stored = DB::table('sources')
            ->join('source_scopes', 'source_scopes.source_id', '=', 'sources.id')
            ->where('source_scopes.team_abbrev', $teamAbbrev)
            ->whereIn('sources.handle', $handles)
            ->pluck('sources.handle')
            ->all();

        expect($stored)->toEqualCanonicalizing($handles);
    }
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

it('writes approved and declined X post audits only for local troubleshooting', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    $originalEnvironment = app()->environment();
    app()['env'] = 'local';
    $written = [];
    File::shouldReceive('ensureDirectoryExists')
        ->times(3)
        ->with(base_path('docs/troubleshooting/lineups/TOR'));
    File::shouldReceive('put')->twice()->andReturnUsing(
        function (string $path, string $contents) use (&$written): int {
            $written[$path] = $contents;

            return strlen($contents);
        }
    );
    File::shouldReceive('append')->once()->andReturnUsing(
        function (string $path, string $contents) use (&$written): int {
            $written[$path] = $contents;

            return strlen($contents);
        }
    );
    $game = (object) [
        'nhl_game_id' => 2026020191, 'game_date' => today()->toDateString(),
        'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $response = xLineupResponse([
        lineupCandidate('audit_reporter', 'https://x.com/audit_reporter/status/10'),
    ]);
    array_unshift($response['data'], [
        'id' => '999',
        'text' => 'General hockey update with no player groups.',
        'author_id' => 'irrelevant-author',
        'created_at' => now()->toIso8601String(),
        'public_metrics' => [],
    ]);
    Http::fake(['api.x.com/*' => Http::response($response)]);

    try {
        $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');
    } finally {
        app()['env'] = $originalEnvironment;
    }

    expect($candidates)->toHaveCount(1)
        ->and($written)->toHaveKeys([
            base_path('docs/troubleshooting/lineups/TOR/x_post_999.md'),
            base_path('docs/troubleshooting/lineups/TOR/x_post_10.md'),
        ])
        ->and($written[base_path('docs/troubleshooting/lineups/TOR/x_post_999.md')])
        ->toContain('**Decision:** Declined', 'No target-team players were recognized')
        ->and($written[base_path('docs/troubleshooting/lineups/TOR/x_post_10.md')])
        ->toContain('**Decision:** Approved', '## Matched target-team players', '## Raw X post JSON')
        ->and($written[base_path('docs/troubleshooting/lineups/TOR/search.md')])
        ->toContain('- Timeline page: `timeline:@timeline_test_source posts 1-5`', '- Results returned: 2');
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

it('retains unresolved prospect names inside structurally complete lineup groups', function (): void {
    foreach ([
        [8484401, 'Known One', 'C'],
        [8484402, 'Known Two', 'LW'],
        [8484403, 'Known Three', 'C'],
        [8484404, 'Known Four', 'LW'],
        [8484405, 'Known Five', 'C'],
        [8484406, 'Known Six', 'LW'],
        [8484407, 'Known Seven', 'C'],
        [8484408, 'Known Eight', 'LW'],
        [8484409, 'Known Nine', 'D'],
        [8484410, 'Known Ten', 'D'],
        [8484411, 'Known Eleven', 'D'],
    ] as [$nhlId, $fullName, $position]) {
        [$firstName, $lastName] = explode(' ', $fullName, 2);
        Player::query()->create([
            'nhl_id' => $nhlId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'position' => $position,
            'team_abbrev' => 'CAR',
            'current_league_abbrev' => 'NHL',
        ]);
    }

    $players = app(\App\Services\NhlLineupTextParser::class)->parse(implode("\n", [
        'Known One-Known Two-Prospect Alpha',
        'Known Three-Known Four-Prospect Beta',
        'Known Five-Known Six-Prospect Gamma',
        'Known Seven-Known Eight-Prospect Delta',
        'Known Nine-Prospect Echo',
        'Known Ten-Prospect Foxtrot',
        'Known Eleven-Prospect Golf',
    ]), 'CAR');

    expect(collect($players)->where('lineup_role', 'forward'))->toHaveCount(12)
        ->and(collect($players)->where('lineup_role', 'defense'))->toHaveCount(6)
        ->and(collect($players)->pluck('name'))->toContain(
            'Prospect Alpha',
            'Prospect Echo',
            'Prospect Golf'
        );
});

it('recognizes apostrophe variants in reported player names', function (string $reportedSurname): void {
    foreach ([
        [8484301, 'Conor', 'Geekie'],
        [8484302, 'Ryan', "O'Reilly"],
        [8484303, 'Benjamin', 'Rautiainen'],
    ] as [$nhlId, $firstName, $lastName]) {
        Player::query()->create([
            'nhl_id' => $nhlId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $firstName . ' ' . $lastName,
            'position' => 'C',
            'team_abbrev' => 'TBL',
            'current_league_abbrev' => 'NHL',
        ]);
    }

    $players = app(\App\Services\NhlLineupTextParser::class)->parse(
        "Geekie-{$reportedSurname}-Rautiainen",
        'TBL'
    );

    expect($players)->toHaveCount(3)
        ->and(collect($players)->pluck('name')->all())
        ->toBe(['Conor Geekie', "Ryan O'Reilly", 'Benjamin Rautiainen']);
})->with([
    'straight apostrophe' => "O'Reilly",
    'curly apostrophe' => 'O’Reilly',
    'backtick' => 'O`Reilly',
    'no apostrophe' => 'OReilly',
]);

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

it('uses reported first initials to distinguish same-team players with the same surname', function (): void {
    foreach ([
        [8479314, 'Matthew', 'Tkachuk'],
        [8480801, 'Brady', 'Tkachuk'],
    ] as [$nhlId, $firstName, $lastName]) {
        Player::query()->create([
            'nhl_id' => $nhlId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $firstName . ' ' . $lastName,
            'position' => 'L',
            'team_abbrev' => 'FLA',
            'current_league_abbrev' => 'NHL',
        ]);
    }

    $players = app(\App\Services\NhlLineupTextParser::class)->parse(implode("\n", [
        'Forwards',
        'B. Tkachuk - Center One - Wing One',
        'Wing Two - Center Two - M. Tkachuk',
    ]), 'FLA');

    expect(collect($players)->pluck('name')->all())->toBe([
        'Brady Tkachuk', 'Center One', 'Wing One',
        'Wing Two', 'Center Two', 'Matthew Tkachuk',
    ]);
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

it('does not impose an application daily timeline request limit', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('integration_api_usage_logs')->insert([
        'provider' => 'x', 'operation' => 'nhl_lineup_source_timeline',
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

it('queues all teams and passes the near-puck-drop window to each job', function (): void {
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

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 6);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010100
        && $job->teamAbbrev === 'MTL'
        && $job->queue === 'lineups');
    expect(Queue::pushed(ImportNhlAnticipatedLineupTeamJob::class)
        ->every(fn ($job): bool => $job->window === 'within-two-hours'))->toBeTrue();
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010100
        && $job->teamAbbrev === 'TOR'
        && $job->queue === 'lineups');
    $this->travelBack();
});

it('writes a fresh local import manifest and resets eligible team search files', function (): void {
    Queue::fake();
    $originalEnvironment = app()->environment();
    app()['env'] = 'local';
    $written = [];
    $auditDirectory = base_path('docs/troubleshooting/lineups');
    $staleFiles = [
        $auditDirectory . '/import_old.md',
        $auditDirectory . '/TOR/search.md',
        $auditDirectory . '/TOR/x_post_old.md',
    ];
    File::shouldReceive('ensureDirectoryExists')->times(3);
    File::shouldReceive('allFiles')->once()->with($auditDirectory)->andReturn([
        new \SplFileInfo($auditDirectory . '/README.md'),
        ...array_map(fn (string $path): \SplFileInfo => new \SplFileInfo($path), $staleFiles),
        new \SplFileInfo($auditDirectory . '/keep.txt'),
    ]);
    File::shouldReceive('delete')->once()->with($staleFiles)->andReturnTrue();
    File::shouldReceive('put')->times(3)->andReturnUsing(
        function (string $path, string $contents) use (&$written): int {
            $written[$path] = $contents;

            return strlen($contents);
        }
    );
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010120, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => today('America/Toronto'), 'game_dow' => today()->format('l'),
        'game_month' => today()->format('F'), 'start_time_utc' => now()->addHours(4),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);

    try {
        Artisan::call('nhl:import-anticipated-lineups');
    } finally {
        app()['env'] = $originalEnvironment;
    }

    $importPath = collect(array_keys($written))->first(
        fn (string $path): bool => preg_match('/\/import_\d{8}_\d{6}_\d{6}\.md$/', $path) === 1
    );
    expect($importPath)->not->toBeNull()
        ->and($written[$importPath])->toContain(
            '- Eligible team jobs: 2',
            '`MTL` vs `TOR`',
            '`TOR` vs `MTL`',
            '**dispatched**'
        )
        ->and($written[base_path('docs/troubleshooting/lineups/MTL/search.md')])
        ->toContain('# MTL lineup searches', '- Import audit: `../import_')
        ->and($written[base_path('docs/troubleshooting/lineups/TOR/search.md')])
        ->toContain('# TOR lineup searches', '- Import audit: `../import_');
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

it('queues both teams even when one already has a reported lineup', function (): void {
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

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 2);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026020099
        && $job->teamAbbrev === 'MTL');
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026020099
        && $job->teamAbbrev === 'TOR');
});

it('dispatches but skips discovery for a verified lineup with an unresolved depth player', function (): void {
    Queue::fake();
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $current = createCurrentAnticipatedLineup([
        'evidence_status' => 'reported',
        'source_count' => 1,
    ]);
    $current->observation->players()->where('line_key', 'F4')->where('slot_index', 3)->update([
        'player_id' => null,
        'nhl_player_id' => null,
        'resolution_status' => 'unresolved',
    ]);

    Artisan::call('nhl:import-anticipated-lineups');

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 2);
    $job = Queue::pushed(ImportNhlAnticipatedLineupTeamJob::class)
        ->first(fn ($job): bool => $job->teamAbbrev === 'TOR');
    $job->handle(app(NhlAnticipatedLineupImporter::class));
    Http::assertNothingSent();
});

it('skips both NHL and X discovery when a complete current lineup exists', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    createCurrentAnticipatedLineup();
    Http::fake();

    $job = new ImportNhlAnticipatedLineupTeamJob(2026020099, 'TOR', 10);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    Http::assertNothingSent();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.com'));
});

it('records a terminal queue failure and closes its lineup import run', function (): void {
    $run = ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'command' => 'nhl:import-anticipated-lineups', 'total_records' => 1,
        'processed_records' => 0, 'successful_records' => 0,
        'failed_records' => 0, 'skipped_records' => 0, 'started_at' => now(),
    ]);
    $job = new ImportNhlAnticipatedLineupTeamJob(2026020999, 'TOR', 10, $run->id);

    $job->failed(new \RuntimeException('Lineup worker timed out.'));

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->processed_records)->toBe(1)
        ->and($run->failed_records)->toBe(1)
        ->and($run->error_message)->toBe('Lineup worker timed out.');
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

it('does not revisit a complete lineup while the opponent remains missing', function (): void {
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

    Http::assertNothingSent();
});

it('queues today and tomorrow with the outside window for jobs to evaluate', function (): void {
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

    Queue::assertPushed(ImportNhlAnticipatedLineupTeamJob::class, 4);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010117);
    Queue::assertPushed(fn (ImportNhlAnticipatedLineupTeamJob $job): bool => $job->nhlGameId === 2026010116
        && $job->window === 'outside-two-hours');
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

it('evaluates the discovery window inside the team job before contacting providers', function (
    string $date,
    string $start,
    string $window,
    bool $eligible
): void {
    $this->travelTo(Carbon::parse('2026-09-19 16:00:00 UTC'));
    config(['services.x.bearer_token' => 'test-key']);
    Http::fake(['*' => Http::response([])]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010120, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => $date, 'game_dow' => 'Saturday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse($start, 'UTC'),
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $run = ImportRun::query()->create([
        'source' => 'nhl-anticipated-lineups', 'status' => 'working',
        'command' => 'nhl:import-anticipated-lineups', 'total_records' => 1,
        'processed_records' => 0, 'successful_records' => 0,
        'failed_records' => 0, 'skipped_records' => 0, 'started_at' => now(),
    ]);

    $job = new ImportNhlAnticipatedLineupTeamJob(2026010120, 'TOR', 10, $run->id, $window);
    $job->handle(app(NhlAnticipatedLineupImporter::class));

    if ($eligible) {
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api-web.nhle.com'));
    } else {
        Http::assertNothingSent();
    }
    expect($run->fresh()->processed_records)->toBe(1)
        ->and($run->fresh()->skipped_records)->toBe(1)
        ->and($run->fresh()->status)->toBe('completed');
    $this->travelBack();
})->with([
    'near game' => ['2026-09-19', '2026-09-19 17:00:00', 'within-two-hours', true],
    'boundary' => ['2026-09-19', '2026-09-19 18:00:00', 'within-two-hours', true],
    'started today' => ['2026-09-19', '2026-09-19 15:00:00', 'within-two-hours', true],
    'too early' => ['2026-09-19', '2026-09-19 21:00:00', 'within-two-hours', false],
    'tomorrow not near' => ['2026-09-20', '2026-09-20 17:00:00', 'within-two-hours', false],
    'outside excludes near' => ['2026-09-19', '2026-09-19 17:00:00', 'outside-two-hours', false],
    'outside tomorrow' => ['2026-09-20', '2026-09-20 17:00:00', 'outside-two-hours', true],
    'manual today' => ['2026-09-19', '2026-09-19 17:00:00', 'all', true],
    'manual tomorrow' => ['2026-09-20', '2026-09-20 17:00:00', 'all', true],
    'stale queued game' => ['2026-09-18', '2026-09-18 17:00:00', 'all', false],
]);

it('caps every source at six five-post pages even when pagination continues', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 16:00:00 UTC'));
    config(['services.x.bearer_token' => 'test-key']);
    createTimelineSource('TOR', 10, 'source_one', 'source-1');
    createTimelineSource('TOR', 10, 'source_two', 'source-2');
    $game = (object) [
        'nhl_game_id' => 2026010120, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $requests = 0;
    Http::fake(function ($request) use (&$requests) {
        $requests++;

        return Http::response([
            'data' => array_map(fn (int $index): array => [
                'id' => (string) ($requests * 10 + $index),
                'text' => 'General team update.',
                'created_at' => '2026-09-20T12:00:00Z',
            ], range(1, 5)),
            'meta' => ['next_token' => 'page-' . $requests],
        ]);
    });

    expect(app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR'))->toBe([]);
    Http::assertSentCount(12);
    foreach (['source-1', 'source-2'] as $sourceId) {
        expect(Http::recorded()->filter(fn (array $record): bool =>
            str_contains($record[0]->url(), '/users/' . $sourceId . '/tweets')
            && (int) $record[0]['max_results'] === 5))->toHaveCount(6);
    }
    $this->travelBack();
});

it('reads source timelines round robin in five-post pages', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    createTimelineSource('TOR', 10, 'source_one', 'source-1');
    createTimelineSource('TOR', 10, 'source_two', 'source-2');
    $game = (object) [
        'nhl_game_id' => 2026010120, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $irrelevant = [[
        'id' => '991',
        'text' => 'General team update.',
        'author_id' => 'source-1',
        'created_at' => now()->toIso8601String(),
        'public_metrics' => [],
    ]];
    $lineup = xLineupResponse([
        lineupCandidate('source_one', 'https://x.com/source_one/status/992'),
    ]);
    $lineup['data'][0]['author_id'] = 'source-1';
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push(['data' => $irrelevant, 'meta' => ['next_token' => 'source-1-page-2']])
            ->push(['data' => [], 'meta' => ['next_token' => 'source-2-page-2']])
            ->push($lineup),
    ]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    $requests = Http::recorded()
        ->map(fn (array $record): array => [
            $record[0]->url(),
            $record[0]['pagination_token'],
            (int) $record[0]['max_results'],
        ])
        ->all();
    expect($requests)->toBe([
        ['https://api.x.com/2/users/source-1/tweets', null, 5],
        ['https://api.x.com/2/users/source-2/tweets', null, 5],
        ['https://api.x.com/2/users/source-1/tweets', 'source-1-page-2', 5],
    ])
        ->and($candidates)->toHaveCount(1)
        ->and($candidates[0]['source_handle'])->toBe('source_one');
});

it('rejects timeline posts older than the day before the target game', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    createTimelineSource('TOR', 10, 'window_reporter', 'window-source');
    $game = (object) [
        'nhl_game_id' => 2026010125, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $old = xLineupResponse([
        lineupCandidate('window_reporter', 'https://x.com/window_reporter/status/995'),
    ]);
    $old['data'][0]['author_id'] = 'window-source';
    $old['data'][0]['created_at'] = '2026-09-18T23:59:59Z';
    $old['meta'] = ['next_token' => 'next-five'];
    $eligible = xLineupResponse([
        lineupCandidate('window_reporter', 'https://x.com/window_reporter/status/996'),
    ]);
    $eligible['data'][0]['author_id'] = 'window-source';
    $eligible['data'][0]['created_at'] = '2026-09-19T04:00:00Z';
    Http::fake(['api.x.com/*' => Http::sequence()->push($old)->push($eligible)]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    expect(Http::recorded())->toHaveCount(2)
        ->and($candidates)->toHaveCount(1)
        ->and($candidates[0]['post_url'])->toBe('https://x.com/window_reporter/status/996');
});

it('declines a partial lineup and continues to the next stored source', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    Event::fake([ImportStreamEvent::class]);
    createTimelineSource('TOR', 10, 'partial_reporter', 'partial-source');
    createTimelineSource('TOR', 10, 'full_reporter', 'full-source');
    $game = (object) [
        'nhl_game_id' => 2026010124, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ];
    $partial = xLineupResponse([
        lineupCandidate('partial_reporter', 'https://x.com/partial_reporter/status/993'),
    ]);
    $partial['data'][0]['text'] = "Defense\nDefense 1 - Defense 2";
    $partial['data'][0]['author_id'] = 'partial-source';
    $full = xLineupResponse([
        lineupCandidate('full_reporter', 'https://x.com/full_reporter/status/994'),
    ]);
    $full['data'][0]['author_id'] = 'full-source';
    Http::fake([
        'api.x.com/*' => Http::sequence()
            ->push($partial)
            ->push($full),
    ]);

    $candidates = app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'TOR');

    expect(Http::recorded())->toHaveCount(2)
        ->and($candidates)->toHaveCount(1)
        ->and($candidates[0]['source_handle'])->toBe('full_reporter');
    Event::assertDispatched(ImportStreamEvent::class, fn (ImportStreamEvent $event): bool =>
        $event->source === 'nhl-anticipated-lineups'
        && $event->message === 'TOR | @partial_reporter | posts 1-5');
});

it('combines complete forward and defense posts into one current lineup', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    createTimelineSource('TOR', 10, 'forward_reporter', 'forward-source');
    createTimelineSource('TOR', 10, 'defense_reporter', 'defense-source');
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026010126, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $forwardResponse = xLineupResponse([
        lineupCandidate('forward_reporter', 'https://x.com/forward_reporter/status/997'),
    ]);
    $forwardResponse['data'][0]['text'] = implode("\n", [
        'Forwards',
        'Forward 1 - Forward 2 - Forward 3',
        'Forward 4 - Forward 5 - Forward 6',
        'Forward 7 - Forward 8 - Forward 9',
        'Forward 10 - Forward 11 - Forward 12',
    ]);
    $forwardResponse['data'][0]['author_id'] = 'forward-source';
    $defenseResponse = xLineupResponse([
        lineupCandidate('defense_reporter', 'https://x.com/defense_reporter/status/998'),
    ]);
    $defenseResponse['data'][0]['text'] = implode("\n", [
        'Defense',
        'Defense 1 - Defense 2',
        'Defense 3 - Defense 4',
        'Defense 5 - Defense 6',
    ]);
    $defenseResponse['data'][0]['author_id'] = 'defense-source';
    Http::fake(['api.x.com/*' => Http::sequence()->push($forwardResponse)->push($defenseResponse)]);

    $result = app(NhlAnticipatedLineupImporter::class)->importFromX($game, 'TOR', 10);
    $payload = app(\App\Services\NhlAnticipatedLineupPayload::class)
        ->forGameTeam(2026010126, 'TOR', false);

    expect($result['observed'])->toBe(2)
        ->and($payload)->not->toBeNull()
        ->and($payload['players'])->toHaveCount(18)
        ->and(collect($payload['players'])->where('lineup_role', 'forward'))->toHaveCount(12)
        ->and(collect($payload['players'])->where('lineup_role', 'defense'))->toHaveCount(6)
        ->and($payload['sources'])->toHaveCount(2);
    $this->assertDatabaseHas('nhl_lineup_observations', ['completeness' => 'forwards'])
        ->assertDatabaseHas('nhl_lineup_observations', ['completeness' => 'defense'])
        ->assertDatabaseCount('nhl_current_lineup_components', 2)
        ->assertDatabaseHas('nhl_current_lineups', [
            'nhl_game_id' => 2026010126,
            'team_id' => 10,
            'evidence_status' => 'reported',
            'source_count' => 2,
        ]);
});

it('replaces only the current component supplied by a newer complete group', function (): void {
    $game = NhlGame::query()->create([
        'nhl_game_id' => 2026010127, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR',
    ]);
    $full = lineupCandidate('update_reporter', 'https://x.com/update_reporter/status/1001');
    $full['published_at'] = '2026-09-20T14:00:00Z';
    foreach ($full['players'] as $index => &$player) {
        $player['nhl_player_id'] = 8481000 + $index;
    }
    unset($player);
    $newForwards = lineupCandidate('update_reporter', 'https://x.com/update_reporter/status/1002');
    $newForwards['published_at'] = '2026-09-20T15:00:00Z';
    $newForwards['players'] = collect($newForwards['players'])->where('lineup_role', 'forward')
        ->values()->map(function (array $player, int $index): array {
            $player['name'] = 'Updated Forward ' . ($index + 1);
            $player['nhl_player_id'] = 8483000 + $index;

            return $player;
        })->all();
    $discovery = \Mockery::mock(\App\Services\XNhlLineupDiscovery::class);
    $discovery->shouldReceive('discover')->twice()->andReturn([$full], [$newForwards]);
    $importer = new NhlAnticipatedLineupImporter(
        app(\App\Services\NhlOfficialGameRosterDiscovery::class),
        $discovery,
        app(\App\Services\NhlLineupPlayerResolver::class)
    );

    $importer->importFromX($game, 'TOR', 10);
    $importer->importFromX($game, 'TOR', 10);
    $payload = app(\App\Services\NhlAnticipatedLineupPayload::class)
        ->forGameTeam(2026010127, 'TOR', false);

    expect(collect($payload['players'])->where('lineup_role', 'forward')->pluck('nhl_player_id')->first())
        ->toBe(8483000)
        ->and(collect($payload['players'])->where('lineup_role', 'defense')->pluck('nhl_player_id')->first())
        ->toBe(8481012);
    $this->assertDatabaseCount('nhl_current_lineup_components', 2);
});

it('does not call X when a team has no stored timeline sources', function (): void {
    config(['services.x.bearer_token' => 'test-key']);
    \App\Models\NhlTeam::query()->create(['nhl_id' => 55, 'abbrev' => 'NEW']);
    Http::fake();
    $game = (object) [
        'nhl_game_id' => 2026010119, 'game_date' => '2026-09-20',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'NEW', 'home_team_abbrev' => 'TOR',
    ];

    app(\App\Services\XNhlLineupDiscovery::class)->discover($game, 'NEW');

    Http::assertNothingSent();
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

it('does not expose a current lineup whose evidence predates the day-before-game window', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00 America/Toronto'));
    $current = createCurrentAnticipatedLineup();
    $current->observation()->update([
        'provider_published_at' => Carbon::parse('2026-09-18 23:59:59 America/Toronto'),
        'observed_at' => Carbon::parse('2026-09-20 10:00:00 America/Toronto'),
    ]);

    $payload = app(\App\Services\NhlAnticipatedLineupPayload::class)
        ->build(Carbon::parse('2026-09-20 America/Toronto'), 2026020099);

    expect($payload['anticipated_lineups'])->toHaveCount(0);
    $this->travelBack();
});

it('shows every scheduled game on the public games page for today', function (): void {
    $this->travelTo(Carbon::parse('2026-09-19 14:00:00 UTC'));
    foreach ([2026010201, 2026010202] as $gameId) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
            'start_time_utc' => now()->addHours(4), 'away_team_abbrev' => 'MTL',
            'home_team_abbrev' => 'TOR',
        ]);
    }

    $this->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Games/Index')
            ->where('canManageGameSync', false)
            ->where('gameSyncSchedule', null)
            ->has('initialPayload.games', 2)
            ->where('initialPayload.games.0.nhl_game_id', 2026010201)
            ->where('initialPayload.games.1.nhl_game_id', 2026010202));

    $this->travelBack();
});

it('filters the public games page by an explicit date', function (): void {
    foreach ([['2026-09-19', 2026010203], ['2026-09-20', 2026010204]] as [$date, $gameId]) {
        NhlGame::query()->create([
            'nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => $date, 'game_dow' => 'Sunday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse($date)->addHours(23), 'away_team_abbrev' => 'DAL',
            'home_team_abbrev' => 'STL',
        ]);
    }

    $this->get(route('games.index', ['date' => '2026-09-20']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Games/Index')
            ->where('initialPayload.meta.date', '2026-09-20')
            ->has('initialPayload.games', 1)
            ->where('initialPayload.games.0.nhl_game_id', 2026010204));
});

it('returns the public games payload for asynchronous date navigation', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010214, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'NYR',
    ]);

    $this->getJson(route('games.payload', ['date' => '2026-09-21']))
        ->assertOk()
        ->assertJsonPath('meta.date', '2026-09-21')
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('games.0.nhl_game_id', 2026010214)
        ->assertJsonPath('games.0.away.team_abbrev', 'BOS')
        ->assertJsonPath('games.0.home.team_abbrev', 'NYR');
});

it('shows stored scores when both team boxscores completed processing', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010215, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-20 23:00:00 UTC'),
        'away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'away_team_score' => 2,
        'home_team_id' => 3, 'home_team_abbrev' => 'NYR', 'home_team_score' => 4,
    ]);
    DB::table('nhl_import_progress')->insert([
        'season_id' => '20262027', 'game_date' => '2026-09-20', 'game_id' => '2026010215',
        'game_type' => 1, 'import_type' => 'boxscore', 'items_count' => 2, 'status' => 'completed',
        'discovered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_boxscores')->insert([
        [
            'nhl_game_id' => 2026010215, 'nhl_player_id' => 8471001, 'nhl_team_id' => 6,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026010215, 'nhl_player_id' => 8471002, 'nhl_team_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $this->getJson(route('games.payload', ['date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonPath('games.0.away.score', 2)
        ->assertJsonPath('games.0.home.score', 4);
    $this->get(route('games.index', ['date' => '2026-09-20']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPayload.games.0.away.score', 2)
            ->where('initialPayload.games.0.home.score', 4));
});

it('hides stored scores until boxscores for both teams complete processing', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010216, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-20 23:00:00 UTC'),
        'away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'away_team_score' => 2,
        'home_team_id' => 3, 'home_team_abbrev' => 'NYR', 'home_team_score' => 4,
    ]);
    DB::table('nhl_import_progress')->insert([
        'season_id' => '20262027', 'game_date' => '2026-09-20', 'game_id' => '2026010216',
        'game_type' => 1, 'import_type' => 'boxscore', 'items_count' => 1, 'status' => 'completed',
        'discovered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_boxscores')->insert([
        'nhl_game_id' => 2026010216, 'nhl_player_id' => 8471003, 'nhl_team_id' => 6,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson(route('games.payload', ['date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonPath('games.0.away.score', null)
        ->assertJsonPath('games.0.home.score', null);
});

it('requires a valid date for the public games payload', function (): void {
    $this->getJson(route('games.payload'))->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->getJson(route('games.payload', ['date' => 'September-21']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('validates the public games date filter', function (): void {
    $this->get(route('games.index', ['date' => 'September-19']))
        ->assertRedirect()
        ->assertSessionHasErrors('date');
});

it('shows a calm empty state when no games are scheduled for a lineup date', function (): void {
    $this->get(route('games.index', ['date' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Games/Index')
            ->where('initialPayload.meta.date', '2026-09-30')
            ->has('initialPayload.games', 0));
});

it('shows independent not reported statuses for teams without current lineups', function (): void {
    NhlGame::query()->create([
        'nhl_game_id' => 2026010205, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-19', 'game_dow' => 'Saturday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-19 23:00:00 UTC'),
        'away_team_abbrev' => 'DAL', 'home_team_abbrev' => 'STL',
    ]);

    $this->get(route('games.index', ['date' => '2026-09-19']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPayload.games.0.away.lineup', null)
            ->where('initialPayload.games.0.home.lineup', null));
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
    $observation->players()->createMany($current->observation->players->map(fn ($player): array =>
        collect($player->getAttributes())->except(['id', 'nhl_lineup_observation_id', 'created_at', 'updated_at'])->all()
    )->all());
    NhlCurrentLineup::query()->create([
        'nhl_game_id' => $current->nhl_game_id, 'team_id' => 8, 'team_abbrev' => 'MTL',
        'nhl_lineup_observation_id' => $observation->id, 'structure_hash' => str_repeat('b', 64),
        'evidence_status' => 'strongly_corroborated', 'source_count' => 3,
        'first_observed_at' => now()->subHour(), 'last_observed_at' => now(),
    ]);

    $this->get(route('games.index', ['date' => today()->toDateString()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPayload.games.0.away.lineup.evidence_status', 'strongly_corroborated')
            ->where('initialPayload.games.0.home.lineup.evidence_status', 'reported'));
});

it('groups current players and exposes supporting sources on lineup detail', function (): void {
    $current = createCurrentAnticipatedLineup();
    $current->observation->players()->createMany([
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

    $this->get(route('games.show', ['nhlGameId' => $current->nhl_game_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Games/Show')
            ->where('game.nhl_game_id', $current->nhl_game_id)
            ->where('game.home.lineup.players.0.player_name', 'Test Player 13')
            ->where('game.home.lineup.players.7.player_name', 'Test Player 2')
            ->where('game.home.lineup.players.18.player_name', 'Starting Goalie')
            ->where('game.home.lineup.players.19.player_name', 'Healthy Scratch')
            ->where('game.home.lineup.sources.0.post_url', 'https://x.com/testreporter/status/1')
            ->where('game.away.lineup', null));
});

it('restricts manual lineup submissions to super admins', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }
    $response = $this->postJson('/games/2026020099/lineup', ['team_abbrev' => 'TOR', 'text' => 'Test']);
    $response->assertStatus($signedIn ? 403 : 401);
    Http::assertNothingSent();
})->with([false, true]);

it('imports a super admin pasted lineup without provider requests and exposes it publicly', function (): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00 America/Toronto'));
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    DB::table('nhl_teams')->insert(['nhl_id' => 10, 'abbrev' => 'TOR']);
    $current = createCurrentAnticipatedLineup();
    $text = $current->observation->players->groupBy('line_key')
        ->map(fn ($group) => $group->pluck('player_name')->implode(' - '))->implode("\n");
    $current->observation->delete();

    $this->actingAs($user)->postJson('/games/2026020099/lineup', ['team_abbrev' => 'TOR', 'text' => $text])
        ->assertOk()->assertJsonPath('lineup.team_abbrev', 'TOR')
        ->assertJsonPath('lineup.evidence_status', 'reported')
        ->assertJsonPath('lineup.sources.0.platform', 'manual');
    Http::assertNothingSent();
    $this->assertDatabaseHas('sources', ['platform' => 'manual', 'handle' => 'user-' . $user->id]);
    $observation = NhlLineupObservation::query()->where('nhl_game_id', 2026020099)->firstOrFail();
    expect($observation->post_text)->toBe($text)
        ->and($observation->raw_evidence['submitted_by_user_id'])->toBe($user->id)
        ->and($observation->players()->count())->toBe(18);
    $this->withToken(availabilityToken())->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020099')
        ->assertOk()->assertJsonPath('anticipated_lineups.0.evidence_status', 'reported');
    $this->postJson('/games/2026020099/lineup', ['team_abbrev' => 'TOR', 'text' => $text])->assertConflict();
    expect(NhlLineupObservation::query()->where('nhl_game_id', 2026020099)->count())->toBe(1);
    $this->travelBack();
});

it('rejects invalid manual lineup input without writing evidence', function (string $team, string $text, int $gameId, int $status): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00 America/Toronto'));
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    DB::table('nhl_teams')->insert(['nhl_id' => 10, 'abbrev' => 'TOR']);
    $current = createCurrentAnticipatedLineup();
    $current->observation->delete();

    $this->actingAs($user)->postJson('/games/' . $gameId . '/lineup', ['team_abbrev' => $team, 'text' => $text])
        ->assertStatus($status);
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    $this->assertDatabaseMissing('sources', ['platform' => 'manual']);
    Http::assertNothingSent();
    $this->travelBack();
})->with([
    'empty' => ['TOR', '', 2026020099, 422],
    'partial' => ['TOR', 'Test Player 1 - Test Player 2 - Test Player 3', 2026020099, 422],
    'wrong team' => ['BOS', 'Some lineup', 2026020099, 422],
    'missing game' => ['TOR', 'Some lineup', 9999999999, 404],
]);

it('exposes game sync settings only to a super admin', function (): void {
    $user = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99,
        'scope' => 'global', 'is_active' => true,
    ]);
    $user->roles()->attach($role->id, ['organization_id' => null]);

    $this->actingAs($user)->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Games/Index')
            ->where('canManageGameSync', true)
            ->where('gameSyncSchedule.enabled', false)
            ->where('gameSyncSchedule.lanes.today.interval_seconds', 60)
            ->where('gameSyncScheduleUrl', route('admin.imports.schedule.update', [
                'key' => AdminImportSchedules::GAME_BOXSCORES,
            ])));
});

it('returns not found for an unknown public game', function (): void {
    $this->get(route('games.show', ['nhlGameId' => 2999999999]))->assertNotFound();
});

it('does not expose the removed public lineups routes', function (): void {
    $this->get('/lineups')->assertNotFound();
    $this->get('/lineups/payload?date=2026-09-21')->assertNotFound();
    $this->get('/lineups/2026010201')->assertNotFound();
});

it('promotes games ahead of stats and removes lineups from the news menu', function (): void {
    $this->get(route('games.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Home', 'Games', 'Stats', 'News'])
        ->assertDontSee('>Lineups</', false);
});

it('shows cached pregame state and an observed starting goalie for a utc-today game', function (): void {
    Carbon::setTestNow('2026-09-21 12:00:00 UTC');
    NhlGame::query()->create([
        'nhl_game_id' => 2026010301, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'NYR',
    ]);
    createGoalieObservation([
        'nhl_game_id' => 2026010301, 'game_date' => '2026-09-21', 'team_abbrev' => 'BOS',
        'opponent_abbrev' => 'NYR', 'is_home' => false, 'player_name' => 'Expected Goalie',
    ]);
    Http::fake(['*' => Http::response(['gameState' => 'FUT'], 200)]);

    $this->getJson(route('games.payload', ['date' => '2026-09-21']))
        ->assertOk()
        ->assertJsonPath('games.0.game_state', 'FUT')
        ->assertJsonPath('games.0.game_state_label', 'Pregame')
        ->assertJsonPath('games.0.away.starting_goalie.name', 'Expected Goalie')
        ->assertJsonPath('games.0.away.starting_goalie.status', 'expected');

    $this->getJson(route('games.payload', ['date' => '2026-09-21']))->assertOk();
    Http::assertSentCount(1);
    Carbon::setTestNow();
});

it('treats every non-terminal non-pregame provider state as live', function (): void {
    Carbon::setTestNow('2026-09-21 12:00:00 UTC');
    NhlGame::query()->create([
        'nhl_game_id' => 2026010302, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 16:00:00 UTC'),
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'NYR',
    ]);
    Http::fake(['*' => Http::response([
        'gameState' => 'INTERMISSION', 'awayTeam' => ['score' => 2, 'sog' => 15], 'homeTeam' => ['score' => 1, 'sog' => 0],
    ], 200)]);

    $this->getJson(route('games.payload', ['date' => '2026-09-21']))
        ->assertOk()
        ->assertJsonPath('games.0.game_state_label', 'Live')
        ->assertJsonPath('games.0.away.score', 2)
        ->assertJsonPath('games.0.home.score', 1)
        ->assertJsonPath('games.0.away.sog', 15)
        ->assertJsonPath('games.0.home.sog', 0);
    $this->assertDatabaseHas('nhl_games', [
        'nhl_game_id' => 2026010302, 'game_state' => 'INTERMISSION',
        'away_team_score' => 2, 'home_team_score' => 1,
        'away_team_sog' => 15, 'home_team_sog' => 0,
    ]);
    Carbon::setTestNow();
});

it('exposes live starter identity and individual goalie statistics', function (?int $goalsAgainst, ?int $saves): void {
    $this->travelTo(Carbon::parse('2026-09-22 00:30:00 UTC'));
    Player::query()->create([
        'nhl_id' => 8489991, 'full_name' => 'Live Starter', 'position' => 'G',
        'team_abbrev' => 'PHI', 'head_shot_url' => 'https://example.test/starter.png',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010395, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'PHI', 'home_team_abbrev' => 'WSH', 'game_state' => 'LIVE',
    ]);
    $starter = ['playerId' => 8489991, 'starter' => true, 'name' => ['default' => 'L. Starter']];
    if ($goalsAgainst !== null) {
        $starter['goalsAgainst'] = $goalsAgainst;
    }
    if ($saves !== null) {
        $starter['saves'] = $saves;
    }
    Http::fake(['*' => Http::response([
        'gameState' => 'LIVE', 'awayTeam' => ['score' => 1], 'homeTeam' => ['score' => 7],
        'playerByGameStats' => ['awayTeam' => ['goalies' => [
            ['playerId' => 8489992, 'starter' => false, 'toi' => '00:00', 'goalsAgainst' => 0, 'saves' => 0], $starter,
        ]]],
    ])]);
    $this->getJson('/games/payload?date=2026-09-21')->assertOk()
        ->assertJsonCount(1, 'games.0.away.goalies')
        ->assertJsonPath('games.0.away.goalies.0.nhl_player_id', 8489991)
        ->assertJsonPath('games.0.away.goalies.0.name', 'L. Starter')
        ->assertJsonPath('games.0.away.goalies.0.avatar_url', 'https://example.test/starter.png')
        ->assertJsonPath('games.0.away.goalies.0.goals_against', $goalsAgainst)
        ->assertJsonPath('games.0.away.goalies.0.saves', $saves);
    $this->get('/games/2026010395')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.away.goalies.0.goals_against', $goalsAgainst)
        ->where('game.away.goalies.0.saves', $saves));
    $this->travelBack();
})->with([[2, 14], [0, 0], [null, null]]);

it('uses only provider live data and database avatars without requiring starter flags', function (): void {
    $this->travelTo(Carbon::parse('2026-09-22 00:30:00 UTC'));
    $this->mock(\App\Services\NhlStartingGoalieSelector::class)->shouldNotReceive('select');
    Player::query()->create([
        'nhl_id' => 8489991, 'full_name' => 'Do not display this database name', 'position' => 'G',
        'head_shot_url' => 'https://example.test/avatar.png',
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010396, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'OLD', 'home_team_abbrev' => 'OLD', 'game_state' => 'LIVE',
        'away_team_score' => 99, 'away_team_sog' => 99,
    ]);
    Http::fake(['*' => Http::response([
        'id' => 2026010396, 'gameState' => 'LIVE', 'gameDate' => '2026-09-21',
        'startTimeUTC' => '2026-09-21T23:05:00Z',
        'awayTeam' => ['abbrev' => 'PHI', 'score' => 1],
        'homeTeam' => ['abbrev' => 'WSH', 'score' => 3],
        'playerByGameStats' => ['awayTeam' => ['goalies' => [
            ['playerId' => 8489991, 'name' => ['default' => 'Provider Starter'], 'shotsAgainst' => 18, 'goalsAgainst' => 3, 'toi' => '30:00'],
            ['playerId' => 8489992, 'name' => ['default' => 'Provider Relief'], 'saveShotsAgainst' => '4/4', 'goalsAgainst' => 0, 'toi' => '10:00'],
            ['playerId' => 8489993, 'name' => ['default' => 'Unused Backup'], 'shotsAgainst' => 0, 'goalsAgainst' => 0, 'toi' => '00:00'],
        ]]],
    ])]);
    $this->getJson('/games/payload?date=2026-09-21')->assertOk()
        ->assertJsonPath('games.0.live_mode', true)
        ->assertJsonPath('games.0.start_time_utc', '2026-09-21T23:05:00Z')
        ->assertJsonPath('games.0.away.team_abbrev', 'PHI')
        ->assertJsonPath('games.0.away.score', 1)
        ->assertJsonPath('games.0.away.sog', null)
        ->assertJsonPath('games.0.away.lineup', null)
        ->assertJsonPath('games.0.away.starting_goalie', null)
        ->assertJsonCount(2, 'games.0.away.goalies')
        ->assertJsonPath('games.0.away.goalies.0.name', 'Provider Starter')
        ->assertJsonPath('games.0.away.goalies.0.avatar_url', 'https://example.test/avatar.png')
        ->assertJsonPath('games.0.away.goalies.0.saves', 15)
        ->assertJsonPath('games.0.away.goalies.1.saves', 4)
        ->assertJsonPath('games.0.away.goalies.1.avatar_url', null);
    $this->travelBack();
});

it('treats final as the terminal gamecenter state', function (): void {
    Carbon::setTestNow('2026-09-21 12:00:00 UTC');
    NhlGame::query()->create([
        'nhl_game_id' => 2026010303, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 10:00:00 UTC'),
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'NYR',
    ]);
    Http::fake(['*' => Http::response([
        'gameState' => 'FINAL', 'awayTeam' => ['score' => 3], 'homeTeam' => ['score' => 4],
    ], 200)]);

    $this->getJson(route('games.payload', ['date' => '2026-09-21']))
        ->assertOk()
        ->assertJsonPath('games.0.game_state', 'FINAL')
        ->assertJsonPath('games.0.game_state_label', 'Final')
        ->assertJsonPath('games.0.home.score', 4);
    Carbon::setTestNow();
});

it('does not request gamecenter for a date outside utc today', function (): void {
    Carbon::setTestNow('2026-09-21 23:30:00 UTC');
    NhlGame::query()->create([
        'nhl_game_id' => 2026010304, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-22', 'game_dow' => 'Tuesday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-22 23:00:00 UTC'),
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'NYR',
    ]);

    $this->getJson(route('games.payload', ['date' => '2026-09-22']))
        ->assertOk()
        ->assertJsonPath('games.0.game_state_label', null);
    Http::assertNothingSent();
    Carbon::setTestNow();
});

it('refreshes unfinished games across utc midnight in index and detail payloads', function (string $state): void {
    $this->travelTo(Carbon::parse('2026-09-22 00:30:00 UTC'));
    NhlGame::query()->create([
        'nhl_game_id' => 2026010390, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'PHI', 'home_team_abbrev' => 'WSH',
        'game_state' => $state, 'away_team_score' => 0, 'home_team_score' => 0,
    ]);
    Http::fake(['*' => Http::response([
        'gameState' => 'LIVE', 'awayTeam' => ['score' => 2, 'sog' => 12],
        'homeTeam' => ['score' => 0, 'sog' => 8],
    ])]);
    $this->getJson('/games/payload?date=2026-09-21')->assertOk()
        ->assertJsonPath('games.0.game_state_label', 'Live')
        ->assertJsonPath('games.0.away.score', 2)
        ->assertJsonPath('games.0.home.score', 0)
        ->assertJsonPath('games.0.away.sog', 12);
    $this->assertDatabaseHas('nhl_games', [
        'nhl_game_id' => 2026010390, 'game_state' => 'LIVE', 'away_team_score' => 2, 'home_team_sog' => 8,
    ]);
    $this->get('/games/2026010390')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.away.score', 2)->where('game.home.sog', 8));
    Http::assertSentCount(1);
    $this->travelBack();
})->with(['LIVE', 'PRE', 'FUT']);

it('does not substitute stored live data on provider failure while retaining final history', function (string $state): void {
    $this->travelTo(Carbon::parse('2026-09-22 00:30:00 UTC'));
    NhlGame::query()->create([
        'nhl_game_id' => 2026010391, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-21', 'game_dow' => 'Monday', 'game_month' => 'September',
        'start_time_utc' => Carbon::parse('2026-09-21 23:00:00 UTC'),
        'away_team_abbrev' => 'PHI', 'home_team_abbrev' => 'WSH', 'game_state' => $state,
        'away_team_score' => 0, 'home_team_score' => 3, 'away_team_sog' => 9, 'home_team_sog' => 20,
    ]);
    Http::fake(['*' => Http::response([], 503)]);
    $this->getJson('/games/payload?date=2026-09-21')->assertOk()
        ->assertJsonPath('games.0.game_state_label', $state === 'FINAL' ? 'Final' : null)
        ->assertJsonPath('games.0.away.score', $state === 'FINAL' ? 0 : null)
        ->assertJsonPath('games.0.home.score', $state === 'FINAL' ? 3 : null)
        ->assertJsonPath('games.0.home.sog', $state === 'FINAL' ? 20 : null);
    if ($state === 'FINAL') {
        Http::assertNothingSent();
    }
    $this->travelBack();
})->with(['LIVE', 'FINAL']);

it('keeps yesterday unfinished games on the scheduler while excluding final and older games', function (): void {
    $now = Carbon::parse('2026-09-22 00:30:00 UTC')->toImmutable();
    foreach ([['2026-09-21', 'LIVE'], ['2026-09-21', 'FINAL'], ['2026-09-20', 'LIVE']] as $index => [$date, $state]) {
        NhlGame::query()->create([
            'nhl_game_id' => 2026010392 + $index, 'season_id' => '20262027', 'game_type' => 1,
            'game_date' => $date, 'game_dow' => 'Monday', 'game_month' => 'September',
            'start_time_utc' => Carbon::parse($date . ' 23:00:00 UTC'),
            'away_team_abbrev' => 'PHI', 'home_team_abbrev' => 'WSH', 'game_state' => $state,
        ]);
    }
    $schedule = new AdminImportSchedule([
        'enabled' => true, 'lane_enabled' => true,
        'game_sync_timing' => ['start_before_minutes' => 30, 'pregame_seconds' => 900, 'live_seconds' => 300],
    ]);
    expect(app(AdminImportSchedules::class)->dueBoxscoreGames($schedule, $now)->pluck('nhl_game_id')->all())
        ->toBe([2026010392]);
    NhlGame::query()->where('nhl_game_id', 2026010392)->update(['boxscore_synced_at' => $now]);
    expect(app(AdminImportSchedules::class)->dueBoxscoreGames($schedule, $now))->toHaveCount(0);
});

it('resolves lineup acronym references through the canonical lineup resolver', function (): void {
    $player = Player::query()->create([
        'nhl_id' => 8482105, 'first_name' => 'Jacob', 'last_name' => 'Bernard-Docker',
        'full_name' => 'Jacob Bernard-Docker', 'position' => 'D', 'team_abbrev' => 'DET',
    ]);

    $resolved = app(\App\Services\NhlLineupPlayerResolver::class)->resolve('JBD', 'DET');

    expect($resolved?->is($player))->toBeTrue();
});

it('resolves an unambiguous lineup surname prefix for the target team', function (): void {
    $player = Player::query()->create([
        'nhl_id' => 8481540, 'first_name' => 'Juraj', 'last_name' => 'Slafkovsky',
        'full_name' => 'Juraj Slafkovsky', 'position' => 'LW', 'team_abbrev' => 'MTL',
    ]);

    $resolved = app(\App\Services\NhlLineupPlayerResolver::class)->resolve('Slaf', 'MTL');

    expect($resolved?->is($player))->toBeTrue();
});

it('resolves a unique canonical player when the stored team assignment is stale', function (): void {
    $player = Player::query()->create([
        'nhl_id' => 8484901, 'first_name' => 'Trade', 'last_name' => 'Candidate',
        'full_name' => 'Trade Candidate', 'position' => 'C', 'team_abbrev' => 'AHL',
    ]);

    $resolved = app(\App\Services\NhlLineupPlayerResolver::class)->resolve('Trade Candidate', 'DAL');

    expect($resolved?->is($player))->toBeTrue();
});

it('uses configured first name variants in reported lineup names', function (): void {
    config(['name_variants.first_name_variants' => ['Nicholas' => ['Nick']]]);
    $player = Player::query()->create([
        'nhl_id' => 8484902, 'first_name' => 'Nicholas', 'last_name' => 'Prospect',
        'full_name' => 'Nicholas Prospect', 'position' => 'C', 'team_abbrev' => 'BUF',
    ]);

    $resolved = app(\App\Services\NhlLineupPlayerResolver::class)->resolve('Nick Prospect', 'BUF');

    expect($resolved?->is($player))->toBeTrue();
});

it('resolves a reported sweater number from the teams latest imported boxscore', function (): void {
    $player = Player::query()->create([
        'nhl_id' => 8484903, 'first_name' => 'Numbered', 'last_name' => 'Prospect',
        'full_name' => 'Numbered Prospect', 'position' => 'D', 'team_abbrev' => 'WSH',
    ]);
    DB::table('nhl_teams')->insert([
        'nhl_id' => 15, 'abbrev' => 'WSH', 'created_at' => now(), 'updated_at' => now(),
    ]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010999, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-20', 'game_dow' => 'Sunday', 'game_month' => 'September',
        'start_time_utc' => '2026-09-20 23:00:00',
        'away_team_abbrev' => 'WSH', 'home_team_abbrev' => 'BOS',
    ]);
    DB::table('nhl_boxscores')->insert([
        'nhl_game_id' => 2026010999, 'nhl_player_id' => $player->nhl_id, 'nhl_team_id' => 15,
        'sweater_number' => 48, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $resolved = app(\App\Services\NhlLineupPlayerResolver::class)->resolve('48', 'WSH');

    expect($resolved?->is($player))->toBeTrue();
});
