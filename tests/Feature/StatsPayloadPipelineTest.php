<?php

declare(strict_types=1);

use App\Models\IntegrationSecret;
use App\Models\NhlSeasonStat;
use App\Models\Perspective;
use App\Models\PlatformLeague;
use App\Models\PlatformLeaguePlayerStat;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\User;
use App\Services\PlatformLeaguePlayerStatService;
use App\Support\FantraxViewerScope;
use App\Support\Stats\LeagueStatsOwnershipHydrator;
use App\Support\Stats\LeagueStatsPerspectiveFactory;
use App\Support\Stats\LeagueStatsPlayerUniverseFilter;
use App\Support\Stats\SeasonStatsPayloadRequest;
use App\Support\Stats\StatsDerivedFilterApplier;
use App\Support\Stats\StatsFilterSet;
use App\Support\Stats\StatsPayloadAssembler;
use App\Support\Stats\StatsPayloadBuilder;
use App\Support\Stats\StatsQueryContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-09 12:00:00');
    Cache::flush();

    $this->statsRequest = function (array $query = [], ?User $user = null): Request {
        $request = Request::create('/stats/payload', 'GET', $query);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->statsPlayer = function (array $overrides = []): Player {
        $player = new Player(array_merge([
            'id' => $overrides['id'] ?? 1,
            'nhl_id' => $overrides['nhl_id'] ?? 1001,
            'first_name' => $overrides['first_name'] ?? 'Test',
            'last_name' => $overrides['last_name'] ?? 'Player',
            'full_name' => $overrides['full_name'] ?? 'Test Player',
            'dob' => $overrides['dob'] ?? '2000-01-01',
            'position' => $overrides['position'] ?? 'C',
            'pos_type' => $overrides['pos_type'] ?? 'F',
            'team_abbrev' => $overrides['team_abbrev'] ?? 'TOR',
            'is_goalie' => $overrides['is_goalie'] ?? false,
            'head_shot_url' => $overrides['head_shot_url'] ?? 'https://example.test/player.png',
        ], $overrides));
        $player->setRelation('contracts', collect());

        return $player;
    };

    $this->statsRow = function (Player $player, array $overrides = []): object {
        return (object) array_merge([
            'player' => $player,
            'player_id' => $player->id,
            'nhl_player_id' => $player->nhl_id,
            'league_abbrev' => 'OHL',
            'team_abbrev' => 'TOR',
            'gp' => 10,
            'g' => 3,
            'a' => 4,
            'pts' => 7,
            'toi' => 600,
        ], $overrides);
    };

    $this->createRosterFixture = function (array $overrides = []): array {
        $user = User::factory()->create();
        $league = PlatformLeague::create([
            'platform' => $overrides['platform'] ?? 'fantrax',
            'platform_league_id' => $overrides['platform_league_id'] ?? 'league-1',
            'name' => $overrides['league_name'] ?? 'League One',
            'sport' => 'hockey',
        ]);
        $team = PlatformTeam::create([
            'platform_league_id' => $league->id,
            'platform_team_id' => $overrides['platform_team_id'] ?? 'team-1',
            'name' => $overrides['team_name'] ?? 'Team One',
        ]);
        $player = Player::create([
            'nhl_id' => $overrides['nhl_id'] ?? 9001,
            'first_name' => 'Roster',
            'last_name' => 'Player',
            'full_name' => 'Roster Player',
            'dob' => '2001-01-01',
            'position' => $overrides['position'] ?? 'C',
            'pos_type' => $overrides['pos_type'] ?? 'F',
            'team_abbrev' => 'TOR',
            'is_goalie' => $overrides['is_goalie'] ?? false,
            'head_shot_url' => 'https://example.test/roster.png',
        ]);

        $user->platformLeagues()->attach($league->id, [
            'team_id' => $team->id,
            'is_active' => true,
            'extras' => json_encode(['provider' => $league->platform], JSON_THROW_ON_ERROR),
            'synced_at' => now(),
        ]);
        $team->roster()->attach($player->id, [
            'platform' => $league->platform,
            'platform_player_id' => 'platform-player-1',
            'slot' => $overrides['slot'] ?? 'C',
            'status' => $overrides['status'] ?? 'active',
            'eligibility' => json_encode($overrides['eligibility'] ?? ['C'], JSON_THROW_ON_ERROR),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $league, $team, $player];
    };

    $this->createConnectedLeagueFixture = function (array $overrides = []): array {
        [$user, $league, $team, $player] = ($this->createRosterFixture)($overrides);

        IntegrationSecret::create([
            'user_id' => $user->id,
            'provider' => 'fantrax',
            'secret' => 'connected-secret',
            'status' => 'connected',
        ]);

        $perspective = Perspective::create([
            'name' => $overrides['perspective_name'] ?? 'Skaters',
            'slug' => $overrides['perspective_slug'] ?? 'skaters',
            'author_id' => $user->id,
            'visibility' => 'private',
            'sport' => 'hockey',
            'is_slicable' => true,
            'settings' => [
                'columns' => [
                    ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                    ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                ],
                'sort' => ['sortKey' => 'g', 'sortDirection' => 'desc'],
                'filters' => [],
            ],
        ]);

        return [$user, $league, $team, $player, $perspective];
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::flush();
});

it('creates an empty filter set without a request', function (): void {
    $filters = StatsFilterSet::fromRequest(null);

    expect($filters->positions)->toBe([])
        ->and($filters->positionTypes)->toBe([])
        ->and($filters->teams)->toBe([])
        ->and($filters->leagues)->toBe([])
        ->and($filters->numericRanges)->toBe([]);
});

it('normalizes position filter arrays by trimming blanks', function (): void {
    $filters = StatsFilterSet::fromRequest(($this->statsRequest)([
        'pos' => [' C ', '', 'LW'],
        'pos_type' => [' F ', 'D'],
    ]));

    expect($filters->positions)->toBe(['C', 'LW'])
        ->and($filters->positionTypes)->toBe(['F', 'D']);
});

it('normalizes scalar filter query values into lists', function (): void {
    $filters = StatsFilterSet::fromRequest(($this->statsRequest)([
        'team' => 'TOR',
        'league' => 'OHL',
    ]));

    expect($filters->teams)->toBe(['TOR'])
        ->and($filters->leagues)->toBe(['OHL']);
});

it('parses numeric min and max query ranges as floats', function (): void {
    $filters = StatsFilterSet::fromRequest(($this->statsRequest)([
        'gp_min' => '10',
        'gp_max' => '82',
        'contract_value_num_min' => '1.5',
    ]));

    expect($filters->numericRanges['gp'])->toBe(['min' => 10.0, 'max' => 82.0])
        ->and($filters->numericRanges['contract_value_num'])->toBe(['min' => 1.5, 'max' => null]);
});

it('keeps non numeric range bounds as null', function (): void {
    $filters = StatsFilterSet::fromRequest(($this->statsRequest)([
        'gp_min' => 'bad',
    ]));

    expect($filters->numericRanges['gp'])->toBe(['min' => null, 'max' => null]);
});

it('returns position echo payloads for applied metadata', function (): void {
    $filters = StatsFilterSet::fromRequest(($this->statsRequest)([
        'pos' => ['C'],
        'pos_type' => ['F'],
    ]));

    expect($filters->positionEcho())->toBe([
        'pos' => ['C'],
        'pos_type' => ['F'],
    ]);
});

it('creates query context defaults from a request', function (): void {
    $user = User::factory()->create();
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([], $user), null, null, 'skaters');

    expect($context->user?->id)->toBe($user->id)
        ->and($context->requestedPerspective)->toBe('skaters')
        ->and($context->slice)->toBe('total')
        ->and($context->gameType)->toBe(2)
        ->and($context->period)->toBe('season')
        ->and($context->resource)->toBe('players');
});

