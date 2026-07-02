<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\ImportYahooPlayersPageJob;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\NhlOrchestratorJob;
use App\Jobs\SeasonSumJob;
use App\Jobs\SyncYahooTeamRosterJob;
use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\NhlGameImportRun;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\Role;
use App\Models\User;
use App\Models\YahooFantasyConnection;
use App\Models\YahooPlayer;
use App\Services\YahooFantasyPlayerImporter;
use App\Services\YahooFantasyRosterService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->makeSuperAdmin = function (): User {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'level' => 99,
            'is_active' => true,
        ]);

        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    };

    $this->makePlayer = static function (array $overrides = []): Player {
        return Player::create(array_merge([
            'nhl_id' => null,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'full_name' => 'Test Player',
            'dob' => '1990-01-01',
            'position' => 'C',
            'team_abbrev' => 'ANA',
            'current_league_abbrev' => 'NHL',
        ], $overrides));
    };

    $this->makeIdentity = static function (array $overrides = []): PlayerExternalIdentity {
        return PlayerExternalIdentity::create(array_merge([
            'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'provider_player_id' => 'fantrax-1',
            'provider_slug' => 'fantrax-1',
            'display_name' => 'Test Player',
            'normalized_name' => 'test player',
            'first_name' => 'Test',
            'last_name' => 'Player',
            'birthdate' => '1990-01-01',
            'position' => 'C',
            'team' => 'ANA',
            'raw_payload' => ['name' => 'Test Player'],
            'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
            'match_confidence' => 75,
            'unmatched_reason' => PlayerExternalIdentity::REASON_INSUFFICIENT_IDENTITY_DATA,
            'first_seen_at' => '2026-06-26 10:00:00',
            'last_seen_at' => '2026-06-26 10:00:00',
        ], $overrides));
    };
});

it('blocks guests from the player triage inbox', function () {
    $this->getJson(route('admin.player-triage'))->assertUnauthorized();
});

it('blocks authenticated non-admin users from the player triage inbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('admin.player-triage'))
        ->assertForbidden();
});

it('redirects direct player triage page visits back to the admin panel', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.player-triage'))
        ->assertRedirect(route('admin.dashboard'));
});

it('blocks guests from the player triage detail json endpoint', function () {
    $identity = ($this->makeIdentity)();

    $this->getJson(route('admin.player-triage.detail', $identity))
        ->assertUnauthorized();
});

it('blocks authenticated non-admin users from the player triage detail json endpoint', function () {
    $identity = ($this->makeIdentity)();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('admin.player-triage.detail', $identity))
        ->assertForbidden();
});

it('blocks guests from the Yahoo OAuth proof redirect', function () {
    $this->get(route('admin.yahoo.oauth.redirect'))->assertRedirect(route('login'));
});

it('blocks authenticated non-admin users from the Yahoo OAuth proof redirect', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.yahoo.oauth.redirect'))
        ->assertForbidden();
});

it('shows Yahoo connect in the authenticated account drawer', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard', ['tab' => 'triage']))
        ->assertOk()
        ->assertSee('Yahoo')
        ->assertSee(route('integrations.yahoo.redirect'))
        ->assertSee('return_to=')
        ->assertSee('drawer=account')
        ->assertSee('Connect');
});

it('shows Yahoo connected in the authenticated account drawer', function () {
    $user = User::factory()->create();
    YahooFantasyConnection::create([
        'user_id' => $user->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Yahoo')
        ->assertSee('Connected');
});

it('blocks guests from the NHL game import status endpoint', function () {
    $this->getJson(route('admin.nhl-game-imports.status'))->assertUnauthorized();
});

it('blocks authenticated non-admin users from the NHL game import status endpoint', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertForbidden();
});

it('blocks guests from queuing NHL game discovery', function () {
    $this->postJson(route('admin.nhl-game-imports.discover'), [
        'date' => '2026-01-15',
    ])->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing NHL game discovery', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'date' => '2026-01-15',
        ])
        ->assertForbidden();
});

it('blocks guests from queuing NHL game processing', function () {
    $this->postJson(route('admin.nhl-game-imports.process'), [
        'date' => '2026-01-15',
    ])->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing NHL game processing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.process'), [
            'date' => '2026-01-15',
        ])
        ->assertForbidden();
});

it('blocks guests from queuing NHL season stat syncs', function () {
    $this->postJson(route('admin.nhl-game-imports.season-sync'), [
        'season' => '20252026',
    ])->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing NHL season stat syncs', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.season-sync'), [
            'season' => '20252026',
        ])
        ->assertForbidden();
});

it('allows super admins to queue NHL game discovery for a single date', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'date' => '2026-01-15',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('run.mode', NhlGameImportRun::MODE_DATE)
        ->assertJsonPath('run.start_date', '2026-01-15')
        ->assertJsonPath('run.end_date', '2026-01-15')
        ->assertJsonPath('run.queued_jobs', 1);

    $this->assertDatabaseHas('nhl_game_import_runs', [
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
    ]);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job): bool {
        return $job->start->toDateString() === '2026-01-15'
            && $job->end->toDateString() === '2026-01-15';
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event): bool {
        return $event->reason === 'discovery-queued';
    });
});

it('allows super admins to queue NHL game discovery for a range', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'start' => '2026-01-17',
            'end' => '2026-01-15',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.mode', NhlGameImportRun::MODE_RANGE)
        ->assertJsonPath('run.start_date', '2026-01-17')
        ->assertJsonPath('run.end_date', '2026-01-15')
        ->assertJsonPath('run.date_count', 3);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job): bool {
        return $job->start->toDateString() === '2026-01-17'
            && $job->end->toDateString() === '2026-01-15';
    });
});

it('allows super admins to queue NHL game processing for each date in a range', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process'), [
            'start' => '2026-01-17',
            'end' => '2026-01-15',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_PROCESS)
        ->assertJsonPath('run.queued_jobs', 3);

    foreach (['2026-01-17', '2026-01-16', '2026-01-15'] as $date) {
        Bus::assertDispatched(NhlOrchestratorJob::class, fn (NhlOrchestratorJob $job): bool => $job->gameDate === $date);
    }
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event): bool {
        return $event->reason === 'processing-queued';
    });
});

it('allows super admins to queue NHL season stat syncs', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.season-sync'), [
            'season' => '20252026',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_SEASON_SYNC)
        ->assertJsonPath('run.mode', NhlGameImportRun::MODE_SEASON)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_QUEUED)
        ->assertJsonPath('run.start_date', '2026-08-31')
        ->assertJsonPath('run.end_date', '2025-09-01')
        ->assertJsonPath('run.queued_jobs', 1)
        ->assertJsonPath('run.payload.season', '20252026')
        ->assertJsonPath('run.payload.season_label', '2025-26');

    $run = NhlGameImportRun::query()->firstOrFail();

    $this->assertDatabaseHas('nhl_game_import_runs', [
        'action' => NhlGameImportRun::ACTION_SEASON_SYNC,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 1,
        'queued_jobs' => 1,
    ]);

    Bus::assertDispatched(SeasonSumJob::class, function (SeasonSumJob $job) use ($run): bool {
        return $job->seasonId === '20252026' && $job->runId === $run->id;
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'season-sync-queued' && $event->runId === $run->id;
    });
});

it('rejects invalid NHL season stat sync selections', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.season-sync'), [
            'season' => '2025',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('season');

    Bus::assertNotDispatched(SeasonSumJob::class);
});

it('queues NHL game processing from a discovery run without creating a second run', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 1,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process'), [
            'run_id' => $run->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.id', $run->id)
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('run.processing_started', true)
        ->assertJsonPath('run.queued_jobs', 3);

    expect(NhlGameImportRun::count())->toBe(1);

    foreach (['2026-01-17', '2026-01-16', '2026-01-15'] as $date) {
        Bus::assertDispatched(NhlOrchestratorJob::class, fn (NhlOrchestratorJob $job): bool => $job->gameDate === $date);
    }
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'processing-queued' && $event->runId === $run->id;
    });
});

it('defaults NHL game processing to today when no date option is provided', function () {
    Bus::fake();
    $this->travelTo('2026-01-15 12:00:00');

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process'), [])
        ->assertAccepted()
        ->assertJsonPath('run.mode', NhlGameImportRun::MODE_DEFAULT)
        ->assertJsonPath('run.start_date', '2026-01-15')
        ->assertJsonPath('run.end_date', '2026-01-15');

    Bus::assertDispatched(NhlOrchestratorJob::class, fn (NhlOrchestratorJob $job): bool => $job->gameDate === '2026-01-15');
});

it('requires an explicit NHL discovery date selection', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    Bus::assertNotDispatched(NhlDiscoveryJob::class);
});

it('rejects ambiguous NHL discovery date selections', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'date' => '2026-01-15',
            'start' => '2026-01-17',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    Bus::assertNotDispatched(NhlDiscoveryJob::class);
});

it('rejects NHL game import date ranges larger than the admin limit', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'start' => '2026-06-01',
            'end' => '2026-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    Bus::assertNotDispatched(NhlDiscoveryJob::class);
});

