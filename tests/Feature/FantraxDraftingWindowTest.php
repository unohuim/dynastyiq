<?php

declare(strict_types=1);

use App\Models\IntegrationSecret;
use App\Models\DiscordServer;
use App\Events\DraftPickMade;
use App\Events\FantraxDraftPickToast;
use App\Jobs\SyncFantraxDraftStateJob;
use App\Listeners\AnnounceFantraxDraftPick;
use App\Models\Draft;
use App\Models\DraftNotificationSetting;
use App\Models\DraftPick;
use App\Models\DraftQueueItem;
use App\Models\FantraxPlayer;
use App\Models\League;
use App\Models\LeagueUserRole;
use App\Models\Organization;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\SocialAccount;
use App\Models\Stat;
use App\Models\User;
use App\Services\DraftPickCardRenderer;
use App\Services\FantraxDraftingWindow;
use App\Services\SyncFantraxDraftState;
use App\Services\SyncFantraxLeague;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->normalizer = new FantraxDraftingWindow();
    $this->communityLeagueSequence = 0;

    $this->normalizeDraft = function (
        array $leagueInfo = [],
        array $draftResults = [],
        ?Throwable $error = null,
        ?CarbonImmutable $now = null,
        array $playerNamesByFantraxId = [],
        array $teamMetaByFantraxId = [],
        array $draftPickInfo = []
    ): array {
        return $this->normalizer->normalize(
            $leagueInfo,
            $draftResults,
            $error,
            $now,
            $playerNamesByFantraxId,
            $teamMetaByFantraxId,
            $draftPickInfo
        );
    };

    $this->createCommunityLeague = function (array $overrides = []): array {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => $overrides['organization_name'] ?? 'Test Community',
            'short_name' => 'TC',
            'slug' => $overrides['organization_slug'] ?? 'test-community-' . (++$this->communityLeagueSequence),
            'settings' => ['commissioner_tools' => true],
        ]);
        $organization->users()->attach($user->id);

        $league = League::create([
            'name' => $overrides['league_name'] ?? 'Community League',
            'sport' => 'hockey',
        ]);
        $organization->leagues()->attach($league->id, [
            'linked_at' => now(),
        ]);

        if (($overrides['connect_fantrax'] ?? true) === true) {
            IntegrationSecret::create([
                'user_id' => $user->id,
                'provider' => 'fantrax',
                'secret' => 'secret-key',
                'status' => 'connected',
            ]);

            $platformLeague = PlatformLeague::create([
                'platform' => 'fantrax',
                'platform_league_id' => $overrides['platform_league_id'] ?? 'fantrax-league',
                'name' => 'Fantrax League',
                'sport' => 'hockey',
            ]);
            $team = PlatformTeam::create([
                'platform_league_id' => $platformLeague->id,
                'platform_team_id' => 'team-1',
                'name' => 'Team One',
            ]);
            $league->platformLeagues()->attach($platformLeague->id, [
                'linked_at' => now(),
                'status' => 'active',
            ]);
            $user->platformLeagues()->attach($platformLeague->id, [
                'team_id' => $team->id,
                'is_active' => true,
                'extras' => json_encode(['provider' => 'fantrax']),
                'synced_at' => now(),
            ]);
        }

        return [$user, $organization, $league];
    };

    $this->createDraft = function (PlatformLeague $platformLeague, array $overrides = []): Draft {
        return Draft::create([
            'platform_league_id' => $platformLeague->id,
            'source_type' => $overrides['source_type'] ?? 'platform_mirror',
            'platform' => $overrides['platform'] ?? 'fantrax',
            'external_draft_id' => $overrides['external_draft_id'] ?? 'fantrax:' . $platformLeague->platform_league_id . ':current',
            'name' => $overrides['name'] ?? $platformLeague->name . ' Draft',
            'draft_type' => $overrides['draft_type'] ?? 'snake',
            'status' => $overrides['status'] ?? 'live',
            'starts_at' => $overrides['starts_at'] ?? CarbonImmutable::parse('2026-09-21 19:00:00'),
            'pick_clock_seconds' => $overrides['pick_clock_seconds'] ?? 300,
            'settings' => $overrides['settings'] ?? ['provider' => 'fantrax'],
        ]);
    };

    $this->createDraftPick = function (Draft $draft, array $overrides = []): DraftPick {
        return DraftPick::create([
            'draft_id' => $draft->id,
            'provider_pick_key' => $overrides['provider_pick_key'] ?? 'overall:' . ($overrides['overall_pick'] ?? 1),
            'overall_pick' => $overrides['overall_pick'] ?? 1,
            'round' => $overrides['round'] ?? 1,
            'pick' => $overrides['pick'] ?? ($overrides['overall_pick'] ?? 1),
            'pick_in_round' => $overrides['pick_in_round'] ?? 1,
            'platform_team_id' => $overrides['platform_team_id'] ?? null,
            'provider_team_id' => $overrides['provider_team_id'] ?? 'team-1',
            'player_id' => $overrides['player_id'] ?? null,
            'provider_player_id' => $overrides['provider_player_id'] ?? null,
            'source' => $overrides['source'] ?? 'fantrax',
            'status' => $overrides['status'] ?? (($overrides['provider_player_id'] ?? null) ? 'picked' : 'pending'),
            'picked_at' => $overrides['picked_at'] ?? null,
            'detected_at' => $overrides['detected_at'] ?? null,
            'announced_at' => $overrides['announced_at'] ?? null,
            'payload_hash' => $overrides['payload_hash'] ?? hash('sha256', (string) ($overrides['provider_pick_key'] ?? 'overall:1')),
            'raw_payload' => $overrides['raw_payload'] ?? [],
        ]);
    };

    $this->discordRequestPayload = static function ($request): array {
        $data = $request->data();
        $payload = $data['payload_json'] ?? null;

        if (is_array($payload)) {
            $payload = $payload[0] ?? null;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    };
});

it('uses a human readable draft date as the title', function (): void {
    $payload = ($this->normalizeDraft)([
        'draftDate' => '2026-09-21 19:00:00',
    ]);

    expect($payload['title'])->toBe('September 21, 2026');
});

it('falls back to a generic draft title when no date exists', function (): void {
    $payload = ($this->normalizeDraft)([]);

    expect($payload['title'])->toBe('Draft')
        ->and($payload['draft_at'])->toBeNull();
});

it('returns the draft date as an ISO timestamp when it can be parsed from league info', function (): void {
    $payload = ($this->normalizeDraft)([
        'draftAt' => '2026-09-21T19:00:00-04:00',
    ]);

    expect($payload['draft_at'])->toContain('2026-09-21T19:00:00');
});

it('uses draft results draft date when league info has no draft date', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftDate' => '2026-06-28T12:00:00.0-0400',
    ]);

    expect($payload['title'])->toBe('June 28, 2026')
        ->and($payload['draft_at'])->toContain('2026-06-28T12:00:00');
});

it('marks the draft live when the draft date has passed and current draft picks exist', function (): void {
    $payload = ($this->normalizeDraft)(
        ['draftDate' => '2026-09-21 19:00:00'],
        [],
        null,
        CarbonImmutable::parse('2026-09-21 20:00:00'),
        [],
        [],
        ['currentDraftPicks' => [['round' => 1]]]
    );

    expect($payload['is_live'])->toBeTrue()
        ->and($payload['status_text'])->toBe('Live')
        ->and($payload['status_tone'])->toBe('green');
});

it('marks the draft scheduled when the draft date is in the future', function (): void {
    $payload = ($this->normalizeDraft)(
        ['draftDate' => '2026-09-21 19:00:00'],
        [],
        null,
        CarbonImmutable::parse('2026-09-20 20:00:00')
    );

    expect($payload['is_live'])->toBeFalse()
        ->and($payload['status_text'])->toBe('Scheduled')
        ->and($payload['status_tone'])->toBe('blue');
});

