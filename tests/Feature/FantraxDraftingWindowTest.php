<?php

declare(strict_types=1);

use App\Models\IntegrationSecret;
use App\Models\DiscordServer;
use App\Events\DraftPickMade;
use App\Events\FantraxDraftPickMade;
use App\Events\FantraxDraftPickToast;
use App\Jobs\SyncFantraxDraftStateJob;
use App\Listeners\AnnounceFantraxDraftPick;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\DraftQueueItem;
use App\Models\FantraxDraftPick;
use App\Models\FantraxDraftState;
use App\Models\FantraxPlayer;
use App\Models\League;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->normalizer = new FantraxDraftingWindow();

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
            'slug' => $overrides['organization_slug'] ?? 'test-community',
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
            ['playerName' => 'Drafted Player', 'teamName' => 'Draft Team'],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['player_name'])->toBe('Drafted Player')
        ->and($payload['rows'][0]['team_name'])->toBe('Draft Team');
});

it('normalizes draft rows from the draftResults key', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftResults' => [
            ['playerName' => 'Alias Player', 'teamName' => 'Alias Team'],
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

it('persists a baseline fantrax draft state and pick rows from provider payloads', function (): void {
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
        ->and(FantraxDraftState::query()->where('platform_league_id', $platformLeague->id)->first()?->status)->toBe('live')
        ->and(FantraxDraftPick::query()->where('platform_league_id', $platformLeague->id)->count())->toBe(2)
        ->and(FantraxDraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->fantrax_player_id)->toBeNull()
        ->and(FantraxDraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->drafted_at?->toIso8601String())->toBe('2026-06-28T16:01:39+00:00')
        ->and(Draft::query()->where('platform_league_id', $platformLeague->id)->count())->toBe(1)
        ->and(Draft::query()->where('platform_league_id', $platformLeague->id)->first()?->status)->toBe('live')
        ->and(DraftPick::query()->count())->toBe(2)
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->status)->toBe('on_clock');
});

it('detects a new fantrax draft selection when a previously unmade pick receives a player id', function (): void {
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
        ->and($result['new_picks'][0]->fantrax_player_id)->toBe('fantrax-player-1')
        ->and(FantraxDraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->fantrax_player_id)->toBe('fantrax-player-1')
        ->and(FantraxDraftState::query()->where('platform_league_id', $platformLeague->id)->first()?->last_detected_pick_at)->not->toBeNull()
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->provider_player_id)->toBe('fantrax-player-1')
        ->and(DraftPick::query()->where('provider_pick_key', 'overall:1')->first()?->status)->toBe('picked');

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

it('dispatches scheduled fantrax draft polling only for due live draft states', function (): void {
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

    FantraxDraftState::create([
        'platform_league_id' => $liveDuePlatformLeague->id,
        'status' => 'live',
        'poll_interval_minutes' => 1,
        'last_checked_at' => now()->subMinutes(2),
    ]);
    FantraxDraftState::create([
        'platform_league_id' => $liveFreshPlatformLeague->id,
        'status' => 'live',
        'poll_interval_minutes' => 5,
        'last_checked_at' => now(),
    ]);
    FantraxDraftState::create([
        'platform_league_id' => $scheduledPlatformLeague->id,
        'status' => 'scheduled',
        'poll_interval_minutes' => 1,
        'last_checked_at' => now()->subMinutes(2),
    ]);
    FantraxDraftState::create([
        'platform_league_id' => $completePlatformLeague->id,
        'status' => 'complete',
        'poll_interval_minutes' => 1,
        'last_checked_at' => now()->subMinutes(2),
    ]);
    Bus::fake();

    $this->artisan('fantrax:drafts:poll')->assertSuccessful();

    Bus::assertDispatched(SyncFantraxDraftStateJob::class, static fn (SyncFantraxDraftStateJob $job): bool => $job->platformLeagueId === $liveDuePlatformLeague->id);
    Bus::assertDispatchedTimes(SyncFantraxDraftStateJob::class, 1);
});

it('loads community league draft panel from persisted draft payloads when available', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'persisted-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'persisted-draft-league')->firstOrFail();
    FantraxDraftState::create([
        'platform_league_id' => $platformLeague->id,
        'status' => 'live',
        'raw_draft_results' => [
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['playerName' => 'Persisted Pick', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ],
        'raw_draft_pick_info' => [
            'currentDraftPicks' => [['teamId' => 'team-2']],
        ],
    ]);
    Bus::fake();

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Persisted Team'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('Persisted Pick')
        ->assertSee('Persisted Team');

    Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'getDraftResults')
        || str_contains($request->url(), 'getDraftPicks'));
    Bus::assertNotDispatched(SyncFantraxDraftStateJob::class);
});

