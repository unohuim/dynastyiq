<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\User;
use App\Services\ResolveNhlUnit;
use App\Services\SumNhlGameStrengthUnits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-03 12:00:00');
    fake()->seed(928);
    Http::preventStrayRequests();
    Queue::fake();
    $this->viewer = User::factory()->create();
    $this->actingAs($this->viewer);

    $this->game = static function (int $id, string $date, array $overrides = []): void {
        DB::table('nhl_games')->insert(array_merge([
            'nhl_game_id' => $id,
            'season_id' => '20262027',
            'game_type' => 2,
            'game_date' => $date,
            'game_dow' => 'Thu',
            'game_month' => 'Oct',
            'home_team_id' => 22,
            'home_team_abbrev' => 'EDM',
            'away_team_id' => 20,
            'away_team_abbrev' => 'CGY',
        ], $overrides));
    };
    $this->summary = static function (int $gameId, int $unitId, array $overrides = []): void {
        DB::table('nhl_unit_game_strength_summaries')->insert(array_merge([
            'nhl_game_id' => $gameId,
            'unit_id' => $unitId,
            'team_id' => 22,
            'team_abbrev' => 'EDM',
            'strength' => 'EV',
            'toi' => 240,
            'shifts' => 4,
            'gf' => 2,
            'ga' => 1,
            'sf' => 4,
            'sa' => 2,
            'satf' => 6,
        ], $overrides));
    };
    $this->players = collect([
        101 => ['Connor', 'McDavid'],
        102 => ['Leon', 'Draisaitl'],
        103 => ['Vasily', 'Podkolzin'],
        104 => ['Other', 'Player'],
    ])->map(static fn (array $name, int $nhlId): Player => Player::create([
        'nhl_id' => $nhlId,
        'first_name' => $name[0],
        'last_name' => $name[1],
        'full_name' => implode(' ', $name),
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'EDM',
    ]));
    $this->unit = app(ResolveNhlUnit::class)->resolve('F', [101, 102, 103], 'EDM');
    $this->otherUnit = app(ResolveNhlUnit::class)->resolve('F', [102, 104], 'EDM');
    ($this->game)(2026020001, '2026-10-01');
    ($this->game)(2026020002, '2026-10-02');
    ($this->game)(2026020003, '2026-10-01');
    ($this->summary)(2026020001, $this->unit->id);
    ($this->summary)(2026020002, $this->unit->id, ['toi' => 120, 'shifts' => 2, 'gf' => 1, 'sf' => 5, 'ga' => 0]);
    ($this->summary)(2026020003, $this->otherUnit->id, ['toi' => 180, 'shifts' => 3, 'gf' => 0, 'sf' => 1, 'ga' => 5]);

    $this->read = fn (array $query = []) => $this->get(route('stats.units.index', $query))->assertOk();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('redirects guests away from the line combination page', function (): void {
    auth()->logout();
    $this->get(route('stats.units.index'))->assertRedirect(route('login'));
});

it('rejects guest JSON reads of line combinations', function (): void {
    auth()->logout();
    $this->getJson(route('stats.units.index'))->assertUnauthorized();
});

it('allows an authenticated user without an admin role to read combinations', function (): void {
    expect($this->viewer->roles()->count())->toBe(0);
    ($this->read)()->assertViewIs('stats-units');
});

it('defaults to summed combinations across the selected games', function (): void {
    $response = ($this->read)();
    $row = $response->viewData('units')->getCollection()->firstWhere('unit_id', $this->unit->id);
    expect($response->viewData('sum'))->toBeTrue()
        ->and($response->viewData('units')->total())->toBe(2)
        ->and((int) $row->gp)->toBe(2)
        ->and((int) $row->shifts)->toBe(6)
        ->and((int) $row->toi)->toBe(360)
        ->and((int) $row->gf)->toBe(3);
});

