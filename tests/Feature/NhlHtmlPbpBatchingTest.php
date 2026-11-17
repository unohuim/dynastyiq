<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\NhlGameValidation;
use App\Models\Player;
use App\Services\NhlHtmlPbpReportLocator;
use App\Services\NhlHtmlPbpReportParser;
use App\Services\NhlValidationTroubleshootingExporter;
use App\Services\VerifyNhlHtmlPlayByPlay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-17 12:00:00 UTC'));
    Bus::fake();
    Http::preventStrayRequests();
    Http::fake(['https://www.nhl.com/batching-report' => Http::response('fixture')]);
    $this->game = function (int $id): void {
        NhlGame::create([
            'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => '2026-11-16', 'game_dow' => 'Monday', 'game_month' => 'November',
            'home_team_id' => 1, 'home_team_abbrev' => 'TOR', 'away_team_id' => 2, 'away_team_abbrev' => 'MTL',
        ]);
    };
    ($this->game)(2026020001);
    $this->players = collect(range(1, 4))->mapWithKeys(fn (int $id): array => [$id => Player::create([
        'nhl_id' => 9000000 + $id, 'full_name' => 'Player '.$id, 'first_name' => 'Player', 'last_name' => (string) $id,
        'position' => 'C', 'pos_type' => 'F',
        'team_abbrev' => $id === 4 ? 'MTL' : 'TOR',
    ])]);
    foreach ([1, 2, 3, 4] as $id) {
        DB::table('nhl_boxscores')->insert([
            'nhl_game_id' => 2026020001, 'nhl_player_id' => 9000000 + $id,
            'nhl_team_id' => $id === 4 ? 2 : 1, 'sweater_number' => $id === 4 ? 87 : 86 + $id,
        ]);
    }
    $this->shift = function (array $players = [1, 2], int $start = 0, int $end = 1200, int $game = 2026020001, string $team = 'TOR'): int {
        $unit = DB::table('nhl_units')->insertGetId(['team_abbrev' => $team, 'unit_type' => 'F']);
        foreach ($players as $id) {
            DB::table('nhl_unit_players')->insert(['unit_id' => $unit, 'player_id' => $this->players[$id]->id]);
        }
        return DB::table('nhl_unit_shifts')->insertGetId([
            'unit_id' => $unit, 'nhl_game_id' => $game, 'period' => 1,
            'start_time' => '0:00', 'end_time' => '20:00', 'start_game_seconds' => $start,
            'end_game_seconds' => $end, 'seconds' => $end - $start, 'team_id' => $team === 'TOR' ? 1 : 2,
            'team_abbrev' => $team,
        ]);
    };
    $this->shiftId = ($this->shift)();
    $this->htmlEvents = [];
    $this->htmlPlayers = [
        ['team_abbrev' => 'TOR', 'sweater_number' => 87, 'position_code' => 'C'],
        ['team_abbrev' => 'TOR', 'sweater_number' => 88, 'position_code' => 'LW'],
    ];
    $this->event = function (int $number, array $api = [], array $html = [], ?int $shift = null, int $game = 2026020001): int {
        $clock = sprintf('%d:%02d', intdiv(60 + $number, 60), (60 + $number) % 60);
        $id = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $game, 'nhl_event_id' => (string) $number, 'period' => 1,
            'time_in_period' => $clock, 'seconds_in_game' => 60 + $number,
            'type_desc_key' => 'shot-on-goal', 'sort_order' => $number, ...$api,
        ]);
        if ($shift !== 0) {
            DB::table('event_unit_shifts')->insert(['event_id' => $id, 'unit_shift_id' => $shift ?? $this->shiftId]);
        }
        $this->htmlEvents[] = ['period' => 1, 'time_in_period' => $clock, 'type' => 'shot-on-goal',
            'on_ice_players' => $this->htmlPlayers, ...$html];
        return $id;
    };
    $this->urls = ['playByPlay' => 'https://www.nhl.com/batching-report'];
    $locator = Mockery::mock(NhlHtmlPbpReportLocator::class);
    $locator->shouldReceive('reportUrls')->andReturnUsing(fn () => $this->urls);
    $parser = Mockery::mock(NhlHtmlPbpReportParser::class);
    $parser->shouldReceive('parse')->andReturnUsing(fn () => $this->htmlEvents);
    $exporter = Mockery::mock(NhlValidationTroubleshootingExporter::class);
    $exporter->shouldReceive('deleteGameDirectory')->byDefault();
    $this->verifier = new VerifyNhlHtmlPlayByPlay($locator, $parser, $exporter);
    $this->queries = function (callable $action): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $action();
            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    };
    $this->positionWrites = fn (array $queries) => collect($queries)->filter(fn (array $query): bool =>
        str_starts_with(strtolower($query['query']), 'insert') && str_contains($query['query'], 'nhl_unit_shift_players'));
});

afterEach(function (): void {
    $this->travelBack();
});

