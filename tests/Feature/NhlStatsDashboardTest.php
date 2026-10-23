<?php

declare(strict_types=1);

use App\Models\Perspective;
use App\Models\Player;
use App\Models\User;
use App\Events\NhlSeasonStatsSynced;
use App\Jobs\RefreshNhlStatsDashboardJob;
use App\Listeners\QueueNhlStatsDashboardRefresh;
use App\Services\NhlDashboardPayload;
use App\Services\NhlStatsDashboardPayload;
use App\Support\Stats\StatsPayloadBuilder;
use App\Support\Stats\StatsFilterSet;
use App\Support\Stats\SeasonStatsPayloadRequest;
use App\Models\NhlGameImportRun;
use App\Events\NhlStatsSourceUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Cache::forever(NhlStatsDashboardPayload::CACHE_PREFIX.'latest-season', '20262027');
    Bus::fake();
    Http::preventStrayRequests();
    User::factory()->create();
    $this->mock(NhlDashboardPayload::class, function ($mock): void {
        $mock->shouldReceive('statsHighlights')->andReturn([
            'performance_date' => '2026-10-22', 'team_trends' => [], 'player_trends' => [], 'line_combinations' => [],
        ]);
        $mock->shouldNotReceive('build');
    });
    DB::table('nhl_teams')->insertOrIgnore([
        ['nhl_id' => 10, 'abbrev' => 'TOR'], ['nhl_id' => 8, 'abbrev' => 'MTL'],
    ]);
    $this->player = Player::create(['nhl_id' => 9000001, 'first_name' => 'Alex', 'last_name' => 'Leader',
        'full_name' => 'Alex Leader', 'position' => 'C', 'team_abbrev' => 'TOR', 'head_shot_url' => '/leader.png', 'hero_image_url' => '/leader-action.jpg']);
    $this->goalie = Player::create(['nhl_id' => 9000002, 'first_name' => 'Sam', 'last_name' => 'Goalie',
        'full_name' => 'Sam Goalie', 'position' => 'G', 'is_goalie' => true, 'team_abbrev' => 'MTL']);
    DB::table('nhl_season_stats')->insert([
        ['season_id' => '20262027', 'game_type' => 2, 'nhl_player_id' => 9000001, 'nhl_team_id' => 10,
            'gp' => 2, 'g' => 3, 'a' => 5, 'pts' => 8, 'sog' => 20, 'h' => 4, 'toi' => 2400],
        ['season_id' => '20262027', 'game_type' => 2, 'nhl_player_id' => 9000002, 'nhl_team_id' => 8,
            'gp' => 2, 'g' => 0, 'a' => 0, 'pts' => 0, 'sog' => 0, 'h' => 0, 'toi' => 7200],
    ]);
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['ga' => 3, 'sv' => 57, 'sa' => 60, 'wins' => 1, 'so' => 1]);
    DB::table('nhl_games')->insert(['nhl_game_id' => 2026020001, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-22', 'game_state' => 'OFF', 'home_team_id' => 10, 'home_team_abbrev' => 'TOR',
        'away_team_id' => 8, 'away_team_abbrev' => 'MTL', 'home_team_score' => 4, 'away_team_score' => 2]);
    $this->facts = function (): array {
        $service = app(NhlStatsDashboardPayload::class);
        $service->refreshCachedFacts('20262027', 'leaders');
        return $service->build('20262027');
    };
    $this->publicReport = fn (): Perspective => Perspective::create(['name' => 'Skaters', 'slug' => 'skaters',
        'visibility' => 'public_guest', 'sport' => 'hockey', 'is_slicable' => true,
        'settings' => ['columns' => [['key' => 'pts', 'label' => 'PTS'], ['key' => 'g', 'label' => 'G']],
            'filters' => ['pos' => ['operator' => '!=', 'value' => 'G']]]]);
    $this->reportInput = fn (Perspective $perspective, array $query = []): SeasonStatsPayloadRequest => new SeasonStatsPayloadRequest(
        null, $perspective, $perspective->settings, true, '20262027', $query['slice'] ?? 'total', 2,
        Request::create('/api/stats', 'GET', $query), StatsFilterSet::fromRequest(Request::create('/api/stats', 'GET', $query)),
    );
    $this->prospectRows = function (array $rows): Player {
        $player = Player::create(['first_name' => 'Pat', 'last_name' => 'Prospect', 'full_name' => 'Pat Prospect',
            'position' => 'C', 'pos_type' => 'F', 'is_goalie' => false]);
        foreach ($rows as $row) {
            DB::table('stats')->insert(array_merge(['player_id' => $player->id, 'season_id' => '20262027',
                'league_abbrev' => 'OHL', 'team_name' => 'Prospect Club', 'is_prospect' => true,
                'game_type_id' => 2, 'gp' => 10, 'g' => 5, 'a' => 7, 'pts' => 12], $row));
        }

        return $player;
    };
    $this->teamShots = function (bool $complete = true, array $extra = []): void {
        DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['home_team_score' => 1, 'away_team_score' => 0]);
        $model = DB::table('nhl_expected_goals_models')->insertGetId(['name' => 'Team goals', 'version' => 'team-v1',
            'training_season_id' => '20262027', 'prediction_target' => 'goal', 'status' => 'draft']);
        foreach (array_merge([
            ['team_id' => 10, 'goalie_player_id' => 9000002, 'is_goal' => true, 'xg' => 0.2],
            ['team_id' => 10, 'goalie_player_id' => 9000002, 'is_goal' => false, 'xg' => 0.1],
            ['team_id' => 8, 'goalie_player_id' => 9000003, 'is_goal' => false, 'xg' => 0.1],
        ], $extra) as $index => $row) {
            $xg = $row['xg'];
            unset($row['xg']);
            $play = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => 2026020001]);
            $fact = DB::table('nhl_shot_attempts_facts')->insertGetId(array_merge([
                'play_by_play_id' => $play, 'nhl_game_id' => 2026020001, 'season_id' => '20262027',
                'is_shot_attempt' => true, 'attempt_result' => 'shot_on_goal', 'strength' => 'EV',
            ], $row));
            if ($complete || $index !== 1) {
                DB::table('nhl_shot_attempt_predictions')->insert(['expected_goals_model_id' => $model,
                    'shot_attempt_fact_id' => $fact, 'play_by_play_id' => $play, 'nhl_game_id' => 2026020001,
                    'prediction_target' => 'goal', 'is_scored' => true, 'xg' => $xg]);
            }
        }
    };
    $this->goalieScoring = function (bool $complete = true): void {
        DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['gp' => 1, 'ga' => 1]);
        $model = DB::table('nhl_expected_goals_models')->insertGetId(['name' => 'Stored season goals',
            'version' => 'fixture-v1', 'training_season_id' => '20262027', 'prediction_target' => 'goal', 'status' => 'draft']);
        foreach ([true, false] as $index => $goal) {
            $play = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => 2026020001]);
            $fact = DB::table('nhl_shot_attempts_facts')->insertGetId(['play_by_play_id' => $play,
                'nhl_game_id' => 2026020001, 'season_id' => '20262027', 'goalie_player_id' => 9000002,
                'is_goal' => $goal, 'is_shot_attempt' => true, 'attempt_result' => $goal ? 'goal' : 'shot_on_goal']);
            if ($complete || $index === 0) {
                DB::table('nhl_shot_attempt_predictions')->insert(['expected_goals_model_id' => $model,
                    'shot_attempt_fact_id' => $fact, 'play_by_play_id' => $play, 'nhl_game_id' => 2026020001,
                    'prediction_target' => 'goal', 'is_scored' => true, 'xg' => $goal ? 0.8 : 0.7]);
            }
        }
    };
});

