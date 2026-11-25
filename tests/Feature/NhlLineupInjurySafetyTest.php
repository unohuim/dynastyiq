<?php

declare(strict_types=1);

use App\Jobs\RefreshNhlGamePredictionJob;
use App\Models\ApiClient;
use App\Models\NhlCurrentLineup;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlPlayerInjury;
use App\Models\NhlStartingGoalieObservation;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlGameLineupProjectionBuilder;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use App\Services\NhlLineupAvailability;
use App\Services\NhlPublicGamePage;
use App\Services\NhlStartingGoalieSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    DB::table('nhl_teams')->insertOrIgnore([['nhl_id' => 21, 'abbrev' => 'COL'], ['nhl_id' => 68, 'abbrev' => 'UTA']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020346, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-18', 'start_time_utc' => '2026-11-19 02:00:00', 'game_state' => 'FUT',
        'game_dow' => 'Wednesday', 'game_month' => 'November', 'away_team_id' => 21, 'home_team_id' => 68,
        'away_team_abbrev' => 'COL', 'home_team_abbrev' => 'UTA']);
    $this->model = NhlModelRun::create(['run_key' => 'lineup-injury-safety', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20252026'], 'target_season_id' => '20262027']);
    $this->players = [];
    $this->texts = [];
    foreach ([21 => 'COL', 68 => 'UTA'] as $team => $abbrev) {
        $groups = [];
        foreach (range(1, 22) as $i) {
            $position = $i <= 12 || $i === 19 ? 'C' : ($i <= 20 ? 'D' : 'G');
            $name = ucfirst(strtolower($abbrev)).chr(96 + $i).' Skater'.chr(96 + $i);
            $p = Player::create(['nhl_id' => 8800000 + $team * 100 + $i, 'full_name' => $name,
                'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1],
                'position' => $position, 'pos_type' => $position === 'C' ? 'F' : $position, 'team_abbrev' => $abbrev]);
            $this->players[$abbrev][$i] = $p;
            if ($i <= 18) {
                $key = $i <= 12 ? 'F'.(int) ceil($i / 3) : 'D'.(int) ceil(($i - 12) / 2);
                $groups[$key][] = $name;
            }
            if ($i <= 20) {
                DB::table('nhl_player_toi_projections')->insert(['projection_version' => 'injury-fixture',
                    'source_season_id' => '20252026', 'target_season_id' => '20262027', 'player_id' => $p->nhl_id,
                    'target_team_abbrev' => $abbrev, 'position' => $position,
                    'projected_toi_per_game_seconds' => 1500 - $i, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        $this->texts[$abbrev] = collect($groups)->map(fn ($names, $key): string => $key.': '.implode(', ', $names))->implode("\n")
            ."\nGoalies\n".$this->players[$abbrev][21]->full_name."\n".$this->players[$abbrev][22]->full_name;
    }
    $this->client = ApiClient::create(['name' => 'Lineup safety partner', 'slug' => 'lineup-safety',
        'token_hash' => ApiClient::hashToken('lineup-safety-token'), 'scopes' => ['nhl-lineups:write', 'nhl-stats:read']]);
    $this->submit = fn (string $team = 'COL', ?string $text = null) => $this->withToken('lineup-safety-token')->postJson('/api/nhl-lineups',
        ['nhl_game_id' => 2026020346, 'team_abbrev' => $team, 'text' => $text ?? $this->texts[$team]]);
    $this->out = function (int $slot = 1, array $changes = [], string $team = 'COL'): NhlPlayerInjury {
        $p = $this->players[$team][$slot];
        return NhlPlayerInjury::create(['player_id' => $p->id, 'nhl_player_id' => $p->nhl_id,
            'player_name' => $p->full_name, 'team_abbrev' => $team, 'availability' => 'out', 'evidence_level' => 'reported',
            'status_text' => 'Expected to be out until at least Nov 28', 'sources' => ['cbs'],
            'first_observed_at' => now()->subDay(), 'last_observed_at' => now(), 'last_seen_at' => now(), ...$changes]);
    };
    $this->savedPayload = function (): array {
        $payload = ['prediction_available' => true, 'game' => ['nhl_game_id' => 2026020346],
            'inputs' => ['sat_model_run_id' => $this->model->id, 'away_goalie_id' => $this->players['COL'][21]->nhl_id,
                'home_goalie_id' => $this->players['UTA'][21]->nhl_id],
            'prediction' => ['winner' => ['team_abbrev' => 'COL'], 'predicted_score' => ['away' => 3, 'home' => 2],
                'public_confidence_score' => 65, 'confidence_score' => 70], 'market_probabilities' => []];
        foreach (['away' => 'COL', 'home' => 'UTA'] as $side => $team) {
            $roster = [];
            foreach (range(1, 18) as $i) {
                $roster[] = ['nhl_player_id' => $this->players[$team][$i]->nhl_id, 'player_name' => $this->players[$team][$i]->full_name,
                    'position' => $i <= 12 ? 'F' : 'D', 'projected_goals' => 0.1];
            }
            $payload['teams'][$side] = ['team_abbrev' => $team, 'roster' => $roster];
            $payload['goalies'][$side] = ['nhl_player_id' => $this->players[$team][21]->nhl_id, 'name' => $this->players[$team][21]->full_name];
        }
        return $payload;
    };
    $this->save = fn (array $payload) => app(NhlGamePredictionHistory::class)->record(2026020346,
        ['nhl_game_id' => 2026020346], 'injury-fixture', [], $payload);
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('rejects an out skater through the partner submission endpoint without saving observations', function (): void {
    ($this->out)();
    ($this->submit)()->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    $this->assertDatabaseCount('nhl_current_lineups', 0);
});

it('reports every unavailable submitted player by name and reason', function (): void {
    ($this->out)(1); ($this->out)(13);
    $errors = ($this->submit)()->assertUnprocessable()->json('errors.text');
    expect(implode(' ', $errors))->toContain($this->players['COL'][1]->full_name)
        ->toContain($this->players['COL'][13]->full_name)->toContain('Nov 28');
});

it('rejects an unavailable submitted goalie as well as skaters', function (): void {
    ($this->out)(21);
    ($this->submit)()->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->assertDatabaseCount('nhl_starting_goalie_observations', 0);
});

it('preserves the previous accepted lineup when a later submission is rejected', function (): void {
    $id = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->out)(1);
    ($this->submit)()->assertUnprocessable();
    expect(NhlCurrentLineup::first()->nhl_lineup_observation_id)->toBe($id);
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
});

it('accepts healthy replacements then reads exactly the accepted players through HTTP', function (): void {
    ($this->out)(1);
    $text = str_replace($this->players['COL'][1]->full_name, $this->players['COL'][19]->full_name, $this->texts['COL']);
    $id = ($this->submit)('COL', $text)->assertCreated()->json('submission_id');
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $id]);
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    $ids = collect($read->json('game.teams.away.players'))->pluck('nhl_player_id')->all();
    expect($ids)->toContain($this->players['COL'][19]->nhl_id)->not->toContain($this->players['COL'][1]->nhl_id);
});

it('keeps questionable unknown and suspected evidence eligible', function (string $availability, string $evidence): void {
    ($this->out)(1, ['availability' => $availability, 'evidence_level' => $evidence]);
    ($this->submit)()->assertCreated();
})->with([['questionable', 'reported'], ['unknown', 'reported'], ['out', 'suspected']]);

it('does not treat an estimated return date as medical clearance', function (): void {
    ($this->out)(1, ['anticipated_return_date' => '2026-11-17']);
    ($this->submit)()->assertUnprocessable();
});

it('does not apply injury evidence first observed after the target cutoff', function (): void {
    ($this->out)(1, ['first_observed_at' => '2026-11-20 12:00:00']);
    expect(app(NhlLineupAvailability::class)->blocked($this->game, 'COL'))->toBeEmpty();
});

it('does not remove actual participants from a finished game', function (): void {
    ($this->out)(); $this->game->update(['game_state' => 'OFF']);
    ($this->submit)()->assertCreated();
    expect(app(NhlLineupAvailability::class)->predictionErrors($this->game, ($this->savedPayload)()))->toBe([]);
});

it('retains started-game official identity authority over current injuries', function (): void {
    ($this->out)(); $this->game->update(['game_state' => 'LIVE']);
    expect(app(NhlLineupAvailability::class)->blocked($this->game, 'COL'))->toBeEmpty();
});

it('allows an injured player to be listed as a scratch without dressing them', function (): void {
    ($this->out)();
    expect(app(NhlLineupAvailability::class)->errors($this->game, 'COL', [
        ['nhl_player_id' => $this->players['COL'][1]->nhl_id, 'lineup_role' => 'scratch'],
    ]))->toBe([]);
});

it('returns a healthy projected lineup when accepted evidence becomes injury incompatible', function (): void {
    $observationId = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->out)();
    expect(app(NhlAnticipatedLineupPayload::class)->forGameTeam(2026020346, 'COL', false))->toBeNull();
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    $players = collect($read->json('game.teams.away.players'));
    expect($players->whereIn('lineup_role', ['forward', 'defense']))->toHaveCount(18);
    expect($players->pluck('nhl_player_id')->all())->toContain($this->players['COL'][19]->nhl_id)
        ->not->toContain($this->players['COL'][1]->nhl_id);
    $read->assertJsonPath('game.teams.away.lineup_status', 'projected');
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $observationId]);
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
});

