<?php

declare(strict_types=1);

use App\Services\BuildNhlFaceoffFacts;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->gameId = 2025020001;
    $this->homeTeamId = 10;
    $this->awayTeamId = 20;
    $this->playSequence = 0;

    $this->insertGame = function (array $overrides = []): void {
        DB::table('nhl_games')->insert(array_merge([
            'nhl_game_id' => $this->gameId,
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => '2025-10-07',
            'game_dow' => 'Tue',
            'game_month' => 'Oct',
            'home_team_id' => $this->homeTeamId,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => $this->awayTeamId,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertPlay = function (array $overrides = []): int {
        $seconds = (int) ($overrides['seconds_in_game'] ?? 0);
        $this->playSequence++;

        return (int) DB::table('play_by_plays')->insertGetId(array_merge([
            'nhl_game_id' => $this->gameId,
            'nhl_event_id' => 'event-' . $seconds . '-' . $this->playSequence,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_game' => $seconds,
            'seconds_in_period' => $seconds,
            'seconds_since_last_event' => null,
            'sort_order' => $seconds,
            'type_desc_key' => 'faceoff',
            'event_owner_team_id' => $this->homeTeamId,
            'fo_winning_player_id' => 8470001,
            'fo_losing_player_id' => 8470002,
            'zone_code' => 'O',
            'strength' => 'EV',
            'situation_code' => '1551',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->buildFacts = function (): int {
        return app(BuildNhlFaceoffFacts::class)->buildForGame($this->gameId);
    };

    $this->firstFact = function (): object {
        return DB::table('nhl_faceoff_facts')->orderBy('id')->first();
    };

    $this->insertLinkedUnits = function (int $eventId): array {
        $homePlayerIds = [
            DB::table('players')->insertGetId([
                'nhl_id' => 8470001,
                'first_name' => 'Home',
                'last_name' => 'Center',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            DB::table('players')->insertGetId([
                'nhl_id' => 8470003,
                'first_name' => 'Home',
                'last_name' => 'Wing',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];
        $awayPlayerIds = [
            DB::table('players')->insertGetId([
                'nhl_id' => 8470002,
                'first_name' => 'Away',
                'last_name' => 'Center',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            DB::table('players')->insertGetId([
                'nhl_id' => 8470004,
                'first_name' => 'Away',
                'last_name' => 'Wing',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];
        $homeUnitId = DB::table('nhl_units')->insertGetId([
            'team_abbrev' => 'TOR',
            'unit_type' => 'F',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $awayUnitId = DB::table('nhl_units')->insertGetId([
            'team_abbrev' => 'MTL',
            'unit_type' => 'F',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $homeShiftId = DB::table('nhl_unit_shifts')->insertGetId([
            'team_id' => $this->homeTeamId,
            'team_abbrev' => 'TOR',
            'unit_id' => $homeUnitId,
            'nhl_game_id' => $this->gameId,
            'period' => 1,
            'start_time' => '00:00',
            'end_time' => '00:30',
            'start_game_seconds' => 0,
            'end_game_seconds' => 30,
            'seconds' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $awayShiftId = DB::table('nhl_unit_shifts')->insertGetId([
            'team_id' => $this->awayTeamId,
            'team_abbrev' => 'MTL',
            'unit_id' => $awayUnitId,
            'nhl_game_id' => $this->gameId,
            'period' => 1,
            'start_time' => '00:00',
            'end_time' => '00:30',
            'start_game_seconds' => 0,
            'end_game_seconds' => 30,
            'seconds' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[$homeShiftId, $homePlayerIds], [$awayShiftId, $awayPlayerIds]] as [$shiftId, $playerIds]) {
            DB::table('event_unit_shifts')->insert([
                'event_id' => $eventId,
                'unit_shift_id' => $shiftId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($playerIds as $playerId) {
                DB::table('nhl_unit_shift_players')->insert([
                    'unit_shift_id' => $shiftId,
                    'player_id' => $playerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return [
            'home_unit_id' => $homeUnitId,
            'away_unit_id' => $awayUnitId,
            'home_player_ids' => $homePlayerIds,
            'away_player_ids' => $awayPlayerIds,
        ];
    };
});

it('creates one faceoff fact for a faceoff play', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)();

    expect(($this->buildFacts)())->toBe(1)
        ->and(DB::table('nhl_faceoff_facts')->count())->toBe(1);
});

it('ignores non faceoff plays', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['type_desc_key' => 'shot-on-goal']);

    expect(($this->buildFacts)())->toBe(0)
        ->and(DB::table('nhl_faceoff_facts')->count())->toBe(0);
});

it('upserts faceoff facts without duplicating rows', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)();

    ($this->buildFacts)();
    ($this->buildFacts)();

    expect(DB::table('nhl_faceoff_facts')->count())->toBe(1);
});

it('stores game and season context', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)();
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->nhl_game_id)->toBe($this->gameId)
        ->and($fact->season_id)->toBe('20252026')
        ->and($fact->game_date)->toBe('2025-10-07');
});

it('stores the faceoff winner and loser team ids', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['event_owner_team_id' => $this->awayTeamId]);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->winning_team_id)->toBe($this->awayTeamId)
        ->and($fact->losing_team_id)->toBe($this->homeTeamId);
});

it('stores the faceoff winner and loser team abbreviations', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['event_owner_team_id' => $this->awayTeamId]);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->winning_team_abbrev)->toBe('MTL')
        ->and($fact->losing_team_abbrev)->toBe('TOR');
});

it('stores winning and losing faceoff player ids', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)([
        'fo_winning_player_id' => 8471111,
        'fo_losing_player_id' => 8472222,
    ]);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->winning_player_id)->toBe(8471111)
        ->and($fact->losing_player_id)->toBe(8472222);
});

it('stores offensive zone from the winning team perspective', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['zone_code' => 'O']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->winning_team_zone)->toBe('O')
        ->and(($this->firstFact)()->winning_team_zone_bucket)->toBe('offensive');
});

it('flips offensive zone to defensive for the losing team perspective', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['zone_code' => 'O']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->losing_team_zone)->toBe('D')
        ->and(($this->firstFact)()->losing_team_zone_bucket)->toBe('defensive');
});

it('keeps neutral zone neutral for both teams', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['zone_code' => 'N']);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->winning_team_zone)->toBe('N')
        ->and($fact->losing_team_zone)->toBe('N');
});

it('normalizes long provider zone codes', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['zone_code' => 'OZ']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->winning_team_zone)->toBe('O');
});