afterEach(function (): void { $this->travelBack(); });

it('lands public stats on the leaders dashboard', function (): void {
    app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'leaders');
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stats/Index')->where('view', 'leaders')->missing('leaders')->missing('initialPayload')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->where('leaders.hero.name', 'Alex Leader')));
});
it('allows authenticated users to read the same public leaders', function (): void {
    app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'leaders');
    $this->actingAs(User::factory()->create())->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'leaders')->missing('leaders')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->where('leaders.hero.stats.points', 8)));
});
it('defaults to the latest positive regular-season stat season', function (): void {
    expect(($this->facts)()['season'])->toBe('20262027');
});
it('provides the grid and season strip leader categories', function (): void {
    expect(array_column(($this->facts)()['categories'], 'key'))->toBe(['points', 'goals', 'assists', 'ppg', 'sog', 'hits', 'ppp', 'blocks']);
});
it('provides the three goalie categories in the requested order', function (): void {
    expect(array_column(($this->facts)()['goalie_categories'], 'label'))->toBe(['Quality Starts', 'GSAx', 'EVSV%']);
});
it('features the quality-starts leader as the goalie hero using the goalie image and identity', function (): void {
    $this->goalie->update(['hero_image_url' => '/goalie-action.jpg']);
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['quality_starts' => 2, 'evsv' => 45, 'evsa' => 50]);
    $data = ($this->facts)();
    expect($data['goalie_hero']['id'])->toBe(9000002)->and($data['goalie_hero']['name'])->toBe('Sam Goalie')
        ->and($data['goalie_hero']['hero'])->toBe('/goalie-action.jpg')
        ->and($data['goalie_hero']['ranks']['quality_starts'])->toBe(1)
        ->and($data['goalie_hero']['ranks']['ev_sv_pct'])->toBe(1)
        ->and($data['goalie_hero']['ranks']['gsax'])->toBeNull()
        ->and($data['hero']['id'])->toBe(9000001);
});
it('does not replace an unavailable goalie hero with the skater points leader', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['gp' => 0]);
    $data = ($this->facts)();
    expect($data['goalie_hero'])->toBeNull()->and($data['hero']['id'])->toBe(9000001);
});
it('changes the goalie hero when another goalie has more quality starts', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['quality_starts' => 2]);
    Player::create(['nhl_id' => 9000003, 'first_name' => 'New', 'last_name' => 'Leader',
        'full_name' => 'New Leader', 'position' => 'G', 'team_abbrev' => 'TOR', 'hero_image_url' => '/new-goalie.jpg']);
    DB::table('nhl_season_stats')->insert(['season_id' => '20262027', 'game_type' => 2,
        'nhl_player_id' => 9000003, 'nhl_team_id' => 10, 'gp' => 4, 'quality_starts' => 3]);
    $hero = ($this->facts)()['goalie_hero'];
    expect($hero['id'])->toBe(9000003)->and($hero['hero'])->toBe('/new-goalie.jpg')
        ->and($hero['stats']['quality_starts'])->toBe(3)->and($hero['ranks']['quality_starts'])->toBe(1);
});
it('reads the goalie hero sweater number from the latest actual appearance', function (): void {
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 9000002,
        'nhl_team_id' => 8, 'toi_seconds' => 3600, 'sweater_number' => 31]);
    expect(($this->facts)()['goalie_hero']['number'])->toBe(31);
});
it('shows goalie hero quality-start percentage from starts and total saves', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['gp' => 4, 'starts' => 4, 'quality_starts' => 2]);
    $hero = ($this->facts)()['goalie_hero'];
    expect($hero['stats']['quality_start_pct'])->toBe(50.0)->and($hero['stats']['saves'])->toBe(57)
        ->and($hero['ranks']['quality_start_pct'])->toBe(1)->and($hero['ranks']['saves'])->toBe(1);
});
it('withholds quality-start percentage and its rank when starts are unavailable', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['starts' => 0]);
    $hero = ($this->facts)()['goalie_hero'];
    expect($hero['stats']['quality_start_pct'])->toBeNull()->and($hero['ranks']['quality_start_pct'])->toBeNull();
});
it('keeps the hero based on quality-start count while ranking quality-start percentage and saves independently', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['gp' => 4, 'starts' => 4, 'quality_starts' => 3]);
    Player::create(['nhl_id' => 9000003, 'first_name' => 'Another', 'last_name' => 'Goalie',
        'full_name' => 'Another Goalie', 'position' => 'G', 'team_abbrev' => 'TOR']);
    DB::table('nhl_season_stats')->insert(['season_id' => '20262027', 'game_type' => 2,
        'nhl_player_id' => 9000003, 'nhl_team_id' => 10, 'gp' => 2, 'starts' => 2, 'quality_starts' => 2, 'sv' => 70]);
    $hero = ($this->facts)()['goalie_hero'];
    expect($hero['id'])->toBe(9000002)->and($hero['stats']['quality_start_pct'])->toBe(75.0)
        ->and($hero['ranks']['quality_start_pct'])->toBe(2)->and($hero['ranks']['saves'])->toBe(2);
});
it('ranks goalie quality starts and even-strength save percentage from their own totals', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['quality_starts' => 2, 'evsv' => 45, 'evsa' => 50]);
    $categories = collect(($this->facts)()['goalie_categories'])->keyBy('key');
    expect($categories['quality_starts']['player']['id'])->toBe(9000002)
        ->and($categories['quality_starts']['player']['stats']['quality_starts'])->toBe(2)
        ->and($categories['ev_sv_pct']['player']['stats']['ev_sv_pct'])->toBe(90.0);
});
it('withholds EVSV percentage when no even-strength shots were faced', function (): void {
    expect(collect(($this->facts)()['goalie_categories'])->firstWhere('key', 'ev_sv_pct')['player'])->toBeNull();
});
it('keeps GSAx unavailable without scored goalie expectations', function (): void {
    expect(collect(($this->facts)()['goalie_categories'])->firstWhere('key', 'gsax')['player'])->toBeNull();
});
it('calculates goalie GSAx as scored xGA minus the matching goals allowed', function (): void {
    ($this->goalieScoring)();
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBe(0.5);
});
it('uses a model trained in an earlier season when it scores the displayed season', function (): void {
    ($this->goalieScoring)();
    DB::table('nhl_expected_goals_models')->update(['training_season_id' => '20252026', 'status' => 'published']);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBe(0.5);
});
it('reads newly imported SAT model ratings when no legacy predictions exist', function (): void {
    ($this->goalieScoring)();
    foreach (DB::table('nhl_shot_attempt_predictions')->get() as $prediction) {
        DB::table('nhl_shot_attempt_model_scores')->insert([
            'expected_goals_model_id' => $prediction->expected_goals_model_id, 'shot_attempt_fact_id' => $prediction->shot_attempt_fact_id,
            'play_by_play_id' => $prediction->play_by_play_id, 'nhl_game_id' => $prediction->nhl_game_id,
            'prediction_target' => 'goal', 'is_scored' => true, 'probability' => $prediction->xg,
        ]);
    }
    DB::table('nhl_shot_attempt_predictions')->delete();
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBe(0.5);
});
it('lets an excluded current SAT rating override an older scored prediction', function (): void {
    ($this->goalieScoring)();
    $prediction = DB::table('nhl_shot_attempt_predictions')->first();
    DB::table('nhl_shot_attempt_model_scores')->insert([
        'expected_goals_model_id' => $prediction->expected_goals_model_id, 'shot_attempt_fact_id' => $prediction->shot_attempt_fact_id,
        'play_by_play_id' => $prediction->play_by_play_id, 'nhl_game_id' => $prediction->nhl_game_id,
        'prediction_target' => 'goal', 'is_scored' => false, 'exclusion_reason' => 'missing_shooter',
    ]);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBeNull();
});
it('matches Test profile goal expectations for a SOG-trained model', function (): void {
    ($this->goalieScoring)();
    DB::table('nhl_expected_goals_models')->update(['feature_config' => json_encode(['sample_mode' => 'sog'])]);
    DB::table('nhl_shot_attempts_facts')->where('is_goal', true)->update(['is_shot_on_goal' => true]);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toEqualWithDelta(-0.2, 0.000001);
});
it('uses imported per-attempt ratings for the Advanced Leaders on-ice xGF total', function (): void {
    ($this->goalieScoring)();
    $unit = DB::table('nhl_units')->insertGetId(['team_abbrev' => 'TOR', 'unit_type' => 'F']);
    $shift = DB::table('nhl_unit_shifts')->insertGetId([
        'unit_id' => $unit, 'nhl_game_id' => 2026020001, 'team_id' => 10, 'team_abbrev' => 'TOR',
        'period' => 1, 'start_time' => '00:00', 'end_time' => '01:00',
        'start_game_seconds' => 0, 'end_game_seconds' => 60, 'seconds' => 60,
    ]);
    DB::table('nhl_unit_shift_players')->insert(['unit_shift_id' => $shift, 'player_id' => $this->player->id]);
    DB::table('nhl_shot_attempts_facts')->update(['team_id' => 10]);
    foreach (DB::table('nhl_shot_attempt_predictions')->get() as $prediction) {
        DB::table('event_unit_shifts')->insert(['event_id' => $prediction->play_by_play_id, 'unit_shift_id' => $shift]);
        DB::table('nhl_shot_attempt_model_scores')->insert([
            'expected_goals_model_id' => $prediction->expected_goals_model_id, 'shot_attempt_fact_id' => $prediction->shot_attempt_fact_id,
            'play_by_play_id' => $prediction->play_by_play_id, 'nhl_game_id' => $prediction->nhl_game_id,
            'prediction_target' => 'goal', 'is_scored' => true, 'probability' => $prediction->xg,
        ]);
    }
    DB::table('nhl_shot_attempt_predictions')->delete();
    expect(($this->facts)()['hero']['stats']['xgf'])->toBe(1.5);
});
it('ignores a newer same-season model that has no scored attempts for the displayed season', function (): void {
    ($this->goalieScoring)();
    DB::table('nhl_expected_goals_models')->update(['training_season_id' => '20252026', 'status' => 'published']);
    DB::table('nhl_expected_goals_models')->insert(['name' => 'Unscored draft', 'version' => 'fixture-v2',
        'training_season_id' => '20262027', 'prediction_target' => 'goal', 'status' => 'draft', 'trained_at' => now()]);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBe(0.5);
});
it('explains missing goalie scores without claiming GSAx is zero', function (): void {
    $data = ($this->facts)();
    expect($data['goalie_hero']['stats']['gsax'])->toBeNull()
        ->and($data['goalie_hero']['gsax_reason'])->toBe('No scored xG data is available for this season.');
});
it('exposes the incomplete scoring count as the goalie GSAx reason', function (): void {
    ($this->goalieScoring)(false);
    expect(($this->facts)()['goalie_hero']['gsax_reason'])->toBe('1 of 2 attempts have xG scores.');
});
it('keeps table-view leader payloads compact and skips unused dashboard rows', function (): void {
    app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'skaters');
    $data = app(NhlStatsDashboardPayload::class)->build('20262027', 'skaters');
    expect($data['hero']['id'])->toBe(9000001)->and($data['skaters'])->toBe([])
        ->and($data['goalies'])->toBe([])->and($data['teams'])->toBe([]);
});
it('withholds goalie GSAx when any faced attempt lacks a score', function (): void {
    ($this->goalieScoring)(false);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBeNull();
});
it('withholds goalie GSAx when facts do not cover the counted goalie games', function (): void {
    ($this->goalieScoring)();
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['gp' => 2]);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBeNull();
});
it('withholds goalie GSAx when actual goals disagree with fact coverage', function (): void {
    ($this->goalieScoring)();
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['ga' => 2]);
    expect(($this->facts)()['goalies'][0]['stats']['gsax'])->toBeNull();
});
it('reads power-play points and blocks from their own season totals', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['ppp' => 5, 'b' => 11]);
    $categories = collect(($this->facts)()['categories'])->keyBy('key');
    expect($categories['ppp']['label'])->toBe('PPP')->and($categories['ppp']['player']['stats']['ppp'])->toBe(5)
        ->and($categories['blocks']['label'])->toBe('Blocks')->and($categories['blocks']['player']['stats']['blocks'])->toBe(11);
});
it('provides season skater peripheral totals for Smash Potatoes', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['sog' => 20, 'h' => 14, 'b' => 9, 'pim' => 16]);
    $skater = ($this->facts)()['skaters'][0];
    expect($skater['gp'])->toBe(2)->and($skater['stats']['sog'])->toBe(20)
        ->and($skater['stats']['hits'])->toBe(14)->and($skater['stats']['blocks'])->toBe(9)
        ->and($skater['stats']['pim'])->toBe(16);
});
it('uses the actual points leader identity and stored action photo', function (): void {
    $hero = ($this->facts)()['hero'];
    expect($hero['id'])->toBe(9000001)->and($hero['hero'])->toBe('/leader-action.jpg')->and($hero['stats']['points'])->toBe(8);
});
it('keeps skaters and goalies in separate candidate lists', function (): void {
    $data = ($this->facts)();
    expect(array_column($data['skaters'], 'id'))->toBe([9000001])->and(array_column($data['goalies'], 'id'))->toBe([9000002]);
});
it('excludes players with no games instead of manufacturing rates', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['gp' => 0]);
    expect(($this->facts)()['hero'])->toBeNull();
});
it('does not mix preseason totals into regular-season leaders', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['game_type' => 1]);
    expect(($this->facts)()['skaters'])->toBe([]);
});
it('derives points and time per game from matching totals', function (): void {
    $stats = ($this->facts)()['hero']['stats'];
    expect($stats['ppg'])->toBe(4.0)->and($stats['toi'])->toBe(1200.0);
});
it('keeps unavailable ice time unavailable', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['toi' => null]);
    expect(($this->facts)()['hero']['stats']['toi'])->toBeNull();
});
it('calculates goalie GAA from goals against and ice-time seconds', function (): void {
    expect(($this->facts)()['goalies'][0]['stats']['gaa'])->toBe(1.5);
});
it('calculates save percentage using saves divided by shots against', function (): void {
    expect(($this->facts)()['goalies'][0]['stats']['sv_pct'])->toBe(95.0);
});
it('withholds goalie rates with missing denominators', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['sa' => 0, 'toi' => 0]);
    $goalie = ($this->facts)()['goalies'][0]['stats'];
    expect($goalie['gaa'])->toBeNull()->and($goalie['sv_pct'])->toBeNull();
});
it('leaves previous-season comparisons unknown without history', function (): void {
    expect(($this->facts)()['hero']['deltas']['points'])->toBeNull();
});
it('compares per-game production rather than unequal season totals', function (): void {
    DB::table('nhl_season_stats')->insert(['season_id' => '20252026', 'game_type' => 2,
        'nhl_player_id' => 9000001, 'nhl_team_id' => 10, 'gp' => 80, 'pts' => 160]);
    expect(($this->facts)()['hero']['deltas']['points'])->toBe(100.0);
});
it('uses actual team GF rather than summed player season goals', function (): void {
    $team = collect(($this->facts)()['teams'])->firstWhere('team', 'TOR');
    expect($team['gp'])->toBe(1)->and($team['gf'])->toBe(4)->and($team['gf_pg'])->toBe(4.0);
});
it('keeps the two teams goals against and goals for distinct', function (): void {
    $teams = collect(($this->facts)()['teams']);
    expect($teams->firstWhere('team', 'TOR')['ga_pg'])->toBe(2.0)
        ->and($teams->firstWhere('team', 'MTL')['ga_pg'])->toBe(4.0);
});
it('does not present missing team boxscore totals as zero', function (): void {
    expect(collect(($this->facts)()['teams'])->firstWhere('team', 'TOR')['points'])->toBeNull();
});
it('reads team points from actual team-game boxscores instead of current player assignments', function (): void {
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 9000001,
        'nhl_team_id' => 10, 'points' => 6, 'assists' => 4, 'sog' => 7, 'hits' => 2, 'power_play_goals' => 1]);
    $team = collect(($this->facts)()['teams'])->firstWhere('team', 'TOR');
    expect($team['points'])->toBe(6)->and($team['sog'])->toBe(7)->and($team['ppg_pg'])->toBe(1.0);
});
it('excludes unplayed future games from team totals', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_state' => 'FUT']);
    expect(($this->facts)()['teams'])->toBe([]);
});
it('does not infer rookie eligibility from the prospect flag', function (): void {
    $this->player->update(['is_prospect' => true]);
    expect(($this->facts)()['rookies'])->toBe([])->and(($this->facts)()['rookie_note'])->toContain('No eligible rookie skaters');
});
it('lists an NHL-only eligible rookie using the non-drafting service mode', function (): void {
    $this->player->update(['dob' => '2005-01-01', 'is_prospect' => false]);
    \App\Models\PlayerExternalIdentity::query()->create([
        'player_id' => $this->player->id, 'provider' => 'nhl_draft', 'provider_player_id' => 'rookie-fixture',
    ]);
    \App\Models\Stat::query()->create([
        'player_id' => $this->player->id, 'season_id' => '20262027', 'league_abbrev' => 'NHL',
        'team_name' => 'Toronto Maple Leafs', 'game_type_id' => 2, 'gp' => 2,
    ]);
    ($this->facts)();
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload
            ->where('leaders.rookies.0.id', 9000001)->where('leaders.rookies.0.stats.points', 8)));
    expect(app(\App\Services\ProspectEligibilityService::class)->isProspect(9000001))->toBeFalse();
});
it('withholds xGF when no scored goal model is available', function (): void {
    expect(($this->facts)()['hero']['stats']['xgf'])->toBeNull();
});
it('does not fetch editorial feeds or remote stats for the dashboard', function (): void {
    ($this->facts)();
    Http::assertNothingSent();
});
it('retains explicit historical season selection', function (): void {
    $data = app(NhlStatsDashboardPayload::class)->build('20252026');
    expect($data['season'])->toBe('20252026')->and($data['hero'])->toBeNull();
});
it('keeps the compatibility props available on the new landing', function (): void {
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('initialPayload')->has('perspectives')->where('apiUrl', url('/api/stats'))
        ->reloadOnly('initialPayload', fn (Assert $reload) => $reload->has('initialPayload.headings')->has('initialPayload.data')));
});
it('maps the goalies category to the existing public goalie perspective', function (): void {
    Perspective::create(['name' => 'Goalies', 'slug' => 'goalies', 'visibility' => 'public_guest', 'sport' => 'hockey',
        'is_slicable' => true, 'settings' => ['columns' => [], 'filters' => []]]);
    $this->get('/stats?view=goalies')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'goalies')->where('selectedPerspective', 'goalies')->missing('initialPayload')
        ->loadDeferredProps('stats-table', fn (Assert $reload) => $reload->has('initialPayload')->missing('leaders')));
});
it('preserves existing explicit perspective links as table views', function (): void {
    $this->get('/stats?perspective=skaters')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view', 'skaters'));
});
it('provides a distinct teams category without replacing the route', function (): void {
    app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'teams');
    $this->get('/stats?view=teams')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view', 'teams')->missing('leaders')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->has('leaders.teams', 2)));
});
it('does not build leader facts before returning the initial page shell', function (): void {
    $this->mock(NhlStatsDashboardPayload::class, function ($mock): void {
        $mock->shouldNotReceive('build');
        $mock->shouldReceive('latestPreparedSeason')->andReturn('20262027');
    });
    $this->get('/stats?view=skaters')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'skaters')->missing('leaders')->missing('initialPayload')->where('selectedSeason', '20262027'));
});
it('rejects an unsupported category value', function (): void {
    $this->getJson('/stats?view=unknown')->assertUnprocessable()->assertJsonValidationErrors('view');
});