it('returns NHL game import status with pipeline progress counts', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_PROCESS,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 3,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-01-15',
        'game_dow' => 'Thu',
        'game_month' => 'Jan',
        'home_team_id' => 1,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 2,
        'away_team_abbrev' => 'MTL',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([
        ['date' => '2026-01-15', 'type' => 'pbp', 'status' => 'completed'],
        ['date' => '2026-01-16', 'type' => 'summary', 'status' => 'running'],
        ['date' => '2026-01-17', 'type' => 'boxscore', 'status' => 'scheduled'],
    ] as $index => $row) {
        DB::table('nhl_import_progress')->insert([
            'season_id' => '20252026',
            'game_date' => $row['date'],
            'game_id' => (string) (2025020001 + $index),
            'game_type' => 2,
            'import_type' => $row['type'],
            'status' => $row['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.status', NhlGameImportRun::STATUS_RUNNING)
        ->assertJsonPath('runs.0.progress.total_stage_rows', 3)
        ->assertJsonPath('runs.0.progress.completed_stage_rows', 1)
        ->assertJsonPath('runs.0.progress.running_stage_rows', 1)
        ->assertJsonPath('runs.0.progress.scheduled_stage_rows', 1)
        ->assertJsonPath('runs.0.facts.selected_date_count', 3)
        ->assertJsonPath('runs.0.facts.discovered_game_date_count', 3)
        ->assertJsonPath('runs.0.facts.discovered_game_count', 3)
        ->assertJsonPath('runs.0.facts.scheduled_stage_rows', 1)
        ->assertJsonPath('runs.0.facts.total_stage_rows', 3)
        ->assertJsonPath('runs.0.games.0.game_id', '2025020001')
        ->assertJsonPath('runs.0.games.0.game_date', '2026-01-15')
        ->assertJsonPath('runs.0.games.0.away_team_abbrev', 'MTL')
        ->assertJsonPath('runs.0.games.0.home_team_abbrev', 'TOR')
        ->assertJsonPath('runs.0.games.0.total_stage_rows', 1)
        ->assertJsonPath('runs.0.games.0.completed_stage_rows', 1)
        ->assertJsonPath('runs.0.games.0.percentage', 100)
        ->assertJsonPath('processable.date_count', 1);
});

it('returns discovered NHL game import runs as completed once pipeline rows exist', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    DB::table('nhl_import_progress')->insert([
        'season_id' => '20252026',
        'game_date' => '2026-01-15',
        'game_id' => '2025020001',
        'game_type' => 2,
        'import_type' => 'pbp',
        'status' => 'scheduled',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('runs.0.status', NhlGameImportRun::STATUS_COMPLETED)
        ->assertJsonPath('runs.0.facts.total_stage_rows', 1)
        ->assertJsonPath('runs.0.facts.scheduled_stage_rows', 1);
});

it('returns NHL season options and season sync progress in game import status', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_SEASON_SYNC,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'season' => '20252026',
            'season_label' => '2025-26',
            'rows_upserted' => 812,
        ],
    ]);

    DB::table('nhl_games')->insert([
        [
            'nhl_game_id' => 2025020001,
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => '2026-01-15',
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'nhl_game_id' => 2024020001,
            'season_id' => '20242025',
            'game_type' => 2,
            'game_date' => '2025-01-15',
            'game_dow' => 'Wed',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.action', NhlGameImportRun::ACTION_SEASON_SYNC)
        ->assertJsonPath('runs.0.status', NhlGameImportRun::STATUS_COMPLETED)
        ->assertJsonPath('runs.0.progress.percentage', 100)
        ->assertJsonPath('runs.0.progress.completed_stage_rows', 1)
        ->assertJsonPath('runs.0.facts', [])
        ->assertJsonPath('runs.0.games', [])
        ->assertJsonPath('seasons.0.season', '20252026')
        ->assertJsonPath('seasons.0.label', '2025-26')
        ->assertJsonPath('seasons.1.season', '20242025')
        ->assertJsonPath('seasons.1.label', '2024-25');
});

it('blocks guests from the user Yahoo OAuth redirect', function () {
    $this->get(route('integrations.yahoo.redirect'))->assertRedirect(route('login'));
});

it('redirects authenticated users to Yahoo authorization with the user callback uri', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'yahoo.oauth.authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('integrations.yahoo.redirect', [
            'return_to' => '/admin?tab=triage',
            'drawer' => 'account',
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('yahoo_oauth_state');
    $response->assertSessionHas('yahoo_oauth_redirect_uri', route('integrations.yahoo.callback'));
    $response->assertSessionHas('yahoo_oauth_return_url', url('/admin?tab=triage&drawer=account'));

    $location = $response->headers->get('Location');
    $parts = parse_url((string) $location);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['scheme'].'://'.$parts['host'].$parts['path'])->toBe('https://api.login.yahoo.com/oauth2/request_auth')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['client_id'] ?? null)->toBe('yahoo-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe(route('integrations.yahoo.callback'))
        ->and($query['state'] ?? '')->not->toBe('');
});

it('persists a user Yahoo OAuth callback and redirects back to the stored admin state', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'services.yahoo.client_secret' => 'yahoo-client-secret',
        'yahoo.oauth.token' => 'https://api.login.yahoo.com/oauth2/get_token',
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    Http::fake([
        'https://api.login.yahoo.com/oauth2/get_token' => Http::response([
            'access_token' => 'user-access-token',
            'refresh_token' => 'user-refresh-token',
            'expires_in' => 3600,
        ]),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl/players;start=0;count=5' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/users;use_login=1/games;game_keys=nhl/leagues' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <users>
    <user>
      <games>
        <game>
          <game_key>475</game_key>
          <leagues count="1">
            <league>
              <league_key>475.l.12345</league_key>
              <league_id>12345</league_id>
              <name>Dynasty Hockey</name>
              <url>https://hockey.fantasysports.yahoo.com/hockey/12345</url>
              <season>2026</season>
            </league>
          </leagues>
        </game>
      </games>
    </user>
  </users>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/users;use_login=1/games;game_keys=nhl/teams' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <users>
    <user>
      <games>
        <game>
          <teams count="1">
            <team>
              <team_key>475.l.12345.t.2</team_key>
              <team_id>2</team_id>
              <name>Rob's Team</name>
              <url>https://hockey.fantasysports.yahoo.com/hockey/12345/2</url>
            </team>
          </teams>
        </game>
      </games>
    </user>
  </users>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/league/475.l.12345/teams' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <league>
    <league_key>475.l.12345</league_key>
    <teams count="2">
      <team>
        <team_key>475.l.12345.t.1</team_key>
        <team_id>1</team_id>
        <name>Opponent Team</name>
      </team>
      <team>
        <team_key>475.l.12345.t.2</team_key>
        <team_id>2</team_id>
        <name>Rob's Team</name>
      </team>
    </teams>
  </league>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/league/475.l.12345/settings' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <league>
    <league_key>475.l.12345</league_key>
    <settings>
      <roster_positions>
        <roster_position>
          <position>C</position>
          <position_type>O</position_type>
          <count>2</count>
        </roster_position>
        <roster_position>
          <position>D</position>
          <position_type>O</position_type>
          <count>4</count>
        </roster_position>
        <roster_position>
          <position>G</position>
          <position_type>G</position_type>
          <count>2</count>
        </roster_position>
        <roster_position>
          <position>BN</position>
          <count>5</count>
        </roster_position>
      </roster_positions>
    </settings>
  </league>
</fantasy_content>
XML),
    ]);

    $user = User::factory()->create();
    Queue::fake([SyncYahooTeamRosterJob::class]);

    $this->actingAs($user)
        ->withSession([
            'yahoo_oauth_state' => 'expected-state',
            'yahoo_oauth_redirect_uri' => route('integrations.yahoo.callback'),
            'yahoo_oauth_return_url' => url('/admin?tab=triage&drawer=account'),
        ])
        ->get(route('integrations.yahoo.callback', [
            'state' => 'expected-state',
            'code' => 'auth-code',
        ]))
        ->assertRedirect(url('/admin?tab=triage&drawer=account&yahoo_connected=1'))
        ->assertSessionHas('success', 'Yahoo connected');

    $connection = YahooFantasyConnection::query()->where('user_id', $user->id)->firstOrFail();

    expect($connection->status)->toBe('connected')
        ->and($connection->access_token)->toBe('user-access-token')
        ->and($connection->refresh_token)->toBe('user-refresh-token')
        ->and($connection->meta['game']['game_key'] ?? null)->toBe('475')
        ->and($connection->meta['league_sync']['leagues_count'] ?? null)->toBe(1)
        ->and($connection->meta['league_sync']['owned_teams_count'] ?? null)->toBe(1);

    $this->assertDatabaseHas('platform_leagues', [
        'platform' => 'yahoo',
        'platform_league_id' => '475.l.12345',
        'name' => 'Dynasty Hockey',
        'sport' => 'hockey',
    ]);
    $league = PlatformLeague::query()
        ->where('platform', 'yahoo')
        ->where('platform_league_id', '475.l.12345')
        ->firstOrFail();
    $this->assertDatabaseHas('platform_league_roster_slots', [
        'platform_league_id' => $league->id,
        'slot' => 'C',
        'slot_type' => 'starter',
        'position_type' => 'F',
        'count' => 2,
        'sort_order' => 1,
    ]);
    $this->assertDatabaseHas('platform_league_roster_slots', [
        'platform_league_id' => $league->id,
        'slot' => 'BN',
        'slot_type' => 'bench',
        'position_type' => null,
        'count' => 5,
        'sort_order' => 4,
    ]);
    $this->assertDatabaseHas('platform_teams', [
        'platform_team_id' => '475.l.12345.t.2',
        'name' => "Rob's Team",
    ]);
    $this->assertDatabaseHas('league_user_teams', [
        'user_id' => $user->id,
        'is_active' => true,
    ]);
    $team = PlatformTeam::query()
        ->where('platform_team_id', '475.l.12345.t.2')
        ->firstOrFail();
    Queue::assertPushed(
        SyncYahooTeamRosterJob::class,
        fn (SyncYahooTeamRosterJob $job): bool => $job->platformTeamId === $team->id,
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.login.yahoo.com/oauth2/get_token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === route('integrations.yahoo.callback'));
});

