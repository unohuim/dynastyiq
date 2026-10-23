<?php

declare(strict_types=1);

use App\Models\Perspective;
use App\Models\Player;
use App\Models\User;
use App\Services\NhlDashboardPayload;
use App\Services\NhlStatsDashboardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
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
    $this->facts = fn (): array => app(NhlStatsDashboardPayload::class)->build();
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
    $this->get('/stats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stats/Index')->where('view', 'leaders')->missing('leaders')->missing('initialPayload')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->where('leaders.hero.name', 'Alex Leader')));
});
it('allows authenticated users to read the same public leaders', function (): void {
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
    expect(($this->facts)()['rookies'])->toBe([])->and(($this->facts)()['rookie_note'])->toContain('not available');
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
    $this->get('/stats?view=teams')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view', 'teams')->missing('leaders')
        ->loadDeferredProps('stats-leaders', fn (Assert $reload) => $reload->has('leaders.teams', 2)));
});
it('does not build leader facts before returning the initial page shell', function (): void {
    $this->mock(NhlStatsDashboardPayload::class, fn ($mock) => $mock->shouldNotReceive('build'));
    $this->get('/stats?view=skaters')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'skaters')->missing('leaders')->missing('initialPayload')->where('selectedSeason', '20262027'));
});
it('rejects an unsupported category value', function (): void {
    $this->getJson('/stats?view=unknown')->assertUnprocessable()->assertJsonValidationErrors('view');
});