it('marks the draft complete when the draft date has passed and no current draft picks exist', function (): void {
    $payload = ($this->normalizeDraft)(
        ['draftDate' => '2026-09-21 19:00:00'],
        [],
        null,
        CarbonImmutable::parse('2026-09-21 20:00:00'),
        [],
        [],
        ['currentDraftPicks' => []]
    );

    expect($payload['is_live'])->toBeFalse()
        ->and($payload['status_text'])->toBe('Complete')
        ->and($payload['status_tone'])->toBe('slate');
});

it('returns an unavailable payload when the draft endpoint fails', function (): void {
    $payload = ($this->normalizeDraft)([], [], new RuntimeException('Fantrax failed'));

    expect($payload['available'])->toBeFalse()
        ->and($payload['status_text'])->toBe('Unavailable')
        ->and($payload['error_text'])->toBe('Draft results are unavailable right now.');
});

it('normalizes draft rows from the results key', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerId' => 'drafted-player-id', 'playerName' => 'Drafted Player', 'teamName' => 'Draft Team'],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['player_name'])->toBe('Drafted Player')
        ->and($payload['rows'][0]['team_name'])->toBe('Draft Team');
});

it('normalizes draft rows from the draftResults key', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftResults' => [
            ['playerId' => 'alias-player-id', 'playerName' => 'Alias Player', 'teamName' => 'Alias Team'],
        ],
    ]);

    expect($payload['rows'][0]['player_name'])->toBe('Alias Player');
});

it('normalizes draft rows from the draftPicks key used by Fantrax draft results', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => '062h5', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
        ],
    ], null, null, [
        '062h5' => ['name' => 'Draft Pick Player'],
    ], [
        'team-1' => ['owner_avatar_url' => 'https://example.test/team.png'],
    ]);

    expect($payload['rows'][0]['player_name'])->toBe('Draft Pick Player')
        ->and($payload['rows'][0]['pick_in_round'])->toBe(1)
        ->and($payload['rows'][0]['overall_pick'])->toBe(1)
        ->and($payload['rows'][0]['team_avatar_url'])->toBe('https://example.test/team.png');
});

it('keeps unmade draft picks blank when no player id exists', function (): void {
    $payload = ($this->normalizeDraft)([
        'teamInfo' => [
            ['id' => 'team-1', 'name' => 'Waiting Team'],
        ],
    ], [
        'draftPicks' => [
            ['teamId' => 'team-1', 'round' => 1, 'pick' => 6, 'pickInRound' => 6],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['player_name'])->toBe('')
        ->and($payload['rows'][0]['fantrax_player_id'])->toBe('')
        ->and($payload['rows'][0]['pick_in_round'])->toBe(6)
        ->and($payload['rows'][0]['overall_pick'])->toBe(6)
        ->and($payload['rows'][0]['team_name'])->toBe('Waiting Team')
        ->and($payload['rows'][0]['stats'])->toBe(['gp' => null, 'g' => null, 'a' => null, 'pts' => null]);
});

it('marks only the next unmade draft pick for the loading experience', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['teamId' => 'team-1', 'round' => 1, 'pick' => 6, 'pickInRound' => 6],
            ['teamId' => 'team-2', 'round' => 1, 'pick' => 7, 'pickInRound' => 7],
        ],
    ]);

    expect($payload['rows'][0]['is_next_pick'])->toBeTrue()
        ->and($payload['rows'][1]['is_next_pick'])->toBeFalse();
});

it('marks the first unmade draft pick even when later picks are already made', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => 'player-1', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ['teamId' => 'team-2', 'round' => 1, 'pick' => 2, 'pickInRound' => 2],
            ['playerId' => 'player-3', 'teamId' => 'team-3', 'round' => 1, 'pick' => 3, 'pickInRound' => 3],
            ['teamId' => 'team-4', 'round' => 1, 'pick' => 4, 'pickInRound' => 4],
        ],
    ], null, null, [
        'player-1' => ['name' => 'First Player'],
        'player-3' => ['name' => 'Third Player'],
    ]);

    expect($payload['rows'][1]['is_next_pick'])->toBeTrue()
        ->and($payload['rows'][3]['is_next_pick'])->toBeFalse()
        ->and($payload['rows'][1]['pick_in_round'])->toBe(2);
});

it('defaults the active round to the next unmade draft pick round', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => 'player-1', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ['teamId' => 'team-2', 'round' => 2, 'pick' => 2, 'pickInRound' => 1],
            ['teamId' => 'team-3', 'round' => 3, 'pick' => 3, 'pickInRound' => 1],
        ],
    ], null, null, [
        'player-1' => ['name' => 'Drafted Player'],
    ]);

    expect($payload['active_round_index'])->toBe(1)
        ->and($payload['rounds'][1]['rows'][0]['is_next_pick'])->toBeTrue();
});

it('defaults the active round to the last round when every visible pick is made', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => 'player-1', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ['playerId' => 'player-2', 'teamId' => 'team-2', 'round' => 2, 'pick' => 2, 'pickInRound' => 1],
        ],
    ], null, null, [
        'player-1' => ['name' => 'First Drafted Player'],
        'player-2' => ['name' => 'Second Drafted Player'],
    ]);

    expect($payload['active_round_index'])->toBe(1);
});

it('exposes fantrax draft pick timestamps for draft room countdowns', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => 'player-1', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1, 'time' => 1782662499000],
            ['playerId' => 'player-2', 'teamId' => 'team-2', 'round' => 1, 'pick' => 2, 'pickInRound' => 2, 'time' => 1782662939000],
        ],
    ], null, null, [
        'player-1' => ['name' => 'First Drafted Player'],
        'player-2' => ['name' => 'Second Drafted Player'],
    ]);

    expect($payload['rows'][0]['picked_at'])->toBe('2026-06-28T16:01:39+00:00')
        ->and($payload['last_pick_at'])->toBe('2026-06-28T16:08:59+00:00');
});

it('persists a baseline fantrax draft and pick rows from provider payloads', function (): void {
    [, , $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'draft-state-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'draft-state-league')->firstOrFail();

    $result = app(SyncFantraxDraftState::class)->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1, 'time' => 1782662499000],
                ['teamId' => 'team-2', 'round' => 1, 'pick' => 2, 'pickInRound' => 2],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-1']]],
        CarbonImmutable::parse('2026-09-21 20:00:00')
    );

    expect($result['new_picks'])->toBe([])
        ->and(Draft::query()->where('platform_league_id', $platformLeague->id)->count())->toBe(1)
        ->and(Draft::query()->where('platform_league_id', $platformLeague->id)->first()?->status)->toBe('live')
        ->and(DraftPick::query()->count())->toBe(2)
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->provider_player_id)->toBeNull()
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->picked_at?->toIso8601String())->toBe('2026-06-28T16:01:39+00:00')
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->status)->toBe('on_clock');
});

it('detects a new fantrax draft selection when a canonical unmade pick receives a player id', function (): void {
    [, , $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'draft-delta-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'draft-delta-league')->firstOrFail();
    $service = app(SyncFantraxDraftState::class);

    Event::fake([DraftPickMade::class]);

    $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-1']]],
        CarbonImmutable::parse('2026-09-21 20:00:00')
    );

    Event::assertNotDispatched(DraftPickMade::class);

    $result = $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['teamId' => 'team-1', 'playerId' => 'fantrax-player-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-2']]],
        CarbonImmutable::parse('2026-09-21 20:01:00')
    );

    expect($result['new_picks'])->toHaveCount(1)
        ->and($result['new_picks'][0]->provider_player_id)->toBe('fantrax-player-1')
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->provider_player_id)->toBe('fantrax-player-1')
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->status)->toBe('picked')
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->detected_at)->not->toBeNull();

    Event::assertDispatched(DraftPickMade::class);
});