it('sanitizes invalid slice and game type values in query context', function (): void {
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([
        'slice' => 'bad',
        'game_type' => 9,
    ]));

    expect($context->slice)->toBe('total')
        ->and($context->gameType)->toBe(2);
});

it('prefers season_id over season in query context', function (): void {
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([
        'season' => '20242025',
        'season_id' => '20252026',
    ]));

    expect($context->season)->toBe('20252026');
});

it('normalizes blank column group to null in query context', function (): void {
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([
        'column_group' => ' ',
    ]));

    expect($context->columnGroup)->toBeNull();
});

it('keeps goalie column group and draft context in query context', function (): void {
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([
        'column_group' => 'goalie',
        'draft_context' => '1',
    ]));

    expect($context->columnGroup)->toBe('goalie')
        ->and($context->draftContext)->toBeTrue();
});

it('returns a cloned query context with a resolved perspective', function (): void {
    $context = StatsQueryContext::fromRequest(($this->statsRequest)([
        'perspective' => 'fantrax-league-7',
    ]));
    $perspective = (object) ['slug' => 'fantrax-league-7'];

    $next = $context->withPerspective($perspective);

    expect($next)->not->toBe($context)
        ->and($next->perspective)->toBe($perspective)
        ->and($next->requestedPerspective)->toBe('fantrax-league-7');
});