it('shows one combination per game when sum is unchecked', function (): void {
    $response = ($this->read)(['sum' => 0]);
    $rows = $response->viewData('units')->getCollection()->where('unit_id', $this->unit->id)->keyBy('nhl_game_id');
    expect($response->viewData('sum'))->toBeFalse()
        ->and($response->viewData('units')->total())->toBe(3)
        ->and($rows)->toHaveCount(2)
        ->and((int) $rows[2026020001]->shifts)->toBe(4)
        ->and((int) $rows[2026020001]->toi)->toBe(240)
        ->and((int) $rows[2026020001]->gf)->toBe(2)
        ->and((int) $rows[2026020002]->shifts)->toBe(2);
});

it('combines strength rows within each game rather than duplicating combinations', function (): void {
    ($this->summary)(2026020001, $this->unit->id, ['strength' => 'PP', 'toi' => 60, 'shifts' => 1, 'gf' => 1]);
    $rows = ($this->read)(['sum' => 0, 'nhl_game_id' => 2026020001])->viewData('units');
    expect($rows->total())->toBe(1)
        ->and((int) $rows->items()[0]->shifts)->toBe(5)
        ->and((int) $rows->items()[0]->toi)->toBe(300)
        ->and((int) $rows->items()[0]->gf)->toBe(3);
});

