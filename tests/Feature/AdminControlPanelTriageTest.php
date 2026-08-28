<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Events\NhlSatModelUpdated;
use App\Jobs\BuildNhlFaceoffFactsJob;
use App\Jobs\BuildNhlOfficialSatProfilesJob;
use App\Jobs\BuildNhlShotAttemptFactsJob;
use App\Jobs\BuildNhlStaffSatProfilesJob;
use App\Jobs\BackfillNhlExpectedGoalsJob;
use App\Jobs\DedupeNhlPlayByPlayRepairJob;
use App\Jobs\ImportYahooPlayersPageJob;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\NhlOrchestratorJob;
use App\Jobs\QueueDuplicatePbpAffectedRebuildsJob;
use App\Jobs\ScanDuplicateNhlPlayByPlayRepairJob;
use App\Jobs\SeasonSumJob;
use App\Jobs\SyncYahooTeamRosterJob;
use App\Models\ApiClient;
use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\NhlGameImportRun;
use App\Models\NhlGameValidation;
use App\Models\NhlModelRun;
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
use App\Services\NhlImportOrchestrator;
use App\Support\NhlImportStages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->makeSuperAdmin = function (): User {
        $user = User::factory()->create();
        $role = Role::firstOrCreate([
            'slug' => 'super-admin',
        ], [
            'name' => 'Super Admin',
            'level' => 99,
            'scope' => 'global',
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
        ->get(route('dashboard'))
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

it('blocks guests from the NHL shot attempts admin panel', function () {
    $this->getJson(route('admin.nhl-shot-attempts.index'))->assertUnauthorized();
});

it('blocks authenticated non-admin users from the NHL shot attempts admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.nhl-shot-attempts.index'))
        ->assertForbidden();
});

it('blocks guests from the NHL SAT model bucket view', function () {
    $run = NhlModelRun::query()->create([
        'run_key' => 'sat-buckets-guest-blocked',
        'name' => 'SAT buckets guest blocked',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_COMPLETE,
        'completed_at' => now()->subMinute(),
    ]);

    $this->getJson(route('admin.nhl-sat-models.buckets', $run))->assertUnauthorized();
});

it('blocks authenticated non-admin users from the NHL SAT model bucket view', function () {
    $run = NhlModelRun::query()->create([
        'run_key' => 'sat-buckets-non-admin-blocked',
        'name' => 'SAT buckets non-admin blocked',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_COMPLETE,
        'metrics' => [
            'training_attempts' => 1,
            'training_total_sat' => 2,
            'training_excluded_sat' => 1,
            'training_excluded_sat_rate' => 0.5,
        ],
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.nhl-sat-models.buckets', $run))
        ->assertForbidden();
});

it('blocks guests from the NHL faceoffs admin panel', function () {
    $this->getJson(route('admin.nhl-faceoffs.index'))->assertUnauthorized();
});

it('blocks authenticated non-admin users from the NHL faceoffs admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.nhl-faceoffs.index'))
        ->assertForbidden();
});

it('allows super admins to view the NHL shot attempts admin panel', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index'))
        ->assertOk()
        ->assertSee('NHL Shot Attempts')
        ->assertSee('Facts')
        ->assertSee('Factors')
        ->assertSee('Aggregates')
        ->assertDontSee('Predictive')
        ->assertSee('QA');
});

it('allows super admins to view NHL game-context SAT profiles', function () {
    $goalModelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'name' => 'test-context-goal',
        'version' => 'v1',
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'goal',
        'training_season_id' => '20252026',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $sogModelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'name' => 'test-context-sog',
        'version' => 'v1',
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20252026',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $officialId = DB::table('nhl_officials')->insertGetId([
        'display_name' => 'Referee Example',
        'normalized_name' => 'referee example',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staffId = DB::table('nhl_staff')->insertGetId([
        'display_name' => 'Coach Example',
        'normalized_name' => 'coach example',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $baseProfile = [
        'source_season_id' => '20252026',
        'game_type' => 2,
        'goal_expected_goals_model_id' => $goalModelId,
        'shot_on_goal_expected_goals_model_id' => $sogModelId,
        'role' => 'referee',
        'matched_bucket_key' => 'L01|shot_type_group=wrist|distance_group=mid|angle_group=center|sequence_group=settled',
        'fallback_level' => 1,
        'bucket_dimensions' => json_encode(['shot_type_group' => 'wrist']),
        'shot_type_group' => 'wrist',
        'distance_group' => 'mid',
        'angle_group' => 'center',
        'sequence_group' => 'settled',
        'source_games' => 10,
        'source_sat' => 100,
        'source_unblocked_sat' => 80,
        'source_sog' => 50,
        'source_goals' => 6,
        'source_xg' => 5.55,
        'source_xsog' => 48.25,
        'source_profile_share' => 0.4,
        'goal_probability' => 0.0555,
        'shot_on_goal_probability' => 0.4825,
        'prior_bucket_key' => 'L99|baseline=league',
        'prior_fallback_level' => 99,
        'prior_sat' => 2500,
        'prior_weight_sat' => 2200,
        'shrinkage_weight' => 0.18,
        'confidence_score' => 0.82,
        'confidence_bucket' => 'high',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('nhl_official_sat_profile_buckets')->insert(array_merge($baseProfile, [
        'nhl_official_id' => $officialId,
    ]));
    DB::table('nhl_staff_sat_profile_buckets')->insert(array_merge($baseProfile, [
        'nhl_staff_id' => $staffId,
        'role' => 'head_coach',
        'team_context' => 'offense',
    ]));
    DB::table('nhl_staff_sat_profile_buckets')->insert(array_merge($baseProfile, [
        'nhl_staff_id' => $staffId,
        'role' => 'head_coach',
        'team_context' => 'defense',
        'matched_bucket_key' => 'L01|shot_type_group=slap|distance_group=point_or_high|angle_group=straight_on|sequence_group=settled',
        'shot_type_group' => 'slap',
        'source_sat' => 24,
        'prior_bucket_key' => 'L03|distance_group=point_or_high|angle_group=straight_on|sequence_group=settled',
        'prior_fallback_level' => 3,
        'prior_sat' => 76,
        'prior_weight_sat' => 52,
        'shrinkage_weight' => 0.68,
        'confidence_score' => 0.32,
        'confidence_bucket' => 'low',
    ]));

    $baseAggregateProfile = [
        'source_season_id' => '20252026',
        'game_type' => 2,
        'goal_expected_goals_model_id' => $goalModelId,
        'shot_on_goal_expected_goals_model_id' => $sogModelId,
        'role' => 'referee',
        'aggregate_bucket_key' => 'A03|shot_type_group=wrist|distance_zone=outside_slot',
        'aggregate_level' => 3,
        'aggregate_label' => 'Wrist shots outside the slot',
        'aggregate_dimensions' => json_encode(['aggregate_level' => 3, 'shot_type_group' => 'wrist', 'distance_zone' => 'outside_slot']),
        'source_games' => 10,
        'source_sat' => 100,
        'source_unblocked_sat' => 80,
        'source_sog' => 50,
        'source_goals' => 6,
        'source_xg' => 5.55,
        'source_xsog' => 48.25,
        'source_profile_share' => 0.4,
        'goal_probability' => 0.0555,
        'shot_on_goal_probability' => 0.4825,
        'confidence_score' => 0.82,
        'confidence_bucket' => 'high',
        'shrinkage_weight' => 0.18,
        'included_bucket_count' => 2,
        'included_bucket_keys' => json_encode([
            'L01|shot_type_group=wrist|distance_group=mid|angle_group=center|sequence_group=settled',
            'L01|shot_type_group=wrist|distance_group=point_or_high|angle_group=straight_on|sequence_group=settled',
        ]),
        'metadata' => json_encode([
            'aggregate_bucket_purposes' => ['comparison', 'summary'],
            'avg_distance' => 41.5,
            'avg_angle' => 18.25,
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('nhl_official_sat_aggregate_profile_buckets')->insert(array_merge($baseAggregateProfile, [
        'nhl_official_id' => $officialId,
    ]));
    DB::table('nhl_staff_sat_aggregate_profile_buckets')->insert(array_merge($baseAggregateProfile, [
        'nhl_staff_id' => $staffId,
        'role' => 'head_coach',
        'team_context' => 'offense',
        'source_sat' => 120,
        'goal_probability' => 0.0755,
    ]));

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', ['tab' => 'context-sat-profiles']))
        ->assertOk()
        ->assertSee('Refs &amp; Coaches SAT Profiles', false)
        ->assertSee('Aggregate Profiles')
        ->assertSee('Bucket Comparisons')
        ->assertSee('Exact Bucket Detail')
        ->assertSee('Open this section to load aggregate profiles.')
        ->assertSee('Open this section to load bucket comparisons.')
        ->assertSee('Open this section to load exact bucket detail.')
        ->assertDontSee('Wrist shots outside the slot')
        ->assertDontSee('L99|baseline=league');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.aggregate', [
            'tab' => 'context-sat-profiles',
        ]))
        ->assertOk()
        ->assertSee('Profile Bucket')
        ->assertSee('Exact Buckets')
        ->assertSee('Wrist shots outside the slot')
        ->assertSee('Referee Example')
        ->assertSee('Coach Example')
        ->assertSee('offense');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparisons', [
            'tab' => 'context-sat-profiles',
        ]))
        ->assertOk()
        ->assertSee('Wrist shots outside the slot')
        ->assertSee('2 entities')
        ->assertSee('avg dist 41.50 ft')
        ->assertSee('avg angle 18.25 deg')
        ->assertSee('Open this bucket to load matching coaches and refs.')
        ->assertDontSee('Referee Example')
        ->assertDontSee('Coach Example')
        ->assertDontSee('SAT/G');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparison-rows', [
            'tab' => 'context-sat-profiles',
            'aggregate_bucket_key' => 'A03|shot_type_group=wrist|distance_zone=outside_slot',
        ]))
        ->assertOk()
        ->assertSeeInOrder(['Coach Example', 'Referee Example'])
        ->assertSee('context_profile_bucket_sort=goal_probability')
        ->assertSee('&darr;', false)
        ->assertSee('Referee Example')
        ->assertSee('Coach Example')
        ->assertSee('SAT/G');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparison-rows', [
            'tab' => 'context-sat-profiles',
            'aggregate_bucket_key' => 'A03|shot_type_group=wrist|distance_zone=outside_slot',
            'context_profile_bucket_sort' => 'goal_probability',
            'context_profile_bucket_direction' => 'asc',
        ]))
        ->assertOk()
        ->assertSeeInOrder(['Referee Example', 'Coach Example'])
        ->assertSee('&uarr;', false);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.exact', [
            'tab' => 'context-sat-profiles',
        ]))
        ->assertOk()
        ->assertSee('SAT/G')
        ->assertSee('Lg Share')
        ->assertSee('+/- Lg')
        ->assertSee('L99|baseline=league')
        ->assertSee('2,500')
        ->assertSee('18%');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.context-sat-profiles.exact', [
            'tab' => 'context-sat-profiles',
            'context_profile_useful_only' => '1',
        ]))
        ->assertOk()
        ->assertSee('Referee Example')
        ->assertSee('Coach Example')
        ->assertDontSee('L03|distance_group=point_or_high|angle_group=straight_on|sequence_group=settled');
});

it('allows super admins to queue NHL game-context SAT profiles from admin', function () {
    Bus::fake([
        BuildNhlOfficialSatProfilesJob::class,
        BuildNhlStaffSatProfilesJob::class,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->post(route('admin.nhl-shot-attempts.game-context-sat-profiles.build'), [
            'source_season_id' => '20252026',
            'game_type' => 2,
            'only' => 'staff',
        ])
        ->assertRedirect(route('admin.nhl-shot-attempts.index', [
            'tab' => 'context-sat-profiles',
            'context_profile_season_id' => '20252026',
            'context_profile_game_type' => 2,
        ]));

    Bus::assertDispatched(BuildNhlStaffSatProfilesJob::class, function (BuildNhlStaffSatProfilesJob $job): bool {
        return $job->sourceSeasonId === '20252026' && $job->gameType === 2;
    });
    Bus::assertNotDispatched(BuildNhlOfficialSatProfilesJob::class);
});

it('validates NHL game-context SAT profile build scope', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->from(route('admin.nhl-shot-attempts.index', ['tab' => 'context-sat-profiles']))
        ->post(route('admin.nhl-shot-attempts.game-context-sat-profiles.build'), [
            'source_season_id' => '20252026',
            'game_type' => 2,
            'only' => 'players',
        ])
        ->assertSessionHasErrors('only');
});

it('allows super admins to view the NHL faceoffs admin panel', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-faceoffs.index'))
        ->assertOk()
        ->assertSee('NHL Faceoffs')
        ->assertSee('Teams')
        ->assertSee('Players')
        ->assertSee('Units')
        ->assertSee('Games');
});