it('builds virtual schema for derived filters from assembled rows', function (): void {
    [, , $schema] = app(StatsDerivedFilterApplier::class)->apply(null, collect([
        ['gp' => 10, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
        ['gp' => 20, 'contract_value_num' => 5.0, 'contract_last_year_num' => 2030],
    ]));

    expect(collect($schema)->pluck('key')->all())->toBe(['gp', 'contract_value_num', 'contract_last_year_num']);
});

it('returns no applied derived filters without a request', function (): void {
    [, $applied] = app(StatsDerivedFilterApplier::class)->apply(null, collect([
        ['gp' => 10, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
    ]));

    expect($applied)->toBe(['filters' => []]);
});

it('filters derived rows by games played range', function (): void {
    [$rows, $applied] = app(StatsDerivedFilterApplier::class)->apply(($this->statsRequest)([
        'gp_min' => 15,
    ]), collect([
        ['name' => 'Low', 'gp' => 10, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
        ['name' => 'High', 'gp' => 20, 'contract_value_num' => 5.0, 'contract_last_year_num' => 2030],
    ]));

    expect($rows->pluck('name')->all())->toBe(['High'])
        ->and($applied['filters']['gp'])->toBe(['min' => 15, 'max' => null]);
});

it('filters derived rows by contract value range', function (): void {
    [$rows] = app(StatsDerivedFilterApplier::class)->apply(($this->statsRequest)([
        'contract_value_num_max' => 3,
    ]), collect([
        ['name' => 'Cheap', 'gp' => 10, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
        ['name' => 'Costly', 'gp' => 20, 'contract_value_num' => 5.0, 'contract_last_year_num' => 2030],
    ]));

    expect($rows->pluck('name')->all())->toBe(['Cheap']);
});

it('filters derived rows by contract last year range', function (): void {
    [$rows] = app(StatsDerivedFilterApplier::class)->apply(($this->statsRequest)([
        'contract_last_year_num_min' => 2029,
    ]), collect([
        ['name' => 'Soon', 'gp' => 10, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
        ['name' => 'Later', 'gp' => 20, 'contract_value_num' => 5.0, 'contract_last_year_num' => 2030],
    ]));

    expect($rows->pluck('name')->all())->toBe(['Later']);
});

it('echoes multiple applied derived filter ranges', function (): void {
    [, $applied] = app(StatsDerivedFilterApplier::class)->apply(($this->statsRequest)([
        'gp_max' => 40,
        'contract_value_num_min' => 1.5,
        'contract_last_year_num_max' => 2030,
    ]), collect([
        ['name' => 'One', 'gp' => 20, 'contract_value_num' => 2.5, 'contract_last_year_num' => 2028],
    ]));

    expect($applied['filters'])->toHaveKeys(['gp', 'contract_value_num', 'contract_last_year_num']);
});

it('keeps prospect rows split by league outside draft context', function (): void {
    $player = ($this->statsPlayer)(['id' => 10, 'nhl_id' => 1010]);
    $rows = app(StatsPayloadAssembler::class)->assembleRowsFromCollection(collect([
        ($this->statsRow)($player, ['league_abbrev' => 'OHL', 'gp' => 10, 'g' => 4]),
        ($this->statsRow)($player, ['league_abbrev' => 'AHL', 'gp' => 8, 'g' => 2]),
    ]), [['key' => 'g', 'label' => 'G']], 'total', true, 'prospects');

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('league')->sort()->values()->all())->toBe(['AHL', 'OHL']);
});

it('groups draft context prospect rows by player and keeps the highest games played row', function (): void {
    $player = ($this->statsPlayer)(['id' => 11, 'nhl_id' => 1011]);
    $rows = app(StatsPayloadAssembler::class)->assembleRowsFromCollection(collect([
        ($this->statsRow)($player, ['league_abbrev' => 'OHL', 'gp' => 10, 'g' => 4]),
        ($this->statsRow)($player, ['league_abbrev' => 'AHL', 'gp' => 20, 'g' => 9]),
    ]), [['key' => 'g', 'label' => 'G']], 'total', true, 'prospects', ['draft_context' => true]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['gp'])->toBe(20)
        ->and($rows->first()['g'])->toBe(9);
});

it('decorates existing payload rows with fantasy ownership metadata', function (): void {
    [$user, $league, , $player] = ($this->createRosterFixture)();
    $payload = [
        'headings' => [],
        'data' => [[
            'name' => 'Roster Player',
            'player_id' => $player->id,
            'nhl_player_id' => $player->nhl_id,
        ]],
        'meta' => ['season' => '20252026', 'game_type' => 2],
        'settings' => [],
    ];

    $hydrated = app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user->id);

    expect($hydrated['data'][0]['fantasy_team_name'])->toBe('Team One')
        ->and($hydrated['data'][0]['fantasy_team_is_user_team'])->toBeTrue()
        ->and($hydrated['data'][0]['roster_slot'])->toBe('C');
});

it('scopes fantrax league ownership hydration to the viewer division', function (): void {
    [$user, $league, $team, $player] = ($this->createRosterFixture)([
        'platform_league_id' => 'division-stats-league',
        'team_name' => 'Gretzky Team',
    ]);
    $league->forceFill([
        'settings' => [
            'league_shape' => [
                'player_pool_scope' => 'division',
            ],
        ],
    ])->save();
    $team->forceFill([
        'extras' => ['fantrax' => ['division' => 'Gretzky']],
    ])->save();
    $orrTeam = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => 'team-2',
        'name' => 'Orr Team',
        'extras' => ['fantrax' => ['division' => 'Orr']],
    ]);
    $orrPlayer = Player::create([
        'nhl_id' => 9002,
        'first_name' => 'Other',
        'last_name' => 'Division',
        'full_name' => 'Other Division',
        'dob' => '2001-01-01',
        'position' => 'D',
        'pos_type' => 'D',
        'team_abbrev' => 'BOS',
    ]);
    $orrTeam->roster()->attach($orrPlayer->id, [
        'platform' => 'fantrax',
        'platform_player_id' => 'platform-player-2',
        'slot' => 'D',
        'status' => 'active',
        'eligibility' => json_encode(['D'], JSON_THROW_ON_ERROR),
        'starts_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = [
        'headings' => [],
        'data' => [
            [
                'name' => 'Roster Player',
                'player_id' => $player->id,
                'nhl_player_id' => $player->nhl_id,
            ],
            [
                'name' => 'Other Division',
                'player_id' => $orrPlayer->id,
                'nhl_player_id' => $orrPlayer->nhl_id,
            ],
        ],
        'meta' => ['season' => '20252026', 'game_type' => 2],
        'settings' => [],
    ];
    $scope = app(FantraxViewerScope::class)->resolve($league->fresh(), $user);
    $timings = null;

    $hydrated = app(LeagueStatsOwnershipHydrator::class)->hydrate(
        $payload,
        $league->fresh(),
        $user->id,
        $timings,
        $scope,
    );

    $rows = collect($hydrated['data'])->keyBy('player_id');

    expect($scope)->toMatchArray(['scope' => 'division', 'division' => 'Gretzky'])
        ->and($rows[$player->id]['fantasy_team_name'])->toBe('Gretzky Team')
        ->and($rows[$orrPlayer->id]['fantasy_team_name'])->toBeNull();
});

it('appends roster only rows missing from the payload', function (): void {
    [$user, $league, , $player] = ($this->createRosterFixture)();
    NhlSeasonStat::create([
        'season_id' => '20252026',
        'nhl_player_id' => $player->nhl_id,
        'nhl_team_id' => 1,
        'game_type' => 2,
        'gp' => 12,
        'g' => 5,
    ]);
    $payload = [
        'headings' => [['key' => 'gp'], ['key' => 'g']],
        'data' => [],
        'meta' => ['season' => '20252026', 'game_type' => 2],
        'settings' => [],
    ];

    $hydrated = app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user->id);

    expect($hydrated['data'])->toHaveCount(1)
        ->and($hydrated['data'][0]['league_roster_only'])->toBeTrue()
        ->and($hydrated['data'][0]['gp'])->toBe(12)
        ->and($hydrated['data'][0]['g'])->toBe(5.0);
});

it('does not count reserve or injured players against configured active roster slot placeholders', function (): void {
    [$user, $league, $team] = ($this->createRosterFixture)([
        'slot' => 'D',
        'status' => 'active',
        'eligibility' => ['D'],
    ]);
    DB::table('platform_league_roster_slots')->insert([
        'platform_league_id' => $league->id,
        'slot' => 'D',
        'slot_type' => 'starter',
        'position_type' => 'skater',
        'count' => 6,
        'sort_order' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(2, 4) as $index) {
        $player = Player::create([
            'nhl_id' => 9100 + $index,
            'first_name' => 'Active',
            'last_name' => "Defense {$index}",
            'full_name' => "Active Defense {$index}",
            'dob' => '2001-01-01',
            'position' => 'D',
            'pos_type' => 'D',
            'team_abbrev' => 'TOR',
        ]);
        $team->roster()->attach($player->id, [
            'platform' => 'fantrax',
            'platform_player_id' => "active-d-{$index}",
            'slot' => 'D',
            'status' => 'active',
            'eligibility' => json_encode(['D'], JSON_THROW_ON_ERROR),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach (['bench', 'ir'] as $index => $status) {
        $player = Player::create([
            'nhl_id' => 9200 + $index,
            'first_name' => ucfirst($status),
            'last_name' => 'Defense',
            'full_name' => ucfirst($status) . ' Defense',
            'dob' => '2001-01-01',
            'position' => 'D',
            'pos_type' => 'D',
            'team_abbrev' => 'TOR',
        ]);
        $team->roster()->attach($player->id, [
            'platform' => 'fantrax',
            'platform_player_id' => "{$status}-d",
            'slot' => 'D',
            'status' => $status,
            'eligibility' => json_encode(['D'], JSON_THROW_ON_ERROR),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $payload = [
        'headings' => [],
        'data' => [],
        'meta' => ['season' => '20252026', 'game_type' => 2],
        'settings' => [],
    ];

    $hydrated = app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user->id);
    $openDefenseSlots = collect($hydrated['data'])
        ->where('league_roster_placeholder', true)
        ->where('roster_slot', 'D');

    expect($openDefenseSlots)->toHaveCount(2);
});

it('hydrates rostered goalie rows already present in the payload from season stats', function (): void {
    [$user, $league, , $player] = ($this->createRosterFixture)([
        'position' => 'G',
        'pos_type' => 'G',
        'is_goalie' => true,
        'slot' => 'G',
        'eligibility' => ['G'],
    ]);
    NhlSeasonStat::create([
        'season_id' => '20252026',
        'nhl_player_id' => $player->nhl_id,
        'nhl_team_id' => 1,
        'game_type' => 2,
        'gp' => 29,
        'wins' => 11,
    ]);
    $payload = [
        'headings' => [['key' => 'wins']],
        'data' => [[
            'name' => 'Roster Goalie',
            'player_id' => $player->id,
            'nhl_player_id' => $player->nhl_id,
            'pos_type' => 'G',
            'is_goalie' => true,
            'gp' => 0,
            'wins' => 0,
            'stats' => [
                'gp' => 0,
                'wins' => 0,
            ],
        ]],
        'meta' => ['season' => '20252026', 'game_type' => 2],
        'settings' => [
            'columnGroups' => [
                'goalie' => [['key' => 'wins']],
            ],
        ],
    ];

    $hydrated = app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user->id);

    expect($hydrated['data'])->toHaveCount(1)
        ->and($hydrated['data'][0]['fantasy_team_name'])->toBe('Team One')
        ->and($hydrated['data'][0]['gp'])->toBe(29)
        ->and($hydrated['data'][0]['wins'])->toBe(11.0)
        ->and($hydrated['data'][0]['stats']['gp'])->toBe(29.0)
        ->and($hydrated['data'][0]['stats']['wins'])->toBe(11.0);
});

it('preserves payload games played when overlaying provider league stats', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'league-provider-stats',
        'name' => 'Provider Stats League',
        'sport' => 'hockey',
    ]);
    $player = Player::create([
        'nhl_id' => 9901,
        'first_name' => 'Goalie',
        'last_name' => 'Overlay',
        'full_name' => 'Goalie Overlay',
        'dob' => '2000-01-01',
        'position' => 'G',
        'pos_type' => 'G',
        'team_abbrev' => 'TOR',
        'is_goalie' => true,
    ]);
    PlatformLeaguePlayerStat::create([
        'platform_league_id' => $league->id,
        'player_id' => $player->id,
        'platform' => 'fantrax',
        'provider_identity_key' => 'fantrax:league-provider-stats:20252026:season:goalie-overlay',
        'platform_player_id' => 'goalie-overlay',
        'season' => '20252026',
        'scope' => 'season',
        'stats' => ['gp' => 0, 'wins' => 12],
        'raw_payload' => [],
        'synced_at' => now(),
    ]);
    $payload = [
        'headings' => [['key' => 'gp'], ['key' => 'wins']],
        'data' => [[
            'player_id' => $player->id,
            'nhl_player_id' => $player->nhl_id,
            'pos_type' => 'G',
            'is_goalie' => true,
            'gp' => 29,
            'wins' => 0,
        ]],
        'settings' => ['defaultSort' => 'wins', 'defaultSortDirection' => 'desc'],
        'meta' => ['season' => '20252026'],
    ];

    $overlaid = app(PlatformLeaguePlayerStatService::class)->overlayStatsPayload($payload, $league, '20252026');

    expect($overlaid['data'][0]['gp'])->toBe(29)
        ->and($overlaid['data'][0]['wins'])->toBe(12)
        ->and($overlaid['meta']['statsSource'])->toBe('provider');
});

it('reuses cached ownership maps within the ownership cache window', function (): void {
    [$user, $league, $team, $player] = ($this->createRosterFixture)();
    $payload = [
        'headings' => [],
        'data' => [['player_id' => $player->id, 'nhl_player_id' => $player->nhl_id]],
        'meta' => [],
        'settings' => [],
    ];
    $hydrator = app(LeagueStatsOwnershipHydrator::class);

    $first = $hydrator->hydrate($payload, $league, $user->id);
    $team->update(['name' => 'Changed Team']);
    $second = $hydrator->hydrate($payload, $league->fresh(), $user->id);

    expect($first['data'][0]['fantasy_team_name'])->toBe('Team One')
        ->and($second['data'][0]['fantasy_team_name'])->toBe('Team One');
});

it('records ownership sub timings when timing output is requested', function (): void {
    [$user, $league, , $player] = ($this->createRosterFixture)();
    $timings = [];
    $payload = [
        'headings' => [],
        'data' => [['player_id' => $player->id, 'nhl_player_id' => $player->nhl_id]],
        'meta' => [],
        'settings' => [],
    ];

    app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user->id, $timings);

    expect($timings)->toHaveKeys(['ownership_map_ms', 'ownership_decorate_ms', 'ownership_missing_rows_ms']);
});

it('keeps non platform league payload rows unchanged when filtering player universe', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'espn',
        'platform_league_id' => 'espn-1',
        'name' => 'ESPN League',
        'sport' => 'hockey',
    ]);
    $payload = ['data' => [['player_id' => 123]]];

    $filtered = app(LeagueStatsPlayerUniverseFilter::class)->filter($payload, $league);

    expect($filtered)->toBe($payload);
});