it('queues public stats refreshes from the committed season-sync event', function (): void {
    Queue::fake();
    NhlSeasonStatsSynced::dispatch('20262027', 2);
    Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, fn ($job): bool =>
        $job->class === QueueNhlStatsDashboardRefresh::class && $job->queue === 'projections');
});

it('queues each public view for the affected season', function (): void {
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    Bus::assertDispatchedTimes(RefreshNhlStatsDashboardJob::class, 5);
    foreach (['leaders', 'skaters', 'goalies', 'teams'] as $view) {
        Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool =>
            $job->seasonId === '20262027' && $job->view === $view && $job->queue === 'projections' && $job->afterCommit);
    }
});

it('refreshes following-season comparisons when previous-season summaries change', function (): void {
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20252026', 1));
    Bus::assertDispatchedTimes(RefreshNhlStatsDashboardJob::class, 9);
    Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool => $job->seasonId === '20262027');
});

it('coalesces repeated queued refreshes for the same public season and view', function (): void {
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    Bus::assertDispatchedTimes(RefreshNhlStatsDashboardJob::class, 5);
});

it('scopes refresh ownership by season and view', function (): void {
    $one = new RefreshNhlStatsDashboardJob('20262027', 'leaders');
    $otherView = new RefreshNhlStatsDashboardJob('20262027', 'goalies');
    $otherSeason = new RefreshNhlStatsDashboardJob('20252026', 'leaders');
    expect($one->uniqueId())->not->toBe($otherView->uniqueId())->not->toBe($otherSeason->uniqueId())
        ->and($one)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class)
        ->and($one->middleware()[0])->toBeInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class);
});

