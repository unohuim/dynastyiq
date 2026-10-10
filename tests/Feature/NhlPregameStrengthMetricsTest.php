<?php

declare(strict_types=1);

use App\Models\NhlPregameContextRun;
use App\Services\NhlPregameContextBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-09 12:00:00 UTC'));
    Bus::fake();
    Http::preventStrayRequests();
    $this->player = DB::table('players')->insertGetId([
        'nhl_id' => 101, 'first_name' => 'Test', 'last_name' => 'Skater',
        'full_name' => 'Test Skater', 'position' => 'C', 'pos_type' => 'F',
    ]);
    $this->run = NhlPregameContextRun::create([
        'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
    ]);
    $this->game = function (int $id, string $date, string $season = '20252026', int $team = 10): void {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2, 'game_state' => 'OFF',
            'game_date' => $date, 'start_time_utc' => $date . ' 19:00:00',
            'game_dow' => 'Fri', 'game_month' => 'Oct', 'home_team_id' => 10, 'away_team_id' => 20,
        ]);
        DB::table('nhl_game_summaries')->insert([
            'nhl_game_id' => $id, 'nhl_player_id' => 101, 'nhl_team_id' => $team,
            'toi' => 1200, 'sat' => 9, 'sog' => 5, 'g' => 2,
        ]);
    };
    $this->exposure = function (int $id, string $strength, int $toi): void {
        DB::table('nhl_player_game_strength_summaries')->insert([
            'nhl_game_id' => $id, 'player_id' => $this->player, 'nhl_player_id' => 101,
            'team_id' => 10, 'strength' => $strength, 'toi' => $toi,
            'satf' => 40, 'sata' => 30, 'sf' => 20, 'sa' => 10, 'gf' => 2, 'ga' => 1,
            'individual_pts' => 1,
        ]);
    };
    $this->fact = function (int $id, string $strength, bool $sog = false, bool $goal = false, array $extra = []): void {
        $playId = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $id, 'period' => 1, 'seconds_in_game' => 120, 'type_desc_key' => 'shot-on-goal',
        ]);
        DB::table('nhl_shot_attempts_facts')->insert([
            'play_by_play_id' => $playId, 'nhl_game_id' => $id, 'shooter_player_id' => 101,
            'attempt_result' => 'shot', 'is_shot_attempt' => true,
            'is_shot_on_goal' => $sog, 'is_goal' => $goal,
            'strength' => $strength, 'strength_bucket' => strtolower($strength), 'period_type' => 'REG',
            ...$extra,
        ]);
    };
    $this->build = function (): array {
        app(NhlPregameContextBuilder::class)->build($this->run, 2025020010);

        return json_decode(DB::table('nhl_player_game_pregame_contexts')
            ->where('nhl_game_id', 2025020010)->where('nhl_player_id', 101)->value('metrics'), true);
    };
    ($this->game)(2025020010, '2025-10-20');
    ($this->game)(2025020001, '2025-10-10');
    ($this->exposure)(2025020001, 'EV', 600);
    ($this->exposure)(2025020001, 'PP', 120);
    ($this->exposure)(2025020001, 'PK', 60);
});

it('persists individual EV PP PK counts and rates separately from on ice totals', function (): void {
    ($this->fact)(2025020001, 'EV', true, true);
    ($this->fact)(2025020001, 'EV');
    ($this->fact)(2025020001, 'PP', true);
    ($this->fact)(2025020001, 'PK');
    $metrics = ($this->build)();
    expect($metrics['strength']['EV']['last_5'])->toMatchArray([
        'sat' => 2, 'sog' => 1, 'goals' => 1, 'sat_per_60' => 12,
        'sog_per_60' => 6, 'goals_per_60' => 6, 'shooting_pct' => 100,
        'corsi_for' => 40, 'pdo' => 100,
    ])->and($metrics['strength']['PP']['last_5']['sat_per_60'])->toEqual(30)
        ->and($metrics['strength']['PK']['last_5']['sat_per_60'])->toEqual(60)
        ->and($metrics['strength']['PK']['last_5']['shooting_pct'])->toBeNull()
        ->and($metrics['all']['last_5']['sat'])->toBe(9);
});