it('filters fantrax league payload rows to provider and roster player universe', function (): void {
    [, $league, $team, $rosteredPlayer] = ($this->createRosterFixture)();
    $providerPlayer = Player::create([
        'nhl_id' => 9002,
        'first_name' => 'Provider',
        'last_name' => 'Player',
        'full_name' => 'Provider Player',
        'dob' => '2001-01-01',
        'position' => 'LW',
        'pos_type' => 'F',
        'team_abbrev' => 'TOR',
        'is_goalie' => false,
    ]);
    $unknownPlayer = Player::create([
        'nhl_id' => 9003,
        'first_name' => 'Unknown',
        'last_name' => 'Player',
        'full_name' => 'Unknown Player',
        'dob' => '2001-01-01',
        'position' => 'RW',
        'pos_type' => 'F',
        'team_abbrev' => 'TOR',
        'is_goalie' => false,
    ]);
    DB::table('fantrax_players')->insert([
        'player_id' => $providerPlayer->id,
        'fantrax_id' => 'fantrax-provider',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = [
        'data' => [
            ['player_id' => $providerPlayer->id],
            ['id' => $rosteredPlayer->id],
            ['player_id' => $unknownPlayer->id],
        ],
    ];

    $filtered = app(LeagueStatsPlayerUniverseFilter::class)->filter($payload, $league);

    expect($filtered['data'])->toHaveCount(2)
        ->and(collect($filtered['data'])->pluck('player_id')->filter()->values()->all())->toBe([$providerPlayer->id]);
});

it('empties fantrax league payload rows when no provider or roster universe exists', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'empty-league',
        'name' => 'Empty League',
        'sport' => 'hockey',
    ]);

    $filtered = app(LeagueStatsPlayerUniverseFilter::class)->filter([
        'data' => [['player_id' => 999]],
    ], $league);

    expect($filtered['data'])->toBe([]);
});