it('publishes fresh public facts under the existing season and view key', function (): void {
    $payload = app(NhlStatsDashboardPayload::class);
    $payload->build('20262027', 'skaters');
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['pts' => 12]);
    (new RefreshNhlStatsDashboardJob('20262027', 'skaters'))->handle($payload);
    $this->get('/stats?view=skaters')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->where('leaders.hero.stats.points', 12)));
    expect(Cache::get(NhlStatsDashboardPayload::CACHE_PREFIX.'skaters:20262027')['hero']['stats']['points'])->toBe(12);
});

it('retains published season facts beyond the old five-minute expiry', function (): void {
    $payload = app(NhlStatsDashboardPayload::class);
    $payload->refreshCachedFacts('20262027', 'skaters');
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['pts' => 99]);
    $this->travel(6)->minutes();
    expect($payload->build('20262027', 'skaters')['hero']['stats']['points'])->toBe(8);
});

it('keeps the last successful payload when a background refresh fails', function (): void {
    $payload = app(NhlStatsDashboardPayload::class);
    $original = $payload->refreshCachedFacts('20262027', 'skaters');
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        if (str_contains($query->sql, 'nhl_season_stats')) {
            throw new \RuntimeException('Refresh interrupted');
        }
    });
    expect(fn () => $payload->refreshCachedFacts('20262027', 'skaters'))->toThrow(\RuntimeException::class);
    expect(Cache::get(NhlStatsDashboardPayload::CACHE_PREFIX.'skaters:20262027'))->toBe($original);
});