it('syncs Yahoo team roster players through staging identities and roster memberships', function () {
    config([
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
    ]);

    Http::fake([
        'https://fantasysports.yahooapis.com/fantasy/v2/team/475.l.12345.t.2/roster/players' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <team>
    <team_key>475.l.12345.t.2</team_key>
    <roster>
      <players count="1">
        <player>
          <player_key>475.p.5980</player_key>
          <player_id>5980</player_id>
          <name>
            <full>Nathan MacKinnon</full>
            <first>Nathan</first>
            <last>MacKinnon</last>
          </name>
          <editorial_team_abbr>COL</editorial_team_abbr>
          <display_position>C</display_position>
          <eligible_positions>
            <position>C</position>
          </eligible_positions>
          <selected_position>
            <position>C</position>
          </selected_position>
        </player>
      </players>
    </roster>
  </team>
</fantasy_content>
XML),
    ]);

    $user = User::factory()->create();
    YahooFantasyConnection::create([
        'user_id' => $user->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);
    $league = PlatformLeague::create([
        'platform' => 'yahoo',
        'platform_league_id' => '475.l.12345',
        'name' => 'Dynasty Hockey',
        'sport' => 'hockey',
    ]);
    $team = PlatformTeam::create([
        'platform_league_id' => $league->id,
        'platform_team_id' => '475.l.12345.t.2',
        'name' => "Rob's Team",
    ]);
    $user->platformLeagues()->attach($league->id, [
        'team_id' => $team->id,
        'is_active' => true,
        'extras' => json_encode(['provider' => 'yahoo']),
        'synced_at' => now(),
    ]);
    $player = Player::create([
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'full_name' => 'Nathan MacKinnon',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'COL',
        'status' => 'active',
    ]);
    $stalePlayer = Player::create([
        'first_name' => 'Stale',
        'last_name' => 'Player',
        'full_name' => 'Stale Player',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'COL',
        'status' => 'active',
    ]);
    DB::table('platform_roster_memberships')->insert([
        'platform_team_id' => $team->id,
        'player_id' => $stalePlayer->id,
        'platform' => 'yahoo',
        'platform_player_id' => '475.p.old',
        'slot' => 'C',
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $result = app(YahooFantasyRosterService::class)->syncTeam($team->id);

    expect($result)->toBe([
        'players_count' => 1,
        'resolved_count' => 1,
    ]);
    $this->assertDatabaseHas('yahoo_players', [
        'player_key' => '475.p.5980',
        'yahoo_player_id' => '5980',
        'player_id' => $player->id,
    ]);
    $this->assertDatabaseHas('player_external_identities', [
        'provider' => PlayerExternalIdentity::PROVIDER_YAHOO,
        'provider_player_id' => '5980',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $this->assertDatabaseHas('platform_roster_memberships', [
        'platform_team_id' => $team->id,
        'player_id' => $player->id,
        'platform' => 'yahoo',
        'platform_player_id' => '475.p.5980',
        'slot' => 'C',
        'status' => 'active',
        'ends_at' => null,
    ]);

    expect(
        DB::table('platform_roster_memberships')
            ->where('platform_team_id', $team->id)
            ->where('player_id', $stalePlayer->id)
            ->whereNotNull('ends_at')
            ->exists(),
    )->toBeTrue();
});

it('ignores unsafe Yahoo OAuth return urls', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'yahoo.oauth.authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('integrations.yahoo.redirect', [
            'return_to' => 'https://evil.example/admin?tab=triage',
            'drawer' => 'account',
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('yahoo_oauth_return_url', url('/dashboard?drawer=account'));
});

it('redirects super admins to Yahoo authorization with configured OAuth fields', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'services.yahoo.redirect' => 'https://dynastyiq.com/auth/yahoo/callback',
        'yahoo.oauth.authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
    ]);

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.yahoo.oauth.redirect'));

    $response->assertRedirect();
    $response->assertSessionHas('yahoo_oauth_state');

    $location = $response->headers->get('Location');
    $parts = parse_url((string) $location);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['scheme'].'://'.$parts['host'].$parts['path'])->toBe('https://api.login.yahoo.com/oauth2/request_auth')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['client_id'] ?? null)->toBe('yahoo-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('https://dynastyiq.com/auth/yahoo/callback')
        ->and($query['state'] ?? '')->not->toBe('');
});

it('blocks guests from the Yahoo OAuth proof callback', function () {
    $this->get(route('admin.yahoo.oauth.callback'))->assertRedirect(route('login'));
});

it('blocks authenticated non-admin users from the Yahoo OAuth proof callback', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.yahoo.oauth.callback'))
        ->assertForbidden();
});

it('rejects Yahoo OAuth proof callbacks with invalid state', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->withSession(['yahoo_oauth_state' => 'expected-state'])
        ->get(route('admin.yahoo.oauth.callback', [
            'state' => 'wrong-state',
            'code' => 'auth-code',
        ]))
        ->assertForbidden();
});

it('rejects Yahoo OAuth proof callbacks without an authorization code', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->withSession(['yahoo_oauth_state' => 'expected-state'])
        ->get(route('admin.yahoo.oauth.callback', [
            'state' => 'expected-state',
        ]))
        ->assertUnprocessable();
});

it('exchanges a Yahoo OAuth code and returns sanitized game and player diagnostics', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'services.yahoo.client_secret' => 'yahoo-client-secret',
        'services.yahoo.redirect' => 'https://dynastyiq.com/auth/yahoo/callback',
        'yahoo.oauth.token' => 'https://api.login.yahoo.com/oauth2/get_token',
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    Http::fake([
        'https://api.login.yahoo.com/oauth2/get_token' => Http::response([
            'access_token' => 'access-token-value',
            'refresh_token' => 'refresh-token-value',
            'expires_in' => 3600,
        ]),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl/players;start=0;count=5' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.12345</player_key>
        <player_id>12345</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <primary_position>C</primary_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/users;use_login=1/games;game_keys=nhl/leagues' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <users>
    <user>
      <games>
        <game>
          <leagues count="0" />
        </game>
      </games>
    </user>
  </users>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/users;use_login=1/games;game_keys=nhl/teams' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <users>
    <user>
      <games>
        <game>
          <teams count="0" />
        </game>
      </games>
    </user>
  </users>
</fantasy_content>
XML),
    ]);

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->withSession(['yahoo_oauth_state' => 'expected-state'])
        ->getJson(route('admin.yahoo.oauth.callback', [
            'state' => 'expected-state',
            'code' => 'auth-code',
        ]));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('connection.status', 'connected')
        ->assertJsonPath('game.game_key', '475')
        ->assertJsonPath('game.code', 'nhl')
        ->assertJsonPath('players.0.player_key', '475.p.12345')
        ->assertJsonPath('players.0.full_name', 'Nathan MacKinnon')
        ->assertJsonPath('players.0.editorial_team_abbr', 'COL')
        ->assertJsonMissing(['access_token' => 'access-token-value'])
        ->assertJsonMissing(['refresh_token' => 'refresh-token-value']);

    $this->assertSessionHas('yahoo_oauth_probe_token.access_token', 'access-token-value');

    $connection = YahooFantasyConnection::query()->where('status', 'connected')->firstOrFail();

    expect($connection->access_token)->toBe('access-token-value')
        ->and($connection->refresh_token)->toBe('refresh-token-value')
        ->and($connection->meta['game']['game_key'] ?? null)->toBe('475');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.login.yahoo.com/oauth2/get_token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === 'https://dynastyiq.com/auth/yahoo/callback');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl'
        && $request->hasHeader('Authorization', 'Bearer access-token-value'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl/players;start=0;count=5'
        && $request->hasHeader('Authorization', 'Bearer access-token-value'));
});

it('blocks guests from importing Yahoo players', function () {
    $this->postJson(route('admin.yahoo.players.import'))->assertUnauthorized();
});

it('blocks authenticated non-admin users from importing Yahoo players', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('admin.yahoo.players.import'))
        ->assertForbidden();
});

it('requires a Yahoo OAuth connection before importing Yahoo players', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.yahoo.players.import'))
        ->assertStatus(409);
});

it('refreshes an expired Yahoo connection before queued player page imports', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'services.yahoo.client_secret' => 'yahoo-client-secret',
        'yahoo.oauth.token' => 'https://api.login.yahoo.com/oauth2/get_token',
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    Http::fake([
        'https://api.login.yahoo.com/oauth2/get_token' => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 3600,
        ]),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=1' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
    ]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'expired-access-token',
        'refresh_token' => 'old-refresh-token',
        'token_expires_at' => now()->subMinute(),
        'connected_at' => now(),
    ]);
    $importRun = \App\Models\ImportRun::create([
        'source' => 'yahoo',
        'status' => 'working',
        'options' => ['all_players' => true, 'page_size' => 1],
        'meta' => ['dynamic_total' => true],
        'ran_at' => now(),
        'started_at' => now(),
    ]);

    Queue::fake([ImportYahooPlayersPageJob::class]);

    (new ImportYahooPlayersPageJob($connection->id, $importRun->id, 0, 1))
        ->handle(app(YahooFantasyPlayerImporter::class));

    $connection->refresh();

    expect($connection->access_token)->toBe('fresh-access-token')
        ->and($connection->refresh_token)->toBe('fresh-refresh-token');

    $importRun->refresh();

    expect($importRun->processed_records)->toBe(1)
        ->and($importRun->successful_records)->toBe(1)
        ->and($importRun->status)->toBe('working');

    Queue::assertPushed(
        ImportYahooPlayersPageJob::class,
        fn (ImportYahooPlayersPageJob $job): bool => $job->connectionId === $connection->id
            && $job->importRunId === $importRun->id
            && $job->start === 1
            && $job->pageSize === 1
            && $job->gameKey === '475',
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.login.yahoo.com/oauth2/get_token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh-token');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl'
        && $request->hasHeader('Authorization', 'Bearer fresh-access-token'));
});