it('builds fantrax synthetic perspective metadata for league pages', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'league-meta',
        'name' => 'League Meta',
        'sport' => 'hockey',
    ]);
    $factory = app(LeagueStatsPerspectiveFactory::class);

    expect($factory->leagueScoringPerspectiveSlug($league))->toBeNull()
        ->and($factory->fantraxLeaguePerspectiveSlug($league))->toBe('fantrax-league-' . $league->id);
});

it('builds yahoo scoring settings only when every scoring category is mapped', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'yahoo',
        'platform_league_id' => 'yahoo-mapped',
        'name' => 'Yahoo Mapped',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                ['id' => '1', 'short' => 'G', 'stat_key' => 'g'],
                ['id' => '2', 'short' => 'SV%', 'stat_key' => 'sv_pct'],
            ],
        ],
    ]);

    $settings = app(LeagueStatsPerspectiveFactory::class)->leagueScoringPerspectiveSettings($league);

    expect($settings['columns'])->toHaveCount(2)
        ->and($settings['columnGroups']['skater'][0]['key'])->toBe('g')
        ->and(collect($settings['columnGroups']['goalie'])->pluck('key')->all())->toBe(['gp', 'sv_pct']);
});

it('builds league scoring settings for supported formula categories', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-formula',
        'name' => 'Fantrax Formula',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                [
                    'id' => 'HOCKEY_SKATING:OSG3',
                    'short' => 'OSG3',
                    'label' => 'Old School Grit 3',
                    'formula' => '(5 * f) + h + b - pim',
                    'required_schema_columns' => ['f', 'h', 'b', 'pim'],
                    'is_supported' => true,
                    'alignment_status' => 'formula',
                ],
            ],
        ],
    ]);

    $settings = app(LeagueStatsPerspectiveFactory::class)->leagueScoringPerspectiveSettings($league);

    expect($settings)->not->toBeNull()
        ->and($settings['columns'][0]['key'])->toBe('HOCKEY_SKATING:OSG3')
        ->and($settings['columns'][0]['formula'])->toBe('(5 * f) + h + b - pim');
});