it('shows NHL shot attempts in the account drawer for super admins', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Admin Control Panel')
        ->assertSee('Shot Attempts')
        ->assertSee(route('admin.nhl-shot-attempts.index'));
});

it('shows NHL faceoffs in the account drawer for super admins', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Admin Control Panel')
        ->assertSee('Admin Faceoffs')
        ->assertSee(route('admin.nhl-faceoffs.index'));
});

it('uses analytics sessions as durable user last seen activity', function () {
    Carbon::setTestNow('2026-08-11 12:00:00');
    $admin = ($this->makeSuperAdmin)();
    $user = User::factory()->create([
        'name' => 'Analytics User',
        'email' => 'analytics-user@example.com',
    ]);

    $visitorId = DB::table('analytics_visitors')->insertGetId([
        'anonymous_id' => 'f77bf004-9224-4ec9-baf4-9bfec313a7aa',
        'user_id' => $user->id,
        'first_seen_at' => '2026-08-09 10:00:00',
        'last_seen_at' => '2026-08-10 18:30:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('analytics_sessions')->insert([
        'analytics_visitor_id' => $visitorId,
        'user_id' => $user->id,
        'session_uuid' => '62dd390d-e396-4943-a6f7-e660c74aaeb3',
        'started_at' => '2026-08-10 18:00:00',
        'last_seen_at' => '2026-08-10 18:30:00',
        'engaged_seconds' => 300,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('analytics-user@example.com')
        ->assertSee('2026-08-10T18:30:00');

    Carbon::setTestNow();
});

it('orders admin users by the freshest session or analytics activity timestamp', function () {
    Carbon::setTestNow('2026-08-11 12:00:00');
    $admin = ($this->makeSuperAdmin)();
    $analyticsUser = User::factory()->create([
        'name' => 'Analytics Recent',
        'email' => 'analytics-recent@example.com',
    ]);
    $sessionUser = User::factory()->create([
        'name' => 'Session Older',
        'email' => 'session-older@example.com',
    ]);

    $visitorId = DB::table('analytics_visitors')->insertGetId([
        'anonymous_id' => 'ca422f9d-2167-48d3-88d6-3a144151046a',
        'user_id' => $analyticsUser->id,
        'first_seen_at' => '2026-08-10 20:00:00',
        'last_seen_at' => '2026-08-10 20:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('analytics_sessions')->insert([
        'analytics_visitor_id' => $visitorId,
        'user_id' => $analyticsUser->id,
        'session_uuid' => '0996fd1f-fb76-47b2-b203-9d4e3874b5fd',
        'started_at' => '2026-08-10 20:00:00',
        'last_seen_at' => '2026-08-10 20:00:00',
        'engaged_seconds' => 120,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'session-older',
        'user_id' => $sessionUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => '',
        'last_activity' => Carbon::parse('2026-08-10 19:00:00')->timestamp,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    expect($response->getContent())->toContain('analytics-recent@example.com')
        ->and(strpos($response->getContent(), 'analytics-recent@example.com'))
        ->toBeLessThan(strpos($response->getContent(), 'session-older@example.com'));

    Carbon::setTestNow();
});

it('groups NHL shot attempt aggregates by team abbreviation', function () {
    DB::table('nhl_teams')->insert([
        'nhl_id' => 10,
        'abbrev' => 'TOR',
        'full_name' => 'Toronto Maple Leafs',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-04-07',
        'game_dow' => 'Tue',
        'game_month' => 'Apr',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        'id' => 1,
        'nhl_game_id' => 2025020001,
        'event_owner_team_id' => 10,
        'period' => 1,
        'seconds_in_game' => 120,
        'type_desc_key' => 'goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        'id' => 11,
        'nhl_game_id' => 2025020001,
        'event_owner_team_id' => 10,
        'period' => 1,
        'seconds_in_game' => 180,
        'type_desc_key' => 'blocked-shot',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        [
            'play_by_play_id' => 1,
            'nhl_game_id' => 2025020001,
            'season_id' => '20252026',
            'game_date' => '2026-04-07',
            'attempt_result' => 'goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => true,
            'team_id' => 10,
            'shot_distance' => 12.5,
            'abs_shot_angle' => 18.0,
            'shot_type_bucket' => 'wrist',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 11,
            'nhl_game_id' => 2025020001,
            'season_id' => '20252026',
            'game_date' => '2026-04-07',
            'attempt_result' => 'blocked_shot',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => false,
            'is_shot_on_goal' => false,
            'is_goal' => false,
            'team_id' => 10,
            'shot_distance' => 42.5,
            'abs_shot_angle' => 28.0,
            'shot_type_bucket' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'aggregates',
            'group_by' => 'team_abbrev',
        ]))
        ->assertOk()
        ->assertSee('TOR')
        ->assertSee('Blocked')
        ->assertDontSee('Unblocked')
        ->assertSee('2')
        ->assertSee('1')
        ->assertSee('value="team_abbrev"', false)
        ->assertDontSee('value="team_id"', false);
});

it('filters NHL shot attempts by multiple selected seasons from the shared season control', function () {
    DB::table('nhl_games')->insert([
        [
            'nhl_game_id' => 2024020001,
            'season_id' => '20242025',
            'game_type' => 2,
            'game_date' => '2025-01-01',
            'game_dow' => 'Wed',
            'game_month' => 'Jan',
            'home_team_id' => 10,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 20,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2023020001,
            'season_id' => '20232024',
            'game_type' => 2,
            'game_date' => '2024-01-01',
            'game_dow' => 'Mon',
            'game_month' => 'Jan',
            'home_team_id' => 10,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 20,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2022020001,
            'season_id' => '20222023',
            'game_type' => 2,
            'game_date' => '2023-01-01',
            'game_dow' => 'Sun',
            'game_month' => 'Jan',
            'home_team_id' => 10,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 20,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('play_by_plays')->insert([
        [
            'id' => 101,
            'nhl_game_id' => 2024020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'shot-on-goal',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 102,
            'nhl_game_id' => 2023020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'shot-on-goal',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 103,
            'nhl_game_id' => 2022020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'shot-on-goal',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        [
            'play_by_play_id' => 101,
            'nhl_game_id' => 2024020001,
            'season_id' => '20242025',
            'game_date' => '2025-01-01',
            'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => false,
            'team_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 102,
            'nhl_game_id' => 2023020001,
            'season_id' => '20232024',
            'game_date' => '2024-01-01',
            'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => false,
            'team_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 103,
            'nhl_game_id' => 2022020001,
            'season_id' => '20222023',
            'game_date' => '2023-01-01',
            'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => false,
            'team_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'explorer',
            'season_ids' => ['20242025', '20232024'],
        ]))
        ->assertOk()
        ->assertSee('name="season_ids[]"', false)
        ->assertDontSee('name="season_id"', false)
        ->assertSee('2024020001')
        ->assertSee('2023020001')
        ->assertDontSee('2022020001');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'season_ids' => ['20242025', '20232024'],
        ]))
        ->assertOk()
        ->assertSee('name="season_ids[]"', false)
        ->assertSee('Factors');
});

it('shows NHL shot attempt factor rows from raw facts', function () {
    $shooter = ($this->makePlayer)([
        'nhl_id' => 901001,
        'first_name' => 'Left',
        'last_name' => 'Shooter',
        'full_name' => 'Left Shooter',
        'shoots' => 'L',
    ]);
    $blocker = ($this->makePlayer)([
        'nhl_id' => 901002,
        'first_name' => 'Right',
        'last_name' => 'Blocker',
        'full_name' => 'Right Blocker',
        'shoots' => 'R',
    ]);

    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020002,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-04-08',
        'game_dow' => 'Wed',
        'game_month' => 'Apr',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        [
            'id' => 21,
            'nhl_game_id' => 2025020002,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'blocked-shot',
            'shooting_player_id' => $shooter->nhl_id,
            'blocking_player_id' => $blocker->nhl_id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 22,
            'nhl_game_id' => 2025020002,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 180,
            'type_desc_key' => 'goal',
            'shooting_player_id' => $shooter->nhl_id,
            'blocking_player_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 23,
            'nhl_game_id' => 2025020002,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 240,
            'type_desc_key' => 'blocked-shot',
            'shooting_player_id' => $shooter->nhl_id,
            'blocking_player_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 24,
            'nhl_game_id' => 2025020002,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 300,
            'type_desc_key' => 'missed-shot',
            'shooting_player_id' => $shooter->nhl_id,
            'blocking_player_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 25,
            'nhl_game_id' => 2025020002,
            'event_owner_team_id' => 10,
            'period' => 1,
            'seconds_in_game' => 360,
            'type_desc_key' => 'shot-on-goal',
            'shooting_player_id' => $shooter->nhl_id,
            'blocking_player_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        [
            'play_by_play_id' => 21,
            'nhl_game_id' => 2025020002,
            'season_id' => '20252026',
            'game_date' => '2026-04-08',
            'attempt_result' => 'blocked_shot',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => false,
            'is_shot_on_goal' => false,
            'is_goal' => false,
            'team_id' => 10,
            'shooter_player_id' => $shooter->nhl_id,
            'shooter_shoots' => 'L',
            'blocking_player_id' => $blocker->nhl_id,
            'shot_distance' => 42.5,
            'abs_shot_angle' => 28.0,
            'distance_bucket' => 'point_or_high',
            'angle_bucket' => 'a_020_030',
            'shot_type_bucket' => 'wrist',
            'is_rebound' => false,
            'is_rush' => false,
            'strength_bucket' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 22,
            'nhl_game_id' => 2025020002,
            'season_id' => '20252026',
            'game_date' => '2026-04-08',
            'attempt_result' => 'goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => true,
            'team_id' => 10,
            'shooter_player_id' => $shooter->nhl_id,
            'shooter_shoots' => 'L',
            'blocking_player_id' => null,
            'shot_distance' => 12.5,
            'abs_shot_angle' => 18.0,
            'distance_bucket' => 'slot',
            'angle_bucket' => 'a_010_020',
            'shot_type_bucket' => 'wrist',
            'is_rebound' => true,
            'is_rush' => false,
            'strength_bucket' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 23,
            'nhl_game_id' => 2025020002,
            'season_id' => '20252026',
            'game_date' => '2026-04-08',
            'attempt_result' => 'blocked_shot',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => false,
            'is_shot_on_goal' => false,
            'is_goal' => false,
            'team_id' => 10,
            'shooter_player_id' => $shooter->nhl_id,
            'shooter_shoots' => 'L',
            'blocking_player_id' => null,
            'shot_distance' => 40.0,
            'abs_shot_angle' => 31.0,
            'distance_bucket' => 'point_or_high',
            'angle_bucket' => 'a_030_040',
            'shot_type_bucket' => 'snap',
            'is_rebound' => false,
            'is_rush' => false,
            'strength_bucket' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 24,
            'nhl_game_id' => 2025020002,
            'season_id' => '20252026',
            'game_date' => '2026-04-08',
            'attempt_result' => 'missed_shot',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => false,
            'is_goal' => false,
            'team_id' => 10,
            'shooter_player_id' => $shooter->nhl_id,
            'shooter_shoots' => 'L',
            'blocking_player_id' => null,
            'shot_distance' => 30.0,
            'abs_shot_angle' => 91.0,
            'distance_bucket' => 'mid_range',
            'angle_bucket' => null,
            'shot_type_bucket' => 'wrist',
            'is_rebound' => false,
            'is_rush' => false,
            'strength_bucket' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 25,
            'nhl_game_id' => 2025020002,
            'season_id' => '20252026',
            'game_date' => '2026-04-08',
            'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => false,
            'team_id' => 10,
            'shooter_player_id' => $shooter->nhl_id,
            'shooter_shoots' => 'L',
            'blocking_player_id' => null,
            'shot_distance' => 16.0,
            'abs_shot_angle' => 25.0,
            'distance_bucket' => 'slot',
            'angle_bucket' => 'a_020_030',
            'shot_type_bucket' => 'other',
            'is_rebound' => false,
            'is_rush' => false,
            'strength_bucket' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'factor' => 'shooter_blocker_same_hand',
            'sort' => 'attempts',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertSee('Factors')
        ->assertSee('SOG only')
        ->assertSee('Shooting %')
        ->assertDontSee('SOG %')
        ->assertDontSee('Goal %')
        ->assertDontSee('Blocks')
        ->assertDontSee('Misses')
        ->assertDontSee('Block %');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'factor' => 'shooter_blocker_same_hand',
            'factor_sample' => 'sat',
            'include_unknowns' => '1',
            'sort' => 'attempts',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertSee('Shooter/Blocker Same Hand')
        ->assertSee('Different')
        ->assertSee('No Blocker')
        ->assertSee('Unknown Blocker')
        ->assertSee('SAT')
        ->assertSee('Blocks')
        ->assertSee('Misses')
        ->assertSee('SOG %')
        ->assertSee('Goal %')
        ->assertSee('Shooting %')
        ->assertDontSee('Buckets')
        ->assertDontSee('Predictive');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'factor' => 'angle',
            'factor_sample' => 'sat',
        ]))
        ->assertOk()
        ->assertSee('Invalid &gt;90', false)
        ->assertDontSee('a_090_plus')
        ->assertDontSee('Zone');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'factor_keys' => ['distance', 'shot_type'],
            'factor_sample' => 'sat',
            'sort' => 'attempts',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertSee('name="factor_keys[]"', false)
        ->assertSee('Distance + Shot Type')
        ->assertSee('Distance: point_or_high')
        ->assertSee('Shot Type: wrist')
        ->assertSee('Shot Type: snap');

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'factors',
            'factor_keys' => ['distance', 'shot_type'],
            'factor_sample' => 'sat',
            'factor_value_exclusions' => ['shot_type|' . sha1('snap')],
            'sort' => 'attempts',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertSee('name="factor_value_exclusions[]"', false)
        ->assertSee('Distance + Shot Type')
        ->assertSee('Distance: point_or_high · Shot Type: wrist')
        ->assertDontSee('Distance: point_or_high · Shot Type: snap');

    $factorValueResponse = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-shot-attempts.factor-values', [
            'tab' => 'factors',
            'factor_keys' => ['shot_type'],
            'factor_sample' => 'sat',
        ]))
        ->assertOk()
        ->assertJsonFragment(['label' => 'Shot Type: wrist'])
        ->assertJsonFragment(['label' => 'Shot Type: snap'])
        ->assertJsonMissing(['label' => 'Shot Type: other']);

    expect(collect($factorValueResponse->json('values'))->pluck('label')->all())
        ->not->toContain('Distance: point_or_high');

    $unknownExcludedResponse = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-shot-attempts.factor-values', [
            'tab' => 'factors',
            'factor_keys' => ['shooter_blocker_same_hand'],
            'factor_sample' => 'sat',
        ]))
        ->assertOk()
        ->assertJsonFragment(['label' => 'Shooter/Blocker Same Hand: Different'])
        ->assertJsonMissing(['label' => 'Shooter/Blocker Same Hand: Unknown Blocker']);

    expect(collect($unknownExcludedResponse->json('values'))->pluck('label')->all())
        ->not->toContain('Shooter/Blocker Same Hand: Unknown Blocker');

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-shot-attempts.factor-values', [
            'tab' => 'factors',
            'factor_keys' => ['shot_type'],
            'factor_sample' => 'sat',
            'include_unknowns' => '1',
        ]))
        ->assertOk()
        ->assertJsonFragment(['label' => 'Shot Type: other']);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-shot-attempts.factor-values', [
            'tab' => 'factors',
            'factor_selection_state' => 'explicit',
            'factor_sample' => 'sat',
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'values');
});