it('marks unknown zones when the source zone is missing', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['zone_code' => null]);
    ($this->buildFacts)();

    expect(($this->firstFact)()->winning_team_zone_bucket)->toBe('unknown');
});

it('marks advancement when the next event moves up ice', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'D']);
    ($this->insertPlay)([
        'seconds_in_game' => 16,
        'type_desc_key' => 'shot-on-goal',
        'event_owner_team_id' => $this->homeTeamId,
        'zone_code' => 'O',
    ]);
    ($this->buildFacts)();

    expect(($this->firstFact)()->advancement_bucket)->toBe('advanced')
        ->and(($this->firstFact)()->advancement_value)->toBe(2);
});

it('marks held when the next event remains in the same zone', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'O']);
    ($this->insertPlay)([
        'seconds_in_game' => 16,
        'type_desc_key' => 'shot-on-goal',
        'event_owner_team_id' => $this->homeTeamId,
        'zone_code' => 'O',
    ]);
    ($this->buildFacts)();

    expect(($this->firstFact)()->advancement_bucket)->toBe('held')
        ->and(($this->firstFact)()->advancement_value)->toBe(0);
});

it('marks retreat when the next event moves back down ice', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'O']);
    ($this->insertPlay)([
        'seconds_in_game' => 16,
        'type_desc_key' => 'takeaway',
        'event_owner_team_id' => $this->homeTeamId,
        'zone_code' => 'D',
    ]);
    ($this->buildFacts)();

    expect(($this->firstFact)()->advancement_bucket)->toBe('retreated')
        ->and(($this->firstFact)()->advancement_value)->toBe(-2);
});

it('flips the next event zone when the next event belongs to the losing team', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'N']);
    ($this->insertPlay)([
        'seconds_in_game' => 16,
        'type_desc_key' => 'shot-on-goal',
        'event_owner_team_id' => $this->awayTeamId,
        'zone_code' => 'D',
    ]);
    ($this->buildFacts)();

    expect(($this->firstFact)()->next_event_zone)->toBe('O')
        ->and(($this->firstFact)()->advancement_bucket)->toBe('advanced');
});

it('skips stoppages when selecting the next meaningful event', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'N']);
    ($this->insertPlay)(['seconds_in_game' => 12, 'type_desc_key' => 'stoppage', 'zone_code' => 'N']);
    ($this->insertPlay)(['seconds_in_game' => 18, 'type_desc_key' => 'giveaway', 'zone_code' => 'D']);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->next_event_type)->toBe('giveaway')
        ->and($fact->next_event_seconds_delta)->toBe(8);
});

it('marks advancement unknown when there is no next meaningful event', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['seconds_in_game' => 10, 'zone_code' => 'N']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->advancement_bucket)->toBe('unknown')
        ->and(($this->firstFact)()->advancement_value)->toBeNull();
});

it('stores linked unit ids for each faceoff side', function (): void {
    ($this->insertGame)();
    $eventId = ($this->insertPlay)();
    $context = ($this->insertLinkedUnits)($eventId);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect($fact->winning_unit_id)->toBe($context['home_unit_id'])
        ->and($fact->losing_unit_id)->toBe($context['away_unit_id']);
});

it('stores linked on ice player ids for each faceoff side', function (): void {
    ($this->insertGame)();
    $eventId = ($this->insertPlay)();
    $context = ($this->insertLinkedUnits)($eventId);
    ($this->buildFacts)();

    $fact = ($this->firstFact)();

    expect(json_decode($fact->winning_on_ice_player_ids, true))->toBe($context['home_player_ids'])
        ->and(json_decode($fact->losing_on_ice_player_ids, true))->toBe($context['away_player_ids']);
});

it('stores power play strength buckets', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['strength' => 'PP']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->strength_bucket)->toBe('pp');
});

it('stores penalty kill strength buckets', function (): void {
    ($this->insertGame)();
    ($this->insertPlay)(['strength' => 'PK']);
    ($this->buildFacts)();

    expect(($this->firstFact)()->strength_bucket)->toBe('pk');
});