it('persists fantrax league shape without treating normal divisions as duplicate player pools', function (): void {
    config()->set('apiurls.fantrax.base', 'https://fantrax.test/fxea');

    $player = Player::create([
        'first_name' => 'League',
        'last_name' => 'Skater',
        'full_name' => 'League Skater',
        'position' => 'C',
        'pos_type' => 'F',
        'status' => 'active',
    ]);
    $platformLeague = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'clh-shape-league',
        'name' => 'CLH Shape League',
        'sport' => 'hockey',
    ]);
    PlayerExternalIdentity::create([
        'player_id' => $player->id,
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fx-skater',
        'provider_slug' => 'fx-skater',
        'display_name' => 'League Skater',
        'normalized_name' => 'league skater',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'match_confidence' => 100,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'https://fantrax.test/fxea/general/getLeagueInfo?leagueId=clh-shape-league' => Http::response([
            'poolSettings' => ['duplicatePlayerType' => 'NONE'],
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Division One Team', 'division' => 'Division 1'],
                ['id' => 'team-2', 'name' => 'Division Two Team', 'division' => 'Division 2'],
            ],
            'playerInfo' => [
                'fx-skater' => ['eligiblePos' => 'C,F', 'status' => 'T'],
            ],
            'rosterInfo' => [
                'positionConstraints' => [
                    'C' => ['maxActive' => 4],
                    'D' => ['maxActive' => 6],
                    'LW' => ['maxActive' => 4],
                    'RW' => ['maxActive' => 4],
                    'G' => ['maxActive' => 2],
                    'Skt' => ['maxActive' => 1],
                ],
            ],
            'rosterPeriods' => [['period' => 1], ['period' => 2]],
            'scoringPeriods' => [['period' => 1]],
            'scoringSystem' => ['type' => 'rotisserie'],
        ], 200),
        'https://fantrax.test/fxea/general/getTeamRosters?leagueId=clh-shape-league' => Http::response([
            'rosters' => [
                'team-1' => [
                    'teamName' => 'Division One Team',
                    'rosterItems' => [
                        ['id' => 'fx-skater', 'position' => 'C', 'status' => 'ACTIVE'],
                    ],
                ],
            ],
        ], 200),
    ]);

    app(SyncFantraxLeague::class)->sync($platformLeague->id);
    $platformLeague->refresh();

    expect(data_get($platformLeague->settings, 'league_shape.player_pool_scope'))->toBe('league')
        ->and(data_get($platformLeague->settings, 'league_shape.duplicate_player_type'))->toBe('NONE')
        ->and(data_get($platformLeague->settings, 'league_shape.division_count'))->toBe(2)
        ->and(data_get($platformLeague->settings, 'league_shape.scoring_type'))->toBe('rotisserie');

    $teamExtras = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail()
        ->extras;

    expect(data_get($teamExtras, 'fantrax.division'))->toBe('Division 1');

    $this->assertDatabaseHas('platform_league_roster_slots', [
        'platform_league_id' => $platformLeague->id,
        'slot' => 'C',
        'slot_type' => 'starter',
        'position_type' => 'skater',
        'count' => 4,
        'sort_order' => 10,
    ]);
    expect(DB::table('platform_league_roster_slots')
        ->where('platform_league_id', $platformLeague->id)
        ->whereIn('slot', ['LW', 'RW', 'D'])
        ->pluck('sort_order', 'slot')
        ->all())->toBe([
            'D' => 60,
            'LW' => 20,
            'RW' => 30,
        ]);
    $this->assertDatabaseHas('platform_roster_memberships', [
        'player_id' => $player->id,
        'platform_player_id' => 'fx-skater',
        'slot' => 'C',
        'status' => 'active',
    ]);
});

it('uses division grouped fantrax player info for roster eligibility in duplicate player leagues', function (): void {
    config()->set('apiurls.fantrax.base', 'https://fantrax.test/fxea');

    $player = Player::create([
        'first_name' => 'Division',
        'last_name' => 'Defender',
        'full_name' => 'Division Defender',
        'position' => 'D',
        'pos_type' => 'D',
        'status' => 'active',
    ]);
    $platformLeague = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fhl-shape-league',
        'name' => 'FHL Shape League',
        'sport' => 'hockey',
    ]);
    PlayerExternalIdentity::create([
        'player_id' => $player->id,
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fx-defender',
        'provider_slug' => 'fx-defender',
        'display_name' => 'Division Defender',
        'normalized_name' => 'division defender',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'match_confidence' => 100,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'https://fantrax.test/fxea/general/getLeagueInfo?leagueId=fhl-shape-league' => Http::response([
            'poolSettings' => ['duplicatePlayerType' => 'ACROSS_DIVISIONS'],
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Gretzky Team', 'division' => 'Gretzky'],
            ],
            'playerInfo' => [
                'Gretzky' => [
                    'fx-defender' => ['eligiblePos' => 'D,Skt', 'status' => 'FA'],
                ],
            ],
            'rosterInfo' => [
                'positionConstraints' => [
                    'D' => ['maxActive' => 6],
                ],
            ],
            'scoringSystem' => ['type' => 'points'],
        ], 200),
        'https://fantrax.test/fxea/general/getTeamRosters?leagueId=fhl-shape-league' => Http::response([
            'rosters' => [
                'team-1' => [
                    'teamName' => 'Gretzky Team',
                    'rosterItems' => [
                        ['id' => 'fx-defender', 'position' => 'D', 'status' => 'ACTIVE', 'salary' => 850, 'contract' => ['smallId' => 'P4']],
                    ],
                ],
            ],
        ], 200),
    ]);

    app(SyncFantraxLeague::class)->sync($platformLeague->id);
    $platformLeague->refresh();

    expect(data_get($platformLeague->settings, 'league_shape.player_pool_scope'))->toBe('division')
        ->and(data_get($platformLeague->settings, 'league_shape.draft_shape'))->toBe('division_scoped')
        ->and(data_get($platformLeague->settings, 'league_shape.custom_salary_detected'))->toBeTrue()
        ->and(data_get($platformLeague->settings, 'league_shape.contract_codes_detected'))->toBe(['P4']);

    $membership = DB::table('platform_roster_memberships')
        ->where('player_id', $player->id)
        ->where('platform_player_id', 'fx-defender')
        ->first();

    expect($membership)->not->toBeNull()
        ->and(json_decode((string) $membership->eligibility, true))->toBe(['D', 'SKT']);
});