it('imports bounded Yahoo player collection pages into yahoo players', function () {
    config([
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    Http::fake([
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=2' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="2">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
      <player>
        <player_key>475.p.6743</player_key>
        <player_id>6743</player_id>
        <name>
          <full>Connor McDavid</full>
          <first>Connor</first>
          <last>McDavid</last>
        </name>
        <editorial_team_abbr>EDM</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=2;count=1' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.6369</player_key>
        <player_id>6369</player_id>
        <name>
          <full>Leon Draisaitl</full>
          <first>Leon</first>
          <last>Draisaitl</last>
        </name>
        <editorial_team_abbr>EDM</editorial_team_abbr>
        <display_position>C,LW</display_position>
        <eligible_positions>
          <position>C</position>
          <position>LW</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
    ]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    $result = app(YahooFantasyPlayerImporter::class)->import($connection, 3, 2);

    expect($result['game']['game_key'])->toBe('475')
        ->and($result['imported'])->toBe(3)
        ->and($result['players'][0]['player_key'])->toBe('475.p.5980')
        ->and($result['players'][2]['player_key'])->toBe('475.p.6369');

    expect(YahooPlayer::query()->count())->toBe(3)
        ->and(PlayerExternalIdentity::query()->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)->count())->toBe(3)
        ->and(Player::query()->count())->toBe(0);

    $player = YahooPlayer::query()->where('player_key', '475.p.6369')->firstOrFail();
    $identity = PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)
        ->where('provider_player_id', '6369')
        ->firstOrFail();

    expect($player->game_key)->toBe('475')
        ->and($player->yahoo_player_id)->toBe('6369')
        ->and($player->player_external_identity_id)->toBe($identity->id)
        ->and($player->player_id)->toBeNull()
        ->and($player->full_name)->toBe('Leon Draisaitl')
        ->and($player->editorial_team_abbr)->toBe('EDM')
        ->and($player->display_position)->toBe('C,LW')
        ->and($player->eligible_positions)->toBe(['C', 'LW'])
        ->and($player->raw_payload)->toBeArray();

    expect($identity->provider_slug)->toBe('475.p.6369')
        ->and($identity->display_name)->toBe('Leon Draisaitl')
        ->and($identity->first_name)->toBe('Leon')
        ->and($identity->last_name)->toBe('Draisaitl')
        ->and($identity->position)->toBe('C')
        ->and($identity->team)->toBe('EDM')
        ->and($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_UNMATCHED)
        ->and($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=2'
        && $request->hasHeader('Authorization', 'Bearer access-token-value'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=2;count=1'
        && $request->hasHeader('Authorization', 'Bearer access-token-value'));
});

it('queues an all-player Yahoo import from the admin import endpoint', function () {
    config([
        'yahoo.fantasy.players_page_size' => 25,
    ]);
    Queue::fake([ImportYahooPlayersPageJob::class]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.yahoo.players.import'))
        ->assertOk()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('import_run.source', 'yahoo')
        ->assertJsonPath('import_run.status', 'working')
        ->assertJsonPath('import_run.progress.total_records', null)
        ->assertJsonPath('import_run.progress.dynamic_total', true)
        ->assertJsonPath('import_run.progress.percentage', null);

    $importRun = \App\Models\ImportRun::query()->where('source', 'yahoo')->firstOrFail();

    expect($importRun->options)->toBe(['all_players' => true, 'page_size' => 25])
        ->and($importRun->meta['dynamic_total'] ?? null)->toBeTrue();

    Queue::assertPushed(
        ImportYahooPlayersPageJob::class,
        fn (ImportYahooPlayersPageJob $job): bool => $job->connectionId === $connection->id
            && $job->importRunId === $importRun->id
            && $job->start === 0
            && $job->pageSize === 25,
    );
});

it('completes queued Yahoo imports when a player page is short', function () {
    config([
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);
    Queue::fake([ImportYahooPlayersPageJob::class]);

    Http::fake([
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=2' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
    ]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);
    $importRun = \App\Models\ImportRun::create([
        'source' => 'yahoo',
        'status' => 'working',
        'options' => ['all_players' => true, 'page_size' => 2],
        'meta' => ['dynamic_total' => true],
        'ran_at' => now(),
        'started_at' => now(),
    ]);

    (new ImportYahooPlayersPageJob($connection->id, $importRun->id, 0, 2))
        ->handle(app(YahooFantasyPlayerImporter::class));

    $importRun->refresh();

    expect($importRun->status)->toBe('completed')
        ->and($importRun->processed_records)->toBe(1)
        ->and($importRun->successful_records)->toBe(1)
        ->and(YahooPlayer::query()->count())->toBe(1);

    Queue::assertNotPushed(ImportYahooPlayersPageJob::class);
});

it('upserts Yahoo players idempotently by player key', function () {
    config([
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    YahooPlayer::create([
        'game_key' => '475',
        'player_key' => '475.p.5980',
        'yahoo_player_id' => '5980',
        'full_name' => 'Old Name',
        'eligible_positions' => ['LW'],
        'raw_payload' => ['old' => true],
    ]);

    Http::fake([
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=1' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
    ]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    $result = app(YahooFantasyPlayerImporter::class)->import($connection, 1, 1);

    expect($result['imported'])->toBe(1);

    expect(YahooPlayer::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::query()->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)->count())->toBe(1);

    $player = YahooPlayer::query()->where('player_key', '475.p.5980')->firstOrFail();

    expect($player->full_name)->toBe('Nathan MacKinnon')
        ->and($player->editorial_team_abbr)->toBe('COL')
        ->and($player->eligible_positions)->toBe(['C']);
});

it('auto-links imported Yahoo identities when canonical evidence reaches the provider threshold', function () {
    config([
        'yahoo.base_url' => 'https://fantasysports.yahooapis.com/fantasy/v2',
        'yahoo.fantasy.game_code' => 'nhl',
    ]);

    $canonical = ($this->makePlayer)([
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'full_name' => 'Nathan MacKinnon',
        'position' => 'C',
        'team_abbrev' => 'COL',
    ]);

    Http::fake([
        'https://fantasysports.yahooapis.com/fantasy/v2/game/nhl' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <game_key>475</game_key>
    <game_id>475</game_id>
    <name>Hockey</name>
    <code>nhl</code>
    <season>2026</season>
  </game>
</fantasy_content>
XML),
        'https://fantasysports.yahooapis.com/fantasy/v2/game/475/players;start=0;count=1' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<fantasy_content xmlns="https://fantasysports.yahooapis.com/fantasy/v2/base.rng">
  <game>
    <players count="1">
      <player>
        <player_key>475.p.5980</player_key>
        <player_id>5980</player_id>
        <name>
          <full>Nathan MacKinnon</full>
          <first>Nathan</first>
          <last>MacKinnon</last>
        </name>
        <editorial_team_abbr>COL</editorial_team_abbr>
        <display_position>C</display_position>
        <eligible_positions>
          <position>C</position>
        </eligible_positions>
      </player>
    </players>
  </game>
</fantasy_content>
XML),
    ]);

    $admin = ($this->makeSuperAdmin)();
    $connection = YahooFantasyConnection::create([
        'user_id' => $admin->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    $result = app(YahooFantasyPlayerImporter::class)->import($connection, 1, 1);

    expect($result['imported'])->toBe(1);

    $identity = PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)
        ->where('provider_player_id', '5980')
        ->firstOrFail();
    $yahooPlayer = YahooPlayer::query()->where('player_key', '475.p.5980')->firstOrFail();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED)
        ->and($identity->match_confidence)->toBe(95)
        ->and($identity->player_id)->toBe($canonical->id)
        ->and($yahooPlayer->player_external_identity_id)->toBe($identity->id)
        ->and($yahooPlayer->player_id)->toBe($canonical->id);
});

it('empties Yahoo imported player data without deleting canonical players or OAuth connections', function () {
    $player = ($this->makePlayer)([
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'full_name' => 'Nathan MacKinnon',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_YAHOO,
        'provider_player_id' => '5980',
        'provider_slug' => '475.p.5980',
        'display_name' => 'Nathan MacKinnon',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $fantraxIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-5980',
        'display_name' => 'Nathan MacKinnon',
    ]);
    $connection = YahooFantasyConnection::create([
        'user_id' => User::factory()->create()->id,
        'status' => 'connected',
        'access_token' => 'access-token-value',
        'refresh_token' => 'refresh-token-value',
        'token_expires_at' => now()->addHour(),
        'connected_at' => now(),
    ]);

    YahooPlayer::create([
        'player_external_identity_id' => $identity->id,
        'player_id' => $player->id,
        'game_key' => '475',
        'player_key' => '475.p.5980',
        'yahoo_player_id' => '5980',
        'full_name' => 'Nathan MacKinnon',
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'eligible_positions' => ['C'],
        'raw_payload' => ['player_key' => '475.p.5980'],
        'imported_at' => now(),
    ]);

    $this->artisan('yahoo:empty')
        ->assertOk()
        ->expectsOutput('Removed Yahoo imported player data.')
        ->expectsOutput('yahoo_players: 1')
        ->expectsOutput('player_external_identities: 1')
        ->expectsOutput('Canonical players and Yahoo OAuth connections were not deleted.');

    expect(YahooPlayer::query()->count())->toBe(0)
        ->and(PlayerExternalIdentity::query()->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)->count())->toBe(0)
        ->and(PlayerExternalIdentity::query()->whereKey($fantraxIdentity->id)->exists())->toBeTrue()
        ->and(\App\Models\Player::query()->whereKey($player->id)->exists())->toBeTrue()
        ->and(YahooFantasyConnection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

it('requires an explicit NHL empty mode', function () {
    $this->artisan('nhl:empty')
        ->assertExitCode(\Symfony\Component\Console\Command\Command::INVALID)
        ->expectsOutput('Choose at least one mode: nhl:empty --players, nhl:empty --games, or both.');
});

it('empties NHL player identities without deleting game import data', function () {
    $now = now();
    $player = ($this->makePlayer)([
        'nhl_id' => 5980,
        'nhl_team_id' => 21,
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'full_name' => 'Nathan MacKinnon',
    ]);
    $nhlIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '5980',
        'provider_slug' => '5980',
        'display_name' => 'Nathan MacKinnon',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $draftIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
        'provider_player_id' => '2013:1',
        'provider_slug' => '2013:1',
        'display_name' => 'Nathan MacKinnon',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $fantraxIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-5980',
        'provider_slug' => 'fantrax-5980',
        'display_name' => 'Nathan MacKinnon',
    ]);

    DB::table('nhl_teams')->insert([
        'nhl_id' => 21,
        'abbrev' => 'COL',
        'full_name' => 'Colorado Avalanche',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-01-15',
        'game_dow' => 'Thu',
        'game_month' => 'Jan',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->artisan('nhl:empty', ['--players' => true])
        ->assertOk()
        ->expectsOutput('Clearing player_external_identities...')
        ->expectsOutput('player_external_identities: 2')
        ->expectsOutput('Removed NHL player external identities.')
        ->expectsOutput('Canonical players and NHL team reference data were not deleted.');

    expect(\App\Models\Player::query()->whereKey($player->id)->exists())->toBeTrue()
        ->and(DB::table('nhl_games')->where('nhl_game_id', 2025020001)->exists())->toBeTrue()
        ->and(PlayerExternalIdentity::query()->whereKey($nhlIdentity->id)->exists())->toBeFalse()
        ->and(PlayerExternalIdentity::query()->whereKey($draftIdentity->id)->exists())->toBeFalse()
        ->and(PlayerExternalIdentity::query()->whereKey($fantraxIdentity->id)->exists())->toBeTrue();
});

it('empties NHL game import data without deleting player identities', function () {
    $now = now();
    $player = ($this->makePlayer)([
        'nhl_id' => 5980,
        'nhl_team_id' => 21,
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'full_name' => 'Nathan MacKinnon',
    ]);
    $nhlIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '5980',
        'provider_slug' => '5980',
        'display_name' => 'Nathan MacKinnon',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $fantraxIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-5980',
        'provider_slug' => 'fantrax-5980',
        'display_name' => 'Nathan MacKinnon',
    ]);

    DB::table('nhl_teams')->insert([
        'nhl_id' => 21,
        'abbrev' => 'COL',
        'full_name' => 'Colorado Avalanche',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-01-15',
        'game_dow' => 'Thu',
        'game_month' => 'Jan',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $unitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'COL',
        'unit_type' => 'F',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $eventId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2025020001,
        'nhl_player_id' => 5980,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $unitShiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'unit_id' => $unitId,
        'nhl_game_id' => 2025020001,
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '00:45',
        'start_game_seconds' => 0,
        'end_game_seconds' => 45,
        'seconds' => 45,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('event_unit_shifts')->insert([
        'event_id' => $eventId,
        'unit_shift_id' => $unitShiftId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_unit_game_summaries')->insert([
        'nhl_game_id' => 2025020001,
        'unit_id' => $unitId,
        'team_id' => 21,
        'team_abbrev' => 'COL',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_unit_game_strength_summaries')->insert([
        'nhl_game_id' => 2025020001,
        'unit_id' => $unitId,
        'strength' => 'EV',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_player_game_strength_summaries')->insert([
        'nhl_game_id' => 2025020001,
        'player_id' => $player->id,
        'nhl_player_id' => 5980,
        'strength' => 'EV',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_unit_players')->insert([
        'unit_id' => $unitId,
        'player_id' => $player->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_shifts')->insert([
        'nhl_game_id' => 2025020001,
        'nhl_player_id' => 5980,
        'shift_number' => 1,
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '00:45',
        'shift_start_seconds' => 0,
        'shift_end_seconds' => 45,
        'shift_duration_seconds' => 45,
        'team_abbrev' => 'COL',
        'team_name' => 'Avalanche',
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'unit_id' => $unitId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_boxscores')->insert([
        'nhl_game_id' => 2025020001,
        'nhl_player_id' => 5980,
        'nhl_team_id' => 21,
        'player_name' => 'Nathan MacKinnon',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2025020001,
        'nhl_player_id' => 5980,
        'nhl_team_id' => 21,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_season_stats')->insert([
        'season_id' => '20252026',
        'nhl_player_id' => 5980,
        'nhl_team_id' => 21,
        'game_type' => 2,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_import_progress')->insert([
        'season_id' => '20252026',
        'game_date' => '2026-01-15',
        'game_id' => '2025020001',
        'game_type' => 2,
        'import_type' => 'pbp',
        'status' => 'completed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $validationId = DB::table('nhl_game_validations')->insertGetId([
        'nhl_game_id' => 2025020001,
        'validation_type' => 'summary_boxscore',
        'status' => 'failed',
        'mismatch_count' => 1,
        'checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('nhl_game_validation_deltas')->insert([
        'validation_id' => $validationId,
        'nhl_player_id' => 5980,
        'field' => 'goals',
        'boxscore_value' => '2',
        'summary_value' => '1',
        'delta' => 1,
        'severity' => 'error',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);
    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2025020001,
        'source' => 'shifts',
        'status' => 'empty',
        'reason' => 'empty_shiftcharts',
        'url' => 'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2025020001',
        'details' => json_encode(['data_count' => 0]),
        'checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->artisan('nhl:empty', ['--games' => true])
        ->assertOk()
        ->expectsOutput('Clearing event_unit_shifts...')
        ->expectsOutput('event_unit_shifts: 1')
        ->expectsOutput('Clearing nhl_game_validations...')
        ->expectsOutput('nhl_game_validations: 1')
        ->expectsOutput('Clearing nhl_import_progress...')
        ->expectsOutput('nhl_import_progress: 1')
        ->expectsOutput('Clearing nhl_game_import_runs...')
        ->expectsOutput('nhl_game_import_runs: 1')
        ->expectsOutput('Clearing nhl_game_source_statuses...')
        ->expectsOutput('nhl_game_source_statuses: 1')
        ->expectsOutput('nhl_games: 1')
        ->expectsOutput('Removed NHL game import data.')
        ->expectsOutput('Canonical players and NHL team reference data were not deleted.');

    foreach ([
        'event_unit_shifts',
        'nhl_unit_game_strength_summaries',
        'nhl_player_game_strength_summaries',
        'nhl_unit_game_summaries',
        'nhl_unit_players',
        'nhl_unit_shifts',
        'nhl_shifts',
        'nhl_units',
        'nhl_game_validation_deltas',
        'nhl_game_validations',
        'nhl_boxscores',
        'nhl_game_summaries',
        'play_by_plays',
        'nhl_season_stats',
        'nhl_import_progress',
        'nhl_game_import_runs',
        'nhl_game_source_statuses',
        'nhl_games',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    expect(\App\Models\Player::query()->whereKey($player->id)->exists())->toBeTrue()
        ->and(DB::table('nhl_teams')->where('nhl_id', 21)->exists())->toBeTrue()
        ->and(PlayerExternalIdentity::query()->whereKey($nhlIdentity->id)->exists())->toBeTrue()
        ->and(PlayerExternalIdentity::query()->whereKey($fantraxIdentity->id)->exists())->toBeTrue();
});

it('allows super admins to view the player triage inbox', function () {
    $identity = ($this->makeIdentity)();

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertSee('Player Triage')
        ->assertSee($identity->display_name);
});

it('shows unresolved identity statuses in the default inbox', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'candidate-1',
        'display_name' => 'Candidate Player',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'unmatched-1',
        'display_name' => 'Unmatched Player',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'conflict-1',
        'display_name' => 'Conflict Player',
        'match_status' => PlayerExternalIdentity::STATUS_CONFLICT,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertSee('Candidate Player')
        ->assertSee('Unmatched Player')
        ->assertSee('Conflict Player');
});

it('hides high confidence resolver recommendations from the default inbox', function () {
    ($this->makePlayer)([
        'full_name' => 'High Confidence Player',
        'first_name' => 'High',
        'last_name' => 'Confidence',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'high-confidence-1',
        'display_name' => 'High Confidence Player',
        'normalized_name' => 'high confidence player',
        'birthdate' => null,
        'position' => 'R',
        'team' => 'ANA',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'match_confidence' => 75,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertDontSee('High Confidence Player')
        ->assertSee('No identities match the current filters.');
});

it('shows high confidence resolver recommendations when all identities are requested', function () {
    ($this->makePlayer)([
        'full_name' => 'Included Confidence Player',
        'first_name' => 'Included',
        'last_name' => 'Confidence',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'included-confidence-1',
        'display_name' => 'Included Confidence Player',
        'normalized_name' => 'included confidence player',
        'birthdate' => null,
        'position' => 'R',
        'team' => 'ANA',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'match_confidence' => 75,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['include_resolved' => 1]))
        ->assertOk()
        ->assertSee('Included Confidence Player')
        ->assertSee('95% recommendation');
});

it('hides matched and ignored identities from the default inbox', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'matched-1',
        'display_name' => 'Matched Player',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => ($this->makePlayer)()->id,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'ignored-1',
        'display_name' => 'Ignored Player',
        'match_status' => PlayerExternalIdentity::STATUS_IGNORED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertDontSee('Matched Player')
        ->assertDontSee('Ignored Player');
});

it('can include resolved identities with the resolved filter', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'matched-1',
        'display_name' => 'Matched Player',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => ($this->makePlayer)()->id,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'ignored-1',
        'display_name' => 'Ignored Player',
        'match_status' => PlayerExternalIdentity::STATUS_IGNORED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['include_resolved' => 1]))
        ->assertOk()
        ->assertSee('Matched Player')
        ->assertSee('Ignored Player');
});

it('can filter directly to matched identities', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'candidate-1',
        'display_name' => 'Candidate Player',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'matched-1',
        'display_name' => 'Matched Player',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => ($this->makePlayer)()->id,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['statuses' => [PlayerExternalIdentity::STATUS_MATCHED]]))
        ->assertOk()
        ->assertSee('Matched Player')
        ->assertDontSee('Candidate Player');
});

it('can filter directly to matched identities with the triage state segment', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'segment-candidate-1',
        'display_name' => 'Segment Candidate Player',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'segment-matched-1',
        'display_name' => 'Segment Matched Player',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['triage_state' => 'matched']))
        ->assertOk()
        ->assertSee('Segment Matched Player')
        ->assertDontSee('Segment Candidate Player');
});