it('keeps separate games on the same date distinct when Sum is unchecked', function (): void {
    ($this->summary)(2026020003, $this->unit->id, ['toi' => 60, 'shifts' => 1, 'gf' => 1]);
    $query = ['date' => '2026-10-01', 'player_id' => $this->players[101]->id];
    $summed = ($this->read)($query)->viewData('units');
    $byGame = ($this->read)(array_merge($query, ['sum' => 0]))->viewData('units');
    expect($summed->total())->toBe(1)
        ->and((int) $summed->items()[0]->gf)->toBe(3)
        ->and($byGame->total())->toBe(2)
        ->and($byGame->getCollection()->pluck('nhl_game_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([2026020001, 2026020003]);
});

it('renders an empty clearable calendar and Sum before the team selector', function (): void {
    $response = ($this->read)();
    $html = $response->getContent();
    $response->assertSee('id="stats-units-date"', false)
        ->assertSee('type="date"', false)
        ->assertSee('showPicker', false)
        ->assertSee('Clear game date')
        ->assertSee('All Games');
    expect($response->viewData('date'))->toBe('')
        ->and($response->viewData('gameId'))->toBeNull()
        ->and(strpos($html, 'id="stats-units-sum"'))->toBeLessThan(strpos($html, 'id="stats-units-team"'));
});

it('limits summed statistics and game options to the selected schedule date', function (): void {
    $response = ($this->read)(['date' => '2026-10-01']);
    $row = $response->viewData('units')->getCollection()->firstWhere('unit_id', $this->unit->id);
    expect((int) $row->gf)->toBe(2)
        ->and((int) $row->gp)->toBe(1)
        ->and($response->viewData('gameOptions')->pluck('nhl_game_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([2026020001, 2026020003]);
});

it('adds a selected game to the date constraints', function (): void {
    $response = ($this->read)(['date' => '2026-10-01', 'nhl_game_id' => 2026020003]);
    expect($response->viewData('units')->total())->toBe(1)
        ->and((int) $response->viewData('units')->items()[0]->unit_id)->toBe($this->otherUnit->id);
});

it('supports selecting a game without a date', function (): void {
    $response = ($this->read)(['nhl_game_id' => 2026020002]);
    expect($response->viewData('date'))->toBe('')
        ->and((int) $response->viewData('units')->items()[0]->gf)->toBe(1);
});

it('keeps all games on the date when All Games is selected', function (): void {
    $response = ($this->read)(['date' => '2026-10-01', 'nhl_game_id' => '']);
    expect($response->viewData('gameId'))->toBeNull()
        ->and($response->viewData('units')->total())->toBe(2);
});

it('restores the season scope when date and game are cleared', function (): void {
    ($this->read)(['date' => '2026-10-01', 'nhl_game_id' => 2026020001]);
    $response = ($this->read)(['date' => '', 'nhl_game_id' => '']);
    expect($response->viewData('gameOptions'))->toHaveCount(3)
        ->and((int) $response->viewData('units')->items()[0]->gp)->toBe(2);
});

it('clears a selected game that is outside the requested date', function (): void {
    $response = ($this->read)(['date' => '2026-10-02', 'nhl_game_id' => 2026020001]);
    expect($response->viewData('gameId'))->toBeNull()
        ->and((int) $response->viewData('units')->items()[0]->gf)->toBe(1);
});

it('returns an empty result and empty options for a date without summaries', function (): void {
    $response = ($this->read)(['date' => '2026-12-25']);
    expect($response->viewData('units')->total())->toBe(0)
        ->and($response->viewData('gameOptions'))->toBeEmpty()
        ->and($response->viewData('playerOptions'))->toBeEmpty();
    $response->assertSee('No line combinations match these filters.')->assertSee('Clear filters');
});

it('offers only players present in the selected date and game', function (): void {
    $response = ($this->read)(['date' => '2026-10-01', 'nhl_game_id' => 2026020001]);
    expect($response->viewData('playerOptions')->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing($this->players->only([101, 102, 103])->pluck('id')->all());
});

it('filters summed combinations by player without multiplying the totals', function (): void {
    $response = ($this->read)(['player_id' => $this->players[101]->id]);
    $row = $response->viewData('units')->items()[0];
    expect($response->viewData('units')->total())->toBe(1)
        ->and((int) $row->gf)->toBe(3)
        ->and((int) $row->toi)->toBe(360)
        ->and($row->players)->toHaveCount(3);
});

it('filters per-game combinations by player while retaining their linemates', function (): void {
    $response = ($this->read)(['sum' => 0, 'player_id' => $this->players[101]->id]);
    expect($response->viewData('units')->total())->toBe(2);
    $response->assertSee('C. McDavid')->assertSee('L. Draisaitl')->assertSee('V. Podkolzin');
});

it('clears a stale player selection when the player is absent from the scope', function (): void {
    $response = ($this->read)(['nhl_game_id' => 2026020002, 'player_id' => $this->players[104]->id]);
    expect($response->viewData('playerId'))->toBeNull()
        ->and($response->viewData('units')->total())->toBe(1);
});

it('limits game and player options by season and game type', function (): void {
    ($this->game)(2026010001, '2026-09-20', ['game_type' => 1]);
    ($this->summary)(2026010001, $this->otherUnit->id);
    ($this->game)(2025020001, '2025-10-01', ['season_id' => '20252026']);
    ($this->summary)(2025020001, $this->unit->id);
    $response = ($this->read)(['season_id' => '20262027', 'game_type' => 1]);
    expect($response->viewData('gameOptions'))->toHaveCount(1)
        ->and((int) $response->viewData('gameOptions')[0]->nhl_game_id)->toBe(2026010001)
        ->and($response->viewData('playerOptions'))->toHaveCount(2);
});

it('limits combinations and option lists by unit type and team', function (): void {
    $defense = app(ResolveNhlUnit::class)->resolve('D', [104], 'CGY');
    ($this->summary)(2026020001, $defense->id, ['team_abbrev' => 'CGY', 'team_id' => 20]);
    $response = ($this->read)(['pos' => ['D'], 'team' => 'CGY', 'sum' => 0]);
    expect($response->viewData('units')->total())->toBe(1)
        ->and($response->viewData('gameOptions'))->toHaveCount(1)
        ->and($response->viewData('playerOptions'))->toHaveCount(1)
        ->and((int) $response->viewData('units')->items()[0]->unit_id)->toBe($defense->id);
});

it('does not hide short combinations behind whole-minute default minimums', function (): void {
    DB::table('nhl_unit_game_strength_summaries')->where('nhl_game_id', 2026020002)->update(['toi' => 15, 'shifts' => 1]);
    $response = ($this->read)(['sum' => 0, 'date' => '2026-10-02']);
    expect($response->viewData('units')->total())->toBe(1)
        ->and((int) $response->viewData('units')->items()[0]->toi)->toBe(15)
        ->and($response->viewData('filterDefaults')['toi_min'])->toBe(0);
});

it('applies explicit volume filters to per-game totals', function (): void {
    $response = ($this->read)(['sum' => 0, 'shifts_min' => 4]);
    expect($response->viewData('units')->total())->toBe(1)
        ->and((int) $response->viewData('units')->items()[0]->nhl_game_id)->toBe(2026020001);
});

it('sorts counts and shares using each games own totals', function (): void {
    $counts = ($this->read)(['sum' => 0, 'sort' => 'gf', 'display' => 'counts'])->viewData('units');
    $shares = ($this->read)(['sum' => 0, 'sort' => 'gf', 'display' => 'share'])->viewData('units');
    expect((int) $counts->items()[0]->nhl_game_id)->toBe(2026020001)
        ->and((int) $shares->items()[0]->nhl_game_id)->toBe(2026020002);
});

it('preserves game context and constraints across pagination', function (): void {
    $response = ($this->read)(['sum' => 0, 'date' => '2026-10-01', 'per_page' => 1]);
    $units = $response->viewData('units');
    parse_str(parse_url($units->nextPageUrl(), PHP_URL_QUERY), $query);
    expect($query['sum'])->toBe('0')->and($query['date'])->toBe('2026-10-01');
    $response->assertSee('CGY')->assertSee('EDM')->assertSee('Oct 1, 2026');
    $second = $this->get($units->nextPageUrl())->assertOk()->viewData('units');
    expect((int) $second->items()[0]->nhl_game_id)->not->toBe((int) $units->items()[0]->nhl_game_id);
});

it('returns the filtered report fragment for authenticated JSON requests', function (): void {
    $response = $this->getJson(route('stats.units.index', ['sum' => 0, 'nhl_game_id' => 2026020001]));
    $response->assertOk()->assertJsonStructure(['html', 'url']);
    expect($response->json('html'))->toContain('C. McDavid', 'Oct 1, 2026', 'stats-units-sum')
        ->not->toContain('<html');
});

it('rejects malformed dates rather than querying an unintended scope', function (): void {
    $this->getJson(route('stats.units.index', ['date' => '2026-02-30']))
        ->assertUnprocessable()->assertJsonValidationErrors('date');
});

it('rejects malformed game player and grouping inputs', function (): void {
    $this->getJson(route('stats.units.index', ['sum' => 'invalid', 'nhl_game_id' => 'bad', 'player_id' => -1]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sum', 'nhl_game_id', 'player_id']);
});

it('reads imported four-shift performance as one combination for the game', function (): void {
    DB::table('nhl_unit_game_strength_summaries')->where('nhl_game_id', 2026020001)->delete();
    for ($index = 0; $index < 4; $index++) {
        $shiftId = DB::table('nhl_unit_shifts')->insertGetId([
            'unit_id' => $this->unit->id,
            'nhl_game_id' => 2026020001,
            'period' => 1,
            'start_time' => sprintf('%02d:00', $index),
            'end_time' => sprintf('%02d:00', $index + 1),
            'start_game_seconds' => $index * 60,
            'end_game_seconds' => ($index + 1) * 60,
            'seconds' => 60,
            'team_id' => 22,
            'team_abbrev' => 'EDM',
        ]);
        if ($index < 2) {
            $eventId = DB::table('play_by_plays')->insertGetId([
                'nhl_game_id' => 2026020001,
                'type_desc_key' => 'goal',
                'event_owner_team_id' => 22,
                'period' => 1,
                'seconds_in_game' => $index * 60 + 30,
                'strength' => 'EV',
            ]);
            DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId]);
        }
    }

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();
    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', [
        'nhl_game_id' => 2026020001, 'unit_id' => $this->unit->id, 'shifts' => 4, 'toi' => 240, 'gf' => 2,
    ]);
    $response = ($this->read)(['sum' => 0, 'date' => '2026-10-01', 'nhl_game_id' => 2026020001, 'player_id' => $this->players[101]->id]);
    $row = $response->viewData('units')->items()[0];
    expect($response->viewData('units')->total())->toBe(1)
        ->and((int) $row->shifts)->toBe(4)
        ->and((int) $row->toi)->toBe(240)
        ->and((int) $row->gf)->toBe(2);
});