it('rejects private or unsupported report names as public cache refresh targets', function (): void {
    expect(fn () => new RefreshNhlStatsDashboardJob('20262027', 'private-perspective'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'private-perspective'))
        ->toThrow(\InvalidArgumentException::class);
});

it('prepares four mobile rows with the requested headings and metric order', function (): void {
    $rows = ($this->facts)()['mobile_leaders'];
    expect(array_column($rows, 'title'))->toBe(['All Skaters', 'All Goalies', 'All Teams', 'All Prospects'])
        ->and(array_column($rows[0]['cards'], 'label'))->toBe(['G', 'A', 'PPP', 'SOG', 'Blocks', 'Hits'])
        ->and(array_column($rows[1]['cards'], 'label'))->toBe(['W', 'EVSV%', 'QS', 'GSAx', 'SVs', 'QS%'])
        ->and(array_column($rows[2]['cards'], 'label'))->toBe(['W%', 'G', 'Corsi%', 'GAx', 'GSAx'])
        ->and(array_column($rows[3]['cards'], 'label'))->toBe(['G', 'A', 'PTS', 'G/GP', 'PTS/GP']);
});

it('selects a separate skater leader for each mobile metric', function (): void {
    Player::create(['nhl_id' => 9000004, 'first_name' => 'Goal', 'last_name' => 'Leader', 'full_name' => 'Goal Leader', 'position' => 'R']);
    DB::table('nhl_season_stats')->insert(['season_id' => '20262027', 'game_type' => 2, 'nhl_player_id' => 9000004,
        'gp' => 1, 'g' => 10, 'pts' => 10, 'ppp' => 6]);
    $cards = collect(($this->facts)()['mobile_leaders'][0]['cards'])->keyBy('key');
    expect($cards['goals']['entity']['name'])->toBe('Goal Leader')->and($cards['assists']['entity']['name'])->toBe('Alex Leader')
        ->and($cards['ppp']['value'])->toBe(6);
});

it('ranks goalie quality start count independently from quality start percentage', function (): void {
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000002)->update(['quality_starts' => 1, 'starts' => 2]);
    Player::create(['nhl_id' => 9000004, 'first_name' => 'Busy', 'last_name' => 'Goalie', 'full_name' => 'Busy Goalie', 'position' => 'G', 'is_goalie' => true]);
    DB::table('nhl_season_stats')->insert(['season_id' => '20262027', 'game_type' => 2, 'nhl_player_id' => 9000004,
        'gp' => 8, 'starts' => 8, 'quality_starts' => 2, 'wins' => 4, 'evsv' => 20, 'evsa' => 25]);
    $cards = collect(($this->facts)()['mobile_leaders'][1]['cards'])->keyBy('key');
    expect($cards['quality_starts']['entity']['name'])->toBe('Busy Goalie')
        ->and($cards['quality_start_pct']['entity']['name'])->toBe('Sam Goalie')
        ->and($cards['quality_start_pct']['value'])->toBe(50.0)->and($cards['wins']['value'])->toBe(4)
        ->and($cards['ev_sv_pct']['value'])->toBe(80.0)->and($cards['gsax']['value'])->toBeNull();
});

it('uses the non NHL Prospects report population rather than NHL rookie leaders', function (): void {
    ($this->prospectRows)([[], ['league_abbrev' => 'NHL', 'g' => 999, 'pts' => 999],
        ['is_prospect' => false, 'g' => 999, 'pts' => 999], ['game_type_id' => 3, 'g' => 999, 'pts' => 999],
        ['season_id' => '20252026', 'g' => 999, 'pts' => 999]]);
    $cards = collect(($this->facts)()['mobile_leaders'][3]['cards'])->keyBy('key');
    expect($cards['goals']['value'])->toBe(5)->and($cards['points']['value'])->toBe(12)
        ->and($cards['goals']['entity']['name'])->toBe('Pat Prospect')->and($cards['goals']['entity']['subtitle'])->toBe('OHL');
});

it('derives prospect rates from pooled totals and games rather than averaging stored rates', function (): void {
    ($this->prospectRows)([['gp' => 2, 'g' => 2, 'a' => 2, 'pts' => 4, 'g_per_gp' => 99],
        ['gp' => 8, 'g' => 0, 'a' => 6, 'pts' => 6, 'g_per_gp' => 99]]);
    $cards = collect(($this->facts)()['mobile_leaders'][3]['cards'])->keyBy('key');
    expect($cards['goals']['value'])->toBe(2)->and($cards['g_per_gp']['value'])->toBe(0.2)
        ->and($cards['pts_per_gp']['value'])->toBe(1.0);
});

it('keeps different non NHL prospect leagues separate', function (): void {
    ($this->prospectRows)([['league_abbrev' => 'AHL', 'gp' => 10, 'g' => 2], ['league_abbrev' => 'OHL', 'gp' => 5, 'g' => 10]]);
    $cards = collect(($this->facts)()['mobile_leaders'][3]['cards'])->keyBy('key');
    expect($cards['goals']['value'])->toBe(10)->and($cards['goals']['entity']['subtitle'])->toBe('OHL')
        ->and($cards['g_per_gp']['value'])->toBe(2.0);
});

it('withholds unavailable prospect leaders instead of substituting NHL players', function (): void {
    $row = ($this->facts)()['mobile_leaders'][3];
    expect($row['href'])->toContain('perspective=prospects')->and(array_filter(array_column($row['cards'], 'entity')))->toBe([]);
});

it('calculates team Corsi GAx and GSAx from team matched attempts', function (): void {
    ($this->teamShots)();
    $teams = collect(($this->facts)()['teams'])->keyBy('team');
    expect($teams['TOR']['win_pct'])->toBe(100.0)->and($teams['TOR']['cf_pct'])->toEqualWithDelta(200 / 3, 0.00001)
        ->and($teams['TOR']['gax'])->toEqualWithDelta(0.7, 0.00001)
        ->and($teams['TOR']['gsax'])->toEqualWithDelta(0.1, 0.00001)
        ->and($teams['MTL']['gsax'])->toEqualWithDelta(-0.7, 0.00001);
});