it('filters identities by provider', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-1',
        'display_name' => 'Fantrax Player',
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-1',
        'display_name' => 'CapWages Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES]))
        ->assertOk()
        ->assertSee('CapWages Player')
        ->assertDontSee('Fantrax Player');
});

it('shows source options from existing external identity providers', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-1',
        'display_name' => 'Fantrax Player',
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-1',
        'display_name' => 'NHL Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertSee('Source')
        ->assertSee('Fantrax')
        ->assertSee('Nhl');
});

it('filters source identities to rows without canonical records', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-open',
        'display_name' => 'Open Fantrax Player',
        'player_id' => null,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-linked',
        'display_name' => 'Linked Fantrax Player',
        'player_id' => ($this->makePlayer)()->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-open',
        'display_name' => 'Open NHL Player',
        'player_id' => null,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['source' => PlayerExternalIdentity::PROVIDER_FANTRAX]))
        ->assertOk()
        ->assertSee('Player Inbox (1)')
        ->assertSee('Open Fantrax Player')
        ->assertDontSee('Linked Fantrax Player')
        ->assertDontSee('Open NHL Player');
});

it('filters source identities to rows with canonical records when matched is selected without matching source', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-open',
        'display_name' => 'Open CapWages Player',
        'player_id' => null,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-linked',
        'display_name' => 'Linked CapWages Player',
        'player_id' => ($this->makePlayer)()->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-linked-source',
        'display_name' => 'Linked Fantrax Source Player',
        'player_id' => ($this->makePlayer)()->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
            'matched' => 1,
        ]))
        ->assertOk()
        ->assertSee('Player Inbox (1)')
        ->assertSee('Linked CapWages Player')
        ->assertDontSee('Open CapWages Player')
        ->assertDontSee('Linked Fantrax Source Player');
});

it('can show all source identities with the triage state segment', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-segment-open',
        'display_name' => 'Segment Open CapWages Player',
        'player_id' => null,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-segment-linked',
        'display_name' => 'Segment Linked CapWages Player',
        'player_id' => ($this->makePlayer)()->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
            'triage_state' => 'all',
        ]))
        ->assertOk()
        ->assertSee('Segment Open CapWages Player')
        ->assertSee('Segment Linked CapWages Player');
});

it('filters source identities missing a matching source identity', function () {
    $missingPlayer = ($this->makePlayer)([
        'full_name' => 'Missing Fantrax Player',
        'first_name' => 'Missing',
        'last_name' => 'Fantrax',
    ]);
    $coveredPlayer = ($this->makePlayer)([
        'full_name' => 'Covered Fantrax Player',
        'first_name' => 'Covered',
        'last_name' => 'Fantrax',
    ]);

    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-missing',
        'display_name' => 'Missing Fantrax Player',
        'player_id' => $missingPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-covered',
        'display_name' => 'Covered Fantrax Player',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-covered',
        'display_name' => 'Covered Fantrax Player',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        ]))
        ->assertOk()
        ->assertSee('Player Inbox (1)')
        ->assertSee('Missing Fantrax Player')
        ->assertDontSee('Covered Fantrax Player');
});