it('preserves division scoped fantrax draft slots without dispatching pick events for pending rows', function (): void {
    [, , $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'division-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'division-draft-league')->firstOrFail();
    $service = app(SyncFantraxDraftState::class);

    Event::fake([DraftPickMade::class]);

    $result = $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftOrder' => [
                'Gretzky' => ['team-1', 'team-2'],
                'Orr' => ['team-3', 'team-4'],
            ],
            'draftPicks' => [
                ['division' => 'Gretzky', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
                ['division' => 'Orr', 'teamId' => 'team-3', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-1']]],
        CarbonImmutable::parse('2026-09-21 20:00:00')
    );

    $draft = Draft::query()->where('platform_league_id', $platformLeague->id)->firstOrFail();

    expect($result['new_picks'])->toBe([])
        ->and($draft->settings['draft_shape'] ?? null)->toBe('division_scoped')
        ->and($draft->settings['provider_draft_order']['Gretzky'] ?? null)->toBe(['team-1', 'team-2'])
        ->and(DraftPick::query()->where('provider_pick_key', 'division:gretzky:round:1:pick-in-round:1')->exists())->toBeTrue()
        ->and(DraftPick::query()->where('provider_pick_key', 'division:orr:round:1:pick-in-round:1')->exists())->toBeTrue()
        ->and(DraftPick::query()->whereNotNull('overall_pick')->exists())->toBeFalse()
        ->and(DraftPick::query()->whereNotNull('picked_at')->exists())->toBeFalse();

    Event::assertNotDispatched(DraftPickMade::class);
});

it('dispatches a pick event when a division scoped pending fantrax slot receives a player id', function (): void {
    [, , $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'division-delta-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'division-delta-league')->firstOrFail();
    $service = app(SyncFantraxDraftState::class);

    Event::fake([DraftPickMade::class]);

    $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['division' => 'Gretzky', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-1']]],
        CarbonImmutable::parse('2026-09-21 20:00:00')
    );

    Event::assertNotDispatched(DraftPickMade::class);

    $result = $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                [
                    'division' => 'Gretzky',
                    'teamId' => 'team-1',
                    'playerId' => 'division-player-1',
                    'round' => 1,
                    'pick' => 1,
                    'pickInRound' => 1,
                    'time' => 1782662499000,
                ],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-2']]],
        CarbonImmutable::parse('2026-09-21 20:01:00')
    );

    $pick = DraftPick::query()
        ->where('provider_pick_key', 'division:gretzky:round:1:pick-in-round:1')
        ->firstOrFail();

    expect($result['new_picks'])->toHaveCount(1)
        ->and($pick->provider_player_id)->toBe('division-player-1')
        ->and($pick->status)->toBe('picked')
        ->and($pick->picked_at?->toIso8601String())->toBe('2026-06-28T16:01:39+00:00');

    Event::assertDispatched(DraftPickMade::class);
});

it('removes drafted players from draft queues when fantrax sync resolves a picked player', function (): void {
    [$user] = ($this->createCommunityLeague)([
        'platform_league_id' => 'draft-queue-prune-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'draft-queue-prune-league')->firstOrFail();
    $service = app(SyncFantraxDraftState::class);
    $player = Player::create([
        'first_name' => 'Queued',
        'last_name' => 'Player',
        'full_name' => 'Queued Player',
        'position' => 'C',
    ]);
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-queued-player',
        'name' => 'Queued Player',
        'player_id' => $player->id,
    ]);

    $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-1']]],
        CarbonImmutable::parse('2026-09-21 20:00:00')
    );
    $draft = Draft::query()->where('platform_league_id', $platformLeague->id)->firstOrFail();
    DraftQueueItem::create([
        'draft_id' => $draft->id,
        'user_id' => $user->id,
        'player_id' => $player->id,
        'rank' => 1,
    ]);

    $service->syncPayloads(
        $platformLeague,
        [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['teamId' => 'team-2', 'playerId' => 'fantrax-queued-player', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        ['currentDraftPicks' => [['teamId' => 'team-2']]],
        CarbonImmutable::parse('2026-09-21 20:01:00')
    );

    expect(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->player_id)->toBe($player->id)
        ->and(DraftQueueItem::query()->where('draft_id', $draft->id)->where('player_id', $player->id)->exists())->toBeFalse();
});

it('dispatches fantrax draft polling for live canonical fantrax drafts', function (): void {
    ($this->createCommunityLeague)([
        'platform_league_id' => 'poll-live-due-league',
    ]);
    ($this->createCommunityLeague)([
        'platform_league_id' => 'poll-live-fresh-league',
    ]);
    ($this->createCommunityLeague)([
        'platform_league_id' => 'poll-scheduled-league',
    ]);
    ($this->createCommunityLeague)([
        'platform_league_id' => 'poll-complete-league',
    ]);
    ($this->createCommunityLeague)([
        'platform_league_id' => 'poll-missing-state-league',
    ]);
    $liveDuePlatformLeague = PlatformLeague::query()->where('platform_league_id', 'poll-live-due-league')->firstOrFail();
    $liveFreshPlatformLeague = PlatformLeague::query()->where('platform_league_id', 'poll-live-fresh-league')->firstOrFail();
    $scheduledPlatformLeague = PlatformLeague::query()->where('platform_league_id', 'poll-scheduled-league')->firstOrFail();
    $completePlatformLeague = PlatformLeague::query()->where('platform_league_id', 'poll-complete-league')->firstOrFail();

    ($this->createDraft)($liveDuePlatformLeague, [
        'external_draft_id' => 'fantrax:poll-live-due-league:current',
        'status' => 'live',
    ]);
    ($this->createDraft)($liveFreshPlatformLeague, [
        'external_draft_id' => 'fantrax:poll-live-fresh-league:current',
        'status' => 'live',
    ]);
    ($this->createDraft)($scheduledPlatformLeague, [
        'external_draft_id' => 'fantrax:poll-scheduled-league:current',
        'status' => 'scheduled',
    ]);
    ($this->createDraft)($completePlatformLeague, [
        'external_draft_id' => 'fantrax:poll-complete-league:current',
        'status' => 'complete',
    ]);
    Bus::fake();

    $this->artisan('fantrax:drafts:poll')->assertSuccessful();

    Bus::assertDispatched(SyncFantraxDraftStateJob::class, static fn (SyncFantraxDraftStateJob $job): bool => $job->platformLeagueId === $liveDuePlatformLeague->id);
    Bus::assertDispatched(SyncFantraxDraftStateJob::class, static fn (SyncFantraxDraftStateJob $job): bool => $job->platformLeagueId === $liveFreshPlatformLeague->id);
    Bus::assertDispatchedTimes(SyncFantraxDraftStateJob::class, 2);
});

it('loads league draft panel from persisted draft payloads when available', function (): void {
    [$user, , $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'persisted-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'persisted-draft-league')->firstOrFail();
    $draft = ($this->createDraft)($platformLeague, [
        'external_draft_id' => 'fantrax:persisted-draft-league:current',
        'status' => 'live',
    ]);
    $player = Player::create([
        'first_name' => 'Persisted',
        'last_name' => 'Pick',
        'full_name' => 'Persisted Pick',
        'position' => 'C',
    ]);
    $platformTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $platformTeam->forceFill(['name' => 'Persisted Team'])->save();
    ($this->createDraftPick)($draft, [
        'platform_team_id' => $platformTeam->id,
        'provider_team_id' => 'team-1',
        'player_id' => $player->id,
        'provider_player_id' => 'persisted-player',
        'status' => 'picked',
    ]);
    Bus::fake();

    $this->actingAs($user)
        ->get("/leagues/{$platformLeague->id}")
        ->assertOk()
        ->assertSee('Persisted Pick')
        ->assertSee('Persisted Team');

    Bus::assertNotDispatched(SyncFantraxDraftStateJob::class);
});

it('scopes division scoped draft central rows to the viewer division', function (): void {
    [$user] = ($this->createCommunityLeague)([
        'platform_league_id' => 'division-visible-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'division-visible-draft-league')->firstOrFail();
    $platformLeague->forceFill([
        'settings' => [
            'league_shape' => [
                'player_pool_scope' => 'division',
                'draft_shape' => 'division_scoped',
            ],
        ],
    ])->save();
    LeagueUserRole::create([
        'league_id' => $league->id,
        'user_id' => $user->id,
        'role' => 'commissioner',
    ]);
    $draft = ($this->createDraft)($platformLeague, [
        'external_draft_id' => 'fantrax:division-visible-draft-league:current',
        'status' => 'live',
        'settings' => ['provider' => 'fantrax', 'draft_shape' => 'division_scoped'],
    ]);
    $gretzkyTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $gretzkyTeam->forceFill([
        'name' => 'Gretzky Team',
        'extras' => ['fantrax' => ['division' => 'Gretzky']],
    ])->save();
    $orrTeam = PlatformTeam::create([
        'platform_league_id' => $platformLeague->id,
        'platform_team_id' => 'team-2',
        'name' => 'Orr Team',
        'extras' => ['fantrax' => ['division' => 'Orr']],
    ]);
    $gretzkyPlayer = Player::create([
        'first_name' => 'Gretzky',
        'last_name' => 'Pick',
        'full_name' => 'Gretzky Pick',
        'position' => 'C',
    ]);
    $orrPlayer = Player::create([
        'first_name' => 'Orr',
        'last_name' => 'Pick',
        'full_name' => 'Orr Pick',
        'position' => 'D',
    ]);
    ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'division:gretzky:round:1:pick-in-round:1',
        'overall_pick' => null,
        'platform_team_id' => $gretzkyTeam->id,
        'provider_team_id' => 'team-1',
        'player_id' => $gretzkyPlayer->id,
        'provider_player_id' => 'gretzky-player',
        'raw_payload' => ['division' => 'Gretzky'],
    ]);
    $orrPick = ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'division:orr:round:1:pick-in-round:1',
        'overall_pick' => null,
        'platform_team_id' => $orrTeam->id,
        'provider_team_id' => 'team-2',
        'player_id' => $orrPlayer->id,
        'provider_player_id' => 'orr-player',
        'raw_payload' => ['division' => 'Orr'],
    ]);
    $draft->forceFill(['current_draft_pick_id' => $orrPick->id])->save();
    Bus::fake();

    $this->actingAs($user)
        ->get("/leagues/{$platformLeague->id}")
        ->assertOk()
        ->assertSee('Gretzky Pick')
        ->assertSee('Gretzky Team')
        ->assertDontSee('Orr Pick')
        ->assertDontSee('Orr Team');

    Bus::assertNotDispatched(SyncFantraxDraftStateJob::class);
});

it('scopes division scoped league teams and roster players to the viewer division', function (): void {
    [$user] = ($this->createCommunityLeague)([
        'platform_league_id' => 'division-visible-players-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'division-visible-players-league')->firstOrFail();
    $platformLeague->forceFill([
        'settings' => [
            'league_shape' => [
                'player_pool_scope' => 'division',
                'draft_shape' => 'division_scoped',
            ],
        ],
    ])->save();
    LeagueUserRole::create([
        'league_id' => $league->id,
        'user_id' => $user->id,
        'role' => 'commissioner',
    ]);
    $gretzkyTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $gretzkyTeam->forceFill([
        'name' => 'Gretzky Team',
        'extras' => ['fantrax' => ['division' => 'Gretzky']],
    ])->save();
    $orrTeam = PlatformTeam::create([
        'platform_league_id' => $platformLeague->id,
        'platform_team_id' => 'team-2',
        'name' => 'Orr Team',
        'extras' => ['fantrax' => ['division' => 'Orr']],
    ]);
    $gretzkyPlayer = Player::create([
        'first_name' => 'Gretzky',
        'last_name' => 'Roster',
        'full_name' => 'Gretzky Roster',
        'position' => 'C',
        'pos_type' => 'F',
    ]);
    $orrPlayer = Player::create([
        'first_name' => 'Orr',
        'last_name' => 'Roster',
        'full_name' => 'Orr Roster',
        'position' => 'D',
        'pos_type' => 'D',
    ]);
    DB::table('platform_roster_memberships')->insert([
        [
            'platform_team_id' => $gretzkyTeam->id,
            'player_id' => $gretzkyPlayer->id,
            'platform' => 'fantrax',
            'platform_player_id' => 'gretzky-roster',
            'slot' => 'C',
            'status' => 'active',
            'eligibility' => json_encode(['C']),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'platform_team_id' => $orrTeam->id,
            'player_id' => $orrPlayer->id,
            'platform' => 'fantrax',
            'platform_player_id' => 'orr-roster',
            'slot' => 'D',
            'status' => 'active',
            'eligibility' => json_encode(['D']),
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('leagues.players.payload', $platformLeague->id))
        ->assertOk();

    $teams = collect($response->json('teams'));
    $players = $teams->pluck('players')->flatten(1);

    expect($teams->pluck('name')->all())->toContain('Gretzky Team')
        ->and($teams->pluck('name')->all())->not->toContain('Orr Team')
        ->and($teams->firstWhere('name', 'Gretzky Team')['fantrax_division'] ?? null)->toBe('Gretzky')
        ->and($players->pluck('name')->all())->toContain('Gretzky Roster')
        ->and($players->pluck('name')->all())->not->toContain('Orr Roster');
});

it('shows draft creation empty state when no canonical draft exists', function (): void {
    [$user] = ($this->createCommunityLeague)([
        'platform_league_id' => 'hydrate-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'hydrate-draft-league')->firstOrFail();
    Bus::fake();

    $this->actingAs($user)
        ->get("/leagues/{$platformLeague->id}")
        ->assertOk()
        ->assertSee('No draft has been configured for this league.');

    Bus::assertNotDispatched(SyncFantraxDraftStateJob::class);
});

it('announces a new fantrax draft pick to the configured discord draft channel', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'announce-draft-league',
    ]);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'announce-draft-league')->firstOrFail();
    $organization->leagues()->updateExistingPivot($league->id, [
        'meta' => json_encode([
            'draft_notifications' => [
                'discord_channel' => [
                    'id' => 'draft-channel',
                    'name' => 'draft-room',
                ],
            ],
        ]),
    ]);
    PlatformTeam::query()->where('platform_league_id', $platformLeague->id)->update([
        'platform_team_id' => 'team-1',
        'name' => 'Northumberland Nitro',
    ]);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-drafter-1',
        'nickname' => 'drafter',
        'name' => 'Drafter',
        'avatar' => 'https://example.test/drafter-avatar.png',
    ]);
    $otcUser = User::factory()->create();
    $organization->users()->attach($otcUser->id);
    $otcTeam = PlatformTeam::create([
        'platform_league_id' => $platformLeague->id,
        'platform_team_id' => 'team-2',
        'name' => 'OTC Team',
    ]);
    DB::table('league_user_teams')->insert([
        'user_id' => $otcUser->id,
        'platform_league_id' => $platformLeague->id,
        'team_id' => $otcTeam->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    SocialAccount::create([
        'user_id' => $otcUser->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-otc-1',
        'nickname' => 'otc',
        'name' => 'OTC',
    ]);
    $player = Player::create([
        'nhl_id' => 8478405,
        'first_name' => 'Drafted',
        'last_name' => 'Player',
        'full_name' => 'Drafted Player',
        'position' => 'C',
        'head_shot_url' => 'https://example.test/drafted-player.png',
    ]);
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-player-1',
        'name' => 'Drafted Player',
        'player_id' => $player->id,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'Drafted Player',
        'season_id' => '20252026',
        'league_abbrev' => 'OHL',
        'nhl_team_abbrev' => 'TOR',
        'team_name' => 'Draft Team A',
        'gp' => 12,
        'g' => 4,
        'a' => 8,
        'pts' => 12,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'Drafted Player',
        'season_id' => '20252026',
        'league_abbrev' => 'WHL',
        'nhl_team_abbrev' => 'SEA',
        'team_name' => 'Draft Team B',
        'gp' => 40,
        'g' => 11,
        'a' => 19,
        'pts' => 30,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'Drafted Player',
        'season_id' => '20242025',
        'league_abbrev' => 'AHL',
        'nhl_team_abbrev' => 'BOS',
        'team_name' => 'Draft Team C',
        'gp' => 36,
        'g' => 9,
        'a' => 15,
        'pts' => 24,
    ]);
    $draft = ($this->createDraft)($platformLeague, [
        'external_draft_id' => 'fantrax:announce-draft-league:current',
    ]);
    DraftNotificationSetting::create([
        'draft_id' => $draft->id,
        'discord_channel_id' => 'draft-channel',
        'discord_channel_name' => 'draft-room',
        'enabled' => true,
        'settings' => ['source' => 'test'],
    ]);
    $platformTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $draftPick = ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'overall:1',
        'overall_pick' => 1,
        'round' => 1,
        'pick_in_round' => 1,
        'platform_team_id' => $platformTeam->id,
        'provider_team_id' => 'team-1',
        'player_id' => $player->id,
        'provider_player_id' => 'fantrax-player-1',
        'status' => 'picked',
        'payload_hash' => hash('sha256', 'pick'),
    ]);
    ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'overall:2',
        'overall_pick' => 2,
        'round' => 1,
        'pick_in_round' => 2,
        'platform_team_id' => $otcTeam->id,
        'provider_team_id' => 'team-2',
        'provider_player_id' => null,
        'status' => 'on_clock',
        'payload_hash' => hash('sha256', 'pick-2'),
    ]);

    Event::fake([FantraxDraftPickToast::class]);
    app()->instance(DraftPickCardRenderer::class, new class {
        /**
         * @param array<string,mixed> $card
         */
        public function render(array $card, ?string $path = null): ?string
        {
            return null;
        }
    });
    Http::fake([
        'https://example.test/drafter-avatar.png' => Http::response('', 404),
        'https://example.test/drafted-player.png' => Http::response('', 404),
        'https://discord.com/api/v10/channels/draft-channel/messages' => Http::response(['id' => 'message-1']),
    ]);

    app(AnnounceFantraxDraftPick::class)->handle(DraftPickMade::fromDraftPick($draftPick));

    Http::assertSent(function ($request): bool {
        $data = ($this->discordRequestPayload)($request);
        $content = (string) ($data['content'] ?? '');

        return $request->url() === 'https://discord.com/api/v10/channels/draft-channel/messages'
            && $request->hasHeader('Authorization', 'Bot bot-token')
            && str_contains($content, 'selects Drafted Player with pick 1.')
            && str_contains($content, 'is now OTC.');
    });
    Event::assertDispatched(FantraxDraftPickToast::class, static fn (FantraxDraftPickToast $event): bool => $event->userId === $user->id
        && str_contains($event->message, '<@discord-drafter-1> (Northumberland Nitro) selects Drafted Player with pick 1.'));
});