it('refuses league scoring settings when a scoring category is unmapped', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'yahoo',
        'platform_league_id' => 'yahoo-unmapped',
        'name' => 'Yahoo Unmapped',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                ['id' => '1', 'short' => 'G', 'stat_key' => 'g'],
                ['id' => '2', 'short' => 'Mystery', 'stat_key' => ''],
            ],
        ],
    ]);

    expect(app(LeagueStatsPerspectiveFactory::class)->leagueScoringPerspectiveSettings($league))->toBeNull();
});

it('keeps supported fantrax scoring headers when other categories are unsupported', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-mixed',
        'name' => 'Fantrax Mixed',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                [
                    'id' => 'HOCKEY_SKATING:G',
                    'short' => 'G',
                    'label' => 'Goals',
                    'stat_key' => 'g',
                    'is_supported' => true,
                    'alignment_status' => 'direct',
                ],
                [
                    'id' => 'HOCKEY_SKATING:OSG3',
                    'short' => 'OSG3',
                    'label' => 'Old School Grit 3',
                    'formula' => '(5 * f) + h + b - pim',
                    'required_schema_columns' => ['f', 'h', 'b', 'pim'],
                    'is_supported' => true,
                    'alignment_status' => 'formula',
                ],
                [
                    'id' => 'HOCKEY_SKATING:STAR',
                    'short' => 'Star',
                    'label' => 'Stars',
                    'is_supported' => false,
                    'alignment_status' => 'ignored_deprecated',
                ],
            ],
        ],
    ]);

    $settings = app(LeagueStatsPerspectiveFactory::class)->leagueScoringPerspectiveSettings($league);

    expect(collect($settings['columns'])->pluck('key')->all())->toBe(['g', 'HOCKEY_SKATING:OSG3']);
});

it('computes formula columns from assembled row stats', function (): void {
    $player = ($this->statsPlayer)(['id' => 90, 'nhl_id' => 9090]);
    $rows = app(StatsPayloadAssembler::class)->assembleRowsFromCollection(collect([
        ($this->statsRow)($player, [
            'gp' => 10,
            'f' => 1,
            'h' => 10,
            'b' => 5,
            'pim' => 4,
        ]),
    ]), [
        [
            'key' => 'HOCKEY_SKATING:OSG3',
            'label' => 'OSG3',
            'formula' => '(5 * f) + h + b - pim',
            'required_schema_columns' => ['f', 'h', 'b', 'pim'],
        ],
    ], 'total', true, 'season');

    expect($rows->first()['HOCKEY_SKATING:OSG3'])->toBe(16);
});

it('computes formula columns from on-ice dependency stats', function (): void {
    $player = Player::create([
        'nhl_id' => 9191,
        'first_name' => 'Fenwick',
        'last_name' => 'Skater',
        'full_name' => 'Fenwick Skater',
        'dob' => '2000-01-01',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'TOR',
        'is_goalie' => false,
        'head_shot_url' => 'https://example.test/fenwick.png',
    ]);

    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2026020001,
        'season_id' => '20262027',
        'game_type' => 2,
        'game_date' => '2026-10-01',
        'game_dow' => 'Thu',
        'game_month' => 'Oct',
        'home_team_id' => 1,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 2,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_player_game_strength_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'player_id' => $player->id,
        'nhl_player_id' => 9191,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'strength' => 'EV',
        'toi' => 600,
        'ff' => 14,
        'fa' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $columns = [[
        'key' => 'HOCKEY_SKATING:FEN',
        'label' => 'Fen',
        'formula' => 'ff - fa',
        'required_schema_columns' => ['ff', 'fa'],
        'fantasy_scoring_category' => true,
        'fantasy_weight' => 1,
        'is_supported' => true,
    ], [
        'key' => 'fantasy_pts',
        'label' => 'FP',
        'computed_fantasy_points' => true,
    ]];

    $rows = app(StatsPayloadAssembler::class)->assembleRowsFromCollection(collect([
        ($this->statsRow)($player, [
            'gp' => 1,
            'ff' => 0,
            'fa' => 0,
        ]),
    ]), $columns, 'total', true, 'season', [
        'season_id' => '20262027',
        'game_type' => 2,
    ]);

    $rows = app(StatsPayloadAssembler::class)->appendOnIceRows($rows, $columns, [
        'season_id' => '20262027',
        'game_type' => 2,
    ]);

    expect($rows->first()['HOCKEY_SKATING:FEN'])->toBe(5)
        ->and($rows->first()['fantasy_pts'])->toBe(5);
});

it('applies fantrax goalie settings with goalie filters and goalie columns', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-goalie',
        'name' => 'Fantrax Goalie',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                ['short' => 'W', 'auto_stat_key' => 'wins'],
                ['short' => 'SV%', 'auto_stat_key' => 'sv_pct'],
            ],
        ],
    ]);

    $settings = app(LeagueStatsPerspectiveFactory::class)->withFantraxGoalieSettings([
        'columns' => [['key' => 'g', 'label' => 'G']],
        'filters' => [],
    ], $league);

    expect(collect($settings['columns'])->pluck('key')->all())->toBe(['gp', 'wins', 'sv_pct'])
        ->and($settings['filters']['pos_type']['value'])->toBe(['G'])
        ->and($settings['sort']['sortKey'])->toBe('wins');
});