it('shows coverage state instead of resolver recommendation in source matching mode', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Danil Zhilkin',
        'first_name' => 'Danil',
        'last_name' => 'Zhilkin',
        'position' => 'C',
        'team_abbrev' => 'WPG',
    ]);

    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-zhilkin',
        'display_name' => 'Danil Zhilkin',
        'normalized_name' => 'danil zhilkin',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'match_confidence' => 100,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-zhilkin',
        'display_name' => 'Danny Zhilkin',
        'normalized_name' => 'danny zhilkin',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'match_confidence' => null,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        ]))
        ->assertOk()
        ->assertSee('Danil Zhilkin')
        ->assertSee('missing fantrax')
        ->assertDontSee('100% recommendation');
});

it('shows matching source suggestions in source matching detail mode', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Coverage Detail Player',
        'first_name' => 'Coverage',
        'last_name' => 'Detail',
        'position' => 'C',
        'team_abbrev' => 'WPG',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-coverage-detail',
        'display_name' => 'Coverage Detail Player',
        'normalized_name' => 'coverage detail player',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-coverage-detail',
        'display_name' => 'Coverage Detail Player',
        'normalized_name' => 'coverage detail player',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'identity' => $sourceIdentity->id,
        ]))
        ->assertOk()
        ->assertSee('Player Record')
        ->assertSee('Suggested External Records')
        ->assertSee('fantrax-coverage-detail')
        ->assertDontSee('Source Coverage')
        ->assertDontSee('Suggested Player Matches');
});

it('limits matching source suggestions to unlinked exact normalized name identities', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Jonathan Toews',
        'first_name' => 'Jonathan',
        'last_name' => 'Toews',
        'position' => 'C',
        'team_abbrev' => 'WPG',
    ]);
    $otherPlayer = ($this->makePlayer)([
        'full_name' => 'Adam Lowry',
        'first_name' => 'Adam',
        'last_name' => 'Lowry',
        'position' => 'C',
        'team_abbrev' => 'WPG',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-toews',
        'display_name' => 'Jonathan Toews',
        'normalized_name' => 'jonathan toews',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-toews',
        'display_name' => 'Jonathan Toews',
        'normalized_name' => 'jonathan toews',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-lowry',
        'display_name' => 'Adam Lowry',
        'normalized_name' => 'adam lowry',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => $otherPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-wong',
        'display_name' => 'Austin Wong',
        'normalized_name' => 'austin wong',
        'position' => 'C',
        'team' => 'WPG',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-toews-goalie',
        'display_name' => 'Jonathan Toews',
        'normalized_name' => 'jonathan toews',
        'position' => 'G',
        'team' => 'WPG',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'identity' => $sourceIdentity->id,
        ]))
        ->assertOk()
        ->assertSee('fantrax-toews')
        ->assertDontSee('fantrax-lowry')
        ->assertDontSee('fantrax-wong')
        ->assertDontSee('fantrax-toews-goalie');
});

it('allows matching source search to find unlinked compatible position identities by normalized name variant', function () {
    $player = ($this->makePlayer)([
        'full_name' => "Ryan O'Reilly",
        'first_name' => 'Ryan',
        'last_name' => "O'Reilly",
        'position' => 'C',
        'team_abbrev' => 'DET',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-oreilly',
        'display_name' => "Ryan O'Reilly",
        'normalized_name' => 'ryan o reilly',
        'position' => 'C',
        'team' => 'DET',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-oreilly',
        'display_name' => 'Ryan OReilly',
        'normalized_name' => 'ryan oreilly',
        'position' => 'LW',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-oreilly-linked',
        'display_name' => 'Ryan OReilly',
        'normalized_name' => 'ryan oreilly',
        'position' => 'C',
        'team' => 'DET',
        'player_id' => ($this->makePlayer)(['full_name' => 'Linked Ryan OReilly'])->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-oreilly-goalie',
        'display_name' => 'Ryan OReilly',
        'normalized_name' => 'ryan oreilly',
        'position' => 'G',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'identity' => $sourceIdentity->id,
            'matching_identity_search' => "Ryan O'Reilly",
        ]))
        ->assertOk()
        ->assertSee('fantrax-oreilly')
        ->assertDontSee('fantrax-oreilly-linked')
        ->assertDontSee('fantrax-oreilly-goalie');
});

it('links a matching source identity to the selected source canonical player', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Coverage Link Player',
        'first_name' => 'Coverage',
        'last_name' => 'Link',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-coverage-link',
        'display_name' => 'Coverage Link Player',
        'normalized_name' => 'coverage link player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $matchingIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-coverage-link',
        'display_name' => 'Coverage Link Player',
        'normalized_name' => 'coverage link player',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.link-matching-source', $sourceIdentity), [
            'matching_identity_id' => $matchingIdentity->id,
        ])
        ->assertRedirect(route('admin.player-triage', ['identity' => $sourceIdentity->id]));

    $matchingIdentity->refresh();

    expect($matchingIdentity->player_id)->toBe($player->id);
    expect($matchingIdentity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($matchingIdentity->match_confidence)->toBe(100);
});

it('returns linked matching source identity details as json', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Ajax Coverage Link',
        'first_name' => 'Ajax',
        'last_name' => 'Coverage',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-ajax-coverage',
        'display_name' => 'Ajax Coverage Link',
        'normalized_name' => 'ajax coverage link',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $matchingIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-ajax-coverage',
        'display_name' => 'Ajax Coverage Link',
        'normalized_name' => 'ajax coverage link',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-ajax-coverage',
        'display_name' => 'Ajax Coverage Link',
        'normalized_name' => 'ajax coverage link',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.link-matching-source', $sourceIdentity), [
            'matching_identity_id' => $matchingIdentity->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Matching source linked')
        ->assertJsonPath('matched_identity.provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
        ->assertJsonPath('matched_identity.provider_player_id', 'fantrax-ajax-coverage')
        ->assertJsonPath('linked_identities.0.provider', PlayerExternalIdentity::PROVIDER_CAPWAGES);

    expect($matchingIdentity->refresh()->player_id)->toBe($player->id);
});

it('creates a canonical prospect player from an external identity and selected external matches', function () {
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-create-prospect',
        'display_name' => 'Create Prospect',
        'first_name' => 'Create',
        'last_name' => 'Prospect',
        'normalized_name' => 'create prospect',
        'position' => 'C',
        'team' => 'DET',
        'birthdate' => null,
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    CapWagesPlayer::create([
        'player_external_identity_id' => $identity->id,
        'slug' => 'capwages-create-prospect',
        'name' => 'Create Prospect',
        'birth_date' => '2006-04-12',
    ]);
    $externalMatch = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-create-prospect',
        'display_name' => 'Create Prospect',
        'normalized_name' => 'create prospect',
        'position' => 'LW',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    $unrelated = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_ELITEPROSPECTS,
        'provider_player_id' => 'ep-unrelated-prospect',
        'display_name' => 'Unrelated Prospect',
        'normalized_name' => 'unrelated prospect',
        'position' => 'C',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.create-canonical', $identity), [
            'external_identity_ids' => [$externalMatch->id, $unrelated->id],
        ])
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $identity->refresh();
    $externalMatch->refresh();
    $unrelated->refresh();
    $player = Player::findOrFail((int) $identity->player_id);

    expect($player->nhl_id)->toBeNull();
    expect((bool) $player->is_prospect)->toBeTrue();
    expect($player->full_name)->toBe('Create Prospect');
    expect($player->dob)->toBe('2006-04-12');
    expect($player->team_abbrev)->toBe('DET');
    expect($player->pos_type)->toBe('F');
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($externalMatch->player_id)->toBe($player->id);
    expect($externalMatch->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($unrelated->player_id)->toBeNull();
});

it('shows matched source details instead of matching source search when coverage exists', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Matched Detail Player',
        'first_name' => 'Matched',
        'last_name' => 'Detail',
    ]);
    $sourceIdentity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-matched-detail',
        'display_name' => 'Matched Detail Player',
        'normalized_name' => 'matched detail player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-matched-detail',
        'display_name' => 'Matched Detail Player',
        'normalized_name' => 'matched detail player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-matched-detail',
        'display_name' => 'Matched Detail Player',
        'normalized_name' => 'matched detail player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'matched' => 1,
            'identity' => $sourceIdentity->id,
        ]))
        ->assertOk()
        ->assertSee('Player Record')
        ->assertSee('Linked External Sources')
        ->assertSee('nhl-matched-detail')
        ->assertSee('fantrax-matched-detail')
        ->assertSee('capwages-matched-detail')
        ->assertDontSee('Matching Source Search')
        ->assertDontSee('Suggested Fantrax Identities')
        ->assertDontSee('Suggested Player Matches');
});

it('filters source identities missing a matching source when search is empty', function () {
    $missingPlayer = ($this->makePlayer)([
        'full_name' => 'Search Empty Missing',
        'first_name' => 'Search',
        'last_name' => 'Missing',
    ]);
    $coveredPlayer = ($this->makePlayer)([
        'full_name' => 'Search Empty Covered',
        'first_name' => 'Search',
        'last_name' => 'Covered',
    ]);

    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-search-missing',
        'display_name' => 'Search Empty Missing',
        'player_id' => $missingPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-search-covered',
        'display_name' => 'Search Empty Covered',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-search-covered',
        'display_name' => 'Search Empty Covered',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'search' => '',
        ]))
        ->assertOk()
        ->assertSee('Search Empty Missing')
        ->assertDontSee('Search Empty Covered');
});

it('filters source identities that have a matching source identity', function () {
    $missingPlayer = ($this->makePlayer)([
        'full_name' => 'Missing Fantrax Player',
        'first_name' => 'Missing',
        'last_name' => 'Fantrax',
    ]);
    $coveredPlayer = ($this->makePlayer)([
        'full_name' => 'Covered Fantrax Player',
        'first_name' => 'Covered',
        'last_name' => 'Fantrax',
    ]);

    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-missing',
        'display_name' => 'Missing Fantrax Player',
        'player_id' => $missingPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-covered',
        'display_name' => 'Covered Fantrax Player',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-covered',
        'display_name' => 'Covered Fantrax Player',
        'player_id' => $coveredPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_NHL,
            'matching_source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'matched' => 1,
        ]))
        ->assertOk()
        ->assertSee('Player Inbox (1)')
        ->assertSee('Covered Fantrax Player')
        ->assertDontSee('Missing Fantrax Player');
});

it('filters identities by display name search', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'fantrax-1',
        'display_name' => 'Searchable Player',
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'fantrax-2',
        'display_name' => 'Hidden Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['search' => 'searchable']))
        ->assertOk()
        ->assertSee('Searchable Player')
        ->assertDontSee('Hidden Player');
});