it('exposes the same healthy replacement through every partner lineup read', function (string $path, string $key): void {
    ($this->submit)()->assertCreated(); ($this->out)();
    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants(
        (object) $this->game->getAttributes(), teamAbbrev: 'COL'
    );
    $read = $this->withToken('lineup-safety-token')->getJson($path)->assertOk();
    $ids = collect($read->json($key))->whereIn('lineup_role', ['forward', 'defense'])
        ->pluck('nhl_player_id')->sort()->values()->all();
    $expected = collect($record['players'])->pluck('id')->sort()->values()->all();
    expect($ids)->toBe($expected)->and($ids)->toHaveCount(18);
})->with([
    ['/api/nhl/lineups/2026020346', 'game.teams.away.players'],
    ['/api/nhl/lineups?date=2026-11-18', 'games.0.teams.away.players'],
    ['/api/nhl-anticipated-lineups?nhl_game_id=2026020346', 'anticipated_lineups.0.players'],
]);

it('keeps a corrected roster available after rejecting another injured player submission', function (): void {
    $observationId = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->out)();
    ($this->submit)()->assertUnprocessable()->assertJsonValidationErrors('text');
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    expect(collect($read->json('game.teams.away.players'))->pluck('nhl_player_id')->all())
        ->toContain($this->players['COL'][19]->nhl_id)->not->toContain($this->players['COL'][1]->nhl_id);
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $observationId]);
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
});