it('creates draft NHL SAT models from the admin workflow', function () {
    $admin = ($this->makeSuperAdmin)();

    $this->actingAs($admin)
        ->post(route('admin.nhl-sat-models.store'), [
            'name' => 'SAT train 2023 2024 test 2025',
            'model_version' => 'sat_xg_v1',
            'train_season_ids' => ['20232024', '20242025'],
            'test_season_id' => '20252026',
            'notes' => 'First two-season training run.',
        ])
        ->assertRedirect(route('admin.nhl-sat-models.index'));

    $this->assertDatabaseHas('nhl_model_runs', [
        'name' => 'SAT train 2023 2024 test 2025',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_DRAFT,
    ]);

    $run = NhlModelRun::query()->firstOrFail();

    expect($run->train_season_ids)->toBe(['20232024', '20242025'])
        ->and($run->season_weights)->toBe(['20232024' => 0.333333, '20242025' => 0.666667]);

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.index'))
        ->assertOk()
        ->assertSee('SAT Models')
        ->assertSee('Create Model')
        ->assertSee('SAT train 2023 2024 test 2025')
        ->assertSee('20232024, 20242025')
        ->assertSee('20252026')
        ->assertSee('Eval SOG')
        ->assertSee('Eval SAT')
        ->assertDontSee('Family');
});

it('evaluates SAT model SOG danger immediately without scoring the test season', function () {
    Queue::fake();

    $admin = ($this->makeSuperAdmin)();
    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-xg-v1-test-run',
        'name' => 'SAT danger train 2023 2024',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_DRAFT,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.nhl-sat-models.train', $run), [
            'smoothing_prior_attempts' => 75,
        ])
        ->assertRedirect(route('admin.nhl-sat-models.index'));

    Queue::assertNothingPushed();
    $run->refresh();

    expect($run->status)->toBe(NhlModelRun::STATUS_COMPLETE)
        ->and($run->run_config['training_version'])->toBe('sat_xg_v1__run_' . $run->id)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->metrics)->toHaveKey('sog_factor_evaluation')
        ->and($run->metrics)->not->toHaveKey('xg_training_test_season_id');

    $this->assertDatabaseHas('nhl_expected_goals_models', [
        'model_run_id' => $run->id,
        'version' => 'sat_xg_v1__run_' . $run->id,
        'prediction_target' => 'goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'status' => 'draft',
    ]);
    $this->assertDatabaseMissing('nhl_expected_goals_models', [
        'model_run_id' => $run->id,
        'version' => 'sat_xg_v1__run_' . $run->id,
        'prediction_target' => 'shot_on_goal',
    ]);
});

it('evaluates SAT model SAT danger immediately without scoring the test season', function () {
    Queue::fake();

    $admin = ($this->makeSuperAdmin)();
    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-to-sog-v1-test-run',
        'name' => 'SAT to SOG danger train 2023 2024',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_DRAFT,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.nhl-sat-models.train', $run), [
            'evaluation' => 'sat',
            'smoothing_prior_attempts' => 75,
        ])
        ->assertRedirect(route('admin.nhl-sat-models.index'));

    Queue::assertNothingPushed();
    $run->refresh();

    expect($run->status)->toBe(NhlModelRun::STATUS_COMPLETE)
        ->and($run->run_config['training_version'])->toBe('sat_xg_v1__run_' . $run->id)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->metrics)->toHaveKey('sat_factor_evaluation')
        ->and($run->metrics)->not->toHaveKey('xg_training_test_season_id');

    $this->assertDatabaseHas('nhl_expected_goals_models', [
        'model_run_id' => $run->id,
        'version' => 'sat_xg_v1__run_' . $run->id,
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'status' => 'draft',
    ]);
});

it('rebuilds trained SAT evaluation rows immediately when evaluating SAT again', function () {
    Queue::fake();

    $admin = ($this->makeSuperAdmin)();
    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-preserve-trained-test-run',
        'name' => 'SAT preserve trained model',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_COMPLETE,
    ]);
    $version = 'sat_xg_v1__run_' . $run->id;

    $modelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'model_run_id' => $run->id,
        'name' => \App\Services\NhlExpectedGoalsBackfiller::MODEL_NAME,
        'version' => $version,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'feature_config' => json_encode([
            'sample_mode' => 'sat',
            'workflow_action' => 'eval_sat',
            'sat_factor_evaluation' => [
                'winner' => ['label' => 'Distance + Angle', 'score' => 0.0525],
            ],
        ]),
        'metrics' => json_encode([
            'bucket_count' => 1,
            'training_attempts' => 260,
            'training_total_sat' => 300,
            'training_excluded_sat' => 40,
            'training_excluded_sat_rate' => 0.133333,
        ]),
        'status' => 'draft',
        'trained_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_expected_goals_model_buckets')->insert([
        'expected_goals_model_id' => $modelId,
        'bucket_key' => 'L01|distance_group=slot|angle_group=central',
        'fallback_level' => 1,
        'bucket_dimensions' => json_encode(['distance_group' => 'slot', 'angle_group' => 'central']),
        'attempts' => 120,
        'goals' => 90,
        'raw_goal_rate' => 0.750000,
        'smoothed_goal_probability' => 0.720000,
        'confidence_score' => 0.6154,
        'confidence_bucket' => 'medium',
        'shrinkage_weight' => 0.3846,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.nhl-sat-models.train', $run), [
            'evaluation' => 'sat',
            'smoothing_prior_attempts' => 100,
        ])
        ->assertRedirect(route('admin.nhl-sat-models.index'));

    Queue::assertNothingPushed();
    $run->refresh();

    $model = DB::table('nhl_expected_goals_models')
        ->where('model_run_id', $run->id)
        ->where('version', $version)
        ->where('prediction_target', 'shot_on_goal')
        ->first();
    $featureConfig = json_decode($model->feature_config, true, 512, JSON_THROW_ON_ERROR);
    $metrics = json_decode($model->metrics, true, 512, JSON_THROW_ON_ERROR);

    expect($run->status)->toBe(NhlModelRun::STATUS_COMPLETE)
        ->and($run->completed_at)->not->toBeNull()
        ->and($model->status)->toBe('draft')
        ->and($model->trained_at)->not->toBeNull()
        ->and($featureConfig['workflow_action'])->toBe('eval_sat')
        ->and($featureConfig['sat_factor_evaluation']['winner']['label'])->not->toBeNull()
        ->and($metrics['bucket_count'])->toBe(1)
        ->and(DB::table('nhl_expected_goals_model_buckets')->where('expected_goals_model_id', $model->id)->count())->toBe(1);
});

it('shows an empty SAT evaluation state when no SAT rows exist yet', function () {
    $admin = ($this->makeSuperAdmin)();
    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-empty-test-run',
        'name' => 'SAT empty model',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_RUNNING,
    ]);

    DB::table('nhl_expected_goals_models')->insert([
        'model_run_id' => $run->id,
        'name' => \App\Services\NhlExpectedGoalsBackfiller::MODEL_NAME,
        'version' => 'sat_xg_v1__run_' . $run->id,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 100,
        'feature_config' => json_encode([
            'sample_mode' => 'sat',
            'workflow_action' => 'eval_sat',
        ]),
        'metrics' => json_encode(['queued_at' => now()->toIso8601String()]),
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.buckets', [
            'run' => $run,
            'target' => 'shot_on_goal',
        ]))
        ->assertOk()
        ->assertSee('Eval SAT before reviewing SAT danger.')
        ->assertSee('No SAT eval yet')
        ->assertDontSee('Eval SAT is Queued');
});

it('marks SAT model SOG evaluation complete when goal buckets finish', function () {
    Event::fake([NhlSatModelUpdated::class]);

    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-complete-test-run',
        'name' => 'SAT danger train completion',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_RUNNING,
    ]);

    $version = 'sat_xg_v1__run_' . $run->id;

    DB::table('nhl_expected_goals_models')->insert([
        'model_run_id' => $run->id,
        'name' => $version . '_goal',
        'version' => $version,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'metrics' => json_encode([
            'training_total_sog' => 200,
            'training_attempts' => 180,
            'training_excluded_sog' => 20,
            'training_excluded_sog_rate' => 0.1,
            'sog_factor_evaluation' => [
                'winner' => ['label' => 'Distance + Angle'],
            ],
        ]),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $backfiller = Mockery::mock(\App\Services\NhlExpectedGoalsBackfiller::class);
    $backfiller->shouldReceive('trainBucketsForRun')->once();

    (new BackfillNhlExpectedGoalsJob(
        seasonId: '20242025',
        version: $version,
        minimumBucketAttempts: 0,
        smoothingPriorAttempts: 75,
        predictionTarget: 'goal',
        modelRunId: $run->id
    ))->handle($backfiller);

    expect($run->refresh()->status)->toBe(NhlModelRun::STATUS_COMPLETE)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->metrics)->toHaveKey('trained_at')
        ->and($run->metrics['training_excluded_sog_rate'])->toBe(0.1)
        ->and($run->metrics['training_excluded_sog'])->toBe(20);

    Event::assertDispatched(NhlSatModelUpdated::class, function (NhlSatModelUpdated $event) use ($run): bool {
        return $event->modelId === $run->id
            && $event->reason === 'sog-eval-completed';
    });
});