it('filters identities by provider player id search', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'external-777',
        'display_name' => 'External Player',
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'external-888',
        'display_name' => 'Other External Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['search' => '777']))
        ->assertOk()
        ->assertSee('External Player')
        ->assertDontSee('Other External Player');
});

it('filters identities by unmatched reason', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'multiple-1',
        'display_name' => 'Multiple Candidate Player',
        'unmatched_reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'missing-1',
        'display_name' => 'Missing Name Player',
        'unmatched_reason' => PlayerExternalIdentity::REASON_PROVIDER_PAYLOAD_MISSING_NAME,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES]))
        ->assertOk()
        ->assertSee('Multiple Candidate Player')
        ->assertDontSee('Missing Name Player');
});

it('shows selected identity details in the review pane', function () {
    $identity = ($this->makeIdentity)([
        'provider_player_id' => 'selected-1',
        'provider_slug' => 'selected-slug',
        'display_name' => 'Selected Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Selected Player')
        ->assertSee('selected-1')
        ->assertSee('selected-slug')
        ->assertSee('Source Record');
});

it('shows linked external sources for a selected canonical-linked identity', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Linked Context Player',
        'first_name' => 'Linked',
        'last_name' => 'Context',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-linked-context',
        'display_name' => 'Linked Context Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-linked-context',
        'display_name' => 'Linked Context Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Linked External Sources')
        ->assertSee('fantrax-linked-context');
});

it('shows linked identities as player records without source action controls', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Already Linked Player',
        'first_name' => 'Already',
        'last_name' => 'Linked',
        'dob' => '1991-03-04',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'display_name' => 'Already Linked Player',
        'normalized_name' => 'already linked player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Player Record')
        ->assertSee('Already Linked Player')
        ->assertSee('Mar 4, 1991')
        ->assertDontSee('1991-03-04')
        ->assertDontSee('Fantrax identity')
        ->assertDontSee('Source Record')
        ->assertDontSee('Manual Actions')
        ->assertDontSee('Apply recommendation')
        ->assertDontSee('Suggested Player Matches');
});

it('shows player dob when a selected identity is linked', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Player Dob Record',
        'first_name' => 'Player',
        'last_name' => 'Dob',
        'dob' => '1987-09-18',
        'position' => 'RW',
        'team_abbrev' => 'ANA',
        'nhl_id' => 8471234,
    ]);
    $identity = ($this->makeIdentity)([
        'display_name' => 'Player Dob Record',
        'birthdate' => null,
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Player Record')
        ->assertSee('Sep 18, 1987')
        ->assertDontSee('1987-09-18')
        ->assertSee('8471234');
});

it('shows last contract summary when a linked player has capwages contracts', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Contract Detail Player',
        'first_name' => 'Contract',
        'last_name' => 'Detail',
    ]);
    $contract = Contract::create([
        'player_id' => $player->id,
        'contract_type' => 'Standard',
        'contract_length' => '2 years',
        'contract_value' => 2000000,
        'expiry_status' => 'UFA',
        'signing_team' => 'ANA',
        'signing_date' => '2026-07-01',
        'signed_by' => 'Club',
    ]);
    $contract->seasons()->create([
        'season' => '2026-27',
        'season_key' => 20262027,
        'label' => '2026-27',
        'cap_hit' => 1000000,
        'aav' => 1000000,
        'base_salary' => 1000000,
    ]);
    $contract->seasons()->create([
        'season' => '2027-28',
        'season_key' => 20272028,
        'label' => '2027-28',
        'cap_hit' => 1000000,
        'aav' => 1000000,
        'base_salary' => 1000000,
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'display_name' => 'Contract Detail Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Last Contract')
        ->assertSee('Standard')
        ->assertSee('2 years')
        ->assertSee('$2,000,000')
        ->assertSee('2027-28')
        ->assertDontSee('UFA');
});

it('shows suggested external matches when no canonical candidate exists', function () {
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-prospect-context',
        'display_name' => 'Prospect Context',
        'normalized_name' => 'prospect context',
        'position' => 'C',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-prospect-context',
        'display_name' => 'Prospect Context',
        'normalized_name' => 'prospect context',
        'position' => 'LW',
        'team' => 'DET',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Create Player Record')
        ->assertSee('Suggested External Records')
        ->assertSee('fantrax-prospect-context')
        ->assertSee('Link after player record');
});

it('shows suggested external matches alongside canonical candidates before linking', function () {
    ($this->makePlayer)([
        'full_name' => 'External Evidence Player',
        'first_name' => 'External',
        'last_name' => 'Evidence',
        'position' => 'C',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-external-evidence',
        'display_name' => 'External Evidence Player',
        'normalized_name' => 'external evidence player',
        'position' => 'C',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-external-evidence',
        'display_name' => 'External Evidence Player',
        'normalized_name' => 'external evidence player',
        'position' => 'LW',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Suggested Player Matches')
        ->assertSee('Suggested External Records')
        ->assertSee('fantrax-external-evidence')
        ->assertSee('Link after player record');
});

it('shows current resolver recommendation confidence instead of stale stored confidence', function () {
    ($this->makePlayer)([
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    $identity = ($this->makeIdentity)([
        'birthdate' => null,
        'position' => 'R',
        'team' => 'ANA',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'match_confidence' => 75,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('95% recommendation')
        ->assertSee('recommends matched');

    expect($identity->refresh()->match_status)->toBe(PlayerExternalIdentity::STATUS_CANDIDATE);
    expect($identity->match_confidence)->toBe(75);
});

it('shows an empty inbox state when no identities match filters', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk()
        ->assertSee('No identities match the current filters.');
});

it('shows suggested player matches for normalized identity names', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Suggested Player',
        'normalized_name' => 'suggested player',
        'birthdate' => '1992-02-02',
    ]);
    ($this->makePlayer)([
        'full_name' => 'Suggested Player',
        'first_name' => 'Suggested',
        'last_name' => 'Player',
        'dob' => '1992-02-02',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('Suggested Player Matches')
        ->assertSee('Suggested Player');
});

it('orders same-name suggestions by matching birthdate first', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Shared Name',
        'normalized_name' => 'shared name',
        'birthdate' => '1994-04-04',
    ]);
    ($this->makePlayer)([
        'full_name' => 'Shared Name',
        'first_name' => 'Shared',
        'last_name' => 'Name',
        'dob' => '1995-05-05',
        'team_abbrev' => 'BOS',
    ]);
    ($this->makePlayer)([
        'full_name' => 'Shared Name',
        'first_name' => 'Shared',
        'last_name' => 'Name',
        'dob' => '1994-04-04',
        'team_abbrev' => 'ANA',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSeeInOrder(['ANA', 'BOS']);
});

it('searches canonical players manually by name', function () {
    $identity = ($this->makeIdentity)();
    ($this->makePlayer)([
        'full_name' => 'Manual Search Player',
        'first_name' => 'Manual',
        'last_name' => 'Search',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'identity' => $identity->id,
            'player_search' => 'Manual Search',
        ]))
        ->assertOk()
        ->assertSee('Manual Search Player');
});

it('manual player search excludes players already linked to the selected identity provider', function () {
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'position' => 'C',
    ]);
    $availablePlayer = ($this->makePlayer)([
        'full_name' => 'Manual Provider Available',
        'first_name' => 'Manual',
        'last_name' => 'Available',
        'position' => 'C',
    ]);
    $claimedPlayer = ($this->makePlayer)([
        'full_name' => 'Manual Provider Claimed',
        'first_name' => 'Manual',
        'last_name' => 'Claimed',
        'position' => 'C',
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-claimed-player',
        'display_name' => 'Manual Provider Claimed',
        'player_id' => $claimedPlayer->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'identity' => $identity->id,
            'player_search' => 'Manual Provider',
        ]))
        ->assertOk()
        ->assertSee($availablePlayer->full_name)
        ->assertDontSee($claimedPlayer->full_name);
});

it('manual player search filters results by selected identity position type', function () {
    $identity = ($this->makeIdentity)([
        'position' => 'D',
    ]);
    $defender = ($this->makePlayer)([
        'full_name' => 'Jake Defender',
        'first_name' => 'Jake',
        'last_name' => 'Defender',
        'position' => 'D',
    ]);
    $forward = ($this->makePlayer)([
        'full_name' => 'Jake Forward',
        'first_name' => 'Jake',
        'last_name' => 'Forward',
        'position' => 'C',
    ]);
    $goalie = ($this->makePlayer)([
        'full_name' => 'Jake Goalie',
        'first_name' => 'Jake',
        'last_name' => 'Goalie',
        'position' => 'G',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'identity' => $identity->id,
            'player_search' => 'Jake',
        ]))
        ->assertOk()
        ->assertSee($defender->full_name)
        ->assertDontSee($forward->full_name)
        ->assertDontSee($goalie->full_name);
});

it('searches canonical players manually by nhl id', function () {
    $identity = ($this->makeIdentity)();
    ($this->makePlayer)([
        'nhl_id' => 7654321,
        'full_name' => 'NHL Id Player',
        'first_name' => 'NHL',
        'last_name' => 'Id',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'identity' => $identity->id,
            'player_search' => '7654321',
        ]))
        ->assertOk()
        ->assertSee('NHL Id Player');
});

it('requires a canonical player when linking an identity', function () {
    $identity = ($this->makeIdentity)();

    $this->actingAs(($this->makeSuperAdmin)())
        ->from(route('admin.player-triage', ['identity' => $identity->id]))
        ->post(route('admin.player-triage.link', $identity), [])
        ->assertSessionHasErrors('player_id');
});

it('blocks guests from applying resolver recommendations', function () {
    $identity = ($this->makeIdentity)();

    $this->post(route('admin.player-triage.resolve', $identity))
        ->assertRedirect(route('login'));
});

it('links an identity to a selected canonical player', function () {
    $identity = ($this->makeIdentity)([
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'unmatched_reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
    ]);
    $player = ($this->makePlayer)();

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.link', $identity), ['player_id' => $player->id])
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $identity->refresh();

    expect($identity->player_id)->toBe($player->id);
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(100);
    expect($identity->unmatched_reason)->toBeNull();
});

it('links a suggested external source to the selected identity canonical player', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'External Link Player',
        'first_name' => 'External',
        'last_name' => 'Link',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-external-link',
        'display_name' => 'External Link Player',
        'normalized_name' => 'external link player',
        'position' => 'C',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $externalMatch = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-external-link',
        'display_name' => 'External Link Player',
        'normalized_name' => 'external link player',
        'position' => 'LW',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.link-external-source', $identity), [
            'external_identity_id' => $externalMatch->id,
        ])
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $externalMatch->refresh();

    expect($externalMatch->player_id)->toBe($player->id);
    expect($externalMatch->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($externalMatch->match_confidence)->toBe(100);
});