it('retains a healthy accepted roster when an injured replacement is rejected', function (): void {
    $observationId = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->out)(19);
    $text = str_replace($this->players['COL'][1]->full_name, $this->players['COL'][19]->full_name, $this->texts['COL']);
    ($this->submit)('COL', $text)->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $observationId]);
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk()
        ->assertJsonPath('game.teams.away.lineup_status', 'manual');
    expect(collect($read->json('game.teams.away.players'))->pluck('nhl_player_id')->all())
        ->toContain($this->players['COL'][1]->nhl_id)->not->toContain($this->players['COL'][19]->nhl_id);
});

it('resolves one teams replacement independently of missing opposing roster projections', function (): void {
    ($this->submit)()->assertCreated(); ($this->out)();
    DB::table('nhl_player_toi_projections')->where('target_team_abbrev', 'UTA')->delete();
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    $read->assertJsonPath('game.teams.away.lineup_status', 'projected')
        ->assertJsonPath('game.teams.home.lineup_status', 'not_reported');
    expect(collect($read->json('game.teams.away.players'))->whereIn('lineup_role', ['forward', 'defense']))->toHaveCount(18);
});

it('returns independent corrected rosters when both accepted teams contain injured skaters', function (): void {
    ($this->submit)()->assertCreated(); ($this->submit)('UTA')->assertCreated();
    ($this->out)(1); ($this->out)(13, [], 'UTA');
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    foreach (['away' => 'COL', 'home' => 'UTA'] as $side => $team) {
        $players = collect($read->json('game.teams.'.$side.'.players'));
        expect($players->where('lineup_role', 'forward'))->toHaveCount(12);
        expect($players->where('lineup_role', 'defense'))->toHaveCount(6);
        expect($players->pluck('nhl_player_id')->all())->toContain($this->players[$team][$team === 'COL' ? 19 : 20]->nhl_id)
            ->not->toContain($this->players[$team][$team === 'COL' ? 1 : 13]->nhl_id);
        $read->assertJsonPath('game.teams.'.$side.'.lineup_status', 'projected');
    }
});

it('retains healthy accepted lineup authority beside an opposing corrected roster', function (): void {
    ($this->submit)()->assertCreated(); ($this->submit)('UTA')->assertCreated(); ($this->out)();
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    $read->assertJsonPath('game.teams.away.lineup_status', 'projected')
        ->assertJsonPath('game.teams.home.lineup_status', 'manual');
    expect(collect($read->json('game.teams.home.players'))->whereIn('lineup_role', ['forward', 'defense'])
        ->pluck('nhl_player_id')->sort()->values()->all())
        ->toBe(collect($this->players['UTA'])->take(18)->pluck('nhl_id')->sort()->values()->all());
});