it('keeps a SAT model run running when SOG evaluation finishes while SAT evaluation is queued', function () {
    Event::fake([NhlSatModelUpdated::class]);

    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sog-complete-sat-pending-test-run',
        'name' => 'SOG complete with SAT pending',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_RUNNING,
    ]);

    $version = 'sat_xg_v1__run_' . $run->id;

    DB::table('nhl_expected_goals_models')->insert([
        [
            'model_run_id' => $run->id,
            'name' => $version . '_goal',
            'version' => $version,
            'model_type' => 'bucket_smoothed',
            'prediction_target' => 'goal',
            'training_season_id' => '20242025',
            'minimum_bucket_attempts' => 0,
            'smoothing_prior_attempts' => 75,
            'metrics' => json_encode([
                'training_total_sog' => 200,
                'training_attempts' => 180,
                'training_excluded_sog' => 20,
                'training_excluded_sog_rate' => 0.1,
                'sog_factor_evaluation' => [
                    'winner' => ['label' => 'Distance + Angle'],
                ],
            ]),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'model_run_id' => $run->id,
            'name' => $version . '_shot_on_goal',
            'version' => $version,
            'model_type' => 'bucket_smoothed',
            'prediction_target' => 'shot_on_goal',
            'training_season_id' => '20242025',
            'minimum_bucket_attempts' => 0,
            'smoothing_prior_attempts' => 75,
            'metrics' => json_encode(['queued_at' => now()->toIso8601String()]),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $backfiller = Mockery::mock(\App\Services\NhlExpectedGoalsBackfiller::class);
    $backfiller->shouldReceive('trainBucketsForRun')->once();

    (new BackfillNhlExpectedGoalsJob(
        seasonId: '20242025',
        version: $version,
        minimumBucketAttempts: 0,
        smoothingPriorAttempts: 75,
        predictionTarget: 'goal',
        modelRunId: $run->id
    ))->handle($backfiller);

    expect($run->refresh()->status)->toBe(NhlModelRun::STATUS_RUNNING)
        ->and($run->completed_at)->toBeNull()
        ->and($run->metrics['training_excluded_sog_rate'])->toBe(0.1);

    Event::assertDispatched(NhlSatModelUpdated::class, function (NhlSatModelUpdated $event) use ($run): bool {
        return $event->modelId === $run->id
            && $event->reason === 'sog-eval-completed';
    });
});

it('marks SAT model SAT evaluation complete when shot-on-goal buckets finish', function () {
    Event::fake([NhlSatModelUpdated::class]);

    $run = NhlModelRun::query()->create([
        'run_key' => 'training-sat-to-sog-complete-test-run',
        'name' => 'SAT to SOG train completion',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_xg_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_RUNNING,
    ]);

    $version = 'sat_xg_v1__run_' . $run->id;

    DB::table('nhl_expected_goals_models')->insert([
        'model_run_id' => $run->id,
        'name' => $version . '_shot_on_goal',
        'version' => $version,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'metrics' => json_encode([
            'training_total_sat' => 240,
            'training_attempts' => 220,
            'training_excluded_sat' => 20,
            'training_excluded_sat_rate' => 0.083333,
            'sat_factor_evaluation' => [
                'winner' => ['label' => 'Distance + Angle'],
            ],
        ]),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $backfiller = Mockery::mock(\App\Services\NhlExpectedGoalsBackfiller::class);
    $backfiller->shouldReceive('trainBucketsForRun')->once();

    (new BackfillNhlExpectedGoalsJob(
        seasonId: '20242025',
        version: $version,
        minimumBucketAttempts: 0,
        smoothingPriorAttempts: 75,
        predictionTarget: 'shot_on_goal',
        modelRunId: $run->id
    ))->handle($backfiller);

    expect($run->refresh()->status)->toBe(NhlModelRun::STATUS_COMPLETE)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->metrics)->toHaveKey('trained_at')
        ->and($run->metrics['training_excluded_sat_rate'])->toBe(0.083333)
        ->and($run->metrics['training_excluded_sat'])->toBe(20)
        ->and($run->metrics['sat_factor_evaluation']['winner']['label'])->toBe('Distance + Angle');

    Event::assertDispatched(NhlSatModelUpdated::class, function (NhlSatModelUpdated $event) use ($run): bool {
        return $event->modelId === $run->id
            && $event->reason === 'sat-eval-completed';
    });
});

it('shows sortable trained SAT model buckets to super admins', function () {
    $admin = ($this->makeSuperAdmin)();
    $run = NhlModelRun::query()->create([
        'run_key' => 'sat-buckets-sortable',
        'name' => 'SAT buckets sortable model',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'sat_v1',
        'train_start_season_id' => '20232024',
        'train_end_season_id' => '20242025',
        'train_season_ids' => ['20232024', '20242025'],
        'season_weights' => ['20232024' => 0.333333, '20242025' => 0.666667],
        'target_season_id' => '20252026',
        'status' => NhlModelRun::STATUS_COMPLETE,
    ]);

    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2024020001,
        'season_id' => '20242025',
        'game_type' => 2,
        'game_date' => '2025-01-01',
        'game_dow' => 'Wed',
        'game_month' => 'Jan',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('play_by_plays')->insert([
        [
            'id' => 900001,
            'nhl_game_id' => 2024020001,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'shot-on-goal',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 900002,
            'nhl_game_id' => 2024020001,
            'period' => 1,
            'seconds_in_game' => 180,
            'type_desc_key' => 'missed-shot',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('nhl_shot_attempts_facts')->insert([
        [
            'play_by_play_id' => 900001,
            'nhl_game_id' => 2024020001,
            'season_id' => '20242025',
            'game_date' => '2025-01-01',
            'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => true,
            'is_goal' => false,
            'shot_type_bucket' => 'wrist',
            'period_type' => 'REG',
            'is_empty_net' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'play_by_play_id' => 900002,
            'nhl_game_id' => 2024020001,
            'season_id' => '20242025',
            'game_date' => '2025-01-01',
            'attempt_result' => 'missed_shot',
            'is_shot_attempt' => true,
            'is_unblocked_attempt' => true,
            'is_shot_on_goal' => false,
            'is_goal' => false,
            'shot_type_bucket' => 'unknown',
            'period_type' => 'REG',
            'is_empty_net' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $modelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'model_run_id' => $run->id,
        'name' => 'nhl_expected_goals_v1',
        'version' => 'sat_v1__run_' . $run->id,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'feature_config' => json_encode([
            'sample_mode' => 'sog',
            'workflow_action' => 'eval_sog',
            'sog_factor_evaluation' => [
                'baseline_sog' => 180,
                'baseline_goals' => 18,
                'baseline_rate' => 0.1,
                'candidate_method' => 'weighted_absolute_lift_on_training_sog',
                'winner' => [
                    'label' => 'Distance + Angle',
                    'score' => 0.0725,
                ],
                'singles' => [
                    ['label' => 'Distance', 'rows' => 3, 'sog' => 180, 'score' => 0.061],
                ],
                'doubles' => [
                    ['label' => 'Distance + Angle', 'rows' => 8, 'sog' => 180, 'score' => 0.0725],
                ],
            ],
        ]),
        'metrics' => json_encode([
            'bucket_count' => 2,
            'training_attempts' => 1,
            'training_total_sog' => 2,
            'training_excluded_sog' => 1,
            'training_excluded_sog_rate' => 0.5,
        ]),
        'status' => 'queued',
        'trained_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_expected_goals_model_buckets')->insert([
        [
            'expected_goals_model_id' => $modelId,
            'bucket_key' => 'L01|shot_type_group=tip|distance_group=slot',
            'fallback_level' => 1,
            'bucket_dimensions' => json_encode(['shot_type_group' => 'tip', 'distance_group' => 'slot']),
            'attempts' => 40,
            'goals' => 8,
            'raw_goal_rate' => 0.200000,
            'smoothed_goal_probability' => 0.170000,
            'confidence_score' => 0.6000,
            'confidence_bucket' => 'medium',
            'shrinkage_weight' => 0.4000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'expected_goals_model_id' => $modelId,
            'bucket_key' => 'L01|shot_type_group=wrist|distance_group=point_or_high',
            'fallback_level' => 1,
            'bucket_dimensions' => json_encode(['shot_type_group' => 'wrist', 'distance_group' => 'point_or_high']),
            'attempts' => 100,
            'goals' => 4,
            'raw_goal_rate' => 0.040000,
            'smoothed_goal_probability' => 0.060000,
            'confidence_score' => 0.8000,
            'confidence_bucket' => 'high',
            'shrinkage_weight' => 0.2000,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $satModelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'model_run_id' => $run->id,
        'name' => 'nhl_expected_sog_v1',
        'version' => 'sat_v1',
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'feature_config' => json_encode([
            'sample_mode' => 'sat',
            'workflow_action' => 'eval_sat',
            'sat_factor_evaluation' => [
                'baseline_sat' => 260,
                'baseline_sog' => 180,
                'baseline_rate' => 0.692308,
                'candidate_method' => 'weighted_absolute_lift_on_training_sat',
                'winner' => [
                    'label' => 'Distance + Angle',
                    'score' => 0.0525,
                ],
                'singles' => [
                    ['label' => 'Distance', 'rows' => 3, 'sat' => 260, 'score' => 0.041],
                ],
                'doubles' => [
                    ['label' => 'Distance + Angle', 'rows' => 8, 'sat' => 260, 'score' => 0.0525],
                ],
            ],
        ]),
        'metrics' => json_encode([
            'bucket_count' => 2,
            'training_attempts' => 1,
            'training_total_sat' => 2,
            'training_excluded_sat' => 1,
            'training_excluded_sat_rate' => 0.5,
        ]),
        'status' => 'queued',
        'trained_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_expected_goals_model_buckets')->insert([
        [
            'expected_goals_model_id' => $satModelId,
            'bucket_key' => 'L01|distance_group=slot|angle_group=central',
            'fallback_level' => 1,
            'bucket_dimensions' => json_encode(['distance_group' => 'slot', 'angle_group' => 'central']),
            'attempts' => 120,
            'goals' => 90,
            'raw_goal_rate' => 0.750000,
            'smoothed_goal_probability' => 0.720000,
            'confidence_score' => 0.6154,
            'confidence_bucket' => 'medium',
            'shrinkage_weight' => 0.3846,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('nhl_expected_goals_models')->insert([
        'model_run_id' => $run->id,
        'name' => 'nhl_expected_sog_v1',
        'version' => 'sat_v1__run_' . $run->id,
        'model_type' => 'bucket_smoothed',
        'prediction_target' => 'shot_on_goal',
        'training_season_id' => '20242025',
        'minimum_bucket_attempts' => 0,
        'smoothing_prior_attempts' => 75,
        'feature_config' => json_encode([
            'sample_mode' => 'sat',
            'workflow_action' => 'eval_sat',
        ]),
        'metrics' => json_encode(['queued_at' => now()->toIso8601String()]),
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.buckets', $run))
        ->assertOk()
        ->assertSee('SAT buckets sortable model')
        ->assertSee('SOG Danger')
        ->assertSee('Excluded')
        ->assertSee('SOG Interpretation')
        ->assertSee('Distance + Angle')
        ->assertSee('50.0%')
        ->assertSee('1 of 2')
        ->assertSee('Smoothed %')
        ->assertSee('Confidence')
        ->assertSee('Shrinkage')
        ->assertSee('sort=attempts', false)
        ->assertSeeInOrder([
            'L01|shot_type_group=tip|distance_group=slot',
            'L01|shot_type_group=wrist|distance_group=point_or_high',
        ]);

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.buckets', [
            'run' => $run,
            'sort' => 'attempts',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertSeeInOrder([
            'L01|shot_type_group=wrist|distance_group=point_or_high',
            'L01|shot_type_group=tip|distance_group=slot',
        ]);

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.index'))
        ->assertOk()
        ->assertSee('Excluded')
        ->assertSee('View SAT')
        ->assertSee('Eval SAT');

    $this->actingAs($admin)
        ->get(route('admin.nhl-sat-models.buckets', [
            'run' => $run,
            'target' => 'shot_on_goal',
        ]))
        ->assertOk()
        ->assertSee('SAT Danger')
        ->assertSee('SAT Interpretation')
        ->assertDontSee('Queued SAT-to-SOG model')
        ->assertSee('Training SAT')
        ->assertSee('Baseline SOG %')
        ->assertSee('260')
        ->assertSee('180 SOG')
        ->assertSee('SAT')
        ->assertSee('SOG')
        ->assertSee('72.00%');
});

it('renders sortable NHL shot attempt aggregate columns and rebound groupings', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'aggregates',
            'group_by' => 'is_rebound',
            'sort' => 'goals',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertSee('value="is_rebound"', false)
        ->assertSee('value="previous_event_type"', false)
        ->assertSee('sort=goals', false)
        ->assertSee('direction=desc', false)
        ->assertSee('↑');
});

it('displays goalie names in NHL shot attempt aggregate groupings', function () {
    ($this->makePlayer)([
        'nhl_id' => 8470001,
        'first_name' => 'Test',
        'last_name' => 'Goalie',
        'full_name' => 'Test Goalie',
        'position' => 'G',
    ]);
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020002,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-04-08',
        'game_dow' => 'Wed',
        'game_month' => 'Apr',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        'id' => 2,
        'nhl_game_id' => 2025020002,
        'event_owner_team_id' => 10,
        'period' => 1,
        'seconds_in_game' => 120,
        'type_desc_key' => 'shot-on-goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        'play_by_play_id' => 2,
        'nhl_game_id' => 2025020002,
        'season_id' => '20252026',
        'game_date' => '2026-04-08',
        'attempt_result' => 'shot-on-goal',
        'is_shot_attempt' => true,
        'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true,
        'is_goal' => false,
        'team_id' => 10,
        'goalie_player_id' => 8470001,
        'shot_type_bucket' => 'wrist',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'aggregates',
            'group_by' => 'goalie_player_id',
        ]))
        ->assertOk()
        ->assertSee('Test Goalie')
        ->assertDontSee('8470001');
});

it('displays shooter names in NHL shot attempt aggregate groupings', function () {
    ($this->makePlayer)([
        'nhl_id' => 8470002,
        'first_name' => 'Test',
        'last_name' => 'Shooter',
        'full_name' => 'Test Shooter',
        'position' => 'C',
    ]);
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020003,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-04-09',
        'game_dow' => 'Thu',
        'game_month' => 'Apr',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        'id' => 3,
        'nhl_game_id' => 2025020003,
        'event_owner_team_id' => 10,
        'period' => 1,
        'seconds_in_game' => 120,
        'type_desc_key' => 'shot-on-goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        'play_by_play_id' => 3,
        'nhl_game_id' => 2025020003,
        'season_id' => '20252026',
        'game_date' => '2026-04-09',
        'attempt_result' => 'shot-on-goal',
        'is_shot_attempt' => true,
        'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true,
        'is_goal' => false,
        'team_id' => 10,
        'shooter_player_id' => 8470002,
        'shot_type_bucket' => 'wrist',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'aggregates',
            'group_by' => 'shooter_player_id',
        ]))
        ->assertOk()
        ->assertSee('Test Shooter')
        ->assertDontSee('8470002');
});

it('renders NHL shot attempt biometric shot-context cuts', function () {
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020004,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-04-10',
        'game_dow' => 'Fri',
        'game_month' => 'Apr',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 20,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('play_by_plays')->insert([
        'id' => 4,
        'nhl_game_id' => 2025020004,
        'event_owner_team_id' => 10,
        'period' => 1,
        'seconds_in_game' => 120,
        'type_desc_key' => 'shot-on-goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_shot_attempts_facts')->insert([
        'play_by_play_id' => 4,
        'nhl_game_id' => 2025020004,
        'season_id' => '20252026',
        'game_date' => '2026-04-10',
        'attempt_result' => 'shot-on-goal',
        'is_shot_attempt' => true,
        'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true,
        'is_goal' => false,
        'team_id' => 10,
        'shot_distance' => 18.5,
        'abs_shot_angle' => 22.0,
        'distance_bucket' => 'slot',
        'angle_bucket' => 'medium',
        'shot_type_bucket' => 'wrist',
        'shooter_age_years' => 24.5,
        'shooter_height_inches' => 74,
        'shooter_weight_lbs' => 206,
        'goalie_age_years' => 27.5,
        'goalie_height_inches' => 76,
        'goalie_weight_lbs' => 210,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.nhl-shot-attempts.index', [
            'tab' => 'biometrics',
            'biometric_min_attempts' => 1,
            'sort' => 'profile',
        ]))
        ->assertOk()
        ->assertSee('Min Attempts')
        ->assertSee('name="biometric_min_attempts"', false)
        ->assertSee('Height + Shot Context')
        ->assertSee('Weight + Shot Context')
        ->assertSee('Shooter Height + Weight')
        ->assertSee('Shot / Weight')
        ->assertSee('wrist')
        ->assertSee('slot')
        ->assertSee('medium')
        ->assertSee('195-209');
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

it('blocks guests from queuing NHL shot fact processing', function () {
    $this->postJson(route('admin.nhl-game-imports.process-shots'), [
        'run_id' => 1,
    ])->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing NHL shot fact processing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.process-shots'), [
            'run_id' => 1,
        ])
        ->assertForbidden();
});

it('blocks guests from queuing failed-only NHL game import reruns', function () {
    $this->postJson(route('admin.nhl-game-imports.rerun-failed'), [
        'run_id' => 1,
    ])->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing failed-only NHL game import reruns', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.rerun-failed'), [
            'run_id' => 1,
        ])
        ->assertForbidden();
});

it('blocks guests from queuing duplicate PBP repair scans', function () {
    $this->postJson(route('admin.nhl-game-imports.duplicate-pbp.scan'))
        ->assertUnauthorized();
});

it('blocks authenticated non-admin users from queuing duplicate PBP repair scans', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-game-imports.duplicate-pbp.scan'))
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

it('blocks guests from removing NHL game import runs', function () {
    $run = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    $this->deleteJson(route('admin.nhl-game-imports.destroy', $run))
        ->assertUnauthorized();
});

it('blocks authenticated non-admin users from removing NHL game import runs', function () {
    $run = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('admin.nhl-game-imports.destroy', $run))
        ->assertForbidden();
});

it('allows super admins to remove terminal NHL game import runs and their run progress rows', function () {
    Event::fake([NhlGameImportStatusUpdated::class]);

    $run = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    DB::table('nhl_import_progress')->insert([
        'run_id' => $run->id,
        'season_id' => '20252026',
        'game_date' => '2026-01-15',
        'game_id' => '2025020001',
        'game_type' => 2,
        'import_type' => NhlImportStages::PBP,
        'items_count' => 0,
        'status' => 'completed',
        'discovered_at' => '2026-01-15 12:00:00',
        'created_at' => '2026-01-15 12:00:00',
        'updated_at' => '2026-01-15 12:00:00',
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->deleteJson(route('admin.nhl-game-imports.destroy', $run))
        ->assertOk()
        ->assertJsonPath('deleted_run_id', $run->id)
        ->assertJsonPath('deleted_progress_rows', 1);

    expect(NhlGameImportRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(DB::table('nhl_import_progress')->where('run_id', $run->id)->count())->toBe(0);

    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event): bool {
        return $event->reason === 'run-removed';
    });
});

it('prevents removing active NHL game import runs', function () {
    $run = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->deleteJson(route('admin.nhl-game-imports.destroy', $run))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_id');

    expect(NhlGameImportRun::query()->whereKey($run->id)->exists())->toBeTrue();
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

    $run = NhlGameImportRun::query()->firstOrFail();

    expect($run->action)->toBe(NhlGameImportRun::ACTION_DISCOVER)
        ->and($run->mode)->toBe(NhlGameImportRun::MODE_DATE)
        ->and($run->start_date->toDateString())->toBe('2026-01-15')
        ->and($run->end_date->toDateString())->toBe('2026-01-15')
        ->and($run->date_count)->toBe(1)
        ->and($run->queued_jobs)->toBe(1);

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

it('allows super admins to rerun discovery for a previous NHL game import run range', function () {
    Bus::fake();

    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_PROCESS,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 3,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('run.mode', NhlGameImportRun::MODE_RANGE)
        ->assertJsonPath('run.start_date', '2026-01-17')
        ->assertJsonPath('run.end_date', '2026-01-15')
        ->assertJsonPath('run.date_count', 3)
        ->assertJsonPath('run.payload.rerun_from_run_id', $sourceRun->id);

    $run = $sourceRun->refresh();

    expect(NhlGameImportRun::query()->count())->toBe(1)
        ->and($run->action)->toBe(NhlGameImportRun::ACTION_DISCOVER)
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_QUEUED)
        ->and($run->payload['rerun_from_run_id'])->toBe($sourceRun->id);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job) use ($run): bool {
        return $job->start->toDateString() === '2026-01-17'
            && $job->end->toDateString() === '2026-01-15'
            && $job->runId === $run->id;
    });
});

it('reruns discovery from the clicked active same-range NHL game import run', function () {
    Bus::fake();

    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 365,
        'queued_jobs' => 365,
        'payload' => ['season' => '20252026'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Discovery queued.')
        ->assertJsonPath('run.id', $sourceRun->id)
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_QUEUED)
        ->assertJsonPath('run.payload.rerun_from_run_id', $sourceRun->id);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job) use ($sourceRun): bool {
        return $job->start->toDateString() === '2026-08-31'
            && $job->end->toDateString() === '2025-09-01'
            && $job->runId === $sourceRun->id;
    });
});

it('reruns discovery from the clicked NHL game import run when another same-range run is active', function () {
    Bus::fake();

    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 365,
        'queued_jobs' => 365,
        'payload' => ['season' => '20252026'],
    ]);
    $otherActiveRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 365,
        'queued_jobs' => 365,
        'payload' => ['season' => '20252026'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Discovery queued.')
        ->assertJsonPath('run.id', $sourceRun->id)
        ->assertJsonPath('run.payload.rerun_from_run_id', $sourceRun->id);

    expect($otherActiveRun->refresh()->status)->toBe(NhlGameImportRun::STATUS_RUNNING);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job) use ($sourceRun): bool {
        return $job->runId === $sourceRun->id;
    });
});