it('uses goalie stat columns for drafted goalie discord cards', function (): void {
    [, $organization, $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'goalie-announce-draft-league',
    ]);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'goalie-announce-draft-league')->firstOrFail();
    $organization->leagues()->updateExistingPivot($league->id, [
        'meta' => json_encode([
            'draft_notifications' => [
                'discord_channel' => [
                    'id' => 'draft-channel',
                    'name' => 'draft-room',
                ],
            ],
        ]),
    ]);
    PlatformTeam::query()->where('platform_league_id', $platformLeague->id)->update([
        'platform_team_id' => 'team-1',
        'name' => 'Goalie Draft Team',
    ]);
    $player = Player::create([
        'nhl_id' => 8480001,
        'first_name' => 'Drafted',
        'last_name' => 'Goalie',
        'full_name' => 'Drafted Goalie',
        'position' => 'G',
        'pos_type' => 'G',
    ]);
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-goalie-1',
        'name' => 'Drafted Goalie',
        'position' => 'G',
        'player_id' => $player->id,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'Drafted Goalie',
        'season_id' => '20252026',
        'league_abbrev' => 'OHL',
        'nhl_team_abbrev' => 'NYR',
        'team_name' => 'Goalie Club',
        'gp' => 42,
        'wins' => 27,
        'saves' => 1210,
        'sv_pct' => 0.914,
    ]);
    $draft = ($this->createDraft)($platformLeague, [
        'external_draft_id' => 'fantrax:goalie-announce-draft-league:current',
    ]);
    $platformTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $draftPick = ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'overall:43',
        'overall_pick' => 43,
        'round' => 3,
        'pick_in_round' => 7,
        'platform_team_id' => $platformTeam->id,
        'provider_team_id' => 'team-1',
        'player_id' => $player->id,
        'provider_player_id' => 'fantrax-goalie-1',
        'status' => 'picked',
        'payload_hash' => hash('sha256', 'goalie-pick'),
    ]);
    $cardRenderer = new class {
        /** @var array<string,mixed>|null */
        public ?array $card = null;

        /**
         * @param array<string,mixed> $card
         */
        public function render(array $card, ?string $path = null): ?string
        {
            $this->card = $card;

            return null;
        }
    };

    app()->instance(DraftPickCardRenderer::class, $cardRenderer);
    Event::fake([FantraxDraftPickToast::class]);
    Http::fake([
        'https://discord.com/api/v10/channels/draft-channel/messages' => Http::response(['id' => 'message-1']),
    ]);

    app(AnnounceFantraxDraftPick::class)->handle(DraftPickMade::fromDraftPick($draftPick));

    expect($cardRenderer->card)->not->toBeNull()
        ->and($cardRenderer->card['stat_headers'])->toBe(['GP', 'W', 'SV', 'SV%'])
        ->and($cardRenderer->card['stat_keys'])->toBe(['gp', 'wins', 'saves', 'sv_pct'])
        ->and($cardRenderer->card['stats'][0]['gp'])->toBe(42)
        ->and($cardRenderer->card['stats'][0]['wins'])->toBe(27)
        ->and($cardRenderer->card['stats'][0]['saves'])->toBe(1210)
        ->and($cardRenderer->card['stats'][0]['sv_pct'])->toBe(0.914);
});