it('labels fallback line combinations as projected without claiming manual source attribution', function (): void {
    ($this->submit)()->assertCreated(); ($this->out)();
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020346')->assertOk();
    $read->assertJsonPath('anticipated_lineups.0.evidence_status', 'projected')
        ->assertJsonPath('anticipated_lineups.0.team_id', 21)
        ->assertJsonPath('anticipated_lineups.0.manual_override', false)
        ->assertJsonPath('anticipated_lineups.0.prediction_source', 'projected_roster')
        ->assertJsonPath('anticipated_lineups.0.sources', []);
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('shows the corrected calculation lineup in both prediction API and public analysis', function (): void {
    ($this->submit)()->assertCreated(); ($this->submit)('UTA')->assertCreated(); ($this->out)();
    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants((object) $this->game->getAttributes());
    $payload = ($this->savedPayload)();
    foreach (['away' => 21, 'home' => 68] as $side => $team) {
        $payload['anticipated_lineups'][$side] = $record['lineups'][$team];
        $payload['teams'][$side]['roster'] = collect($record['players'])->where('team', $team)
            ->map(fn (array $player): array => ['nhl_player_id' => $player['id'], 'player_name' => $player['name'],
                'position' => $player['role'], 'projected_goals' => 0.1])->values()->all();
    }
    $saved = ($this->save)($payload);
    $revisionId = $saved['meta']['prediction_revision_id'];
    $before = DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->value('payload');
    $api = $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')->assertOk();
    $panel = $this->getJson('/games/2026020346/details?section=lineups&prediction_revision_id='.$revisionId)->assertOk();
    $expected = collect($record['players'])->where('team', 21)->pluck('id')->sort()->values()->all();
    foreach ([$api->json('anticipated_lineups.away.players'), $panel->json('data.away.display_lineup.players')] as $players) {
        expect(collect($players)->whereIn('lineup_role', ['forward', 'defense'])->pluck('nhl_player_id')->sort()->values()->all())
            ->toBe($expected)->not->toContain($this->players['COL'][1]->nhl_id);
    }
    expect(DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->value('payload'))->toBe($before);
});

it('resolves complete healthy fallback participants instead of dropping an injured player from the calculation', function (): void {
    ($this->submit)()->assertCreated(); ($this->submit)('UTA')->assertCreated(); ($this->out)();
    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants((object) $this->game->getAttributes());
    expect($record['players'])->toHaveCount(36)->not->toHaveKey($this->players['COL'][1]->nhl_id)
        ->toHaveKey($this->players['COL'][19]->nhl_id);
    expect($record['lineup_sources'][21])->toBe('projected_roster');
});

it('keeps the selected snapshot identities equal to the players used for statistical inputs', function (): void {
    ($this->submit)()->assertCreated(); ($this->submit)('UTA')->assertCreated();
    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants((object) $this->game->getAttributes());
    $ids = collect($record['lineups'][21]['players'])->whereIn('lineup_role', ['forward', 'defense'])->pluck('nhl_player_id')->sort()->values()->all();
    $calculated = collect($record['players'])->where('team', 21)->pluck('id')->sort()->values()->all();
    expect($ids)->toBe($calculated)->and($ids)->toHaveCount(18);
});

it('rejects an incomplete fallback instead of publishing a shortened roster', function (): void {
    ($this->out)();
    $this->players['COL'][19]->update(['team_abbrev' => 'UTA']);
    expect(fn () => app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants((object) $this->game->getAttributes()))
        ->toThrow(\DomainException::class);
});

it('rejects an explicit roster override containing an unavailable player', function (): void {
    ($this->out)();
    $ids = collect($this->players['COL'])->take(18)->pluck('nhl_id')->all();
    expect(fn () => app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants((object) $this->game->getAttributes(), false, ['away' => $ids]))
        ->toThrow(\DomainException::class, 'marked out');
});

it('uses the next eligible goalie observation when the newest starter is unavailable', function (): void {
    foreach ([22, 21] as $i) {
        NhlStartingGoalieObservation::create(['game_date' => '2026-11-18', 'nhl_game_id' => 2026020346,
            'team_abbrev' => 'COL', 'player_name' => $this->players['COL'][$i]->full_name,
            'nhl_player_id' => $this->players['COL'][$i]->nhl_id, 'provider' => 'rotowire', 'status' => 'expected',
            'fetched_at' => now()->addSeconds($i === 21 ? 1 : 0), 'raw_evidence' => []]);
    }
    ($this->out)(21);
    expect(app(NhlStartingGoalieSelector::class)->select(2026020346, 'COL')['nhl_player_id'])->toBe($this->players['COL'][22]->nhl_id);
});

it('rejects an explicit unavailable starter override', function (): void {
    ($this->out)(21);
    expect(fn () => app(NhlStartingGoalieSelector::class)->select(2026020346, 'COL', providedGoalieId: $this->players['COL'][21]->nhl_id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('changes the preparation eligibility identity when a player becomes unavailable', function (): void {
    $availability = app(NhlLineupAvailability::class);
    $before = $availability->evidence($this->game);
    ($this->out)();
    expect($availability->evidence($this->game))->not->toBe($before);
});

it('does not invalidate eligibility for repeated heartbeat-only updates', function (): void {
    $injury = ($this->out)(); $availability = app(NhlLineupAvailability::class);
    $before = $availability->evidence($this->game);
    $injury->update(['last_seen_at' => now()->addMinutes(5), 'last_observed_at' => now()->addMinutes(5)]);
    expect($availability->evidence($this->game))->toBe($before);
});

it('restores eligibility after current injury state is cleared', function (): void {
    $injury = ($this->out)();
    $injury->delete();
    expect(app(NhlLineupAvailability::class)->blocked($this->game, 'COL'))->toBeEmpty();
});

it('reconstructs legacy API lineups only from the saved calculated roster and starter', function (): void {
    $payload = ($this->savedPayload)();
    $payload['anticipated_lineups']['away'] = ['players' => [['nhl_player_id' => 999, 'lineup_role' => 'forward']]];
    $saved = ($this->save)($payload);
    $read = app(NhlGamePredictionHistory::class)->latestSaved(2026020346);
    $ids = collect($read['anticipated_lineups']['away']['players'])->whereIn('lineup_role', ['forward', 'defense'])->pluck('nhl_player_id')->all();
    expect($ids)->toBe(array_column($payload['teams']['away']['roster'], 'nhl_player_id'));
    $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')
        ->assertOk()->assertJsonPath('anticipated_lineups.away.players.0.nhl_player_id', $ids[0]);
    expect(DB::table('nhl_game_prediction_revisions')->where('id', $saved['meta']['prediction_revision_id'])->value('payload'))
        ->toContain('999');
});

it('queues a replacement rather than returning an unsafe saved prediction as current', function (): void {
    ($this->save)(($this->savedPayload)()); ($this->out)();
    $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')->assertStatus(202);
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('continues serving a healthy saved forecast immediately without new work', function (): void {
    ($this->save)(($this->savedPayload)()); Bus::fake();
    $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')->assertOk();
    Bus::assertNothingDispatched();
});

it('does not apply present injuries to a historical saved forecast read', function (): void {
    ($this->save)(($this->savedPayload)()); ($this->out)();
    $this->game->update(['game_state' => 'OFF', 'game_date' => '2026-11-16']); Bus::fake();
    $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')->assertOk();
    Bus::assertNothingDispatched();
});

it('refreshes only existing upcoming forecasts for changed teams', function (): void {
    ($this->save)(($this->savedPayload)()); ($this->out)();
    app(NhlGamePredictionResponseCache::class)->refreshAvailabilityForTeams(['COL']);
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
    Bus::fake();
    app(NhlGamePredictionResponseCache::class)->refreshAvailabilityForTeams(['BOS']);
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('returns the exact frozen prediction lineup in the public Lineups panel', function (): void {
    ($this->save)(NhlGamePredictionPayload::synchronizePredictionLineups(($this->savedPayload)()));
    $result = app(NhlPublicGamePage::class)->panel(2026020346, 'lineups');
    $ids = array_column($result['data']['away']['display_lineup']['players'], 'nhl_player_id');
    expect($ids)->toContain($this->players['COL'][1]->nhl_id, $this->players['COL'][21]->nhl_id);
    expect($result['data']['away']['predictions'])->toHaveCount(18);
});

it('keeps guest and wrong-scope API access blocked', function (string $method, string $path): void {
    $body = ['nhl_game_id' => 2026020346, 'team_abbrev' => 'COL', 'text' => $this->texts['COL']];
    $this->json($method, $path, $body)->assertUnauthorized();
    $this->client->update(['scopes' => ['nhl-reference:read']]);
    $this->withToken('lineup-safety-token')->json($method, $path, $body)->assertForbidden();
})->with([
    ['POST', '/api/nhl-lineups'],
    ['GET', '/api/nhl/lineups/2026020346'],
    ['GET', '/api/nhl/lineups?date=2026-11-18'],
    ['GET', '/api/nhl-anticipated-lineups?nhl_game_id=2026020346'],
    ['GET', '/api/nhl-game-predictions?nhl_game_id=2026020346'],
]);

it('applies the same injury refusal to a super-admin manual lineup submission', function (): void {
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99, 'scope' => 'global', 'is_active' => true]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    ($this->out)();
    $this->actingAs($admin)->postJson('/games/2026020346/lineup', ['team_abbrev' => 'COL', 'text' => $this->texts['COL']])
        ->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->assertDatabaseCount('nhl_current_lineups', 0);
});

it('rejects an unavailable goalie picker selection before writing an observation', function (): void {
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99, 'scope' => 'global', 'is_active' => true]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    ($this->out)(21);
    $this->actingAs($admin)->postJson('/games/2026020346/starting-goalie',
        ['team_abbrev' => 'COL', 'player_id' => $this->players['COL'][21]->id])->assertUnprocessable()->assertJsonValidationErrors('player_id');
    $this->assertDatabaseCount('nhl_starting_goalie_observations', 0);
});

it('keeps a shared date eligibility identity identical regardless of game iteration order', function (): void {
    ($this->out)();
    $second = clone $this->game;
    $second->nhl_game_id = 2026020347;
    $service = app(NhlLineupAvailability::class);
    $a = $service->evidenceForGames([$this->game, $second]);
    $b = $service->evidenceForGames([$second, $this->game]);
    expect($a[2026020346])->toBe($b[2026020346])->and($a[2026020347])->toBe($b[2026020347]);
});

it('queues existing forecasts after a material injury import but not a repeated unchanged import', function (): void {
    ($this->save)(($this->savedPayload)());
    $name = $this->players['COL'][1]->full_name;
    $html = '<div class="TeamName">Colorado Avalanche</div><table><tbody><tr><td><a>'.$name.'</a></td><td>C</td><td>Nov 17</td><td>Lower Body</td><td>Expected to be out until at least Nov 28</td></tr></tbody></table>';
    $this->mock(\App\Services\CbsNhlClient::class)->shouldReceive('injuries')->twice()->andReturn($html);
    $this->mock(\App\Services\RotoWireNhlClient::class)->shouldReceive('news')->twice()->andReturn('<rss><channel></channel></rss>');
    app(\App\Services\NhlInjuryImporter::class)->import();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
    $this->travel(5)->minutes();
    app(\App\Services\NhlInjuryImporter::class)->import();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
});

it('withholds unsafe Factors without changing or deleting the saved revision', function (): void {
    $saved = ($this->save)(($this->savedPayload)()); ($this->out)();
    $result = app(NhlPublicGamePage::class)->panel(2026020346, 'factors');
    expect($result['data']['preview_analysis']['teams'])->toBe([]);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $saved['meta']['prediction_revision_id']]);
});

it('preserves guest and ordinary-member restrictions on manual lineup and goalie writes', function (string $path): void {
    $body = ['team_abbrev' => 'COL', 'text' => $this->texts['COL'], 'player_id' => $this->players['COL'][21]->id];
    $this->postJson($path, $body)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($path, $body)->assertForbidden();
})->with(['/games/2026020346/lineup', '/games/2026020346/starting-goalie']);

it('permits an eligible super-admin goalie choice and exposes it through the read API', function (): void {
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99, 'scope' => 'global', 'is_active' => true]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    $id = $this->players['COL'][21]->nhl_id;
    $this->actingAs($admin)->postJson('/games/2026020346/starting-goalie',
        ['team_abbrev' => 'COL', 'player_id' => $this->players['COL'][21]->id])->assertOk()->assertJsonPath('starting_goalie.nhl_player_id', $id);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', ['nhl_game_id' => 2026020346, 'nhl_player_id' => $id]);
    $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk()
        ->assertJsonPath('game.teams.away.starting_goalie.nhl_player_id', $id);
});

it('withholds a saved forecast when its calculated starting goalie is now out', function (): void {
    ($this->save)(($this->savedPayload)()); ($this->out)(21);
    $this->withToken('lineup-safety-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020346')->assertStatus(202);
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
});

it('pins the Lineups panel to the displayed revision rather than a newer roster', function (): void {
    $payload = ($this->savedPayload)();
    $first = ($this->save)($payload);
    $payload['teams']['away']['roster'][0]['nhl_player_id'] = $this->players['COL'][19]->nhl_id;
    $payload['teams']['away']['roster'][0]['player_name'] = $this->players['COL'][19]->full_name;
    ($this->save)($payload);
    $panel = app(NhlPublicGamePage::class)->panel(2026020346, 'lineups', $first['meta']['prediction_revision_id']);
    $ids = array_column($panel['data']['away']['display_lineup']['players'], 'nhl_player_id');
    expect($ids)->toContain($this->players['COL'][1]->nhl_id)->not->toContain($this->players['COL'][19]->nhl_id);
});

it('does not let an older injury-incompatible manual observation outrank a healthy automated observation', function (): void {
    ($this->submit)()->assertCreated();
    ($this->out)();
    $text = str_replace($this->players['COL'][1]->full_name, $this->players['COL'][19]->full_name, $this->texts['COL']);
    $healthyId = ($this->submit)('COL', $text)->assertCreated()->json('submission_id');
    $observation = \App\Models\NhlLineupObservation::findOrFail($healthyId);
    $evidence = $observation->raw_evidence;
    $evidence['manual_override'] = false;
    $observation->update(['raw_evidence' => $evidence]);
    DB::transaction(function (): void {
        NhlGame::query()->whereKey(2026020346)->lockForUpdate()->first();
        (new ReflectionMethod(\App\Services\NhlAnticipatedLineupImporter::class, 'refreshCurrent'))
            ->invoke(app(\App\Services\NhlAnticipatedLineupImporter::class), 2026020346, 21, 'COL');
    });
    expect(NhlCurrentLineup::where('team_abbrev', 'COL')->first()->nhl_lineup_observation_id)->toBe($healthyId);
});

it('includes current excluded players in Injuries beside the saved prediction lineup through HTTP', function (): void {
    ($this->out)(1); ($this->out)(13);
    $payload = ($this->savedPayload)();
    foreach ([0 => 19, 12 => 20] as $index => $replacement) {
        $payload['teams']['away']['roster'][$index]['nhl_player_id'] = $this->players['COL'][$replacement]->nhl_id;
        $payload['teams']['away']['roster'][$index]['player_name'] = $this->players['COL'][$replacement]->full_name;
    }
    $saved = ($this->save)($payload);
    $revisionId = $saved['meta']['prediction_revision_id'];
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $revisionId]);
    $before = DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->value('payload');
    $read = $this->getJson('/games/2026020346/details?section=lineups&prediction_revision_id='.$revisionId)->assertOk()
        ->assertJsonCount(2, 'data.away.injuries')->assertJsonCount(0, 'data.home.injuries');
    expect(collect($read->json('data.away.injuries'))->pluck('nhl_player_id')->all())
        ->toContain($this->players['COL'][1]->nhl_id, $this->players['COL'][13]->nhl_id);
    expect(collect($read->json('data.away.display_lineup.players'))->pluck('nhl_player_id')->all())
        ->not->toContain($this->players['COL'][1]->nhl_id, $this->players['COL'][13]->nhl_id);
    expect(DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->value('payload'))->toBe($before);
});

it('returns explicit empty injury lists for both healthy teams in the saved-lineup response', function (): void {
    ($this->save)(($this->savedPayload)());
    $this->getJson('/games/2026020346/details?section=lineups')->assertOk()
        ->assertJsonPath('data.away.injuries', [])->assertJsonPath('data.home.injuries', []);
});

it('keeps each teams current injuries separate from its frozen prediction participants', function (): void {
    ($this->save)(($this->savedPayload)());
    ($this->out)(19, [], 'UTA');
    $this->getJson('/games/2026020346/details?section=lineups')->assertOk()
        ->assertJsonCount(0, 'data.away.injuries')->assertJsonCount(1, 'data.home.injuries')
        ->assertJsonPath('data.home.injuries.0.nhl_player_id', $this->players['UTA'][19]->nhl_id);
});

it('selects a current team member whose projection still names the former team', function (): void {
    $player = $this->players['COL'][13];
    DB::table('nhl_player_toi_projections')->where('player_id', $player->nhl_id)->update(['target_team_abbrev' => 'NSH']);

    $roster = collect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));

    expect($roster)->toHaveCount(18);
    expect($roster->pluck('nhl_player_id')->all())->toContain($player->nhl_id);
    expect($roster->firstWhere('nhl_player_id', $player->nhl_id)['baseline_toi_seconds'])->toBe(1487.0);
    $this->assertDatabaseHas('nhl_player_toi_projections', ['player_id' => $player->nhl_id, 'target_team_abbrev' => 'NSH']);
});

it('excludes a departed player despite the projection retaining their former team', function (): void {
    $player = $this->players['COL'][13];
    $player->update(['team_abbrev' => 'UTA']);
    $builder = app(NhlGameLineupProjectionBuilder::class);

    $former = collect($builder->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));
    $current = collect($builder->projectedRosterPreview('20262027', 'injury-fixture', 'UTA'));

    expect($former->pluck('nhl_player_id')->all())->not->toContain($player->nhl_id)
        ->toContain($this->players['COL'][20]->nhl_id);
    expect($former)->toHaveCount(18);
    expect($current->pluck('nhl_player_id')->all())->toContain($player->nhl_id);
});