it('adds fantrax goalie column group to payload settings', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-column-group',
        'name' => 'Fantrax Column Group',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                ['short' => 'W', 'auto_stat_key' => 'wins'],
                [
                    'id' => 'HOCKEY_GOALIE:GPT4',
                    'short' => 'GPT4',
                    'label' => 'Goalie Points 4',
                    'formula' => '(2 * wins) + ot_losses + shootout_losses + (2 * so)',
                    'required_schema_columns' => ['wins', 'ot_losses', 'shootout_losses', 'so'],
                    'is_supported' => true,
                    'alignment_status' => 'formula',
                ],
            ],
        ],
    ]);
    $payload = [
        'headings' => [
            ['key' => 'name', 'label' => 'Player'],
            ['key' => 'g', 'label' => 'G'],
            ['key' => 'sv', 'label' => 'SV'],
            ['key' => 'HOCKEY_GOALIE:GPT4', 'label' => 'GPT4', 'normalized_group' => 'HOCKEY_GOALIE'],
        ],
        'settings' => [],
    ];

    $next = app(LeagueStatsPerspectiveFactory::class)->withFantraxGoalieColumnGroup($payload, $league);

    expect(collect($next['settings']['columnGroups']['skater'])->pluck('key')->all())->toBe(['g'])
        ->and(collect($next['settings']['columnGroups']['goalie'])->pluck('key')->all())
        ->toBe(['gp', 'wins', 'HOCKEY_GOALIE:GPT4']);
});

it('adds games played to active fantrax goalie payload headings', function (): void {
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-active-goalie-column-group',
        'name' => 'Fantrax Active Goalie Column Group',
        'sport' => 'hockey',
        'scoring_settings' => [
            'categories' => [
                ['short' => 'W', 'auto_stat_key' => 'wins'],
            ],
        ],
    ]);
    $payload = [
        'headings' => [
            ['key' => 'name', 'label' => 'Player'],
            ['key' => 'team', 'label' => 'Team'],
            ['key' => 'pos_type', 'label' => 'Type'],
        ],
        'settings' => [],
        'data' => [],
    ];

    $next = app(LeagueStatsPerspectiveFactory::class)
        ->withActiveFantraxColumnGroupPayload($payload, $league, 'goalie');

    expect(collect($next['headings'])->pluck('key')->all())->toBe(['name', 'team', 'pos_type', 'gp', 'wins'])
        ->and(collect($next['settings']['columnGroups']['goalie'])->pluck('key')->all())->toBe(['gp', 'wins']);
});

it('returns league stats payload for a connected active league member', function (): void {
    [$user, $league, , $player] = ($this->createConnectedLeagueFixture)();
    app()->instance(StatsPayloadBuilder::class, new class ($player) {
        public function __construct(private readonly Player $player)
        {
        }

        public function buildSeasonPayload(SeasonStatsPayloadRequest $request): array
        {
            return [[
                'headings' => [['key' => 'g', 'label' => 'G']],
                'data' => [[
                    'player_id' => $this->player->id,
                    'nhl_player_id' => $this->player->nhl_id,
                    'g' => 7,
                ]],
                'settings' => ['sortable' => ['g']],
                'meta' => ['season' => '20252026', 'game_type' => 2],
            ], null, null, ['fake_ms' => 0.0]];
        }
    });

    $response = $this->actingAs($user)->getJson(route('leagues.stats.payload', $league->id));

    $response->assertOk()
        ->assertJsonPath('selectedPerspective', 'fantrax-league-' . $league->id)
        ->assertJsonPath('settings.ownerColumn', true)
        ->assertJsonPath('settings.leaguePlatform', 'fantrax')
        ->assertJsonCount(1, 'data');
});

it('blocks league stats payload for users without a connected fantasy provider', function (): void {
    [$user, $league] = ($this->createRosterFixture)();

    $response = $this->actingAs($user)->getJson(route('leagues.stats.payload', $league->id));

    $response->assertStatus(409)
        ->assertJsonPath('message', 'Connect a fantasy provider before loading league stats.');
});

it('returns not found when connected users request an inactive league assignment', function (): void {
    [$user, $league] = ($this->createConnectedLeagueFixture)();
    $otherLeague = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'other-active-league',
        'name' => 'Other Active League',
        'sport' => 'hockey',
    ]);
    $otherTeam = PlatformTeam::create([
        'platform_league_id' => $otherLeague->id,
        'platform_team_id' => 'other-team',
        'name' => 'Other Team',
    ]);
    $user->platformLeagues()->attach($otherLeague->id, [
        'team_id' => $otherTeam->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => 'fantrax'], JSON_THROW_ON_ERROR),
        'synced_at' => now(),
    ]);
    $user->platformLeagues()->updateExistingPivot($league->id, ['is_active' => false]);

    $response = $this->actingAs($user)->getJson(route('leagues.stats.payload', $league->id));

    $response->assertNotFound();
});

it('validates unsupported league stats column group values', function (): void {
    [$user, $league] = ($this->createConnectedLeagueFixture)();

    $response = $this->actingAs($user)->getJson(route('leagues.stats.payload', [
        'league_id' => $league->id,
        'column_group' => 'skater',
    ]));

    $response->assertStatus(422);
});