it('withholds team expectations with incomplete scoring while retaining factual Corsi', function (): void {
    ($this->teamShots)(false);
    $teams = collect(($this->facts)()['teams'])->keyBy('team');
    expect($teams['TOR']['gax'])->toBeNull()->and($teams['MTL']['gsax'])->toBeNull()
        ->and($teams['TOR']['cf_pct'])->not->toBeNull()->and($teams['TOR']['gsax'])->toEqualWithDelta(0.1, 0.00001);
});

it('excludes empty net and shootout goals from matched team expectation calculations', function (): void {
    ($this->teamShots)(true, [
        ['team_id' => 10, 'goalie_player_id' => null, 'is_goal' => true, 'is_empty_net' => true, 'xg' => 0.9],
        ['team_id' => 10, 'goalie_player_id' => 9000002, 'is_goal' => true, 'period_type' => 'SO', 'xg' => 0.9],
    ]);
    $teams = collect(($this->facts)()['teams'])->keyBy('team');
    expect($teams['TOR']['gax'])->toEqualWithDelta(0.7, 0.00001)->and($teams['MTL']['gsax'])->toEqualWithDelta(-0.7, 0.00001);
});

it('publishes prepared mobile prospect leaders through the public dashboard response', function (): void {
    ($this->prospectRows)([[]]);
    (new RefreshNhlStatsDashboardJob('20262027', 'leaders'))->handle(app(NhlStatsDashboardPayload::class));
    expect(Cache::get(NhlStatsDashboardPayload::CACHE_PREFIX.'leaders:20262027')['mobile_leaders'][3]['cards'][0]['value'])->toBe(5);
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload
            ->where('leaders.mobile_leaders.3.title', 'All Prospects')
            ->where('leaders.mobile_leaders.3.cards.0.value', 5)
            ->where('leaders.mobile_leaders.3.cards.0.entity.name', 'Pat Prospect')));
});

it('does not add a minimum GP threshold to prospect rate leaders or include prospect goalies', function (): void {
    ($this->prospectRows)([['gp' => 1, 'g' => 2, 'a' => 1, 'pts' => 3]]);
    $goalie = ($this->prospectRows)([['gp' => 1, 'g' => 99, 'a' => 99, 'pts' => 198]]);
    $goalie->update(['position' => 'G', 'pos_type' => 'G', 'is_goalie' => true]);
    $cards = collect(($this->facts)()['mobile_leaders'][3]['cards'])->keyBy('key');
    expect($cards['g_per_gp']['value'])->toBe(2.0)->and($cards['pts_per_gp']['value'])->toBe(3.0);
});


it('builds and retains missing Leaders during the requesting visit', function (): void {
    $facts = app(NhlStatsDashboardPayload::class)->build('20262027');
    expect($facts['cache_status'])->toBe('ready')->and($facts['hero']['name'])->toBe('Alex Leader')
        ->and(Cache::has(NhlStatsDashboardPayload::CACHE_PREFIX.'leaders:20262027'))->toBeTrue();
    Bus::assertNothingDispatched();
});

it('reads prepared leaders without touching source statistics', function (): void {
    ($this->facts)();
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['pts' => 99]);
    DB::enableQueryLog();
    $facts = app(NhlStatsDashboardPayload::class)->build('20262027');
    expect($facts['hero']['stats']['points'])->toBe(8)->and($facts['cache_status'])->toBe('ready');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('repairs missing default-season metadata and reuses it on later requests', function (): void {
    Cache::forget(NhlStatsDashboardPayload::CACHE_PREFIX.'latest-season');
    expect(app(NhlStatsDashboardPayload::class)->latestPreparedSeason())->toBe('20262027')
        ->and(Cache::get(NhlStatsDashboardPayload::CACHE_PREFIX.'latest-season'))->toBe('20262027');
    DB::enableQueryLog();
    expect(app(NhlStatsDashboardPayload::class)->latestPreparedSeason())->toBe('20262027')
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('includes highlights and public report jobs in source-triggered preparation', function (): void {
    $perspective = ($this->publicReport)();
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool => $job->view === 'highlights');
    foreach ([1, 2, 3] as $type) {
        Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool => $job->view === 'table'
            && $job->perspectiveId === $perspective->id && $job->gameType === $type && $job->queue === 'projections');
    }
});

it('never prepares private perspectives in the shared report cache', function (): void {
    $perspective = ($this->publicReport)();
    $perspective->update(['visibility' => 'private']);
    expect(fn () => app(StatsPayloadBuilder::class)->refreshPreparedSeasonPayload($perspective, '20262027', 2))
        ->toThrow(\InvalidArgumentException::class);
    (new QueueNhlStatsDashboardRefresh())->queueSeason('20262027');
    Bus::assertNotDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool => $job->view === 'table');
});

it('publishes complete report slices and serves the prepared table through HTTP', function (): void {
    $perspective = ($this->publicReport)();
    (new RefreshNhlStatsDashboardJob('20262027', 'table', $perspective->id))->handle(app(NhlStatsDashboardPayload::class));
    DB::table('nhl_season_stats')->where('nhl_player_id', 9000001)->update(['pts' => 99]);
    $this->getJson('/api/stats?perspective=skaters&season_id=20262027')->assertOk()
        ->assertJsonPath('data.0.pts', 8)->assertJsonPath('meta.cache_status', 'ready');
    $builder = app(StatsPayloadBuilder::class);
    [$pgp] = $builder->readPreparedSeasonPayload(($this->reportInput)($perspective, ['slice' => 'pgp']));
    expect($pgp['data'][0]['pts'])->toBe(4.0);
});

it('builds a missing public report on demand and caches all slices', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    [$payload] = $builder->readPreparedSeasonPayload(($this->reportInput)($perspective));
    expect($payload['meta']['cache_status'])->toBe('ready')->and($payload['data'][0]['pts'])->toBe(8);
    expect(array_keys(Cache::get($builder->preparedKey($perspective, '20262027', 2))))->toBe(['total', 'pgp', 'p60']);
    Bus::assertNothingDispatched();
});

it('filters prepared reports using total values while displaying a rate slice', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    $builder->refreshPreparedSeasonPayload($perspective, '20262027', 2);
    [$payload] = $builder->readPreparedSeasonPayload(($this->reportInput)($perspective, ['slice' => 'pgp', 'gp_min' => 3]));
    expect($payload['data'])->toBe([])->and($payload['meta']['appliedFilters']['gp']['min'])->toBe(3.0);
});

it('keeps report cache identities separate by perspective settings and game type', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    $original = $builder->preparedKey($perspective, '20262027', 2);
    expect($builder->preparedKey($perspective, '20262027', 3))->not->toBe($original);
    $perspective->settings = ['columns' => [['key' => 'g', 'label' => 'Goals']]];
    expect($builder->preparedKey($perspective, '20262027', 2))->not->toBe($original);
});

it('tracks the preparation batch on its originating season sync', function (): void {
    $run = NhlGameImportRun::create(['action' => 'season-sync', 'mode' => 'season', 'status' => 'running',
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'payload' => ['season' => '20262027']]);
    (new QueueNhlStatsDashboardRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2, $run->id));
    Bus::assertBatched(fn ($batch): bool => $batch->name === 'Public stats · 20262027'
        && count($batch->jobs) >= 5 && $batch->options['queue'] === 'projections' && $batch->options['allowFailures'] === true);
});