it('fills the sixth defensive slot from current membership through calculation and partner reads', function (): void {
    $observation = ($this->submit)()->assertCreated()->json('submission_id');
    $replacement = $this->players['COL'][20];
    DB::table('nhl_player_toi_projections')->where('player_id', $replacement->nhl_id)->update(['target_team_abbrev' => 'NSH']);
    ($this->out)(13);

    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants(
        (object) $this->game->getAttributes(), teamAbbrev: 'COL'
    );
    $read = $this->withToken('lineup-safety-token')->getJson('/api/nhl/lineups/2026020346')->assertOk();
    $players = collect($read->json('game.teams.away.players'));

    expect($players->where('lineup_role', 'forward'))->toHaveCount(12);
    expect($players->where('lineup_role', 'defense'))->toHaveCount(6);
    expect($players->pluck('nhl_player_id')->all())->toContain($replacement->nhl_id)
        ->not->toContain($this->players['COL'][13]->nhl_id);
    expect($record['players'])->toHaveCount(18)->toHaveKey($replacement->nhl_id);
    expect($players->whereIn('lineup_role', ['forward', 'defense'])->pluck('nhl_player_id')->sort()->values()->all())
        ->toBe(collect($record['players'])->pluck('id')->sort()->values()->all());
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $observation]);
    $this->assertDatabaseHas('nhl_player_toi_projections', ['player_id' => $replacement->nhl_id, 'target_team_abbrev' => 'NSH']);
});