it('returns an existing completed same-range NHL game import run instead of creating duplicate discovery work', function () {
    Bus::fake();

    $existingRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'discovery_completed_dates' => ['2026-04-06'],
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'date' => '2026-04-06',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.id', $existingRun->id)
        ->assertJsonPath('message', 'An NHL game import run already exists for this range.');

    expect(NhlGameImportRun::query()->count())->toBe(1);
    Bus::assertNotDispatched(NhlDiscoveryJob::class);
});

it('returns an active same-range NHL game import run instead of creating duplicate discovery work', function () {
    Bus::fake();

    $activeRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'processing_started_at' => '2026-07-27T12:00:00+00:00',
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'date' => '2026-04-06',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.id', $activeRun->id)
        ->assertJsonPath('message', 'An NHL game import run is already active for this range.');

    expect(NhlGameImportRun::query()->count())->toBe(1);
    Bus::assertNotDispatched(NhlDiscoveryJob::class);
});

it('returns an active same-range NHL game import run instead of processing a stale discovery duplicate', function () {
    Bus::fake();

    $activeRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'processing_started_at' => '2026-07-27T12:00:00+00:00',
        ],
    ]);
    $staleDiscoveryRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'discovery_completed_dates' => ['2026-04-06'],
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process'), [
            'run_id' => $staleDiscoveryRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.id', $activeRun->id)
        ->assertJsonPath('message', 'An NHL game import run is already active for this range.');

    expect(NhlGameImportRun::query()->count())->toBe(2);
    Bus::assertNotDispatched(NhlOrchestratorJob::class);
});

it('allows super admins to queue shot fact processing for a game import run range', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);
    $now = now();
    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 1,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    foreach ([
        ['game_id' => 2025020001, 'date' => '2026-01-15'],
        ['game_id' => 2025020002, 'date' => '2026-01-16'],
    ] as $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game['game_id'],
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $game['date'],
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('nhl_import_progress')->insert([
            'run_id' => $sourceRun->id,
            'season_id' => '20252026',
            'game_date' => $game['date'],
            'game_id' => (string) $game['game_id'],
            'game_type' => 2,
            'import_type' => NhlImportStages::PBP,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2025020001,
            'period' => 1,
            'seconds_in_game' => 120,
            'type_desc_key' => 'shot-on-goal',
            'shot_type' => 'wrist',
            'period_type' => 'REG',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'nhl_game_id' => 2025020002,
            'period' => 1,
            'seconds_in_game' => 180,
            'type_desc_key' => 'goal',
            'shot_type' => null,
            'period_type' => 'REG',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process-shots'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_PROCESS)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_RUNNING)
        ->assertJsonPath('run.processing_started', true)
        ->assertJsonPath('run.payload.process_scope', 'shots')
        ->assertJsonPath('run.payload.shot_fact_source_run_id', $sourceRun->id)
        ->assertJsonPath('run.payload.shot_fact_game_count', 2)
        ->assertJsonPath('run.payload.shot_fact_candidate_game_count', 2)
        ->assertJsonPath('run.payload.shot_fact_processable_game_count', 1)
        ->assertJsonPath('run.payload.shot_fact_unprocessable_game_count', 1)
        ->assertJsonPath('run.payload.shot_fact_processed_game_count', 0);

    $run = NhlGameImportRun::query()
        ->where('id', '!=', $sourceRun->id)
        ->firstOrFail();

    Bus::assertBatched(function ($batch) use ($run): bool {
        $jobs = collect($batch->jobs)
            ->filter(fn ($job): bool => $job instanceof BuildNhlShotAttemptFactsJob)
            ->values();

        return $batch->name === 'NHL:ShotAttemptFacts:' . $run->id
            && $jobs->count() === 2
            && $jobs->pluck('runId')->unique()->values()->all() === [$run->id]
            && $jobs->pluck('nhlGameId')->sort()->values()->all() === [2025020001, 2025020002];
    });
    expect(NhlGameImportRun::query()->count())->toBe(2)
        ->and($sourceRun->refresh()->status)->toBe(NhlGameImportRun::STATUS_COMPLETED)
        ->and($sourceRun->payload)->toBe(['start' => '2026-01-17', 'end' => '2026-01-15'])
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_RUNNING)
        ->and($run->action)->toBe(NhlGameImportRun::ACTION_PROCESS)
        ->and($run->queued_jobs)->toBe(2)
        ->and($run->payload['process_scope'])->toBe('shots')
        ->and($run->payload['shot_fact_source_run_id'])->toBe($sourceRun->id)
        ->and($run->payload['shot_fact_game_count'])->toBe(2)
        ->and($run->payload['shot_fact_game_ids'])->toBe([2025020001, 2025020002])
        ->and($run->payload['shot_fact_processable_game_count'])->toBe(1)
        ->and($run->payload['shot_fact_unprocessable_game_count'])->toBe(1)
        ->and($run->payload['processing_started_at'])->not->toBeNull();
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'shot-facts-queued' && $event->runId === $run->id;
    });
});