it('queues preparation when ratings or imported player histories change', function (): void {
    Queue::fake();
    NhlStatsSourceUpdated::dispatch('20262027');
    Queue::assertPushed(\Illuminate\Events\CallQueuedListener::class, fn ($job): bool =>
        $job->class === QueueNhlStatsDashboardRefresh::class && $job->queue === 'projections');
});

it('retains prepared report bundles after a failed replacement', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    $builder->refreshPreparedSeasonPayload($perspective, '20262027', 2);
    $key = $builder->preparedKey($perspective, '20262027', 2);
    $original = Cache::get($key);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        if (str_contains($query->sql, 'nhl_season_stats')) {
            throw new \RuntimeException('Report refresh interrupted');
        }
    });
    expect(fn () => $builder->refreshPreparedSeasonPayload($perspective, '20262027', 2))->toThrow(\RuntimeException::class);
    expect(Cache::get($key))->toBe($original);
});


it('keeps season sync running when aggregation finishes before cache preparation', function (): void {
    $run = NhlGameImportRun::create(['action' => 'season-sync', 'mode' => 'season', 'status' => 'queued',
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'payload' => ['season' => '20262027']]);
    $service = Mockery::mock(\App\Services\SumNhlSeasonStats::class);
    $service->shouldReceive('sum')->once()->with('20262027', $run->id)->andReturn(2);
    (new \App\Jobs\SeasonSumJob('20262027', $run->id))->handle($service);
    $run->refresh();
    expect($run->status)->toBe('running')->and($run->payload['rows_upserted'])->toBe(2)
        ->and($run->payload['stats_cache']['status'])->toBe('queued');
});

it('completes season sync only when prepared reports are published', function (): void {
    $run = NhlGameImportRun::create(['action' => 'season-sync', 'mode' => 'season', 'status' => 'running',
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31',
        'payload' => ['rows_upserted' => 2, 'aggregation_completed_at' => now()->toIso8601String()]]);
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->id = 'prepared-stats-batch';
    QueueNhlStatsDashboardRefresh::recordBatch($run->id, $batch, 'ready');
    $run->refresh();
    expect($run->status)->toBe('completed')->and($run->payload['stats_cache']['status'])->toBe('ready')
        ->and($run->payload['rows_upserted'])->toBe(2);
});

it('makes preparation failures visible without discarding rollup progress', function (): void {
    $run = NhlGameImportRun::create(['action' => 'season-sync', 'mode' => 'season', 'status' => 'running',
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'payload' => ['rows_upserted' => 2]]);
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->id = 'failed-stats-batch';
    QueueNhlStatsDashboardRefresh::recordBatch($run->id, $batch, 'failed', 'Cache publication failed');
    $run->refresh();
    expect($run->status)->toBe('failed')->and($run->last_error)->toBe('Cache publication failed')
        ->and($run->payload['rows_upserted'])->toBe(2);
});

it('requires an explicit scope when rebuilding prepared statistics', function (): void {
    $this->artisan('nhl:stats-cache')->expectsOutput('Choose --season=20262027 or --all.')->assertExitCode(1);
    Bus::assertNothingDispatched();
});

it('queues a cache rebuild without importing season data', function (): void {
    $this->artisan('nhl:stats-cache', ['--season' => '20262027'])
        ->expectsOutput('Public stats preparation queued on projections.')->assertExitCode(0);
    Bus::assertDispatched(RefreshNhlStatsDashboardJob::class);
    Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
});


it('reads retained highlights without rebuilding homepage facts on a stats visit', function (): void {
    $dashboard = new NhlDashboardPayload(app(\App\Services\NhlDashboardNews::class));
    DB::enableQueryLog();
    // This test exercises the warm read; cold reads are covered separately.

    $prepared = ['highlights_cache_status' => 'ready', 'player_trends' => [['name' => 'Prepared Player']]];
    Cache::forever('nhl:stats:highlights:v1', $prepared);
    expect($dashboard->statsHighlights())->toBe($prepared)->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Http::assertNothingSent();
});


it('fails preparation when the cache store rejects publication', function (): void {
    Cache::shouldReceive('forever')->once()->with(NhlStatsDashboardPayload::CACHE_PREFIX.'skaters:20262027', Mockery::type('array'))
        ->andReturn(false);
    expect(fn () => app(NhlStatsDashboardPayload::class)->refreshCachedFacts('20262027', 'skaters'))
        ->toThrow(\RuntimeException::class, 'Public stats cache publication failed.');
});


it('prepares legacy JSON-string settings and serves all slices through the public API', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    $builder->refreshPreparedSeasonPayload($perspective, '20262027', 2);
    $arrayBundle = Cache::get($builder->preparedKey($perspective, '20262027', 2));

    $encoded = json_encode($perspective->settings, JSON_THROW_ON_ERROR);
    $perspective->update(['settings' => $encoded]);
    $perspective->refresh();
    expect($perspective->settings)->toBeString();

    (new RefreshNhlStatsDashboardJob('20262027', 'table', $perspective->id))
        ->handle(app(NhlStatsDashboardPayload::class));
    expect(Cache::get($builder->preparedKey($perspective, '20262027', 2)))->toBe($arrayBundle)
        ->and($perspective->fresh()->settings)->toBe($encoded);

    foreach (['total' => 8, 'pgp' => 4, 'p60' => 12] as $slice => $points) {
        $this->getJson('/api/stats?perspective=skaters&season_id=20262027&slice='.$slice)->assertOk()
            ->assertJsonPath('meta.cache_status', 'ready')
            ->assertJsonPath('data.0.pts', $points);
    }
});

it('normalizes absent or malformed legacy settings to an empty array during preparation', function (): void {
    $perspective = ($this->publicReport)();
    $builder = app(StatsPayloadBuilder::class);
    foreach ([null, 'invalid-json', '"unexpected scalar"'] as $settings) {
        $perspective->update(['settings' => $settings]);
        $perspective->refresh();
        $builder->refreshPreparedSeasonPayload($perspective, '20262027', 2);
        $bundle = Cache::get($builder->preparedKey($perspective, '20262027', 2));
        expect(array_keys($bundle))->toBe(['total', 'pgp', 'p60'])
            ->and($bundle['total']['meta']['cache_status'])->toBe('ready');
    }
});


it('repairs missing default-season metadata when the Leaders publisher completes', function (): void {
    Cache::forget(NhlStatsDashboardPayload::CACHE_PREFIX.'latest-season');
    (new RefreshNhlStatsDashboardJob('20262027', 'leaders'))->handle(app(NhlStatsDashboardPayload::class));
    expect(Cache::get(NhlStatsDashboardPayload::CACHE_PREFIX.'latest-season'))->toBe('20262027');
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedSeason', '20262027')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload
            ->where('leaders.cache_status', 'ready')->where('leaders.hero.name', 'Alex Leader')));
});

it('prepares missing trend data while retaining the existing Leaders snapshot', function (): void {
    $dashboard = new NhlDashboardPayload(app(\App\Services\NhlDashboardNews::class));
    app()->instance(NhlDashboardPayload::class, $dashboard);
    (new RefreshNhlStatsDashboardJob('20262027', 'leaders'))->handle(app(NhlStatsDashboardPayload::class));
    Cache::forget('nhl:stats:highlights:v1');
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('statsCacheMissing', true)
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload
            ->where('leaders.cache_status', 'ready')->where('leaders.highlights_cache_status', 'ready')
            ->where('leaders.hero.name', 'Alex Leader')->has('leaders.mobile_leaders', 4)));
    expect(Cache::has('nhl:stats:highlights:v1'))->toBeTrue();
});