it('retains a healthy replacement without a personal projection and leaves statistics unknown', function (): void {
    ($this->out)(13);
    $replacement = $this->players['COL'][20];
    DB::table('nhl_player_toi_projections')->where('player_id', $replacement->nhl_id)->delete();

    $roster = collect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));
    $row = $roster->firstWhere('nhl_player_id', $replacement->nhl_id);

    expect($roster)->toHaveCount(18);
    expect($row)->not->toBeNull();
    expect($row['baseline_toi_seconds'])->toBeNull();
    expect($row['confidence_score'])->toBeNull();
    expect($row['confidence'])->toBeNull();
    expect($row['player_name'])->toBe($replacement->full_name);
});

it('can select current identities when the entire team has no season TOI projections', function (): void {
    DB::table('nhl_player_toi_projections')->where('target_team_abbrev', 'COL')->delete();

    $roster = collect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));

    expect($roster)->toHaveCount(18);
    expect($roster->where('position', 'D'))->toHaveCount(6);
    expect($roster->where('position', 'C'))->toHaveCount(12);
    expect($roster->every(fn ($row): bool => $row['baseline_toi_seconds'] === null))->toBeTrue();
});

it('attaches statistical projections only from the requested season and version', function (array $changes): void {
    $player = $this->players['COL'][13];
    DB::table('nhl_player_toi_projections')->where('player_id', $player->nhl_id)->update($changes);

    $roster = app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview(
        '20262027', 'injury-fixture', 'COL', [$player->nhl_id]
    );

    expect($roster)->toHaveCount(1);
    expect($roster[0]['nhl_player_id'])->toBe($player->nhl_id);
    expect($roster[0]['baseline_toi_seconds'])->toBeNull();
})->with([
    'another target season' => [['target_season_id' => '20272028']],
    'another projection version' => [['projection_version' => 'other-fixture']],
]);