it('queues faceoff fact jobs for games in a discovered run without starting full imports', function (): void {
    Bus::fake();
    Event::fake();
    $now = Carbon::parse('2026-07-31 12:00:00');
    Carbon::setTestNow($now);

    $sourceRun = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 3,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    foreach ([
        ['game_id' => 2025020001, 'date' => '2026-01-15'],
        ['game_id' => 2025020002, 'date' => '2026-01-16'],
    ] as $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game['game_id'],
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $game['date'],
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('nhl_import_progress')->insert([
            'run_id' => $sourceRun->id,
            'season_id' => '20252026',
            'game_date' => $game['date'],
            'game_id' => (string) $game['game_id'],
            'game_type' => 2,
            'import_type' => NhlImportStages::PBP,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process-faceoffs'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_PROCESS)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_RUNNING)
        ->assertJsonPath('run.processing_started', true)
        ->assertJsonPath('run.payload.process_scope', 'faceoffs')
        ->assertJsonPath('run.payload.faceoff_fact_source_run_id', $sourceRun->id)
        ->assertJsonPath('run.payload.faceoff_fact_game_count', 2);

    $run = NhlGameImportRun::query()
        ->where('id', '!=', $sourceRun->id)
        ->firstOrFail();

    Bus::assertBatched(function ($batch) use ($run): bool {
        $jobs = collect($batch->jobs)
            ->filter(fn ($job): bool => $job instanceof BuildNhlFaceoffFactsJob)
            ->values();

        return $batch->name === 'NHL:FaceoffFacts:' . $run->id
            && $jobs->count() === 2
            && $jobs->pluck('runId')->unique()->values()->all() === [$run->id]
            && $jobs->pluck('nhlGameId')->sort()->values()->all() === [2025020001, 2025020002];
    });

    expect(NhlGameImportRun::query()->count())->toBe(2)
        ->and($sourceRun->refresh()->status)->toBe(NhlGameImportRun::STATUS_COMPLETED)
        ->and($sourceRun->payload)->toBe(['start' => '2026-01-17', 'end' => '2026-01-15'])
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_RUNNING)
        ->and($run->action)->toBe(NhlGameImportRun::ACTION_PROCESS)
        ->and($run->queued_jobs)->toBe(2)
        ->and($run->payload['process_scope'])->toBe('faceoffs')
        ->and($run->payload['faceoff_fact_source_run_id'])->toBe($sourceRun->id)
        ->and($run->payload['faceoff_fact_game_count'])->toBe(2)
        ->and($run->payload['faceoff_fact_game_ids'])->toBe([2025020001, 2025020002])
        ->and($run->payload['processing_started_at'])->not->toBeNull();
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'faceoff-facts-queued' && $event->runId === $run->id;
    });
});

it('rejects shot fact processing when a game import run has no games', function () {
    Bus::fake();
    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process-shots'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_id');

    expect(NhlGameImportRun::query()->count())->toBe(1);
});

it('does not let NHL process supervisor claim shot-scoped runs', function () {
    Queue::fake([NhlOrchestratorJob::class]);
    Event::fake([NhlGameImportStatusUpdated::class]);
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-01-15',
            'process_scope' => 'shots',
            'processing_started_at' => '2026-07-30T12:00:00+00:00',
        ],
    ]);

    DB::table('nhl_import_progress')->insert([
        'run_id' => $run->id,
        'season_id' => '20252026',
        'game_date' => '2026-01-15',
        'game_id' => '2025020001',
        'game_type' => 2,
        'import_type' => NhlImportStages::PBP,
        'status' => 'scheduled',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('nhl:process')
        ->expectsOutput('No discovery run with scheduled work.')
        ->assertExitCode(0);

    Queue::assertNotPushed(NhlOrchestratorJob::class);
    Event::assertNotDispatched(NhlGameImportStatusUpdated::class);
    expect(DB::table('nhl_import_progress')->where('run_id', $run->id)->value('status'))->toBe('scheduled');
});

it('marks a game import run failed when a shot fact job fails', function (): void {
    Event::fake();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-01-15',
            'process_scope' => 'shots',
            'shot_fact_game_count' => 1,
        ],
    ]);

    (new BuildNhlShotAttemptFactsJob(2025020001, $run->id))->failed(new \RuntimeException('shot facts exploded'));

    $run->refresh();

    expect($run->status)->toBe(NhlGameImportRun::STATUS_FAILED)
        ->and($run->last_error)->toBe('shot facts exploded')
        ->and($run->payload['shot_fact_last_error'])->toBe('shot facts exploded')
        ->and($run->payload['shot_fact_failed_game_ids'])->toBe([2025020001])
        ->and($run->payload['shot_fact_failed_at'])->not->toBeNull();

    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'shot-facts-job-failed'
            && $event->runId === $run->id
            && $event->gameId === 2025020001;
    });
});

it('creates scoped API client tokens for server integrations', function (): void {
    $this->artisan('api-client:create', [
        'name' => 'gner8',
        '--scope' => ['nhl-reference:read'],
    ])->assertExitCode(0);

    $client = ApiClient::query()->first();

    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('gner8')
        ->and($client->slug)->toBe('gner8')
        ->and(str_starts_with((string) $client->token_prefix, 'diq_gner8_'))->toBeTrue()
        ->and($client->token_hash)->toHaveLength(64)
        ->and($client->scopes)->toBe(['nhl-reference:read'])
        ->and($client->last_used_at)->toBeNull()
        ->and($client->revoked_at)->toBeNull();
});

it('requires a scoped API client token for NHL reference endpoints', function (): void {
    $token = 'diq_gner8_testing-token';
    ApiClient::create([
        'name' => 'Gner8',
        'slug' => 'gner8',
        'token_prefix' => substr($token, 0, 24),
        'token_hash' => ApiClient::hashToken($token),
        'scopes' => ['other:read'],
    ]);

    $this->getJson('/api/nhl-teams')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'API client token is required.');

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-teams')
        ->assertForbidden()
        ->assertJsonPath('message', 'API client token is invalid for this scope.');
});