it('announces a fantrax draft pick only once when duplicate listener jobs run', function (): void {
    [, $organization, $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'duplicate-announce-draft-league',
    ]);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'duplicate-announce-draft-league')->firstOrFail();
    $organization->leagues()->updateExistingPivot($league->id, [
        'meta' => json_encode([
            'draft_notifications' => [
                'discord_channel' => [
                    'id' => 'draft-channel',
                    'name' => 'draft-room',
                ],
            ],
        ]),
    ]);
    PlatformTeam::query()->where('platform_league_id', $platformLeague->id)->update([
        'platform_team_id' => 'team-1',
        'name' => 'Drafting Team',
    ]);
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-player-1',
        'name' => 'Drafted Player',
    ]);
    $draft = ($this->createDraft)($platformLeague, [
        'external_draft_id' => 'fantrax:duplicate-announce-draft-league:current',
    ]);
    $platformTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    $draftPick = ($this->createDraftPick)($draft, [
        'provider_pick_key' => 'overall:1',
        'overall_pick' => 1,
        'round' => 1,
        'pick_in_round' => 1,
        'platform_team_id' => $platformTeam->id,
        'provider_team_id' => 'team-1',
        'provider_player_id' => 'fantrax-player-1',
        'status' => 'picked',
        'payload_hash' => hash('sha256', 'pick'),
    ]);

    Event::fake([FantraxDraftPickToast::class]);
    Http::fake([
        'https://discord.com/api/v10/channels/draft-channel/messages' => Http::response(['id' => 'message-1']),
    ]);

    $listener = app(AnnounceFantraxDraftPick::class);
    $event = DraftPickMade::fromDraftPick($draftPick);
    $listener->handle($event);
    $listener->handle($event);

    Http::assertSentCount(1);
    Event::assertDispatchedTimes(FantraxDraftPickToast::class, 1);
    expect($draftPick->refresh()->announced_at)->not->toBeNull();
});

it('saves a draft notification channel and creates it on discord when needed', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)();
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $discordServer = DiscordServer::create([
        'organization_id' => $organization->id,
        'discord_guild_id' => 'guild-1',
        'discord_guild_name' => 'Guild One',
    ]);
    $organization->leagues()->updateExistingPivot($league->id, [
        'discord_server_id' => $discordServer->id,
    ]);

    Http::fake([
        'https://discord.com/api/v10/guilds/guild-1/channels' => Http::sequence()
            ->push([
                ['id' => 'text-category', 'name' => 'Text Channels', 'type' => 4, 'position' => 0],
                ['id' => 'existing-channel', 'name' => 'general', 'type' => 0, 'parent_id' => 'text-category', 'position' => 1],
            ])
            ->push([
                ['id' => 'text-category', 'name' => 'Text Channels', 'type' => 4, 'position' => 0],
                ['id' => 'existing-channel', 'name' => 'general', 'type' => 0, 'parent_id' => 'text-category', 'position' => 1],
            ])
            ->push(['id' => 'created-channel', 'name' => 'draft-room', 'type' => 0]),
    ]);

    $this->actingAs($user)
        ->putJson("/communities/{$organization->id}/leagues/{$league->id}/draft-settings", [
            'draft_channel_name' => 'draft room',
        ])
        ->assertOk()
        ->assertJsonPath('channel.id', 'created-channel')
        ->assertJsonPath('channel.name', 'draft-room');

    $pivotMeta = json_decode(
        (string) $organization->leagues()->whereKey($league->id)->firstOrFail()->pivot->meta,
        true
    );

    expect(data_get($pivotMeta, 'draft_notifications.discord_channel.id'))->toBe('created-channel');

    Http::assertSent(static function ($request): bool {
        $data = $request->data();

        return $request->method() === 'POST'
            && str_contains($request->url(), 'discord.com/api/v10/guilds/guild-1/channels')
            && ($data['parent_id'] ?? null) === 'text-category';
    });
});