it('ranks measured zero opportunity above missing opportunity without inventing a value', function (): void {
    $player = $this->players['COL'][13];
    DB::table('nhl_player_toi_projections')->where('player_id', $player->nhl_id)->update(['projected_toi_per_game_seconds' => 0]);
    DB::table('nhl_player_toi_projections')->where('player_id', $this->players['COL'][20]->nhl_id)->delete();

    $roster = collect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));

    expect($roster->firstWhere('nhl_player_id', $player->nhl_id)['baseline_toi_seconds'])->toBe(0.0);
    expect($roster->pluck('nhl_player_id')->all())->not->toContain($this->players['COL'][20]->nhl_id);
});

it('excludes a canonical goalie even when its projection calls it a defenseman', function (): void {
    $goalie = $this->players['COL'][21];
    $goalie->update(['is_goalie' => true]);
    DB::table('nhl_player_toi_projections')->insert(['player_id' => $goalie->nhl_id,
        'projection_version' => 'injury-fixture', 'source_season_id' => '20252026', 'target_season_id' => '20262027',
        'target_team_abbrev' => 'COL', 'position' => 'D', 'projected_toi_per_game_seconds' => 9999]);

    $roster = collect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL'));

    expect($roster)->toHaveCount(18);
    expect($roster->pluck('nhl_player_id')->all())->not->toContain($goalie->nhl_id);
});