it('returns NHL teams for scoped API clients without consumer league ids', function (): void {
    $token = 'diq_gner8_testing-token';
    $client = ApiClient::create([
        'name' => 'Gner8',
        'slug' => 'gner8',
        'token_prefix' => substr($token, 0, 24),
        'token_hash' => ApiClient::hashToken($token),
        'scopes' => ['nhl-reference:read'],
    ]);
    DB::table('nhl_teams')->insert([
        'nhl_id' => 14,
        'abbrev' => 'TBL',
        'full_name' => 'Tampa Bay Lightning',
        'common_name' => 'Lightning',
        'place_name' => 'Tampa Bay',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-teams')
        ->assertOk()
        ->assertJsonMissingPath('data.0.league_id')
        ->assertJsonPath('data.0.external_id', 14)
        ->assertJsonPath('data.0.slug', 'tampa-bay-lightning')
        ->assertJsonPath('data.0.name', 'Tampa Bay Lightning')
        ->assertJsonPath('data.0.abbreviation', 'TBL')
        ->assertJsonPath('data.0.city', 'Tampa Bay')
        ->assertJsonPath('data.0.active', true)
        ->assertJsonPath('data.0.metadata.nhl_id', 14)
        ->assertJsonPath('data.0.metadata.common_name', 'Lightning');

    expect($client->refresh()->last_used_at)->not->toBeNull();
});

it('returns paginated NHL players for scoped API clients with head shot urls', function (): void {
    $this->travelTo(Carbon::parse('2026-07-28 12:00:00'));

    $token = 'diq_gner8_testing-token';
    ApiClient::create([
        'name' => 'Gner8',
        'slug' => 'gner8',
        'token_prefix' => substr($token, 0, 24),
        'token_hash' => ApiClient::hashToken($token),
        'scopes' => ['nhl-reference:read'],
    ]);
    DB::table('nhl_teams')->insert([
        'nhl_id' => 22,
        'abbrev' => 'EDM',
        'full_name' => 'Edmonton Oilers',
        'common_name' => 'Oilers',
        'place_name' => 'Edmonton',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $playerId = DB::table('players')->insertGetId([
        'nhl_id' => 8478402,
        'nhl_team_id' => 22,
        'full_name' => 'Connor McDavid',
        'first_name' => 'Connor',
        'last_name' => 'McDavid',
        'dob' => '1997-01-13',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'EDM',
        'current_league_abbrev' => 'NHL',
        'shoots' => 'L',
        'head_shot_url' => 'https://assets.example/mcdavid.png',
        'status' => 'active',
        'is_goalie' => false,
        'is_prospect' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $contract = Contract::create([
        'player_id' => $playerId,
        'contract_type' => 'Standard',
        'contract_length' => '8 years',
        'contract_value' => 100000000,
        'expiry_status' => 'UFA',
        'signing_team' => 'EDM',
        'signing_date' => '2026-07-01',
        'signed_by' => 'Edmonton Oilers',
    ]);
    $contract->seasons()->create([
        'season_key' => 20262027,
        'label' => '2026-27',
        'cap_hit' => 12500000,
        'aav' => 12500000,
        'base_salary' => 12500000,
    ]);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-players?per_page=1')
        ->assertOk()
        ->assertJsonMissingPath('data.0.league_id')
        ->assertJsonPath('data.0.external_id', 8478402)
        ->assertJsonPath('data.0.current_team_external_id', 22)
        ->assertJsonPath('data.0.slug', 'connor-mcdavid-8478402')
        ->assertJsonPath('data.0.full_name', 'Connor McDavid')
        ->assertJsonPath('data.0.position', 'C')
        ->assertJsonPath('data.0.shoots_or_catches', 'L')
        ->assertJsonPath('data.0.birth_date', '1997-01-13')
        ->assertJsonPath('data.0.head_shot_url', 'https://assets.example/mcdavid.png')
        ->assertJsonPath('data.0.current_cap_hit', 12500000)
        ->assertJsonPath('data.0.current_cap_hit_season_key', 20262027)
        ->assertJsonPath('data.0.active', true)
        ->assertJsonPath('data.0.metadata.current_team_abbreviation', 'EDM')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1);
});

it('returns completed shot fact processing runs without processable game rows', function () {
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-04-07',
        'end_date' => '2026-04-07',
        'date_count' => 1,
        'queued_jobs' => 11,
        'payload' => [
            'date' => '2026-04-07',
            'process_scope' => 'shots',
            'processing_started_at' => '2026-07-27T12:00:00+00:00',
            'shot_fact_game_count' => 11,
            'shot_fact_completed_at' => '2026-07-27T12:01:00+00:00',
        ],
    ]);

    $payload = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->json('runs.0');

    expect($payload['id'])->toBe($run->id)
        ->and($payload['status'])->toBe(NhlGameImportRun::STATUS_COMPLETED)
        ->and($payload['processing_started'])->toBeTrue()
        ->and($payload['progress']['completed_stage_rows'])->toBe(11)
        ->and($payload['facts'])->toBe([])
        ->and($payload['games'])->toBe([]);
});

it('allows super admins to rerun only failed NHL game imports and actionable validations', function () {
    Event::fake([NhlGameImportStatusUpdated::class]);
    $now = now();
    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_PROCESS,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_FAILED,
        'start_date' => '2026-01-17',
        'end_date' => '2026-01-15',
        'date_count' => 3,
        'queued_jobs' => 3,
        'payload' => ['start' => '2026-01-17', 'end' => '2026-01-15'],
    ]);

    foreach ([
        ['game_id' => 2025020001, 'date' => '2026-01-15'],
        ['game_id' => 2025020002, 'date' => '2026-01-16'],
        ['game_id' => 2025020003, 'date' => '2026-01-17'],
        ['game_id' => 2025020004, 'date' => '2026-01-17'],
    ] as $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game['game_id'],
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $game['date'],
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    foreach ([
        ['game_id' => 2025020001, 'status' => 'error'],
        ['game_id' => 2025020002, 'status' => 'completed'],
        ['game_id' => 2025020003, 'status' => 'completed'],
        ['game_id' => 2025020004, 'status' => 'completed'],
    ] as $row) {
        DB::table('nhl_import_progress')->insert([
            'run_id' => $sourceRun->id,
            'season_id' => '20252026',
            'game_date' => DB::table('nhl_games')->where('nhl_game_id', $row['game_id'])->value('game_date'),
            'game_id' => (string) $row['game_id'],
            'game_type' => 2,
            'import_type' => NhlImportStages::VALIDATE_SUMMARY,
            'items_count' => $row['status'] === 'completed' ? 1 : 0,
            'status' => $row['status'],
            'last_error' => $row['status'] === 'error' ? 'validation failed' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    NhlGameValidation::create([
        'nhl_game_id' => 2025020002,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_INVALIDATED,
        'mismatch_count' => 1,
    ]);
    NhlGameValidation::create([
        'nhl_game_id' => 2025020003,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_SHIFTCHART_MISMATCH,
        'mismatch_count' => 1,
    ]);
    NhlGameValidation::create([
        'nhl_game_id' => 2025020004,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_APPROVED,
        'mismatch_count' => 0,
    ]);

    $orchestrator = Mockery::mock(NhlImportOrchestrator::class);
    $orchestrator->shouldReceive('fillActiveGameSlotsForRun')
        ->once()
        ->with(Mockery::type('int'));
    app()->instance(NhlImportOrchestrator::class, $orchestrator);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.rerun-failed'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_PROCESS)
        ->assertJsonPath('run.payload.rerun_scope', 'failed_only')
        ->assertJsonPath('run.payload.rerun_from_run_id', $sourceRun->id)
        ->assertJsonPath('run.payload.failed_game_count', 2)
        ->assertJsonPath('run.payload.reprocess_existing', true);

    $rerun = NhlGameImportRun::query()
        ->where('id', '!=', $sourceRun->id)
        ->firstOrFail();

    expect(DB::table('nhl_import_progress')->where('run_id', $rerun->id)->pluck('game_id')->all())
        ->toEqualCanonicalizing(['2025020001', '2025020002'])
        ->and(DB::table('nhl_import_progress')->where('run_id', $rerun->id)->where('status', 'scheduled')->count())
        ->toBe(2)
        ->and(DB::table('nhl_import_progress')->where('game_id', '2025020003')->value('run_id'))
        ->toBe($sourceRun->id)
        ->and(DB::table('nhl_import_progress')->where('game_id', '2025020004')->value('run_id'))
        ->toBe($sourceRun->id);

    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($rerun): bool {
        return $event->reason === 'processing-queued' && $event->runId === $rerun->id;
    });
});

it('rejects failed-only NHL game import reruns when there are no failed candidates', function () {
    Event::fake([NhlGameImportStatusUpdated::class]);
    $now = now();
    $sourceRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_PROCESS,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => ['date' => '2026-01-15'],
    ]);

    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020005,
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
    DB::table('nhl_import_progress')->insert([
        'run_id' => $sourceRun->id,
        'season_id' => '20252026',
        'game_date' => '2026-01-15',
        'game_id' => '2025020005',
        'game_type' => 2,
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'items_count' => 1,
        'status' => 'completed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    NhlGameValidation::create([
        'nhl_game_id' => 2025020005,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_APPROVED,
        'mismatch_count' => 0,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.rerun-failed'), [
            'run_id' => $sourceRun->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_id');

    expect(NhlGameImportRun::query()->count())->toBe(1);
    Event::assertNotDispatched(NhlGameImportStatusUpdated::class);
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

it('allows super admins to queue duplicate PBP scan runs', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.duplicate-pbp.scan'))
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_REPAIR)
        ->assertJsonPath('run.payload.repair', 'duplicate_pbp')
        ->assertJsonPath('run.payload.repair_stage', 'scanning');

    $run = NhlGameImportRun::query()->firstOrFail();

    Bus::assertDispatched(ScanDuplicateNhlPlayByPlayRepairJob::class, function (ScanDuplicateNhlPlayByPlayRepairJob $job) use ($run): bool {
        return $job->runId === $run->id;
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'duplicate-pbp-scan-queued' && $event->runId === $run->id;
    });
});

it('allows super admins to queue duplicate PBP dedupe from a ready repair run', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_REPAIR,
        'mode' => NhlGameImportRun::MODE_DEFAULT,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-07-28',
        'end_date' => '2026-07-28',
        'date_count' => 0,
        'queued_jobs' => 1,
        'payload' => [
            'repair' => 'duplicate_pbp',
            'repair_stage' => 'ready',
            'repair_game_count' => 3,
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.duplicate-pbp.dedupe', $run))
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_REPAIR)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_QUEUED)
        ->assertJsonPath('run.payload.repair_stage', 'queued');

    Bus::assertDispatched(DedupeNhlPlayByPlayRepairJob::class, function (DedupeNhlPlayByPlayRepairJob $job) use ($run): bool {
        return $job->runId === $run->id;
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'duplicate-pbp-dedupe-queued' && $event->runId === $run->id;
    });
});

it('does not queue duplicate PBP dedupe again after repair metadata exists', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_REPAIR,
        'mode' => NhlGameImportRun::MODE_DEFAULT,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-07-28',
        'end_date' => '2026-07-28',
        'date_count' => 0,
        'queued_jobs' => 1,
        'payload' => [
            'repair' => 'duplicate_pbp',
            'repair_stage' => 'ready',
            'repair_game_count' => 3,
            'dedupe_completed_at' => '2026-07-28T12:00:00-04:00',
            'queued_rebuild_game_count' => 3,
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.duplicate-pbp.dedupe', $run))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_id');

    Bus::assertNotDispatched(DedupeNhlPlayByPlayRepairJob::class);
    Event::assertNotDispatched(NhlGameImportStatusUpdated::class);
});

it('allows super admins to queue duplicate PBP affected-game rebuilds from a rebuild repair run', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_REPAIR,
        'mode' => NhlGameImportRun::MODE_DEFAULT,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-07-28',
        'end_date' => '2026-07-28',
        'date_count' => 1,
        'queued_jobs' => 0,
        'payload' => [
            'repair' => 'duplicate_pbp_rebuild',
            'repair_stage' => 'ready',
            'affected_game_count' => 2,
            'affected_game_ids' => [2025021200, 2025021201],
            'queued_rebuild_game_count' => 0,
        ],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.duplicate-pbp.rebuild', $run))
        ->assertAccepted()
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_REPAIR)
        ->assertJsonPath('run.status', NhlGameImportRun::STATUS_QUEUED)
        ->assertJsonPath('run.payload.repair', 'duplicate_pbp_rebuild')
        ->assertJsonPath('run.payload.repair_stage', 'queued');

    Bus::assertDispatched(QueueDuplicatePbpAffectedRebuildsJob::class, function (QueueDuplicatePbpAffectedRebuildsJob $job) use ($run): bool {
        return $job->runId === $run->id;
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'duplicate-pbp-rebuild-requested' && $event->runId === $run->id;
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

    expect($run->action)->toBe(NhlGameImportRun::ACTION_SEASON_SYNC)
        ->and($run->mode)->toBe(NhlGameImportRun::MODE_SEASON)
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_QUEUED)
        ->and($run->start_date->toDateString())->toBe('2026-08-31')
        ->and($run->end_date->toDateString())->toBe('2025-09-01')
        ->and($run->date_count)->toBe(1)
        ->and($run->queued_jobs)->toBe(1);

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
    $orchestrator = Mockery::mock(NhlImportOrchestrator::class);
    $orchestrator->shouldReceive('fillActiveGameSlotsForRun')
        ->once()
        ->with($run->id);
    app()->instance(NhlImportOrchestrator::class, $orchestrator);

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

    Bus::assertNotDispatched(NhlOrchestratorJob::class);
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event) use ($run): bool {
        return $event->reason === 'processing-queued' && $event->runId === $run->id;
    });
});