it('post-sync trend preparation avoids unrelated homepage calculations and serves Player Trends', function (): void {
    for ($day = 17; $day <= 22; $day++) {
        $gameId = $day === 22 ? 2026020001 : 2026020100 + $day;
        if ($day !== 22) {
            DB::table('nhl_games')->insert(['nhl_game_id' => $gameId, 'season_id' => '20262027', 'game_type' => 2,
                'game_date' => '2026-10-'.$day, 'game_state' => 'OFF', 'home_team_id' => 10, 'away_team_id' => 8,
                'home_team_abbrev' => 'TOR', 'away_team_abbrev' => 'MTL', 'home_team_score' => 3, 'away_team_score' => 1]);
        }
        DB::table('nhl_boxscores')->insert(['nhl_game_id' => $gameId, 'nhl_player_id' => 9000001,
            'nhl_team_id' => 10, 'position' => 'C', 'toi_seconds' => 1200, 'points' => $day - 16]);
    }
    ($this->facts)();
    $dashboard = new NhlDashboardPayload(app(\App\Services\NhlDashboardNews::class));
    app()->instance(NhlDashboardPayload::class, $dashboard);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        foreach (['nhl_shot_attempt', 'nhl_player_injuries', 'nhl_player_transactions'] as $table) {
            if (str_contains($query->sql, $table)) {
                throw new \RuntimeException('Unrelated homepage calculation');
            }
        }
    });
    (new RefreshNhlStatsDashboardJob('20262027', 'highlights'))->handle(app(NhlStatsDashboardPayload::class));
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload
            ->where('leaders.player_trends.0.name', 'Alex Leader')
            ->where('leaders.player_trends.0.current', 4)->where('leaders.player_trends.0.previous', 1)));
    Http::assertNothingSent();
});

it('marks cold page visits for the rewind loading message and clears that flag once prepared', function (): void {
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page->where('statsCacheMissing', true));
    ($this->facts)();
    Cache::forever('nhl:stats:highlights:v1', ['highlights_cache_status' => 'ready']);
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page->where('statsCacheMissing', false));
});


it('prepares Teams standings points and regulation GAA per sixty independently of player points', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['period_type' => 'REG']);
    $facts = app(NhlStatsDashboardPayload::class)->teamView('20262027');
    $teams = collect($facts['teams'])->keyBy('team');
    expect($teams['TOR']['points'])->toBe(2)->and($teams['MTL']['points'])->toBe(0)
        ->and($teams['TOR']['gaa'])->toBe(2.0)->and($teams['TOR']['win_pct'])->toBe(100.0)
        ->and(array_column($facts['team_categories'], 'label'))->toBe(['W%', 'GAA', 'Corsi%'])
        ->and($facts)->not->toHaveKey('team_games');
});

it('uses actual overtime elapsed time for Teams GAA and awards the losing standings point', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['period_type' => 'OT']);
    DB::table('play_by_plays')->insert(['nhl_game_id' => 2026020001, 'period_type' => 'OT',
        'period' => 4, 'type_desc_key' => 'goal', 'seconds_in_game' => 3720]);
    $teams = collect(app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams'])->keyBy('team');
    expect($teams['TOR']['gaa'])->toBe(2 * 3600 / 3720)->and($teams['MTL']['points'])->toBe(1);
});

it('excludes the shootout deciding goal from GAA while retaining displayed scoreboard totals', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update([
        'period_type' => 'SO', 'home_team_score' => 3, 'away_team_score' => 2,
    ]);
    $teams = collect(app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams'])->keyBy('team');
    expect($teams['MTL']['ga'])->toBe(3)->and($teams['MTL']['gaa'])->toBe(2 * 3600 / 3900)
        ->and($teams['MTL']['points'])->toBe(1);
});

it('keeps missing overtime clock and absent stat sources unavailable in Teams', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['period_type' => 'OT']);
    $team = app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams'][0];
    expect($team['gaa'])->toBeNull()->and($team['sat'])->toBeNull()->and($team['cf_pct'])->toBeNull()
        ->and($team['pp_pct'])->toBeNull()->and($team['pk_pct'])->toBeNull();
});

it('persists actual right-rail PP goals and opportunities even without officials', function (): void {
    $importer = app(\App\Services\NhlGameContextImporter::class);
    $payload = ['teamGameStats' => [['category' => 'powerPlay', 'homeValue' => '2/5', 'awayValue' => '0/4']]];
    $importer->importFromPayload(2026020001, $payload);
    $importer->importFromPayload(2026020001, $payload);
    $game = \App\Models\NhlGame::findOrFail(2026020001);
    expect($game->home_pp_goals)->toBe(2)->and($game->home_pp_opportunities)->toBe(5)
        ->and($game->away_pp_goals)->toBe(0)->and($game->away_pp_opportunities)->toBe(4);
    $teams = collect(app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams'])->keyBy('team');
    expect($teams['TOR']['pp_pct'])->toBe(40.0)->and($teams['MTL']['pk_pct'])->toBe(60.0)
        ->and($teams['MTL']['pp_pct'])->toBe(0.0)->and($teams['TOR']['pk_pct'])->toBe(100.0);
});

it('treats malformed and absent PP opportunity coverage as unknown rather than zero', function (): void {
    $importer = app(\App\Services\NhlGameContextImporter::class);
    $importer->importFromPayload(2026020001, ['teamGameStats' => [
        ['category' => 'powerPlay', 'homeValue' => '0/0', 'awayValue' => 'invalid'],
    ]]);
    $teams = collect(app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams']);
    expect($teams->pluck('pp_pct')->all())->toBe([null, null]);
    $importer->importFromPayload(2026020001, ['teamGameStats' => []]);
    expect(\App\Models\NhlGame::findOrFail(2026020001)->home_pp_opportunities)->toBeNull();
});

it('reuses prepared game facts for Teams date and game-type filtering without querying sources', function (): void {
    $service = app(NhlStatsDashboardPayload::class);
    $service->refreshCachedFacts('20262027', 'teams');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $same = $service->teamView('20262027', ['from' => '2026-10-22', 'to' => '2026-10-22']);
    $empty = $service->teamView('20262027', ['from' => '2026-10-23']);
    $playoffs = $service->teamView('20262027', ['game_type' => 3]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($same['teams'])->toHaveCount(2)->and($empty['teams'])->toBe([])
        ->and($playoffs['teams'])->toBe([])->and($queries)->toBe([]);
});

it('pools actual Teams PP numerators and denominators across games instead of averaging percentages', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['home_pp_goals' => 2, 'home_pp_opportunities' => 5]);
    DB::table('nhl_games')->insert(['nhl_game_id' => 2026020002, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-23', 'game_state' => 'FINAL', 'home_team_id' => 10, 'home_team_abbrev' => 'TOR',
        'away_team_id' => 8, 'away_team_abbrev' => 'MTL', 'home_team_score' => 1, 'away_team_score' => 2,
        'home_pp_goals' => 0, 'home_pp_opportunities' => 1]);
    $teams = collect(app(NhlStatsDashboardPayload::class)->teamView('20262027')['teams'])->keyBy('team');
    expect($teams['TOR']['pp_pct'])->toEqualWithDelta(2 / 6 * 100, 0.000001)->and($teams['MTL']['pk_pct'])->toBe((1 - 2 / 6) * 100);
});