it('persists linked positions with one bulk write and approves matching evidence', function (): void {
    ($this->event)(1);
    $queries = ($this->queries)(fn () => $this->verifier->verify(2026020001));
    expect(($this->positionWrites)($queries))->toHaveCount(1);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['unit_shift_id' => $this->shiftId, 'player_id' => $this->players[1]->id, 'position_code' => 'C']);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['unit_shift_id' => $this->shiftId, 'player_id' => $this->players[2]->id, 'position_code' => 'LW']);
    $this->assertDatabaseHas('nhl_game_validations', ['nhl_game_id' => 2026020001, 'validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT, 'status' => 'approved']);
});

it('deduplicates repeated shift player updates and keeps the last nonempty HTML position', function (): void {
    ($this->event)(1);
    $players = $this->htmlPlayers;
    $players[0]['position_code'] = 'RW';
    ($this->event)(2, html: ['on_ice_players' => $players]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseCount('nhl_unit_shift_players', 2);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['player_id' => $this->players[1]->id, 'position_code' => 'RW']);
});

it('preserves row IDs and original creation timestamps when updating positions', function (): void {
    $id = DB::table('nhl_unit_shift_players')->insertGetId([
        'unit_shift_id' => $this->shiftId, 'player_id' => $this->players[1]->id, 'position_code' => 'RW',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    ($this->event)(1);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['id' => $id, 'position_code' => 'C', 'created_at' => '2026-01-01 00:00:00']);
});

it('remains idempotent when the same game is verified again', function (): void {
    ($this->event)(1);
    $this->verifier->verify(2026020001);
    $ids = DB::table('nhl_unit_shift_players')->orderBy('id')->pluck('id')->all();
    $this->verifier->verify(2026020001);
    expect(DB::table('nhl_unit_shift_players')->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('does not replace a known position with an empty later position', function (): void {
    ($this->event)(1);
    $players = $this->htmlPlayers;
    $players[0]['position_code'] = null;
    ($this->event)(2, html: ['on_ice_players' => $players]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['player_id' => $this->players[1]->id, 'position_code' => 'C']);
});

it('does not create positions without a linked unit shift', function (): void {
    ($this->event)(1, shift: 0);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
});

it('resolves explicit NHL IDs without requiring jersey metadata', function (): void {
    ($this->event)(1, html: ['on_ice_players' => [['nhl_player_id' => 9000001, 'position_code' => 'C']]]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['player_id' => $this->players[1]->id, 'position_code' => 'C']);
});

it('resolves identical jerseys separately for the two teams', function (): void {
    $awayShift = ($this->shift)([4], team: 'MTL');
    $event = ($this->event)(1, html: ['on_ice_players' => [
        ['team_abbrev' => 'TOR', 'sweater_number' => 87, 'position_code' => 'C'],
        ['team_abbrev' => 'MTL', 'sweater_number' => 87, 'position_code' => 'RW'],
    ]]);
    DB::table('event_unit_shifts')->insert(['event_id' => $event, 'unit_shift_id' => $awayShift]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['unit_shift_id' => $this->shiftId, 'player_id' => $this->players[1]->id, 'position_code' => 'C']);
    $this->assertDatabaseHas('nhl_unit_shift_players', ['unit_shift_id' => $awayShift, 'player_id' => $this->players[4]->id, 'position_code' => 'RW']);
});

it('does not invent player IDs for unresolved jerseys', function (): void {
    ($this->event)(1, html: ['on_ice_players' => [['team_abbrev' => 'TOR', 'sweater_number' => 99, 'position_code' => 'C']]]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseCount('players', 4);
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
    $this->assertDatabaseMissing('nhl_game_validations', ['validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
});

it('skips normal position enrichment for penalty-shot attempts', function (): void {
    ($this->event)(1, api: ['metadata' => json_encode(['is_penalty_shot_attempt' => true])]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
});

it('retains period-end on-ice mismatch suppression', function (): void {
    ($this->event)(1, api: ['type_desc_key' => 'period-end'], html: [
        'type' => 'period-end', 'on_ice_players' => [['nhl_player_id' => 9999999, 'position_code' => 'C']],
    ]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_game_validations', ['status' => 'approved', 'validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
});

it('retains exact-boundary mismatch suppression using preloaded shifts', function (): void {
    DB::table('nhl_unit_shifts')->where('id', $this->shiftId)->update(['end_game_seconds' => 61]);
    ($this->shift)([3], start: 61);
    ($this->event)(1, html: ['on_ice_players' => [
        ['nhl_player_id' => 9000001, 'position_code' => 'C'], ['nhl_player_id' => 9000003, 'position_code' => 'RW'],
    ]]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_game_validations', ['status' => 'approved', 'validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
});

it('retains TV TH arbitration using the same preloaded jersey identities', function (): void {
    $this->urls['toiHome'] = 'https://www.nhl.com/batching-toi';
    Http::fake([
        'https://www.nhl.com/batching-report' => Http::response('fixture'),
        'https://www.nhl.com/batching-toi' => Http::response('<table>
            <tr><td>87 PLAYER, ONE</td></tr><tr><td>1</td><td>1</td><td>0:00 / 20:00</td><td>2:00 / 18:00</td><td>2:00</td></tr>
            <tr><td>88 PLAYER, TWO</td></tr><tr><td>1</td><td>1</td><td>3:00 / 17:00</td><td>4:00 / 16:00</td><td>1:00</td></tr>
            <tr><td>89 PLAYER, THREE</td></tr><tr><td>1</td><td>1</td><td>0:00 / 20:00</td><td>2:00 / 18:00</td><td>2:00</td></tr>
        </table>'),
    ]);
    ($this->event)(1, html: ['on_ice_players' => [
        ['nhl_player_id' => 9000001, 'position_code' => 'C'], ['nhl_player_id' => 9000003, 'position_code' => 'RW'],
    ]]);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseHas('nhl_game_validations', ['status' => 'approved', 'validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
});

it('keeps non-boundary disagreements nonblocking without falsely approving them', function (): void {
    ($this->event)(1, html: ['on_ice_players' => [['nhl_player_id' => 9000003, 'position_code' => 'C']]]);
    expect($this->verifier->verify(2026020001))->toBe(1);
    $this->assertDatabaseMissing('nhl_game_validations', ['validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
    $this->assertDatabaseHas('nhl_game_source_statuses', ['nhl_game_id' => 2026020001, 'source' => 'html-pbp', 'status' => 'available']);
});

it('keeps evidence reads bounded as repeated HTML events increase', function (): void {
    foreach (range(1, 80) as $number) {
        ($this->event)($number);
    }
    $queries = ($this->queries)(fn () => $this->verifier->verify(2026020001));
    $reads = collect($queries)->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
        && preg_match('/from ["`]?((nhl_games)|(nhl_boxscores)|(players)|(nhl_unit_shifts)|(event_unit_shifts))["`]? /i', $query['query']));
    expect($reads->count())->toBeLessThanOrEqual(8)->and(($this->positionWrites)($queries))->toHaveCount(1);
    $this->assertDatabaseCount('nhl_unit_shift_players', 2);
});

it('writes many distinct natural keys in bounded bulk chunks', function (): void {
    foreach (range(1, 60) as $number) {
        $shift = ($this->shift)(start: $number * 10, end: $number * 10 + 60);
        ($this->event)($number, shift: $shift);
    }
    $queries = ($this->queries)(fn () => $this->verifier->verify(2026020001));
    expect(($this->positionWrites)($queries)->count())->toBeLessThanOrEqual(2);
    $this->assertDatabaseCount('nhl_unit_shift_players', 120);
});

it('does not retain another games player or event indexes in the same service instance', function (): void {
    ($this->event)(1);
    $this->verifier->verify(2026020001);
    ($this->game)(2026020002);
    $this->htmlEvents = [];
    ($this->event)(2, shift: 0, game: 2026020002);
    $this->verifier->verify(2026020002);
    $this->assertDatabaseCount('nhl_unit_shift_players', 2);
});

it('preserves whole-game rollback around bulk enrichment', function (): void {
    ($this->event)(1);
    expect(function (): void {
        DB::transaction(function (): void {
            $this->verifier->verify(2026020001);
            throw new \RuntimeException('Rollback fence');
        });
    })->toThrow(\RuntimeException::class, 'Rollback fence');
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
    $this->assertDatabaseMissing('nhl_game_validations', ['validation_type' => NhlGameValidation::TYPE_PBP_HTML_REPORT]);
});

it('performs no position writes when the parsed report is empty', function (): void {
    $queries = ($this->queries)(fn () => $this->verifier->verify(2026020001));
    expect(($this->positionWrites)($queries))->toHaveCount(0);
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
});

it('keeps provider failures nonfatal and leaves positions intact', function (): void {
    ($this->event)(1);
    $this->verifier->verify(2026020001);
    Http::fake(['https://www.nhl.com/batching-report' => Http::response('', 503)]);
    expect($this->verifier->verify(2026020001))->toBe(0);
    $this->assertDatabaseCount('nhl_unit_shift_players', 2);
});

it('does not enrich shifts linked from another game', function (): void {
    ($this->game)(2026020002);
    $otherShift = ($this->shift)(game: 2026020002);
    ($this->event)(1, shift: $otherShift);
    $this->verifier->verify(2026020001);
    $this->assertDatabaseCount('nhl_unit_shift_players', 0);
});

it('does not alter canonical API event fields during batching', function (): void {
    $event = ($this->event)(1);
    $before = DB::table('play_by_plays')->where('id', $event)->first();
    $this->verifier->verify(2026020001);
    expect(DB::table('play_by_plays')->where('id', $event)->first())->toEqual($before);
    Bus::assertNothingDispatched();
});