it('recreates missing run-scoped NHL import progress rows when processing a discovered run replay', function () {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);
    $now = now();
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

    foreach ([
        ['id' => 2025020001, 'date' => '2026-01-15'],
        ['id' => 2025020002, 'date' => '2026-01-16'],
    ] as $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game['id'],
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $game['date'],
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $orchestrator = Mockery::mock(NhlImportOrchestrator::class);
    $orchestrator->shouldReceive('fillActiveGameSlotsForRun')
        ->once()
        ->with($run->id);
    app()->instance(NhlImportOrchestrator::class, $orchestrator);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.process'), [
            'run_id' => $run->id,
            'reprocess_existing' => true,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.id', $run->id)
        ->assertJsonPath('run.action', NhlGameImportRun::ACTION_DISCOVER)
        ->assertJsonPath('run.processing_started', true);

    expect(DB::table('nhl_import_progress')->where('run_id', $run->id)->count())
        ->toBe(2 * count(NhlImportStages::ordered()));
    $this->assertDatabaseHas('nhl_import_progress', [
        'run_id' => $run->id,
        'game_id' => '2025020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'scheduled',
    ]);
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

it('allows large NHL discovery date ranges because processing is database driven', function () {
    Bus::fake();

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-game-imports.discover'), [
            'start' => '2026-06-01',
            'end' => '2026-01-01',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.start_date', '2026-06-01')
        ->assertJsonPath('run.end_date', '2026-01-01')
        ->assertJsonPath('run.date_count', 152);

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job): bool {
        return $job->start->toDateString() === '2026-06-01'
            && $job->end->toDateString() === '2026-01-01';
    });
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

    foreach ([
        ['id' => 2025020001, 'date' => '2026-01-15'],
        ['id' => 2025020002, 'date' => '2026-01-16'],
        ['id' => 2025020003, 'date' => '2026-01-17'],
    ] as $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game['id'],
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $game['date'],
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

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
        ->assertJsonPath('runs.0.games.0.game_id', 2025020002)
        ->assertJsonPath('runs.0.games.0.game_date', '2026-01-16')
        ->assertJsonPath('runs.0.games.0.away_team_abbrev', 'MTL')
        ->assertJsonPath('runs.0.games.0.home_team_abbrev', 'TOR')
        ->assertJsonPath('runs.0.games.0.total_stage_rows', 1)
        ->assertJsonPath('runs.0.games.0.running_stage_rows', 1)
        ->assertJsonPath('runs.0.games.0.percentage', 0)
        ->assertJsonPath('runs.0.games.1.game_id', 2025020003)
        ->assertJsonPath('runs.0.games.1.scheduled_stage_rows', 1)
        ->assertJsonPath('processable.date_count', 1);
});

it('does not show reassigned reprocess progress on older processed discovery runs', function () {
    $now = now();
    $oldRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2025-10-11',
        'end_date' => '2025-10-01',
        'date_count' => 11,
        'queued_jobs' => 11,
        'payload' => [
            'start' => '2025-10-11',
            'end' => '2025-10-01',
            'processing_started_at' => '2026-07-13T18:00:00+00:00',
        ],
        'created_at' => $now->copy()->subMinute(),
        'updated_at' => $now->copy()->subMinute(),
    ]);
    $reprocessRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2025-10-11',
        'end_date' => '2025-10-01',
        'date_count' => 11,
        'queued_jobs' => 11,
        'payload' => [
            'start' => '2025-10-11',
            'end' => '2025-10-01',
            'processing_started_at' => '2026-07-13T18:05:00+00:00',
            'reprocess_existing' => true,
        ],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([
        ['type' => NhlImportStages::PBP, 'status' => 'completed'],
        ['type' => NhlImportStages::SUMMARY, 'status' => 'running'],
        ['type' => NhlImportStages::BOXSCORE, 'status' => 'scheduled'],
    ] as $row) {
        DB::table('nhl_import_progress')->insert([
            'run_id' => $reprocessRun->id,
            'season_id' => '20252026',
            'game_date' => '2025-10-01',
            'game_id' => '2025020001',
            'game_type' => 2,
            'import_type' => $row['type'],
            'status' => $row['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $runs = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->json('runs');

    $reprocessPayload = collect($runs)->firstWhere('id', $reprocessRun->id);

    expect(collect($runs)->pluck('id')->contains($oldRun->id))->toBeFalse()
        ->and($reprocessPayload['status'])->toBe(NhlGameImportRun::STATUS_RUNNING)
        ->and($reprocessPayload['progress']['total_stage_rows'])->toBe(3)
        ->and($reprocessPayload['progress']['completed_stage_rows'])->toBe(1)
        ->and($reprocessPayload['progress']['running_stage_rows'])->toBe(1)
        ->and($reprocessPayload['progress']['scheduled_stage_rows'])->toBe(1);
});

it('hides stale ready discovery duplicates when a same-range game import run is active', function () {
    $activeRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'processing_started_at' => '2026-07-27T12:00:00+00:00',
        ],
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staleDiscoveryRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'discovery_completed_dates' => ['2026-04-06'],
        ],
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $runs = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonIsArray('runs')
        ->json('runs');

    expect(collect($runs)->pluck('id')->all())->toBe([$activeRun->id])
        ->and(collect($runs)->pluck('id')->contains($staleDiscoveryRun->id))->toBeFalse();
});

it('collapses completed duplicate same-range NHL game import runs in status payloads', function () {
    $now = now();
    $emptyRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'discovery_completed_dates' => ['2026-04-06'],
        ],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $progressRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'processing_started_at' => $now->copy()->subHour()->toIso8601String(),
        ],
        'created_at' => $now->copy()->subMinute(),
        'updated_at' => $now->copy()->subMinute(),
    ]);

    foreach ([2025021229, 2025021230, 2025021231, 2025021232] as $gameId) {
        foreach (NhlImportStages::ordered() as $stage) {
            DB::table('nhl_import_progress')->insert([
                'run_id' => $progressRun->id,
                'season_id' => '20252026',
                'game_date' => '2026-04-06',
                'game_id' => (string) $gameId,
                'game_type' => 2,
                'import_type' => $stage,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $runs = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonIsArray('runs')
        ->json('runs');

    expect(collect($runs)->pluck('id')->all())->toBe([$progressRun->id])
        ->and(collect($runs)->pluck('id')->contains($emptyRun->id))->toBeFalse()
        ->and($runs[0]['facts']['discovered_game_count'])->toBe(4);
});

it('prefers queued discovery rerun rows over completed same-range progress rows in status payloads', function () {
    $now = now();
    $processedRun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 365,
        'queued_jobs' => 365,
        'payload' => [
            'season' => '20252026',
            'processing_started_at' => $now->copy()->subDay()->toIso8601String(),
        ],
        'created_at' => $now->copy()->subDay(),
        'updated_at' => $now->copy()->subDay(),
    ]);
    $rerun = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_QUEUED,
        'start_date' => '2026-08-31',
        'end_date' => '2025-09-01',
        'date_count' => 365,
        'queued_jobs' => 365,
        'payload' => [
            'season' => '20252026',
            'rerun_from_run_id' => $processedRun->id,
        ],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([2025021229, 2025021230] as $gameId) {
        foreach (NhlImportStages::ordered() as $stage) {
            DB::table('nhl_import_progress')->insert([
                'run_id' => $processedRun->id,
                'season_id' => '20252026',
                'game_date' => '2026-04-06',
                'game_id' => (string) $gameId,
                'game_type' => 2,
                'import_type' => $stage,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $runs = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->assertJsonIsArray('runs')
        ->json('runs');

    expect(collect($runs)->pluck('id')->all())->toBe([$rerun->id])
        ->and($runs[0]['action'])->toBe(NhlGameImportRun::ACTION_DISCOVER)
        ->and($runs[0]['processing_started'])->toBeFalse()
        ->and($runs[0]['payload']['rerun_from_run_id'])->toBe($processedRun->id);
});

it('hides per-game rows for clean completed NHL game import runs', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-04-06',
            'processing_started_at' => $now->copy()->subHour()->toIso8601String(),
        ],
    ]);

    foreach ([2025021229, 2025021230, 2025021231, 2025021232] as $index => $gameId) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId,
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => '2026-04-06',
            'game_dow' => 'Mon',
            'game_month' => 'Apr',
            'home_team_id' => 1,
            'home_team_abbrev' => 'BUF',
            'away_team_id' => 2,
            'away_team_abbrev' => 'TBL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (NhlImportStages::ordered() as $stage) {
            DB::table('nhl_import_progress')->insert([
                'run_id' => $run->id,
                'season_id' => '20252026',
                'game_date' => '2026-04-06',
                'game_id' => (string) $gameId,
                'game_type' => 2,
                'import_type' => $stage,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now->copy()->addSeconds($index),
            ]);
        }
    }

    $payload = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->json('runs.0');

    expect($payload['status'])->toBe(NhlGameImportRun::STATUS_COMPLETED)
        ->and($payload['progress']['completed_stage_rows'])->toBe(36)
        ->and($payload['progress']['failed_stage_rows'])->toBe(0)
        ->and($payload['games'])->toBe([]);
});

it('hides stale successful game imports while keeping failed games visible', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_RANGE,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-01-16',
        'end_date' => '2026-01-15',
        'date_count' => 2,
        'queued_jobs' => 2,
        'payload' => [
            'start' => '2026-01-16',
            'end' => '2026-01-15',
            'processing_started_at' => $now->copy()->subDay()->toIso8601String(),
        ],
        'created_at' => $now->copy()->subDay(),
        'updated_at' => $now->copy()->subDay(),
    ]);

    foreach ([2025020001, 2025020002] as $index => $gameId) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId,
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => '2026-01-' . (15 + $index),
            'game_dow' => 'Thu',
            'game_month' => 'Jan',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subDay(),
        ]);
    }

    foreach (NhlImportStages::ordered() as $stage) {
        DB::table('nhl_import_progress')->insert([
            'run_id' => $run->id,
            'season_id' => '20252026',
            'game_date' => '2026-01-15',
            'game_id' => '2025020001',
            'game_type' => 2,
            'import_type' => $stage,
            'status' => 'completed',
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subMinute(),
        ]);
    }

    foreach (NhlImportStages::ordered() as $index => $stage) {
        DB::table('nhl_import_progress')->insert([
            'run_id' => $run->id,
            'season_id' => '20252026',
            'game_date' => '2026-01-16',
            'game_id' => '2025020002',
            'game_type' => 2,
            'import_type' => $stage,
            'status' => $index === 0 ? 'error' : 'completed',
            'last_error' => $index === 0 ? 'Validation failed.' : null,
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subMinute(),
        ]);
    }

    $payload = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-game-imports.status'))
        ->assertOk()
        ->json('runs.0');

    expect(collect($payload['games'])->pluck('game_id')->all())->toBe([2025020002])
        ->and($payload['games'][0]['failed_stage_rows'])->toBe(1)
        ->and($payload['games'][0]['last_error'])->toBe('Validation failed.');
});

it('does not complete discovered NHL game import runs until discovery finishes', function () {
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
        ->assertJsonPath('runs.0.status', NhlGameImportRun::STATUS_QUEUED)
        ->assertJsonPath('runs.0.facts.total_stage_rows', 1)
        ->assertJsonPath('runs.0.facts.scheduled_stage_rows', 1);
});

it('returns discovered NHL game import runs as completed after discovery finishes', function () {
    $now = now();
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-15',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [
            'date' => '2026-01-15',
            'discovery_completed_dates' => ['2026-01-15'],
            'completed_at' => $now->toIso8601String(),
        ],
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
        ->and($query['scope'] ?? null)->toBe('fspt-r')
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

    expect($result['players_count'])->toBe(1)
        ->and($result['resolved_count'])->toBe(1);
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
        ->and($query['scope'] ?? null)->toBe('fspt-r')
        ->and($query['state'] ?? '')->not->toBe('');
});

it('sends configured Yahoo OAuth scopes during authorization redirects', function () {
    config([
        'services.yahoo.client_id' => 'yahoo-client-id',
        'yahoo.oauth.authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
        'yahoo.oauth.scopes' => 'openid email fspt-w',
    ]);

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.yahoo.oauth.redirect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    $parts = parse_url((string) $location);
    parse_str($parts['query'] ?? '', $query);

    expect($query['scope'] ?? null)->toBe('openid email fspt-w');
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
        ->getJson(route('admin.yahoo.oauth.callback', [
            'state' => 'expected-state',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Yahoo authorization code is required.');
});

it('returns Yahoo OAuth provider errors before requiring an authorization code', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->withSession(['yahoo_oauth_state' => 'expected-state'])
        ->getJson(route('admin.yahoo.oauth.callback', [
            'state' => 'expected-state',
            'error' => 'invalid_request',
            'error_description' => 'The requested scope is invalid.',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Yahoo authorization failed: The requested scope is invalid.');
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

    expect(session('yahoo_oauth_probe_token.access_token'))->toBe('access-token-value');

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

it('marks Yahoo OAuth callbacks offline when Fantasy API access is forbidden', function () {
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
<error xml:lang="en-us">
  <description>Forbidden</description>
</error>
XML, 403),
    ]);

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->withSession(['yahoo_oauth_state' => 'expected-state'])
        ->getJson(route('admin.yahoo.oauth.callback', [
            'state' => 'expected-state',
            'code' => 'auth-code',
        ]));

    $response->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Yahoo Fantasy Sports authorization failed. Reconnect Yahoo and approve Fantasy Sports access.')
        ->assertJsonPath('connection.status', 'offline')
        ->assertJsonMissing(['description' => 'Forbidden']);

    $connection = YahooFantasyConnection::query()->firstOrFail();

    expect($connection->status)->toBe('offline')
        ->and($connection->last_error)->toBe('Yahoo Fantasy Sports authorization failed. Reconnect Yahoo and approve Fantasy Sports access.');
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
    DB::table('stats')->insert([
        'player_id' => $player->id,
        'is_prospect' => true,
        'nhl_team_id' => 21,
        'nhl_team_abbrev' => 'COL',
        'player_name' => 'Nathan MacKinnon',
        'season_id' => '20252026',
        'league_abbrev' => 'NHL',
        'team_name' => 'Colorado Avalanche',
        'game_type_id' => 2,
        'gp' => 82,
        'g' => 40,
        'a' => 60,
        'pts' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->artisan('nhl:empty', ['--players' => true])
        ->assertOk()
        ->expectsOutput('Clearing stats...')
        ->expectsOutput('stats: 1')
        ->expectsOutput('Clearing player_external_identities...')
        ->expectsOutput('player_external_identities: 2')
        ->expectsOutput('Removed NHL player stats and external identities.')
        ->expectsOutputToContain('Canonical players and NHL team reference data were not deleted.');

    expect(\App\Models\Player::query()->whereKey($player->id)->exists())->toBeTrue()
        ->and(DB::table('nhl_games')->where('nhl_game_id', 2025020001)->exists())->toBeTrue()
        ->and(DB::table('stats')->count())->toBe(0)
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
        ->expectsOutputToContain('Clearing event_unit_shifts...')
        ->expectsOutputToContain('event_unit_shifts: 1')
        ->expectsOutputToContain('Clearing nhl_game_validations...')
        ->expectsOutputToContain('nhl_game_validations: 1')
        ->expectsOutputToContain('Clearing nhl_import_progress...')
        ->expectsOutputToContain('nhl_import_progress: 1')
        ->expectsOutputToContain('Clearing nhl_game_import_runs...')
        ->expectsOutputToContain('nhl_game_import_runs: 1')
        ->expectsOutputToContain('Clearing nhl_game_source_statuses...')
        ->expectsOutputToContain('nhl_game_source_statuses: 1')
        ->expectsOutputToContain('nhl_games: 1')
        ->expectsOutputToContain('Removed NHL game import data.')
        ->expectsOutputToContain('Canonical players and NHL team reference data were not deleted.');

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
        ->assertJsonPath('inbox.identities.0.display_name', $identity->display_name);
});

it('returns lean embedded player triage json after the initial fragment load', function () {
    $identity = ($this->makeIdentity)();

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['admin_panel' => 1]))
        ->assertOk()
        ->assertJsonPath('inbox.identities.0.display_name', $identity->display_name);

    expect($response->json('html'))->toBeNull();
});

it('returns the embedded player triage fragment when explicitly requested', function () {
    $identity = ($this->makeIdentity)();

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage', ['admin_panel' => 1, 'fragment' => 1]))
        ->assertOk()
        ->assertJsonPath('inbox.identities.0.display_name', $identity->display_name);

    expect($response->json('html'))->toContain('data-player-triage-page');
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
        ->assertJsonPath('inbox.meta.count', 0);
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

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.player-triage'))
        ->assertOk();

    expect($response->json('html'))
        ->toContain('Fantrax')
        ->toContain('Nhl');
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
        ->assertJsonPath('detail.player.full_name', 'Coverage Detail Player')
        ->assertJsonPath('detail.suggested_external_matches.0.provider_player_id', 'fantrax-coverage-detail')
        ->assertJsonCount(0, 'detail.candidate_players');
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
        ->assertDontSee('>1991-03-04<', false)
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
        ->assertDontSee('>1987-09-18<', false)
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