it('hydrates community league draft panel from fantrax and queues persistence when no draft state exists', function (): void {
    [$user, $organization, $league] = ($this->createCommunityLeague)([
        'platform_league_id' => 'hydrate-draft-league',
    ]);
    $platformLeague = PlatformLeague::query()->where('platform_league_id', 'hydrate-draft-league')->firstOrFail();
    Bus::fake();

    Http::fake([
        'https://www.fantrax.com/fxea/general/getLeagueInfo*' => Http::response([
            'teamInfo' => [
                ['id' => 'team-1', 'name' => 'Hydrated Team'],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftResults*' => Http::response([
            'draftDate' => '2026-09-21 19:00:00',
            'draftPicks' => [
                ['playerName' => 'Hydrated Pick', 'teamId' => 'team-1', 'round' => 1, 'pick' => 1, 'pickInRound' => 1],
            ],
        ]),
        'https://www.fantrax.com/fxea/general/getDraftPicks*' => Http::response([
            'currentDraftPicks' => [['teamId' => 'team-2']],
        ]),
    ]);

    $this->actingAs($user)
        ->get("/communities/{$organization->id}/leagues/{$league->id}")
        ->assertOk()
        ->assertSee('Hydrated Pick')
        ->assertSee('Hydrated Team');

    Bus::assertDispatched(SyncFantraxDraftStateJob::class, static fn (SyncFantraxDraftStateJob $job): bool => $job->platformLeagueId === $platformLeague->id
        && data_get($job->draftResults, 'draftPicks.0.playerName') === 'Hydrated Pick'
        && data_get($job->draftPickInfo, 'currentDraftPicks.0.teamId') === 'team-2');
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
    $draftPick = FantraxDraftPick::create([
        'platform_league_id' => $platformLeague->id,
        'provider_pick_key' => 'overall:1',
        'overall_pick' => 1,
        'round' => 1,
        'pick_in_round' => 1,
        'fantrax_team_id' => 'team-1',
        'fantrax_player_id' => 'fantrax-player-1',
        'payload_hash' => hash('sha256', 'pick'),
    ]);
    FantraxDraftPick::create([
        'platform_league_id' => $platformLeague->id,
        'provider_pick_key' => 'overall:2',
        'overall_pick' => 2,
        'round' => 1,
        'pick_in_round' => 2,
        'fantrax_team_id' => 'team-2',
        'fantrax_player_id' => null,
        'payload_hash' => hash('sha256', 'pick-2'),
    ]);

    Event::fake([FantraxDraftPickToast::class]);
    Http::fake([
        'https://example.test/drafter-avatar.png' => Http::response('', 404),
        'https://example.test/drafted-player.png' => Http::response('', 404),
        'https://discord.com/api/v10/channels/draft-channel/messages' => Http::response(['id' => 'message-1']),
    ]);

    app(AnnounceFantraxDraftPick::class)->handle(FantraxDraftPickMade::fromDraftPick($draftPick));

    $cardExpected = function_exists('imagecreatetruecolor') && function_exists('imagepng');

    if ($cardExpected) {
        Http::assertSent(static function ($request): bool {
            $data = isset($request->data()['payload_json'])
                ? json_decode((string) $request->data()['payload_json'], true)
                : $request->data();

            return $request->url() === 'https://discord.com/api/v10/channels/draft-channel/messages'
                && $request->hasHeader('Authorization', 'Bot bot-token')
                && array_key_exists('payload_json', $request->data())
                && ($data['content'] ?? null) === ''
                && data_get($data, 'allowed_mentions.users') === []
                && empty($data['embeds'] ?? []);
        });
    }

    Http::assertSent(static function ($request): bool {
        $data = isset($request->data()['payload_json'])
            ? json_decode((string) $request->data()['payload_json'], true)
            : $request->data();

        return $request->url() === 'https://discord.com/api/v10/channels/draft-channel/messages'
            && $request->hasHeader('Authorization', 'Bot bot-token')
            && ! array_key_exists('payload_json', $request->data())
            && str_contains((string) ($data['content'] ?? ''), '<@discord-drafter-1> (Northumberland Nitro) selects Drafted Player with pick 1.')
            && str_contains((string) ($data['content'] ?? ''), '<@discord-otc-1> is now OTC.')
            && data_get($data, 'allowed_mentions.users') === ['discord-drafter-1', 'discord-otc-1']
            && empty($data['embeds'] ?? []);
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
    $draftPick = FantraxDraftPick::create([
        'platform_league_id' => $platformLeague->id,
        'provider_pick_key' => 'overall:43',
        'overall_pick' => 43,
        'round' => 3,
        'pick_in_round' => 7,
        'fantrax_team_id' => 'team-1',
        'fantrax_player_id' => 'fantrax-goalie-1',
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

    app(AnnounceFantraxDraftPick::class)->handle(FantraxDraftPickMade::fromDraftPick($draftPick));

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
    $draftPick = FantraxDraftPick::create([
        'platform_league_id' => $platformLeague->id,
        'provider_pick_key' => 'overall:1',
        'overall_pick' => 1,
        'round' => 1,
        'pick_in_round' => 1,
        'fantrax_team_id' => 'team-1',
        'fantrax_player_id' => 'fantrax-player-1',
        'payload_hash' => hash('sha256', 'pick'),
    ]);

    Event::fake([FantraxDraftPickToast::class]);
    Http::fake([
        'https://discord.com/api/v10/channels/draft-channel/messages' => Http::response(['id' => 'message-1']),
    ]);

    $listener = app(AnnounceFantraxDraftPick::class);
    $event = FantraxDraftPickMade::fromDraftPick($draftPick);
    $listener->handle($event);
    $listener->handle($event);

    $expectedDiscordRequestCount = function_exists('imagecreatetruecolor') && function_exists('imagepng') ? 2 : 1;

    Http::assertSentCount($expectedDiscordRequestCount);
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

it('keeps drafted player rows even when the team cannot be resolved', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'results' => [
            ['playerName' => 'Unresolved Team Player'],
        ],
    ]);

    expect($payload['rows'])->toHaveCount(1)
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
            ['playerName' => 'Second Player', 'teamName' => 'Team', 'overallPick' => 2],
            ['playerName' => 'First Player', 'teamName' => 'Team', 'overallPick' => 1],
        ],
    ]);

    expect($payload['rows'][0]['player_name'])->toBe('First Player')
        ->and($payload['rows'][1]['player_name'])->toBe('Second Player');
});

it('segments drafted rows by round pages', function (): void {
    $payload = ($this->normalizeDraft)([], [
        'draftPicks' => [
            ['playerName' => 'Round Two Player', 'teamName' => 'Team', 'round' => 2, 'pick' => 1],
            ['playerName' => 'Round One Player', 'teamName' => 'Team', 'round' => 1, 'pick' => 1],
            ['playerName' => 'Round One Second Player', 'teamName' => 'Team', 'round' => 1, 'pick' => 2],
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