it('applies the current resolver recommendation to an identity', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Resolver Match',
        'first_name' => 'Resolver',
        'last_name' => 'Match',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    $identity = ($this->makeIdentity)([
        'provider_player_id' => 'resolver-match-1',
        'display_name' => 'Resolver Match',
        'normalized_name' => 'resolver match',
        'birthdate' => null,
        'position' => 'R',
        'team' => null,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'match_confidence' => 75,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.resolve', $identity))
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $identity->refresh();

    expect($identity->player_id)->toBe($player->id);
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(85);
    expect($identity->unmatched_reason)->toBeNull();
});

it('marks an identity as ignored', function () {
    $identity = ($this->makeIdentity)([
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
        'player_id' => ($this->makePlayer)()->id,
        'match_confidence' => 50,
        'unmatched_reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.ignore', $identity))
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $identity->refresh();

    expect($identity->player_id)->toBeNull();
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_IGNORED);
    expect($identity->match_confidence)->toBeNull();
    expect($identity->unmatched_reason)->toBeNull();
});

it('defers an identity without changing its match state', function () {
    $identity = ($this->makeIdentity)([
        'match_status' => PlayerExternalIdentity::STATUS_CONFLICT,
        'match_confidence' => 25,
        'unmatched_reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.player-triage.defer', $identity))
        ->assertRedirect(route('admin.player-triage', ['identity' => $identity->id]));

    $identity->refresh();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CONFLICT);
    expect($identity->match_confidence)->toBe(25);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES);
});

it('returns a JSON triage fragment for the inbox', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Json Fragment Player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertJsonPath('meta.selected_identity_id', $identity->id)
        ->assertJsonPath('selected_identity.display_name', 'Json Fragment Player')
        ->assertJsonPath('meta.inbox_count', 1)
        ->assertJsonPath('inbox.identities.0.detail_url', route('admin.player-triage.detail', $identity))
        ->assertJsonPath('inbox.meta.loaded_count', 1)
        ->assertJsonPath('inbox.meta.total_count', 1)
        ->assertJson(fn ($json) => $json
            ->has('html')
            ->where('message', null)
            ->etc());
});

it('returns loaded and total counts when the JSON inbox payload is capped', function () {
    foreach (range(1, 80) as $index) {
        ($this->makeIdentity)([
            'provider_player_id' => "json-count-{$index}",
            'provider_slug' => "json-count-{$index}",
            'display_name' => "Json Count Player {$index}",
            'normalized_name' => "json count player {$index}",
        ]);
    }

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['include_resolved' => 1]))
        ->assertOk()
        ->assertJsonPath('inbox.meta.loaded_count', 75)
        ->assertJsonPath('inbox.meta.total_count', 80);
});

it('returns source comparison JSON when linked player dates are raw strings', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Fantrax Date Player',
        'first_name' => 'Fantrax',
        'last_name' => 'Date',
        'dob' => '1994-04-14',
        'position' => 'C',
        'team_abbrev' => 'TOR',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-date-player',
        'display_name' => 'Fantrax Date Player',
        'normalized_name' => 'fantrax date player',
        'position' => 'C',
        'team' => 'TOR',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'capwages-date-option',
        'display_name' => 'CapWages Date Option',
        'normalized_name' => 'capwages date option',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'matching_source' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
            'identity' => $identity->id,
        ]))
        ->assertOk()
        ->assertJsonPath('detail.player.dob', '1994-04-14')
        ->assertJsonPath('detail.selected_identity.id', $identity->id);
});

it('returns detail-only json for a selected triage identity', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Detail Only Player',
    ]);

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage.detail', $identity))
        ->assertOk()
        ->assertJsonPath('detail.selected_identity.id', $identity->id)
        ->assertJsonPath('detail.selected_identity.display_name', 'Detail Only Player');

    $payload = $response->json();

    expect(array_key_exists('html', $payload))->toBeFalse()
        ->and(array_key_exists('inbox', $payload))->toBeFalse();
});

it('filters the JSON triage fragment by search term', function () {
    ($this->makeIdentity)([
        'provider_player_id' => 'json-search-visible',
        'display_name' => 'Visible Json Player',
        'normalized_name' => 'visible json player',
    ]);
    ($this->makeIdentity)([
        'provider_player_id' => 'json-search-hidden',
        'display_name' => 'Hidden Json Player',
        'normalized_name' => 'hidden json player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['search' => 'visible']))
        ->assertOk()
        ->assertJsonPath('meta.inbox_count', 1)
        ->assertSee('Visible Json Player')
        ->assertDontSee('Hidden Json Player');
});

it('returns JSON when linking a canonical player', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Json Link Player',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);
    $player = ($this->makePlayer)([
        'full_name' => 'Json Link Player',
        'first_name' => 'Json',
        'last_name' => 'Link',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.link', $identity), ['player_id' => $player->id])
        ->assertOk()
        ->assertJsonPath('message', 'Identity linked')
        ->assertJsonPath('meta.selected_identity_id', $identity->id)
        ->assertJsonPath('selected_identity.player_id', $player->id)
        ->assertSee('Player Record');

    expect($identity->refresh()->player_id)->toBe($player->id);
});

it('returns JSON validation errors when linking without a canonical player', function () {
    $identity = ($this->makeIdentity)();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.link', $identity), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('player_id');
});

it('returns JSON when linking a suggested external source', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Json External Player',
        'first_name' => 'Json',
        'last_name' => 'External',
    ]);
    $identity = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'json-capwages-external-link',
        'display_name' => 'Json External Player',
        'normalized_name' => 'json external player',
        'position' => 'C',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    $externalMatch = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'json-fantrax-external-link',
        'display_name' => 'Json External Player',
        'normalized_name' => 'json external player',
        'position' => 'LW',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.link-external-source', $identity), [
            'external_identity_id' => $externalMatch->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'External source linked')
        ->assertJsonPath('linked_identity.id', $externalMatch->id)
        ->assertSee('Linked External Sources');

    expect($externalMatch->refresh()->player_id)->toBe($player->id);
});

it('returns JSON errors when external source linking has no canonical player', function () {
    $identity = ($this->makeIdentity)(['player_id' => null]);
    $externalMatch = ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'json-external-no-player',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.link-external-source', $identity), [
            'external_identity_id' => $externalMatch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Link the selected identity to a canonical player first');
});

it('returns JSON when applying resolver recommendations', function () {
    $player = ($this->makePlayer)([
        'full_name' => 'Json Resolver Match',
        'first_name' => 'Json',
        'last_name' => 'Resolver',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    $identity = ($this->makeIdentity)([
        'provider_player_id' => 'json-resolver-match',
        'display_name' => 'Json Resolver Match',
        'normalized_name' => 'json resolver match',
        'birthdate' => null,
        'position' => 'R',
        'team' => null,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.resolve', $identity))
        ->assertOk()
        ->assertJsonPath('message', 'Resolver applied: matched')
        ->assertJsonPath('selected_identity.player_id', $player->id);

    expect($identity->refresh()->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
});

it('returns JSON when ignoring an identity', function () {
    $identity = ($this->makeIdentity)([
        'player_id' => ($this->makePlayer)()->id,
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.ignore', $identity))
        ->assertOk()
        ->assertJsonPath('message', 'Identity ignored')
        ->assertJsonPath('selected_identity.match_status', PlayerExternalIdentity::STATUS_IGNORED);

    expect($identity->refresh()->player_id)->toBeNull();
});

it('returns JSON when deferring an identity without changing state', function () {
    $identity = ($this->makeIdentity)([
        'match_status' => PlayerExternalIdentity::STATUS_CONFLICT,
        'match_confidence' => 25,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.defer', $identity))
        ->assertOk()
        ->assertJsonPath('message', 'Identity left in triage')
        ->assertJsonPath('selected_identity.match_status', PlayerExternalIdentity::STATUS_CONFLICT);

    expect($identity->refresh()->match_confidence)->toBe(25);
});

it('returns JSON when creating a canonical player', function () {
    $identity = ($this->makeIdentity)([
        'display_name' => 'Json Created Prospect',
        'normalized_name' => 'json created prospect',
        'first_name' => 'Json',
        'last_name' => 'Prospect',
        'birthdate' => null,
        'position' => 'C',
        'team' => 'ANA',
        'player_id' => null,
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.player-triage.create-canonical', $identity))
        ->assertOk()
        ->assertJsonPath('message', 'Canonical player created')
        ->assertJsonPath('player.full_name', 'Json Created Prospect')
        ->assertJsonPath('selected_identity.match_status', PlayerExternalIdentity::STATUS_MATCHED);

    expect($identity->refresh()->player_id)->not->toBeNull();
});

it('blocks guests from the imports page', function () {
    $this->get(route('admin.imports'))->assertRedirect(route('login'));
});

it('blocks authenticated non-admin users from the imports page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.imports'))
        ->assertForbidden();
});

it('shows current import workflow buttons to super admins', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.imports'))
        ->assertOk()
        ->assertSee('Import Workflows')
        ->assertSee('NHL Players')
        ->assertSee('Resolve NHL Players')
        ->assertSee('Fantrax Players')
        ->assertSee('Yahoo Players')
        ->assertSee('Contracts')
        ->assertSeeInOrder(['NHL Players', 'Resolve NHL Players', 'Fantrax Players', 'Yahoo Players', 'Contracts'])
        ->assertSee('Run Now')
        ->assertSee('Retry failed');
});

it('shows the admin player imports card list in registry order', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Player Imports')
        ->assertSee('Game Imports')
        ->assertSeeInOrder(['Player Imports', 'Game Imports', 'Triage'])
        ->assertSeeInOrder(['NHL Players', 'Resolve NHL Players', 'Fantrax Players', 'Yahoo Players', 'Contracts'])
        ->assertSee('Run Now')
        ->assertDontSee('Player Inbox');
});

it('shows Yahoo as a triage source after Yahoo identities are imported', function () {
    ($this->makeIdentity)([
        'provider' => PlayerExternalIdentity::PROVIDER_YAHOO,
        'provider_player_id' => '5980',
        'provider_slug' => '475.p.5980',
        'display_name' => 'Nathan MacKinnon',
        'normalized_name' => 'nathan mackinnon',
        'first_name' => 'Nathan',
        'last_name' => 'MacKinnon',
        'position' => 'C',
        'team' => 'COL',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'unmatched_reason' => PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['source' => PlayerExternalIdentity::PROVIDER_YAHOO]))
        ->assertOk()
        ->assertSee('Nathan MacKinnon')
        ->assertSee('475.p.5980');
});