it('keeps missing facts unavailable rather than claiming zero attempts', function (): void {
    $row = ($this->build)()['strength']['EV']['last_5'];
    expect($row['sat'])->toBeNull()->and($row['sat_per_60'])->toBeNull()
        ->and($row['individual_missing_games'])->toBe(1);
});

it('keeps missing strength exposure unavailable', function (): void {
    DB::table('nhl_player_game_strength_summaries')->delete();
    ($this->fact)(2025020001, 'EV', true);
    expect(($this->build)()['strength']['EV']['last_5']['sat_per_60'])->toBeNull();
});

it('preserves genuine zero shot games and zero exposure rates', function (): void {
    ($this->fact)(2025020001, 'EV', true, false, ['shooter_player_id' => 999]);
    DB::table('nhl_player_game_strength_summaries')->where('strength', 'PK')->delete();
    $metrics = ($this->build)();
    expect($metrics['strength']['EV']['last_5']['sat_per_60'])->toEqual(0)
        ->and($metrics['strength']['PK']['last_5']['sat'])->toBe(0)
        ->and($metrics['strength']['PK']['last_5']['sat_per_60'])->toBeNull();
});

it('uses prior seasons and excludes target future and non regular games', function (): void {
    ($this->fact)(2025020001, 'EV');
    foreach ([[2024020001, '2025-04-10', '20242025'], [2025020011, '2025-10-21', '20252026'], [2025010001, '2025-10-09', '20252026']] as [$id, $date, $season]) {
        ($this->game)($id, $date, $season);
        ($this->exposure)($id, 'EV', 600);
        ($this->fact)($id, 'EV');
    }
    DB::table('nhl_games')->where('nhl_game_id', 2025010001)->update(['game_type' => 1]);
    ($this->fact)(2025020010, 'EV');
    $metrics = ($this->build)()['strength']['EV'];
    expect($metrics['last_5']['sat'])->toBe(2)
        ->and($metrics['last_5']['games'])->toBe(2)
        ->and($metrics['season_to_date']['sat'])->toBe(1);
});

it('selects the last five appearances before choosing strength so no exposure games remain', function (): void {
    ($this->fact)(2025020001, 'PP', true);
    foreach (range(2, 6) as $index) {
        $id = 2025020000 + $index;
        ($this->game)($id, '2025-10-' . (10 + $index));
        ($this->exposure)($id, 'EV', 600);
        ($this->fact)($id, 'EV', true);
    }
    $row = ($this->build)()['strength']['PP'];
    expect($row['last_5']['games'])->toBe(5)->and($row['last_5']['sat'])->toBe(0)
        ->and($row['last_5']['sat_per_60'])->toBeNull()
        ->and($row['last_10']['sat'])->toBe(1);
});

it('preserves raw strength for empty net buckets and excludes shootouts', function (): void {
    ($this->fact)(2025020001, 'EV', true, true, ['strength_bucket' => 'en']);
    ($this->fact)(2025020001, 'EV', true, true, ['period_type' => 'SO']);
    expect(($this->build)()['strength']['EV']['last_5']['goals'])->toBe(1);
});

it('marks unclassified player attempts unavailable instead of dropping them silently', function (): void {
    ($this->fact)(2025020001, 'unknown');
    expect(($this->build)()['strength']['EV']['last_5']['sat'])->toBeNull();
});

it('upserts rebuilt snapshots without creating a second version', function (): void {
    ($this->fact)(2025020001, 'EV');
    ($this->build)();
    ($this->fact)(2025020001, 'EV', true);
    $metrics = ($this->build)();
    expect(DB::table('nhl_player_game_pregame_contexts')->where('nhl_game_id', 2025020010)->count())->toBe(1)
        ->and($metrics['strength']['EV']['last_5']['sat'])->toBe(2)
        ->and($metrics['individual_strength_version'])->toBe(1);
});

it('uses pooled strength ice time and historical venue and opponent identity', function (): void {
    ($this->fact)(2025020001, 'EV', true);
    ($this->game)(2025020002, '2025-10-11', '20252026', 20);
    ($this->exposure)(2025020002, 'EV', 1200);
    ($this->fact)(2025020002, 'EV', true);
    $windows = ($this->build)()['strength']['EV'];
    expect($windows['last_5']['sat_per_60'])->toEqual(4)
        ->and($windows['venue_season_to_date']['sat'])->toBe(1)
        ->and($windows['opponent_last_10']['sat'])->toBe(1);
});