it('preloads draft notification channels from the connected discord guild and omits stale saved channels', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'draft-channel-community',
    ]);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $discordServer = DiscordServer::create([
        'organization_id' => $organization->id,
        'discord_guild_id' => 'guild-1',
        'discord_guild_name' => 'Guild One',
    ]);
    $organization->leagues()->updateExistingPivot($league->id, [
        'discord_server_id' => $discordServer->id,
        'meta' => json_encode([
            'draft_notifications' => [
                'discord_channel' => [
                    'id' => 'saved-channel',
                    'name' => 'keeper-draft',
                ],
            ],
        ]),
    ]);

    Http::fake([
        'https://discord.com/api/v10/guilds/guild-1/channels' => Http::response([
            ['id' => 'general-channel', 'name' => 'general', 'type' => 0],
            ['id' => 'voice-channel', 'name' => 'voice-room', 'type' => 2],
        ]),
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Draft Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftPicks' => [],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('general')
        ->assertDontSee('keeper-draft')
        ->assertDontSee('voice-room');

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'discord.com/api/v10/guilds/guild-1/channels')
        && $request->hasHeader('Authorization', 'Bot bot-token'));
});

it('updates a community league name without clearing the selected discord server', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'league_name' => 'Original League Name',
    ]);
    $discordServer = DiscordServer::create([
        'organization_id' => $organization->id,
        'discord_guild_id' => 'guild-1',
        'discord_guild_name' => 'Guild One',
    ]);
    $organization->leagues()->updateExistingPivot($league->id, [
        'discord_server_id' => $discordServer->id,
    ]);

    $this->actingAs($user)
        ->postJson("/organizations/{$organization->id}/leagues/{$league->id}", [
            'name' => 'Renamed League',
        ])
        ->assertOk()
        ->assertJsonPath('league.name', 'Renamed League');

    $league->refresh();
    $pivot = $organization->leagues()->whereKey($league->id)->firstOrFail()->pivot;

    expect($league->name)->toBe('Renamed League')
        ->and((int) $pivot->discord_server_id)->toBe($discordServer->id);
});

it('surfaces a draft notification channel preload message when the bot token is missing', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'draft-channel-missing-token-community',
    ]);
    config(['apiurls.discord-bot.key' => '']);
    $discordServer = DiscordServer::create([
        'organization_id' => $organization->id,
        'discord_guild_id' => 'guild-1',
        'discord_guild_name' => 'Guild One',
    ]);
    $organization->leagues()->updateExistingPivot($league->id, [
        'discord_server_id' => $discordServer->id,
    ]);

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Draft Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftPicks' => [],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('missing_bot_token')
        ->assertSee('Discord channels could not be loaded because the DIQ bot token is not configured.');

    Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'discord.com/api/v10/guilds/guild-1/channels'));
});

it('surfaces the discord status code when draft notification channel preload fails', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'draft-channel-discord-error-community',
    ]);
    config(['apiurls.discord-bot.key' => 'bot-token']);
    $discordServer = DiscordServer::create([
        'organization_id' => $organization->id,
        'discord_guild_id' => 'guild-1',
        'discord_guild_name' => 'Guild One',
    ]);
    $organization->leagues()->updateExistingPivot($league->id, [
        'discord_server_id' => $discordServer->id,
    ]);

    Http::fake([
        'https://discord.com/api/v10/guilds/guild-1/channels' => Http::response([
            'message' => 'Missing Access',
            'code' => 50001,
        ], 403),
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Draft Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftPicks' => [],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('discord_error')
        ->assertSee('Discord returned 403 while loading channels for this server.');

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'discord.com/api/v10/guilds/guild-1/channels')
        && $request->hasHeader('Authorization', 'Bot bot-token'));
});

it('normalizes draft rows from a bare result array', function (): void {
    $payload = ($this->normalizeDraft)([], [
        ['playerName' => 'Bare Player', 'teamName' => 'Bare Team'],
    ]);

    expect($payload['rows'][0]['team_name'])->toBe('Bare Team');
});

it('joins drafting team names from league info when result rows only carry team ids', function (): void {
    $payload = ($this->normalizeDraft)([
        'teamInfo' => [
            ['id' => 'team-2', 'name' => 'Joined Team'],
        ],
    ], [
        'results' => [
            ['playerName' => 'Joined Player', 'teamId' => 'team-2'],
        ],
    ]);

    expect($payload['rows'][0]['team_name'])->toBe('Joined Team');
});

it('prefers the team name from the draft result row when both sources provide one', function (): void {
    $payload = ($this->normalizeDraft)([
        'teamInfo' => [
            ['id' => 'team-2', 'name' => 'League Info Team'],
        ],
    ], [
        'results' => [
            ['playerName' => 'Direct Player', 'teamId' => 'team-2', 'teamName' => 'Direct Team'],
        ],
    ]);

    expect($payload['rows'][0]['team_name'])->toBe('Direct Team');
});

it('drops name-only drafted rows because they do not have a stable provider identity', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerName' => 'Unresolved Team Player'],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(0);
});

it('keeps drafted player rows with a provider player id even when the team cannot be resolved', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerId' => 'fantrax-player-1'],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['fantrax_player_id'])->toBe('fantrax-player-1')
        ->and($payload['rows'][0]['team_name'])->toBe('Unknown team');
});

it('uses locally stored fantrax player names when draft rows only include player ids', function (): void {
    $payload = ($this->normalizeDraft)(
        [],
        [
            'results' => [
                ['playerId' => 'fantrax-1', 'teamName' => 'Stored Team'],
            ],
        ],
        null,
        null,
        [
            'fantrax-1' => [
                'name' => 'Stored Fantrax Player',
                'player_id' => null,
                'nhl_id' => null,
            ],
        ]
    );

    expect($payload['rows'][0]['player_name'])->toBe('Stored Fantrax Player');
});

it('carries canonical ids from the local fantrax player map when available', function (): void {
    $payload = ($this->normalizeDraft)(
        [],
        [
            'results' => [
                ['playerId' => 'fantrax-2', 'teamName' => 'Canonical Team'],
            ],
        ],
        null,
        null,
        [
            'fantrax-2' => [
                'name' => 'Canonical Fantrax Player',
                'player_id' => 123,
                'nhl_id' => 8478402,
            ],
        ]
    );

    expect($payload['rows'][0]['player_id'])->toBe(123)
        ->and($payload['rows'][0]['nhl_id'])->toBe(8478402);
});

it('carries player avatar position league and latest stats from the player map', function (): void {
    $payload = ($this->normalizeDraft)(
        [],
        [
            'draftPicks' => [
                ['playerId' => 'fantrax-3', 'teamName' => 'Stats Team'],
            ],
        ],
        null,
        null,
        [
            'fantrax-3' => [
                'name' => 'Stats Fantrax Player',
                'player_id' => 123,
                'nhl_id' => 8478403,
                'position' => 'C',
                'league_abbrev' => 'OHL',
                'team_abbrev' => 'TOR',
                'avatar_url' => 'https://example.test/player.png',
                'stats' => ['gp' => 62, 'g' => 21, 'a' => 33, 'pts' => 54],
            ],
        ]
    );

    expect($payload['rows'][0]['avatar_url'])->toBe('https://example.test/player.png')
        ->and($payload['rows'][0]['position'])->toBe('C')
        ->and($payload['rows'][0]['league_abbrev'])->toBe('OHL')
        ->and($payload['rows'][0]['team_abbrev'])->toBe('TOR')
        ->and($payload['rows'][0]['stats'])->toBe(['gp' => 62, 'g' => 21, 'a' => 33, 'pts' => 54]);
});