it('passes goalie column group requests through fantrax league payload settings', function (): void {
    [$user, $league, , $player] = ($this->createConnectedLeagueFixture)();
    $league->update([
        'scoring_settings' => [
            'categories' => [
                ['short' => 'W', 'auto_stat_key' => 'wins'],
            ],
        ],
    ]);
    app()->instance(StatsPayloadBuilder::class, new class ($player) {
        public function __construct(private readonly Player $player)
        {
        }

        public function buildSeasonPayload(SeasonStatsPayloadRequest $request): array
        {
            return [[
                'headings' => [['key' => 'wins', 'label' => 'W']],
                'data' => [[
                    'player_id' => $this->player->id,
                    'nhl_player_id' => $this->player->nhl_id,
                    'wins' => 5,
                ]],
                'settings' => [
                    'columns' => $request->settings['columns'],
                    'filters' => $request->settings['filters'],
                ],
                'meta' => ['season' => '20252026', 'game_type' => 2],
            ], null, null, []];
        }
    });

    $response = $this->actingAs($user)->getJson(route('leagues.stats.payload', [
        'league_id' => $league->id,
        'column_group' => 'goalie',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.pos', ['G'])
        ->assertJsonPath('meta.pos_type', ['G'])
        ->assertJsonPath('settings.filters.pos_type.value', ['G'])
        ->assertJsonPath('settings.columns.0.key', 'gp');
});

it('aggregates league team payload rows across pro roster players', function (): void {
    $controller = app(\App\Http\Controllers\StatsController::class);
    $method = new ReflectionMethod($controller, 'teamAggregateLeaguePayload');
    $method->setAccessible(true);

    $payload = [
        'headings' => [
            ['key' => 'name', 'label' => 'Player'],
            ['key' => 'team', 'label' => 'Team'],
            ['key' => 'age', 'label' => 'Age'],
            ['key' => 'contract_value_num', 'label' => 'Cap'],
            ['key' => 'contract_last_year', 'label' => 'Term'],
            ['key' => 'g', 'label' => 'G'],
            ['key' => 'fantasy_pts_pg', 'label' => 'FP/G'],
        ],
        'data' => [
            [
                'name' => 'Player One',
                'fantasy_team_id' => 'team-1',
                'fantasy_team_name' => 'Division Team',
                'fantasy_team_avatar_url' => 'https://example.test/team.png',
                'fantasy_team_is_user_team' => true,
                'roster_group' => 'active',
                'roster_status' => 'active',
                'contract_value_num' => 1.0,
                'g' => 2,
                'fantasy_pts_pg' => 1.0,
                'stats' => ['contract_value_num' => 1.0, 'g' => 2, 'fantasy_pts_pg' => 1.0],
            ],
            [
                'name' => 'Player Two',
                'fantasy_team_id' => 'team-1',
                'fantasy_team_name' => 'Division Team',
                'fantasy_team_avatar_url' => 'https://example.test/team.png',
                'fantasy_team_is_user_team' => true,
                'roster_group' => 'reserve',
                'contract_value_num' => 2.0,
                'g' => 3,
                'fantasy_pts_pg' => 3.0,
                'stats' => ['contract_value_num' => 2.0, 'g' => 3, 'fantasy_pts_pg' => 3.0],
            ],
            [
                'name' => 'Goalie One',
                'fantasy_team_id' => 'team-1',
                'fantasy_team_name' => 'Division Team',
                'fantasy_team_avatar_url' => 'https://example.test/team.png',
                'fantasy_team_is_user_team' => true,
                'roster_group' => 'active',
                'roster_status' => 'active',
                'is_goalie' => true,
                'wins' => 4,
                'sv' => 120,
                'stats' => ['wins' => 4, 'sv' => 120],
            ],
            [
                'name' => 'Minor Player',
                'fantasy_team_id' => 'team-1',
                'fantasy_team_name' => 'Division Team',
                'roster_group' => 'minor',
                'contract_value_num' => 5.0,
                'g' => 10,
                'fantasy_pts_pg' => 10.0,
            ],
            [
                'league_roster_placeholder' => true,
                'fantasy_team_id' => 'team-1',
                'fantasy_team_name' => 'Division Team',
                'contract_value_num' => 7.0,
                'g' => 20,
                'fantasy_pts_pg' => 20.0,
            ],
        ],
        'settings' => [
            'ownerColumn' => true,
            'leaguePlatform' => 'fantrax',
            'columnGroups' => [
                'skater' => [
                    ['key' => 'g', 'label' => 'G'],
                    ['key' => 'fantasy_pts_pg', 'label' => 'FP/G'],
                ],
                'goalie' => [
                    ['key' => 'wins', 'label' => 'W'],
                    ['key' => 'sv', 'label' => 'SV'],
                ],
            ],
        ],
        'meta' => [],
    ];

    $result = $method->invoke($controller, $payload);

    expect(array_map(
        static fn (array $heading): array => [$heading['key'], $heading['label']],
        $result['headings'],
    ))->toBe([
        ['name', 'Team'],
        ['contract_value_num', 'Cap'],
        ['g', 'G'],
        ['fantasy_pts_pg', 'FP/G'],
        ['wins', 'W'],
        ['sv', 'SV'],
    ]);

    expect($result['settings']['resource'])->toBe('teams')
        ->and($result['settings']['teamAggregate'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['player_count'])->toBe(3)
        ->and($result['data'][0]['contract_value_num'])->toBe(3.0)
        ->and($result['data'][0]['g'])->toBe(5.0)
        ->and($result['data'][0]['fantasy_pts_pg'])->toBe(2.0)
        ->and($result['data'][0]['wins'])->toBe(4.0)
        ->and($result['data'][0]['sv'])->toBe(120.0)
        ->and($result['data'][0]['__team_average']['contract_value_num'])->toBe(1.5)
        ->and($result['data'][0]['__team_average']['g'])->toBe(2.5)
        ->and($result['data'][0]['__team_average']['wins'])->toBe(4.0);

    $startersResult = $method->invoke($controller, $payload, true);

    expect($startersResult['settings']['teamAggregateStartersOnly'])->toBeTrue()
        ->and($startersResult['meta']['teamAggregateStartersOnly'])->toBeTrue()
        ->and($startersResult['data'])->toHaveCount(1)
        ->and($startersResult['data'][0]['player_count'])->toBe(2)
        ->and($startersResult['data'][0]['contract_value_num'])->toBe(1.0)
        ->and($startersResult['data'][0]['g'])->toBe(2.0)
        ->and($startersResult['data'][0]['fantasy_pts_pg'])->toBe(1.0)
        ->and($startersResult['data'][0]['wins'])->toBe(4.0)
        ->and($startersResult['data'][0]['sv'])->toBe(120.0);
});