it('preserves explicitly selected historical identities after their current team changes', function (): void {
    $player = $this->players['COL'][13];
    $player->update(['team_abbrev' => 'UTA']);

    $roster = app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview(
        '20262027', 'injury-fixture', 'COL', [$player->nhl_id]
    );

    expect($roster)->toHaveCount(1);
    expect($roster[0]['nhl_player_id'])->toBe($player->nhl_id);
    expect($roster[0]['projection_source'])->toBe('nhl_boxscore');
});

it('keeps an explicitly empty roster from becoming an unrestricted current roster', function (): void {
    expect(app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview('20262027', 'injury-fixture', 'COL', []))
        ->toBe([]);
});

it('invalidates prepared upcoming inputs after a current team membership change', function (): void {
    $forecast = app(NhlCustomGameForecast::class);
    $identity = new ReflectionMethod($forecast, 'preparationIdentity');
    $before = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);
    $this->players['COL'][13]->update(['team_abbrev' => 'UTA']);
    $after = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);

    expect($after[0])->toBe($before[0]);
    expect($after[1])->not->toBe($before[1]);
});

it('does not let a projection team snapshot change current membership or preparation identity', function (): void {
    $forecast = app(NhlCustomGameForecast::class);
    $identity = new ReflectionMethod($forecast, 'preparationIdentity');
    $before = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);
    DB::table('nhl_player_toi_projections')->where('player_id', $this->players['COL'][13]->nhl_id)
        ->update(['target_team_abbrev' => 'NSH']);
    $after = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);

    expect($after)->toBe($before);
});

it('does not invalidate prepared rosters for a player-name heartbeat update', function (): void {
    $forecast = app(NhlCustomGameForecast::class);
    $identity = new ReflectionMethod($forecast, 'preparationIdentity');
    $before = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);
    $this->players['COL'][13]->update(['full_name' => 'Updated Display Name']);
    $after = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, false, []);

    expect($after)->toBe($before);
});

it('keeps current membership changes out of historical boxscore preparation identity', function (): void {
    $forecast = app(NhlCustomGameForecast::class);
    $identity = new ReflectionMethod($forecast, 'preparationIdentity');
    $before = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, true, []);
    $this->players['COL'][13]->update(['team_abbrev' => 'UTA']);
    $after = $identity->invoke($forecast, (object) $this->game->getAttributes(), $this->model, true, []);

    expect($after)->toBe($before);
});

it('does not reintroduce departed players through the previous-game lineup fallback', function (): void {
    NhlGame::create(['nhl_game_id' => 2026020345, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-16', 'start_time_utc' => '2026-11-17 02:00:00', 'game_state' => 'OFF',
        'game_dow' => 'Monday', 'game_month' => 'November', 'away_team_id' => 21, 'home_team_id' => 68,
        'away_team_abbrev' => 'COL', 'home_team_abbrev' => 'UTA']);
    foreach (collect($this->players['COL'])->take(18) as $player) {
        DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020345, 'nhl_team_id' => 21,
            'nhl_player_id' => $player->nhl_id, 'player_name' => $player->full_name, 'position' => $player->position]);
    }
    $this->players['COL'][13]->update(['team_abbrev' => 'UTA']);
    $this->players['COL'][20]->update(['team_abbrev' => 'UTA']);

    expect(fn () => app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants(
        (object) $this->game->getAttributes(), teamAbbrev: 'COL'
    ))->toThrow(\DomainException::class, '12 forwards and 6 defensemen');
});

it('retains actual historical participants even when they are now injured and on another team', function (): void {
    foreach (collect($this->players['COL'])->take(18) as $player) {
        DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020346, 'nhl_team_id' => 21,
            'nhl_player_id' => $player->nhl_id, 'player_name' => $player->full_name, 'position' => $player->position]);
    }
    ($this->out)(13);
    $this->players['COL'][13]->update(['team_abbrev' => 'UTA']);
    $this->game->update(['game_state' => 'OFF']);

    $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants(
        (object) $this->game->getAttributes(), true, teamAbbrev: 'COL'
    );

    expect($record['players'])->toHaveCount(18)->toHaveKey($this->players['COL'][13]->nhl_id);
    expect($record['lineup_sources'][21])->toBe('historical_participants');
});