it('normalizes round pick and overall pick values as integers', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerName' => 'Pick Player', 'teamName' => 'Pick Team', 'round' => '2', 'pick' => '5', 'overallPick' => '17'],
        ],
    ]);

    expect($payload['rows'][0]['round'])->toBe(2)
        ->and($payload['rows'][0]['pick'])->toBe(5)
        ->and($payload['rows'][0]['overall_pick'])->toBe(17);
});

it('sorts drafted rows by overall pick when available', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerId' => 'second-player-id', 'playerName' => 'Second Player', 'teamName' => 'Team', 'overallPick' => 2],
            ['playerId' => 'first-player-id', 'playerName' => 'First Player', 'teamName' => 'Team', 'overallPick' => 1],
        ],
    ]);

    expect($payload['rows'][0]['player_name'])->toBe('First Player')
        ->and($payload['rows'][1]['player_name'])->toBe('Second Player');
});

it('segments drafted rows by round pages', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerId' => 'round-two-player-id', 'playerName' => 'Round Two Player', 'teamName' => 'Team', 'round' => 2, 'pick' => 1],
            ['playerId' => 'round-one-player-id', 'playerName' => 'Round One Player', 'teamName' => 'Team', 'round' => 1, 'pick' => 1],
            ['playerId' => 'round-one-second-player-id', 'playerName' => 'Round One Second Player', 'teamName' => 'Team', 'round' => 1, 'pick' => 2],
        ],
    ]);

    expect($payload['rounds'])->toHaveCount(2)
        ->and($payload['rounds'][0]['label'])->toBe('Round 1')
        ->and($payload['rounds'][0]['count'])->toBe(2)
        ->and($payload['rounds'][0]['rows'][0]['player_name'])->toBe('Round One Player')
        ->and($payload['rounds'][1]['label'])->toBe('Round 2')
        ->and($payload['rounds'][1]['count'])->toBe(1);
});

it('normalizes nested player and team payload shapes', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            [
                'player' => ['id' => 'player-1', 'fullName' => 'Nested Player'],
                'team' => ['id' => 'team-1', 'name' => 'Nested Team'],
            ],
        ],
    ]);

    expect($payload['rows'][0]['fantrax_player_id'])->toBe('player-1')
        ->and($payload['rows'][0]['player_name'])->toBe('Nested Player')
        ->and($payload['rows'][0]['team_name'])->toBe('Nested Team');
});

it('uses draft status when rows exist and no date is available', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerName' => 'Status Player', 'teamName' => 'Status Team'],
        ],
    ]);

    expect($payload['status_text'])->toBe('Draft')
        ->and($payload['status_tone'])->toBe('slate');
});

it('returns an available empty payload for an unconnected league draft window', function (): void {
    $payload = ($this->normalizeDraft)([], []);

    expect($payload['available'])->toBeTrue()
        ->and($payload['rows'])->toBe([])
        ->and($payload['empty_text'])->toBe('No drafted players yet.');
});

it('renders the desktop drafting window on the community league show page', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)();
    $player = Player::create([
        'nhl_id' => 8478404,
        'first_name' => 'Rendered',
        'last_name' => 'Player',
        'full_name' => 'Rendered Player',
        'position' => 'LW',
        'head_shot_url' => 'https://example.test/rendered-player.png',
    ]);

    FantraxPlayer::create([
        'fantrax_id' => 'drafted-player-1',
        'name' => 'Rendered Player',
        'player_id' => $player->id,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'Rendered Player',
        'season_id' => '20252026',
        'league_abbrev' => 'WHL',
        'nhl_team_abbrev' => 'CHI',
        'team_name' => 'Rendered Junior Team',
        'gp' => 55,
        'g' => 20,
        'a' => 31,
        'pts' => 51,
    ]);
    $platformLeague = $league->primaryPlatformLeague();
    $platformTeam = PlatformTeam::query()
        ->where('platform_league_id', $platformLeague?->id)
        ->where('platform_team_id', 'team-1')
        ->firstOrFail();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-user-1',
        'avatar' => 'https://example.test/discord-avatar.png',
    ]);

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'draftDate' => '2026-09-21 19:00:00',
            'draftStatus' => 'live',
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Rendered Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftDate' => '2026-06-28T12:00:00.0-0400',
            'draftPicks' => [
                [
                    'playerId' => 'drafted-player-1',
                    'teamId' => $platformTeam->platform_team_id,
                    'round' => 1,
                    'pick' => 1,
                    'overallPick' => 1,
                ],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [
                ['round' => 1, 'currentOwnerTeamId' => 'team-1'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('June 28, 2026')
        ->assertSee('Live')
        ->assertSee('Draft settings')
        ->assertSee('Round 1')
        ->assertSee('Rendered Player')
        ->assertSee('Rendered Team')
        ->assertSee('LW')
        ->assertSee('WHL')
        ->assertSee('CHI')
        ->assertSee('55')
        ->assertSee('20')
        ->assertSee('31')
        ->assertSee('51')
        ->assertSee('https://example.test/rendered-player.png')
        ->assertSee('https://example.test/discord-avatar.png');
});

it('renders the latest-season league where the drafted player played the most games', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'most-games-community',
    ]);
    $player = Player::create([
        'nhl_id' => 8478405,
        'first_name' => 'League',
        'last_name' => 'Choice',
        'full_name' => 'League Choice',
        'position' => 'C',
    ]);

    FantraxPlayer::create([
        'fantrax_id' => 'drafted-player-2',
        'name' => 'League Choice',
        'player_id' => $player->id,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'League Choice',
        'season_id' => '20242025',
        'league_abbrev' => 'AHL',
        'nhl_team_abbrev' => 'BOS',
        'team_name' => 'Older Team',
        'gp' => 70,
        'g' => 30,
        'a' => 40,
        'pts' => 70,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'League Choice',
        'season_id' => '20252026',
        'league_abbrev' => 'WHL',
        'nhl_team_abbrev' => 'SEA',
        'team_name' => 'Lower GP Team',
        'gp' => 18,
        'g' => 4,
        'a' => 7,
        'pts' => 11,
    ]);
    Stat::create([
        'player_id' => $player->id,
        'player_name' => 'League Choice',
        'season_id' => '20252026',
        'league_abbrev' => 'OHL',
        'nhl_team_abbrev' => 'TOR',
        'team_name' => 'Highest GP Team',
        'gp' => 48,
        'g' => 19,
        'a' => 22,
        'pts' => 41,
    ]);

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Stats Choice Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftPicks' => [
                ['playerId' => 'drafted-player-2', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('League Choice')
        ->assertSee('OHL')
        ->assertSee('TOR')
        ->assertSee('48')
        ->assertSee('19')
        ->assertSee('22')
        ->assertSee('41')
        ->assertDontSee('AHL')
        ->assertDontSee('SEA');
});

it('falls back to a linked fantrax external identity when no fantrax player row exists', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'identity-community',
    ]);
    $player = Player::create([
        'nhl_id' => 8478402,
        'first_name' => 'External',
        'last_name' => 'Player',
        'full_name' => 'External Player',
    ]);
    PlayerExternalIdentity::create([
        'player_id' => $player->id,
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'external-fantrax-1',
        'display_name' => 'External Identity Player',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Identity Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'results' => [
                ['playerId' => 'external-fantrax-1', 'teamId' => 'team-1', 'overallPick' => 1],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('External Identity Player')
        ->assertSee('Identity Team');
});

it('renders an inline drafting error when Fantrax draft results fail', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)();

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'draftDate' => '2026-09-21 19:00:00',
            'teamInfo' => [],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([], 500),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('Draft results are unavailable right now.');
});

it('does not call Fantrax draft endpoints for an unconnected community league', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'connect_fantrax' => false,
    ]);

    Http::fake();

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('No drafted players yet.');

    Http::assertNothingSent();
});

it('blocks guests from the community league show page', function (): void {
    [, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'guest-community',
    ]);

    $this->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertRedirect('/login');
});

it('blocks users outside the community from the community league show page', function (): void {
    [, $organization, $league] = ($this->createCommunityLeague)([
        'organization_slug' => 'scoped-community',
    ]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertNotFound();
});
