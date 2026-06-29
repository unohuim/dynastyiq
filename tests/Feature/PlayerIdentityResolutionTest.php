<?php

declare(strict_types=1);

use App\Classes\ImportNHLPlayer as LegacyImportNHLPlayer;
use App\DTO\PlayerIdentityMatchResult;
use App\Events\PlayerExternalIdentityLinked;
use App\Jobs\ImportCapWagesJob;
use App\Jobs\ImportNHLPlayerJob;
use App\Jobs\ImportNhlDraftPicksJob;
use App\Jobs\ImportPlayersJob;
use App\Jobs\RefreshCapWagesContractsForIdentityJob;
use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Jobs\SyncFantraxLeagueJob;
use App\Listeners\QueueCapWagesContractRefresh;
use App\Listeners\QueueNhlIdentityResolution;
use App\Listeners\SyncFantraxRosterMembershipsForLinkedIdentity;
use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\ContractSeason;
use App\Models\FantraxPlayer;
use App\Models\NhlTeam;
use App\Models\PlatformLeague;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\ImportCapWagesPlayer;
use App\Services\ImportFantraxPlayer;
use App\Services\ImportNHLPlayer;
use App\Services\ImportNhlTeams;
use App\Services\NhlPlayerIdentityLookup;
use App\Services\NhlTeamReference;
use App\Services\PlayerIdentityNormalizer;
use App\Services\PlayerIdentityResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-06-26 12:00:00');
    config(['cache.default' => 'array']);

    $this->nhlPayload = static function (array $overrides = []): array {
        return array_replace_recursive([
            'playerId' => 8478402,
            'currentTeamId' => 10,
            'currentTeamAbbrev' => 'TOR',
            'firstName' => ['default' => 'Auston'],
            'lastName' => ['default' => 'Matthews'],
            'birthDate' => '1997-09-17',
            'birthCountry' => 'USA',
            'position' => 'C',
            'headshot' => 'https://assets.nhle.com/headshots/8478402.png',
            'heroImage' => 'https://assets.nhle.com/heroes/8478402.png',
            'seasonTotals' => [
                [
                    'teamName' => ['default' => 'Toronto Maple Leafs'],
                    'season' => 20252026,
                    'gameTypeId' => 2,
                    'sequence' => 1,
                    'leagueAbbrev' => 'NHL',
                    'gamesPlayed' => 82,
                    'avgToi' => '20:00',
                    'goals' => 60,
                    'assists' => 46,
                    'points' => 106,
                    'shots' => 300,
                ],
            ],
        ], $overrides);
    };

    $this->fakeNhlLanding = function (array $payload, string $playerId = '8478402'): void {
        Http::fake([
            "https://api-web.nhle.com/v1/player/{$playerId}/landing" => Http::response($payload),
        ]);
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

    $this->fantraxEntry = static function (array $overrides = []): array {
        return array_merge([
            'fantraxId' => 'fantrax-1',
            'name' => 'Player, Test',
            'team' => 'ANA',
            'position' => 'C',
            'birthDate' => '1990-01-01',
        ], $overrides);
    };

    $this->capWagesPayload = static function (array $overrides = []): array {
        return array_replace_recursive([
            'nhlId' => 123456,
            'name' => 'Test Player',
            'birthDate' => '1990-01-01',
            'position' => 'C',
            'team' => 'ANA',
            'contracts' => [
                [
                    'signingDate' => '2026-07-01',
                    'contractType' => 'Standard',
                    'contractLength' => '2 years',
                    'contractValue' => 2000000,
                    'expiryStatus' => 'UFA',
                    'signingTeam' => 'ANA',
                    'signedBy' => 'Club',
                    'seasons' => [
                        [
                            'season' => '2026-27',
                            'clause' => null,
                            'capHit' => 1000000,
                            'aav' => 1000000,
                            'performanceBonuses' => 0,
                            'signingBonuses' => 0,
                            'baseSalary' => 1000000,
                            'totalSalary' => 1000000,
                            'minorsSalary' => 100000,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    };

    $this->fakeCapWagesPlayer = static function (string $slug, array $payload): void {
        Http::fake([
            "https://capwages.com/api/gateway/v1/players/{$slug}" => Http::response([
                'data' => $payload,
                'meta' => ['lastUpdated' => '2026-06-01T12:00:00.000Z'],
            ]),
        ]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

it('upserts an nhl external identity when importing an nhl player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->provider)->toBe(PlayerExternalIdentity::PROVIDER_NHL);
});

it('does not duplicate an nhl identity when reimporting the same player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');
    app(ImportNHLPlayer::class)->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(Player::query()->count())->toBe(1);
});

it('creates a canonical player for a new nhl authority identity', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $player = Player::first();

    expect($player)->not->toBeNull();
    expect($player->nhl_id)->toBe(8478402);
    expect($player->full_name)->toBe('Auston Matthews');
});

it('resolves canonical nhl team id from abbrev when player landing omits team id', function () {
    NhlTeam::create([
        'nhl_id' => 10,
        'abbrev' => 'TOR',
        'full_name' => 'Toronto Maple Leafs',
    ]);
    ($this->fakeNhlLanding)(($this->nhlPayload)([
        'currentTeamId' => null,
        'currentTeamAbbrev' => 'TOR',
    ]));

    app(ImportNHLPlayer::class)->import('8478402');

    $player = Player::first();

    expect($player->team_abbrev)->toBe('TOR');
    expect($player->nhl_team_id)->toBe(10);
});

it('links the nhl identity to the created canonical player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->player_id)->toBe(Player::first()->id);
    expect($identity->player->full_name)->toBe('Auston Matthews');
});

it('updates a linked canonical player on reimport', function () {
    Http::fake([
        'https://api-web.nhle.com/v1/player/8478402/landing' => Http::sequence()
            ->push(($this->nhlPayload)())
            ->push(($this->nhlPayload)([
                'currentTeamAbbrev' => 'UTA',
                'currentTeamId' => 59,
            ])),
    ]);

    app(ImportNHLPlayer::class)->import('8478402');
    app(ImportNHLPlayer::class)->import('8478402');

    $player = Player::first();

    expect($player->team_abbrev)->toBe('UTA');
    expect($player->nhl_team_id)->toBe(59);
});

it('preserves the raw nhl payload on the identity', function () {
    $payload = ($this->nhlPayload)([
        'heroImage' => 'https://example.test/hero.png',
    ]);
    ($this->fakeNhlLanding)($payload);

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->raw_payload['playerId'])->toBe(8478402);
    expect($identity->raw_payload['heroImage'])->toBe('https://example.test/hero.png');
});

it('stores matched status and confidence after import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(100);
    expect($identity->unmatched_reason)->toBeNull();
});

it('sets first and last seen timestamps on first import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->first_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
    expect($identity->last_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
});

it('preserves first seen and refreshes last seen on reimport', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    Carbon::setTestNow('2026-06-26 13:30:00');
    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->first_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
    expect($identity->last_seen_at->toDateTimeString())->toBe('2026-06-26 13:30:00');
});

it('normalizes accented names for identity matching', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('Émile Bouchard'))->toBe('emile bouchard');
});

it('normalizes punctuation and spacing for identity matching', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('  Jean-Luc   O\'Neill Jr.  '))->toBe('jean luc o neill jr');
});

it('normalizes empty names to null', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('   '))->toBeNull();
});

it('reports status counts by provider', function () {
    PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '1',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '2',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $counts = app(PlayerIdentityResolver::class)->statusCountsByProvider(PlayerExternalIdentity::PROVIDER_NHL);

    expect($counts[PlayerExternalIdentity::PROVIDER_NHL][PlayerExternalIdentity::STATUS_MATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_NHL][PlayerExternalIdentity::STATUS_UNMATCHED])->toBe(1);
});

it('allows unmatched identity reasons to be persisted', function () {
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-1',
    ]);

    app(PlayerIdentityResolver::class)->applyMatchResult(
        $identity,
        PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER),
    );

    expect($identity->refresh()->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER);
});

it('throws when an nhl identity payload misses the provider player id', function () {
    app(PlayerIdentityResolver::class)->upsertNhlIdentity([
        'firstName' => ['default' => 'Missing'],
        'lastName' => ['default' => 'Identifier'],
    ]);
})->throws(\InvalidArgumentException::class);

it('rejects matched identity results without a canonical player reference', function () {
    new PlayerIdentityMatchResult(PlayerExternalIdentity::STATUS_MATCHED);
})->throws(\InvalidArgumentException::class);

it('rejects undocumented identity match statuses', function () {
    new PlayerIdentityMatchResult('review-needed');
})->throws(\InvalidArgumentException::class);

it('rejects identity match confidence outside the percentage range', function () {
    PlayerIdentityMatchResult::matched(123, 101);
})->throws(\InvalidArgumentException::class);

it('keeps the prospect flag when importing a prospect', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402', true);

    expect((bool)Player::first()->is_prospect)->toBeTrue();
});

it('updates current league from prospect stats', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)([
        'seasonTotals' => [
            [
                'teamName' => ['default' => 'Marlies'],
                'season' => 20252026,
                'gameTypeId' => 2,
                'sequence' => 1,
                'leagueAbbrev' => 'AHL',
                'gamesPlayed' => 10,
                'avgToi' => '18:00',
            ],
        ],
    ]));

    app(ImportNHLPlayer::class)->import('8478402', true);

    expect(Player::first()->current_league_abbrev)->toBe('AHL');
});

it('service playerExists returns true after import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    expect(ImportNHLPlayer::playerExists('8478402'))->toBeTrue();
});

it('legacy import wrapper delegates to the identity workflow', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    (new LegacyImportNHLPlayer())->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
});

it('queued nhl player job uses the identity workflow', function () {
    Event::fake();
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    (new ImportNHLPlayerJob('8478402'))->handle();

    expect(Player::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::query()->count())->toBe(1);
});

it('fantrax import upserts an external identity', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());

    $identity = PlayerExternalIdentity::first();

    expect($identity->provider)->toBe(PlayerExternalIdentity::PROVIDER_FANTRAX);
    expect($identity->provider_player_id)->toBe('fantrax-1');
    expect($identity->raw_payload['name'])->toBe('Player, Test');
    expect(FantraxPlayer::query()->count())->toBe(1);
});

it('fantrax import skips team aggregate rows before identity upsert', function () {
    (new ImportFantraxPlayer())->syncOne([
        'teamName' => 'Detroit Red Wings',
        'name' => 'Team',
        'fantraxId' => '30170#2050',
        'teamShortName' => 'DET',
        'position' => 'Tm',
        'shortName' => 'Tm',
    ]);

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(FantraxPlayer::query()->count())->toBe(0);
});

it('fantrax import skips rows with team aggregate position markers', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'name' => 'Not Team',
        'position' => 'Tm',
        'shortName' => 'Player',
    ]));

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(FantraxPlayer::query()->count())->toBe(0);
});

it('fantrax known provider id links to an existing canonical player', function () {
    $player = ($this->makePlayer)();
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-1',
        'player_id' => $player->id,
        'name' => 'Player, Test',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['birthDate' => null]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->player_id)->toBe($player->id);
    expect(FantraxPlayer::first()->player_id)->toBe($player->id);
});

it('fantrax exact normalized name and birthdate auto-links', function () {
    $player = ($this->makePlayer)();

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($player->id);
});

it('fantrax ambiguous threshold-passing names become conflicts', function () {
    ($this->makePlayer)(['dob' => '1990-01-01']);
    ($this->makePlayer)(['dob' => '1991-01-01']);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['birthDate' => null]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CONFLICT);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES);
    expect($identity->player_id)->toBeNull();
});

it('fantrax exact normalized name only remains a candidate below the provider threshold', function () {
    ($this->makePlayer)([
        'position' => 'G',
        'team_abbrev' => 'NJD',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'birthDate' => null,
        'position' => null,
        'team' => null,
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CANDIDATE);
    expect($identity->match_confidence)->toBe(75);
    expect($identity->player_id)->toBeNull();
});

it('fantrax exact normalized name plus position type auto-links at the provider threshold', function () {
    $player = ($this->makePlayer)([
        'position' => 'C',
        'team_abbrev' => 'NJD',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'birthDate' => null,
        'position' => 'L',
        'team' => null,
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(85);
    expect($identity->player_id)->toBe($player->id);
});

it('nhl provider identities auto-link at the name plus position type threshold when resolver scoring is used', function () {
    $player = ($this->makePlayer)([
        'position' => 'RW',
        'team_abbrev' => 'NJD',
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => 'nhl-scored-1',
        'display_name' => 'Test Player',
        'normalized_name' => 'test player',
        'position' => 'C',
        'match_status' => PlayerExternalIdentity::STATUS_CANDIDATE,
    ]);

    app(PlayerIdentityResolver::class)->resolveNonAuthorityIdentity($identity);

    $identity->refresh();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(85);
    expect($identity->player_id)->toBe($player->id);
});

it('fantrax exact normalized name plus position and team auto-links as name plus plus', function () {
    $player = ($this->makePlayer)([
        'first_name' => 'Akira',
        'last_name' => 'Schmid',
        'full_name' => 'Akira Schmid',
        'position' => 'G',
        'team_abbrev' => 'VGK',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'name' => 'Schmid, Akira',
        'birthDate' => null,
        'position' => 'G',
        'team' => 'VGK',
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($player->id);
});

it('fantrax supporting evidence can disambiguate one threshold-passing candidate', function () {
    $matchedPlayer = ($this->makePlayer)([
        'first_name' => 'Shared',
        'last_name' => 'Goalie',
        'full_name' => 'Shared Goalie',
        'position' => 'G',
        'team_abbrev' => 'VGK',
    ]);
    ($this->makePlayer)([
        'first_name' => 'Shared',
        'last_name' => 'Goalie',
        'full_name' => 'Shared Goalie',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'name' => 'Goalie, Shared',
        'birthDate' => null,
        'position' => 'G',
        'team' => 'VGK',
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($matchedPlayer->id);
});

it('fantrax ignores wrong position type candidates when deciding multiple candidates', function () {
    $matchedPlayer = ($this->makePlayer)([
        'first_name' => 'Position',
        'last_name' => 'Conflict',
        'full_name' => 'Position Conflict',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    ($this->makePlayer)([
        'first_name' => 'Position',
        'last_name' => 'Conflict',
        'full_name' => 'Position Conflict',
        'position' => 'D',
        'team_abbrev' => 'ANA',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'name' => 'Conflict, Position',
        'birthDate' => null,
        'position' => 'LW',
        'team' => 'ANA',
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($matchedPlayer->id);
});

it('fantrax still conflicts when multiple same position type candidates qualify', function () {
    ($this->makePlayer)([
        'first_name' => 'True',
        'last_name' => 'Conflict',
        'full_name' => 'True Conflict',
        'position' => 'C',
        'team_abbrev' => 'ANA',
    ]);
    ($this->makePlayer)([
        'first_name' => 'True',
        'last_name' => 'Conflict',
        'full_name' => 'True Conflict',
        'position' => 'RW',
        'team_abbrev' => 'ANA',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'name' => 'Conflict, True',
        'birthDate' => null,
        'position' => 'LW',
        'team' => 'ANA',
    ]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CONFLICT);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES);
    expect($identity->player_id)->toBeNull();
});

it('capwages name-only provider identities auto-link at the lower provider threshold', function () {
    $player = ($this->makePlayer)();
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
        'birthDate' => null,
        'position' => null,
        'team' => null,
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(75);
    expect($identity->player_id)->toBe($player->id);
});

it('capwages ignores wrong position type candidates when deciding multiple candidates', function () {
    $matchedPlayer = ($this->makePlayer)([
        'first_name' => 'Cap',
        'last_name' => 'Conflict',
        'full_name' => 'Cap Conflict',
        'position' => 'D',
        'team_abbrev' => null,
    ]);
    ($this->makePlayer)([
        'first_name' => 'Cap',
        'last_name' => 'Conflict',
        'full_name' => 'Cap Conflict',
        'position' => 'G',
        'team_abbrev' => null,
    ]);
    ($this->fakeCapWagesPlayer)('cap-conflict', ($this->capWagesPayload)([
        'name' => 'Cap Conflict',
        'nhlId' => null,
        'birthDate' => null,
        'position' => 'LD',
        'team' => null,
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('cap-conflict', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(85);
    expect($identity->player_id)->toBe($matchedPlayer->id);
});

it('capwages exact normalized name plus position type persists an auto-link at 85', function () {
    $player = ($this->makePlayer)([
        'position' => 'RW',
        'team_abbrev' => null,
    ]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
        'birthDate' => null,
        'position' => 'R',
        'team' => null,
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(85);
    expect($identity->player_id)->toBe($player->id);
});

it('imports nhl team references from the nhl stats endpoint', function () {
    Http::fake([
        'https://api.nhle.com/stats/rest/en/team' => Http::response([
            'data' => [
                [
                    'id' => 10,
                    'triCode' => 'TOR',
                    'fullName' => 'Toronto Maple Leafs',
                    'teamName' => 'Maple Leafs',
                    'placeName' => 'Toronto',
                ],
            ],
        ]),
    ]);

    $count = app(ImportNhlTeams::class)->sync();

    $team = NhlTeam::first();

    expect($count)->toBe(1);
    expect($team->nhl_id)->toBe(10);
    expect($team->abbrev)->toBe('TOR');
    expect($team->full_name)->toBe('Toronto Maple Leafs');
    expect($team->raw_payload['triCode'])->toBe('TOR');
});

it('nhl team import updates duplicate abbrev rows from the stats endpoint', function () {
    Http::fake([
        'https://api.nhle.com/stats/rest/en/team' => Http::response([
            'data' => [
                [
                    'id' => 58,
                    'triCode' => 'UTA',
                    'fullName' => 'Utah Hockey Club',
                ],
                [
                    'id' => 59,
                    'triCode' => 'UTA',
                    'fullName' => 'Utah Mammoth',
                ],
            ],
        ]),
    ]);

    $count = app(ImportNhlTeams::class)->sync();

    $team = NhlTeam::first();

    expect($count)->toBe(2);
    expect(NhlTeam::query()->count())->toBe(1);
    expect($team->abbrev)->toBe('UTA');
    expect($team->nhl_id)->toBe(59);
    expect($team->full_name)->toBe('Utah Mammoth');
});

it('nhl player import command dispatches team jobs from nhl team reference rows', function () {
    Bus::fake();
    Http::fake([
        'https://api.nhle.com/stats/rest/en/team' => Http::response([
            'data' => [
                [
                    'id' => 24,
                    'triCode' => 'ANA',
                    'fullName' => 'Anaheim Ducks',
                ],
                [
                    'id' => 10,
                    'triCode' => 'TOR',
                    'fullName' => 'Toronto Maple Leafs',
                ],
            ],
        ]),
    ]);

    Artisan::call('nhl:import', ['--players' => true]);

    $runIds = [];
    Bus::assertBatched(function ($batch) use (&$runIds): bool {
        $teams = [];

        foreach ($batch->jobs as $job) {
            if (! $job instanceof ImportPlayersJob) {
                continue;
            }

            $team = new ReflectionProperty($job, 'teamAbbrev');
            $run = new ReflectionProperty($job, 'importRunId');
            $teams[] = $team->getValue($job);
            $runIds[] = $run->getValue($job);
        }

        sort($teams);

        return $batch->name === 'NHLImport:PlayersThenDraftPicks'
            && $teams === ['ANA', 'TOR'];
    });
    Bus::assertNotDispatched(ImportNhlDraftPicksJob::class);

    expect(NhlTeam::query()->pluck('abbrev')->sort()->values()->all())->toBe(['ANA', 'TOR']);
    expect(array_unique($runIds))->toHaveCount(1);
});

it('nhl draft import ignores player ids and creates one draft-only prospect per name position', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    config(['apiImportNhl.draft_years_back' => 1]);
    Http::fake([
        'https://api-web.nhle.com/v1/draft/picks/2026/all' => Http::response([
            'picks' => [
                [
                    'overallPick' => 1,
                    'playerId' => 900001,
                    'name' => 'Resolvable Pick',
                    'teamAbbrev' => 'ANA',
                    'position' => 'C',
                ],
                [
                    'overallPick' => 2,
                    'playerId' => 900001,
                    'name' => 'Resolvable Pick',
                    'teamAbbrev' => 'ANA',
                    'position' => 'C',
                ],
            ],
        ]),
    ]);

    (new ImportNhlDraftPicksJob('draft-run'))->handle(
        app(PlayerIdentityResolver::class),
        app(PlayerIdentityNormalizer::class),
        app(NhlTeamReference::class),
    );

    Queue::assertNotPushed(ImportNHLPlayerJob::class);
    expect(Player::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(Player::first()->full_name)->toBe('Resolvable Pick');
    expect(PlayerExternalIdentity::first()->provider_player_id)->toBe('2026:1');
});

it('nhl draft import skips name position fingerprints already discovered in the same import run', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    config(['apiImportNhl.draft_years_back' => 1]);
    Http::fake([
        'https://api-web.nhle.com/v1/roster/ANA/current' => Http::response([
            'forwards' => [
                [
                    'id' => 900001,
                    'firstName' => ['default' => 'Already'],
                    'lastName' => ['default' => 'Seen Pick'],
                    'positionCode' => 'C',
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/roster/ANA/20242025' => Http::response([]),
        'https://api-web.nhle.com/v1/prospects/ANA' => Http::response([]),
        'https://api-web.nhle.com/v1/draft/picks/2026/all' => Http::response([
            'picks' => [
                [
                    'overallPick' => 1,
                    'firstName' => ['default' => 'Already'],
                    'lastName' => ['default' => 'Seen Pick'],
                    'teamAbbrev' => 'ANA',
                    'positionCode' => 'C',
                ],
            ],
        ]),
    ]);

    (new ImportPlayersJob('ANA', 'shared-run'))->handle();
    (new ImportNhlDraftPicksJob('shared-run'))->handle(
        app(PlayerIdentityResolver::class),
        app(PlayerIdentityNormalizer::class),
        app(NhlTeamReference::class),
    );

    Queue::assertPushed(ImportNHLPlayerJob::class, 1);
    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(Player::query()->count())->toBe(0);
});

it('nhl draft import creates a minimal canonical prospect for picks without nhl player ids', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    config(['apiImportNhl.draft_years_back' => 1]);
    NhlTeam::create([
        'nhl_id' => 24,
        'abbrev' => 'ANA',
        'full_name' => 'Anaheim Ducks',
    ]);
    Http::fake([
        'https://api-web.nhle.com/v1/draft/picks/2026/all' => Http::response([
            'picks' => [
                [
                    'overallPick' => 12,
                    'name' => 'Draft Only Defender',
                    'teamId' => 24,
                    'teamAbbrev' => 'ANA',
                    'position' => 'LD',
                    'countryCode' => 'CAN',
                    'amateurLeague' => 'OHL',
                ],
            ],
        ]),
    ]);

    (new ImportNhlDraftPicksJob('draft-only-run'))->handle(
        app(PlayerIdentityResolver::class),
        app(PlayerIdentityNormalizer::class),
        app(NhlTeamReference::class),
    );

    $player = Player::first();
    $identity = PlayerExternalIdentity::first();

    Queue::assertNotPushed(ImportNHLPlayerJob::class);
    expect($player->nhl_id)->toBeNull();
    expect($player->nhl_team_id)->toBe(24);
    expect($player->full_name)->toBe('Draft Only Defender');
    expect($player->team_abbrev)->toBe('ANA');
    expect($player->country_code)->toBe('CAN');
    expect($player->position)->toBe('LD');
    expect($player->pos_type)->toBe('D');
    expect($player->is_prospect)->toBeTrue();
    expect($identity->provider)->toBe(PlayerExternalIdentity::PROVIDER_NHL_DRAFT);
    expect($identity->provider_player_id)->toBe('2026:12');
    expect($identity->player_id)->toBe($player->id);
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
});

it('nhl draft import links an existing player by name and position type before creating a prospect', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    config(['apiImportNhl.draft_years_back' => 1]);

    $existingPlayer = ($this->makePlayer)([
        'nhl_id' => 8482763,
        'nhl_team_id' => 6,
        'team_abbrev' => 'BOS',
        'first_name' => 'Fabian',
        'last_name' => 'Lysell',
        'full_name' => 'Fabian Lysell',
        'position' => 'R',
        'pos_type' => 'F',
    ]);

    Http::fake([
        'https://api-web.nhle.com/v1/draft/picks/2026/all' => Http::response([
            'picks' => [
                [
                    'round' => 1,
                    'pickInRound' => 21,
                    'overallPick' => 21,
                    'teamId' => 6,
                    'teamAbbrev' => 'BOS',
                    'firstName' => ['default' => 'Fabian'],
                    'lastName' => ['default' => 'Lysell'],
                    'positionCode' => 'RW',
                    'countryCode' => 'SWE',
                    'amateurLeague' => 'SWEDEN',
                ],
            ],
        ]),
    ]);

    (new ImportNhlDraftPicksJob('draft-existing-run'))->handle(
        app(PlayerIdentityResolver::class),
        app(PlayerIdentityNormalizer::class),
        app(NhlTeamReference::class),
    );

    $identity = PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL_DRAFT)
        ->firstOrFail();

    Queue::assertNotPushed(ImportNHLPlayerJob::class);
    expect(Player::query()->count())->toBe(1);
    expect($identity->provider_player_id)->toBe('2026:21');
    expect($identity->player_id)->toBe($existingPlayer->id);
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
});

it('nhl player import job discovers roster and prospect players once per run', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    Http::fake([
        'https://api-web.nhle.com/v1/roster/ANA/current' => Http::response([
            'forwards' => [
                [
                    'id' => 1001,
                    'firstName' => ['default' => 'Roster'],
                    'lastName' => ['default' => 'Player'],
                    'positionCode' => 'C',
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/roster/ANA/20242025' => Http::response([
            'forwards' => [
                [
                    'id' => 1001,
                    'firstName' => ['default' => 'Roster'],
                    'lastName' => ['default' => 'Player'],
                    'positionCode' => 'C',
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/prospects/ANA' => Http::response([
            'forwards' => [
                [
                    'id' => 1001,
                    'firstName' => ['default' => 'Roster'],
                    'lastName' => ['default' => 'Player'],
                    'positionCode' => 'C',
                ],
            ],
            'defensemen' => [
                [
                    'id' => 2002,
                    'firstName' => ['default' => 'Prospect'],
                    'lastName' => ['default' => 'Player'],
                    'positionCode' => 'D',
                ],
            ],
        ]),
    ]);

    (new ImportPlayersJob('ANA', 'test-run'))->handle();

    Queue::assertPushed(ImportNHLPlayerJob::class, 2);
    Queue::assertPushed(ImportNHLPlayerJob::class, function (ImportNHLPlayerJob $job): bool {
        $playerId = new ReflectionProperty($job, 'playerId');
        $isProspect = new ReflectionProperty($job, 'isProspect');

        return $playerId->getValue($job) === '1001'
            && $isProspect->getValue($job) === false;
    });
    Queue::assertPushed(ImportNHLPlayerJob::class, function (ImportNHLPlayerJob $job): bool {
        $playerId = new ReflectionProperty($job, 'playerId');
        $isProspect = new ReflectionProperty($job, 'isProspect');

        return $playerId->getValue($job) === '2002'
            && $isProspect->getValue($job) === true;
    });
});

it('capwages full team names normalize through nhl teams for name plus plus scoring', function () {
    NhlTeam::create([
        'nhl_id' => 24,
        'abbrev' => 'ANA',
        'full_name' => 'Anaheim Ducks',
        'common_name' => 'Ducks',
        'place_name' => 'Anaheim',
    ]);
    $player = ($this->makePlayer)([
        'position' => 'RW',
        'team_abbrev' => 'ANA',
        'dob' => null,
    ]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
        'birthDate' => null,
        'position' => 'R',
        'team' => 'Anaheim Ducks',
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->team)->toBe('ANA');
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($player->id);
});

it('capwages nested personal info birthdate contributes plus plus scoring', function () {
    $player = ($this->makePlayer)([
        'position' => 'C',
        'team_abbrev' => null,
        'dob' => '1990-01-01',
    ]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
        'birthDate' => null,
        'team' => null,
        'personalInfo' => [
            'birthDate' => '1990-01-01',
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->birthdate?->toDateString())->toBe('1990-01-01');
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($player->id);
});

it('fantrax insufficient name data remains unmatched with a reason', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['name' => '']));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_UNMATCHED);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_PROVIDER_PAYLOAD_MISSING_NAME);
    expect(FantraxPlayer::first()->player_id)->toBeNull();
});

it('fantrax import remains idempotent for identity and legacy player rows', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['team' => 'BOS']));

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(FantraxPlayer::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->team)->toBe('BOS');
});

it('capwages import creates a canonical non-prospect player for eligible contract identities without a match', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();
    $player = Player::first();

    expect($identity->provider)->toBe(PlayerExternalIdentity::PROVIDER_CAPWAGES);
    expect($identity->provider_player_id)->toBe('test-player');
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->player_id)->toBe($player->id);
    expect($player->nhl_id)->toBeNull();
    expect($player->full_name)->toBe('Test Player');
    expect($player->is_prospect)->toBeFalse();
    expect($player->current_league_abbrev)->toBeNull();
    expect(CapWagesPlayer::first()->player_id)->toBe($player->id);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('capwages-created canonical players keep capwages profile context', function () {
    NhlTeam::create([
        'nhl_id' => 24,
        'abbrev' => 'ANA',
        'full_name' => 'Anaheim Ducks',
    ]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'nhlId' => null,
        'leagueStatus' => 'Minor',
        'team' => 'Anaheim Ducks',
        'personalInfo' => [
            'birthDate' => '1990-01-01',
            'birthPlace' => 'Toronto, ON, CAN',
            'nationality' => 'CAN',
        ],
        'physicalAttributes' => [
            'hand' => 'Left',
            'height' => [
                'imperial' => '6\'1"',
                'metric' => 185,
            ],
            'weight' => [
                'imperial' => '194 lbs',
                'metric' => 88,
            ],
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $player = Player::first();

    expect($player->nhl_id)->toBeNull();
    expect($player->nhl_team_id)->toBe(24);
    expect($player->team_abbrev)->toBe('ANA');
    expect((string) $player->dob)->toBe('1990-01-01');
    expect($player->country_code)->toBe('CAN');
    expect($player->height)->toBe('6\'1"');
    expect($player->weight)->toBe(194);
    expect($player->shoots)->toBe('L');
    expect($player->current_league_abbrev)->toBe('Minor');
    expect($player->is_prospect)->toBeFalse();
});

it('capwages durable NHL id links to an existing NHL player despite first-name variation', function () {
    $existing = ($this->makePlayer)([
        'nhl_id' => 8485661,
        'first_name' => 'Alexander',
        'last_name' => 'Weiermair',
        'full_name' => 'Alexander Weiermair',
        'dob' => '2005-05-10',
        'country_code' => 'USA',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => 'VGK',
    ]);

    ($this->fakeCapWagesPlayer)('alex-weiermair', ($this->capWagesPayload)([
        'nhlId' => 8485661,
        'name' => 'Alex Weiermair',
        'firstName' => 'Alex',
        'lastName' => 'Weiermair',
        'birthDate' => '2005-05-10',
        'position' => 'C',
        'team' => 'VGK',
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('alex-weiermair', false);

    $identity = PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_CAPWAGES)
        ->where('provider_player_id', '8485661')
        ->firstOrFail();

    expect(Player::query()->count())->toBe(1);
    expect($identity->player_id)->toBe($existing->id);
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect(CapWagesPlayer::first()->player_id)->toBe($existing->id);
});

it('capwages durable NHL id does not create a null-id canonical player when the NHL row is missing', function () {
    Queue::fake([ImportNHLPlayerJob::class]);
    ($this->fakeCapWagesPlayer)('alex-weiermair', ($this->capWagesPayload)([
        'nhlId' => 8485661,
        'name' => 'Alex Weiermair',
        'firstName' => 'Alex',
        'lastName' => 'Weiermair',
        'birthDate' => '2005-05-10',
        'position' => 'C',
        'team' => 'VGK',
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('alex-weiermair', false);

    expect(Player::query()->count())->toBe(0);
    expect(CapWagesPlayer::query()->count())->toBe(1);
    expect(CapWagesPlayer::first()->player_id)->toBeNull();
    expect(PlayerExternalIdentity::first()->match_status)->toBe(PlayerExternalIdentity::STATUS_UNMATCHED);
    expect(PlayerExternalIdentity::first()->unmatched_reason)
        ->toBe(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER);
});

it('capwages import skips identities with no contract seasons', function () {
    $payload = ($this->capWagesPayload)();
    $payload['contracts'] = [];
    ($this->fakeCapWagesPlayer)('test-player', $payload);

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(Contract::query()->count())->toBe(0);
    expect(ContractSeason::query()->count())->toBe(0);
    expect(CapWagesPlayer::query()->count())->toBe(0);
});

it('capwages import preserves provider access failures instead of labeling them not found', function () {
    Http::fake([
        'https://capwages.com/api/gateway/v1/players/blocked-player' => Http::response('<html>blocked</html>', 403),
    ]);

    (new ImportCapWagesPlayer())->syncBySlug('blocked-player', false);
})->throws(RequestException::class);

it('capwages import skips identities whose latest contract season is before the current season key', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'contracts' => [
            [
                'signingDate' => '2024-07-01',
                'contractType' => 'Standard',
                'contractLength' => '1 year',
                'contractValue' => 1000000,
                'expiryStatus' => 'UFA',
                'signingTeam' => 'ANA',
                'signedBy' => 'Club',
                'seasons' => [
                    [
                        'season' => '2024-25',
                        'capHit' => 1000000,
                        'aav' => 1000000,
                    ],
                ],
            ],
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(Contract::query()->count())->toBe(0);
    expect(ContractSeason::query()->count())->toBe(0);
    expect(CapWagesPlayer::query()->count())->toBe(0);
});

it('capwages import ignores current season buyout rows when deciding import eligibility', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'leagueStatus' => 'Retired',
        'contracts' => [
            [
                'signingDate' => '2015-07-01',
                'contractType' => 'Standard',
                'contractLength' => '1 year',
                'contractValue' => 3000000,
                'expiryStatus' => 'UFA',
                'signingTeam' => 'ANA',
                'signedBy' => 'Club',
                'seasons' => [
                    [
                        'season' => '2015-16',
                        'capHit' => 3000000,
                        'aav' => 3000000,
                        'baseSalary' => 3000000,
                        'totalSalary' => 3000000,
                        'minorsSalary' => 3000000,
                    ],
                    [
                        'season' => '2025-26',
                        'capHit' => 0,
                        'aav' => 0,
                        'baseSalary' => 0,
                        'totalSalary' => 0,
                        'minorsSalary' => 0,
                        'buyout' => [
                            'teamName' => 'Anaheim Ducks',
                            'cost' => 1000000,
                            'earning' => 1000000,
                            'savings' => -1000000,
                            'capHit' => 0,
                        ],
                    ],
                ],
            ],
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    expect(Player::query()->count())->toBe(0);
    expect(Contract::query()->count())->toBe(0);
    expect(ContractSeason::query()->count())->toBe(0);
    expect(CapWagesPlayer::query()->count())->toBe(0);
});

it('capwages import allows identities whose latest contract season matches the current season key', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'contracts' => [
            [
                'signingDate' => '2025-07-01',
                'contractType' => 'Standard',
                'contractLength' => '1 year',
                'contractValue' => 1000000,
                'expiryStatus' => 'UFA',
                'signingTeam' => 'ANA',
                'signedBy' => 'Club',
                'seasons' => [
                    [
                        'season' => '2025-26',
                        'capHit' => 1000000,
                        'aav' => 1000000,
                    ],
                ],
            ],
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(CapWagesPlayer::query()->count())->toBe(1);
});

it('capwages import stores provider profile data for eligible identities', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'name' => 'CapWages Profile Player',
        'slug' => 'test-player',
        'team' => 'Anaheim Ducks',
        'position' => 'C',
        'leagueStatus' => 'NHL',
        'nhlId' => 123456,
        'jerseyNumber' => 91,
        'personalInfo' => [
            'birthDate' => '1990-01-01',
            'birthPlace' => 'Toronto, ON, CAN',
            'nationality' => 'CAN',
        ],
        'physicalAttributes' => [
            'hand' => 'Left',
            'height' => [
                'imperial' => '6\'1"',
                'metric' => 185,
            ],
            'weight' => [
                'imperial' => '194 lbs',
                'metric' => 88,
            ],
        ],
        'acquisition' => [
            'method' => 'Draft',
            'details' => '2020 Round 1, #1 Overall',
            'year' => 'Undrafted',
            'round' => 1,
            'overallPick' => 1,
            'draftTeam' => 'ANA',
        ],
        'ageLimits' => [
            'entryLevelContractSigningAge' => 18,
            'waiversEligibilityAge' => 18,
        ],
    ]));

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();
    $capWagesPlayer = CapWagesPlayer::first();
    $player = Player::first();

    expect($capWagesPlayer->player_external_identity_id)->toBe($identity->id);
    expect($capWagesPlayer->player_id)->toBe($player->id);
    expect($capWagesPlayer->slug)->toBe('test-player');
    expect($capWagesPlayer->name)->toBe('CapWages Profile Player');
    expect($capWagesPlayer->league_status)->toBe('NHL');
    expect($capWagesPlayer->nhl_id)->toBe(123456);
    expect($capWagesPlayer->jersey_number)->toBe(91);
    expect($capWagesPlayer->birth_date?->toDateString())->toBe('1990-01-01');
    expect($capWagesPlayer->birth_place)->toBe('Toronto, ON, CAN');
    expect($capWagesPlayer->nationality)->toBe('CAN');
    expect($capWagesPlayer->hand)->toBe('Left');
    expect($capWagesPlayer->height_imperial)->toBe('6\'1"');
    expect($capWagesPlayer->height_cm)->toBe(185);
    expect($capWagesPlayer->weight_imperial)->toBe('194 lbs');
    expect($capWagesPlayer->weight_kg)->toBe(88);
    expect($capWagesPlayer->acquisition_method)->toBe('Draft');
    expect($capWagesPlayer->acquisition_year)->toBeNull();
    expect($capWagesPlayer->acquisition_overall_pick)->toBe(1);
    expect($capWagesPlayer->elc_signing_age)->toBe(18);
    expect($capWagesPlayer->waivers_eligibility_age)->toBe(18);
    expect($capWagesPlayer->api_last_updated?->toISOString())->toBe('2026-06-01T12:00:00.000000Z');
    expect($capWagesPlayer->raw_payload['name'])->toBe('CapWages Profile Player');
    expect($capWagesPlayer->raw_payload['acquisition']['year'])->toBe('Undrafted');
});

it('dispatches an identity linked event when an identity player link changes', function () {
    Event::fake([PlayerExternalIdentityLinked::class]);
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-link-event',
        'display_name' => 'Link Event Player',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);

    Event::assertDispatched(
        PlayerExternalIdentityLinked::class,
        fn (PlayerExternalIdentityLinked $event): bool => $event->identity->is($identity)
            && $event->previousPlayerId === null
            && $event->playerId === $player->id,
    );
});

it('does not dispatch an identity linked event when the identity remains on the same player', function () {
    Event::fake([PlayerExternalIdentityLinked::class]);
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-same-link',
        'display_name' => 'Same Link Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);

    Event::assertNotDispatched(PlayerExternalIdentityLinked::class);
});

it('matches fantrax first name y and i ending variants when last name and position type match', function () {
    Queue::fake();
    $player = ($this->makePlayer)([
        'first_name' => 'Dmitri',
        'last_name' => 'Voronkov',
        'full_name' => 'Dmitri Voronkov',
        'position' => 'C',
        'team_abbrev' => 'CBJ',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)([
        'fantraxId' => 'fantrax-voronkov',
        'name' => 'Voronkov, Dmitry',
        'position' => 'C',
        'team' => 'CBJ',
    ]));

    expect(PlayerExternalIdentity::query()->where('provider_player_id', 'fantrax-voronkov')->value('player_id'))
        ->toBe($player->id);
    expect(FantraxPlayer::query()->where('fantrax_id', 'fantrax-voronkov')->value('player_id'))
        ->toBe($player->id);
});

it('updates fantrax player rows and queues league syncs when fantrax identities are manually linked', function () {
    Queue::fake();
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-manual-link',
        'display_name' => 'Manual Link Player',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-manual-link',
        'player_id' => null,
        'name' => 'Manual Link Player',
    ]);
    $league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'fantrax-league',
        'name' => 'Fantrax League',
        'sport' => 'hockey',
    ]);

    (new SyncFantraxRosterMembershipsForLinkedIdentity())->handle(
        new PlayerExternalIdentityLinked($identity, null, $player->id),
    );

    expect(FantraxPlayer::query()->where('fantrax_id', 'fantrax-manual-link')->value('player_id'))
        ->toBe($player->id);
    Queue::assertPushed(
        SyncFantraxLeagueJob::class,
        fn (SyncFantraxLeagueJob $job): bool => $job->platformLeagueId === $league->id,
    );
});

it('queues capwages contract refresh when a capwages identity is linked', function () {
    Queue::fake();
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'test-player',
        'provider_slug' => 'test-player',
        'display_name' => 'Test Player',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);

    Queue::assertPushed(
        RefreshCapWagesContractsForIdentityJob::class,
        fn (RefreshCapWagesContractsForIdentityJob $job): bool => $job->identityId === $identity->id,
    );
});

it('uses cached capwages profile payload without queueing refresh when it already exists', function () {
    Queue::fake();
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'test-player',
        'provider_slug' => 'test-player',
        'display_name' => 'Test Player',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    CapWagesPlayer::create([
        'player_external_identity_id' => $identity->id,
        'slug' => 'test-player',
        'name' => 'Test Player',
        'raw_payload' => ($this->capWagesPayload)(),
    ]);

    app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);

    Queue::assertNotPushed(RefreshCapWagesContractsForIdentityJob::class);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('does not queue capwages contract refresh for non-capwages linked identities', function () {
    Queue::fake();
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-no-contract-refresh',
        'display_name' => 'No Contract Refresh',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    (new QueueCapWagesContractRefresh())->handle(
        new PlayerExternalIdentityLinked($identity, null, $player->id),
    );

    Queue::assertNotPushed(RefreshCapWagesContractsForIdentityJob::class);
});

it('queues NHL identity resolution when a non-NHL identity links to a player without an NHL id', function () {
    Queue::fake();
    $player = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'full_name' => 'Jack Campbell',
        'position' => 'G',
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'jack-campbell',
        'provider_slug' => 'jack-campbell',
        'display_name' => 'Jack Campbell',
        'position' => 'G',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    (new QueueNhlIdentityResolution())->handle(
        new PlayerExternalIdentityLinked($identity, null, $player->id),
    );

    Queue::assertPushed(
        ResolveCanonicalPlayerNhlIdentityJob::class,
        fn (ResolveCanonicalPlayerNhlIdentityJob $job): bool => $job->playerId === $player->id
            && $job->sourceIdentityId === $identity->id,
    );
});

it('does not queue NHL identity resolution when the linked player already has an NHL id', function () {
    Queue::fake();
    $player = ($this->makePlayer)([
        'nhl_id' => 8475789,
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'full_name' => 'Jack Campbell',
        'position' => 'G',
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'jack-campbell',
        'display_name' => 'Jack Campbell',
        'position' => 'G',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    (new QueueNhlIdentityResolution())->handle(
        new PlayerExternalIdentityLinked($identity, null, $player->id),
    );

    Queue::assertNotPushed(ResolveCanonicalPlayerNhlIdentityJob::class);
});

it('resolves a provisional canonical player to one NHL stats player candidate', function () {
    $player = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'full_name' => 'Jack Campbell',
        'position' => 'G',
        'pos_type' => 'G',
        'team_abbrev' => null,
        'nhl_team_id' => null,
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'jack-campbell',
        'provider_slug' => 'jack-campbell',
        'display_name' => 'Jack Campbell',
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'position' => 'G',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => $player->id,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8475789,
                    'firstName' => 'Jack',
                    'lastName' => 'Campbell',
                    'fullName' => 'Jack Campbell',
                    'positionCode' => 'G',
                    'currentTeamId' => null,
                ],
                [
                    'id' => 8459304,
                    'firstName' => 'Jack',
                    'lastName' => 'Williams',
                    'fullName' => 'Jack Williams',
                    'positionCode' => 'R',
                    'currentTeamId' => null,
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/player/8475789/landing' => Http::response([
            'playerId' => 8475789,
            'currentTeamId' => 22,
            'currentTeamAbbrev' => 'EDM',
            'firstName' => ['default' => 'Jack'],
            'lastName' => ['default' => 'Campbell'],
            'birthDate' => '1992-01-09',
            'birthCountry' => 'CAN',
            'position' => 'G',
            'headshot' => 'https://assets.nhle.com/mugs/8475789.png',
            'heroImage' => 'https://assets.nhle.com/action/8475789.jpg',
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($player->id, $identity->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    $player->refresh();
    $nhlIdentity = PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL)
        ->where('provider_player_id', '8475789')
        ->firstOrFail();

    expect(Player::query()->count())->toBe(1);
    expect($player->nhl_id)->toBe(8475789);
    expect((string) $player->dob)->toBe('1992-01-09');
    expect($player->country_code)->toBe('CAN');
    expect($player->position)->toBe('G');
    expect($player->pos_type)->toBe('G');
    expect($player->team_abbrev)->toBe('EDM');
    expect($nhlIdentity->player_id)->toBe($player->id);
    expect($nhlIdentity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
});

it('resolves a canonical player without a source identity to one NHL stats player candidate', function () {
    $player = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'full_name' => 'Jack Campbell',
        'position' => 'G',
        'pos_type' => 'G',
        'team_abbrev' => null,
        'nhl_team_id' => null,
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8475789,
                    'firstName' => 'Jack',
                    'lastName' => 'Campbell',
                    'fullName' => 'Jack Campbell',
                    'positionCode' => 'G',
                    'currentTeamId' => null,
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/player/8475789/landing' => Http::response([
            'playerId' => 8475789,
            'currentTeamId' => 22,
            'currentTeamAbbrev' => 'EDM',
            'firstName' => ['default' => 'Jack'],
            'lastName' => ['default' => 'Campbell'],
            'birthDate' => '1992-01-09',
            'birthCountry' => 'CAN',
            'position' => 'G',
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($player->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    $player->refresh();

    expect(Player::query()->count())->toBe(1);
    expect($player->nhl_id)->toBe(8475789);
    expect($player->team_abbrev)->toBe('EDM');
    expect(PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL)
        ->where('provider_player_id', '8475789')
        ->where('player_id', $player->id)
        ->exists())->toBeTrue();
});

it('filters NHL Stats last-name matches down to the exact current-team player', function () {
    $player = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Theo',
        'last_name' => 'Rochette',
        'full_name' => 'Theo Rochette',
        'position' => 'C',
        'pos_type' => 'F',
        'team_abbrev' => null,
        'nhl_team_id' => null,
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8457833,
                    'currentTeamId' => null,
                    'firstName' => 'Gaetan',
                    'fullName' => 'Gaetan Rochette',
                    'lastName' => 'Rochette',
                    'positionCode' => 'L',
                    'sweaterNumber' => null,
                ],
                [
                    'id' => 8458849,
                    'currentTeamId' => null,
                    'firstName' => 'Eric',
                    'fullName' => 'Eric Rochette',
                    'lastName' => 'Rochette',
                    'positionCode' => 'D',
                    'sweaterNumber' => null,
                ],
                [
                    'id' => 8482184,
                    'currentTeamId' => 17,
                    'firstName' => 'Theo',
                    'fullName' => 'Theo Rochette',
                    'lastName' => 'Rochette',
                    'positionCode' => 'C',
                    'sweaterNumber' => null,
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/player/8482184/landing' => Http::response([
            'playerId' => 8482184,
            'currentTeamId' => 17,
            'currentTeamAbbrev' => 'MTL',
            'firstName' => ['default' => 'Theo'],
            'lastName' => ['default' => 'Rochette'],
            'birthDate' => '2002-02-20',
            'birthCountry' => 'CAN',
            'position' => 'C',
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($player->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    $player->refresh();

    expect($player->nhl_id)->toBe(8482184);
    expect(PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL)
        ->where('provider_player_id', '8482184')
        ->where('player_id', $player->id)
        ->exists())->toBeTrue();
    expect(PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL)
        ->whereIn('provider_player_id', ['8457833', '8458849'])
        ->exists())->toBeFalse();
});

it('does not assign an NHL id already owned by another canonical player', function () {
    $existing = ($this->makePlayer)([
        'nhl_id' => 8486256,
        'first_name' => 'Lavr',
        'last_name' => 'Gashilov',
        'full_name' => 'Lavr Gashilov',
        'position' => 'C',
        'pos_type' => 'F',
    ]);
    $duplicate = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Lavr',
        'last_name' => 'Gashilov',
        'full_name' => 'Lavr Gashilov',
        'position' => 'C',
        'pos_type' => 'F',
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8486256,
                    'firstName' => 'Lavr',
                    'lastName' => 'Gashilov',
                    'fullName' => 'Lavr Gashilov',
                    'positionCode' => 'C',
                    'currentTeamId' => 1,
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/player/8486256/landing' => Http::response([
            'playerId' => 8486256,
            'currentTeamId' => 1,
            'currentTeamAbbrev' => 'NJD',
            'firstName' => ['default' => 'Lavr'],
            'lastName' => ['default' => 'Gashilov'],
            'birthDate' => '2007-09-23',
            'birthCountry' => 'RUS',
            'position' => 'C',
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($duplicate->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    expect($existing->refresh()->nhl_id)->toBe(8486256);
    expect($duplicate->refresh()->nhl_id)->toBeNull();
});

it('marks source identities as conflicts when NHL resolution finds an already owned NHL id', function () {
    ($this->makePlayer)([
        'nhl_id' => 8486256,
        'first_name' => 'Lavr',
        'last_name' => 'Gashilov',
        'full_name' => 'Lavr Gashilov',
        'position' => 'C',
        'pos_type' => 'F',
    ]);
    $duplicate = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Lavr',
        'last_name' => 'Gashilov',
        'full_name' => 'Lavr Gashilov',
        'position' => 'C',
        'pos_type' => 'F',
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'lavr-gashilov',
        'display_name' => 'Lavr Gashilov',
        'first_name' => 'Lavr',
        'last_name' => 'Gashilov',
        'position' => 'C',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => $duplicate->id,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8486256,
                    'firstName' => 'Lavr',
                    'lastName' => 'Gashilov',
                    'fullName' => 'Lavr Gashilov',
                    'positionCode' => 'C',
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/player/8486256/landing' => Http::response([
            'playerId' => 8486256,
            'currentTeamAbbrev' => 'NJD',
            'firstName' => ['default' => 'Lavr'],
            'lastName' => ['default' => 'Gashilov'],
            'position' => 'C',
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($duplicate->id, $identity->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    $identity->refresh();

    expect($duplicate->refresh()->nhl_id)->toBeNull();
    expect($identity->player_id)->toBeNull();
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CONFLICT);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES);
});

it('stores NHL candidate identities without mutating a player when multiple same-name position matches exist', function () {
    $player = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'full_name' => 'Jack Campbell',
        'position' => 'G',
        'pos_type' => 'G',
    ]);
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'jack-campbell',
        'display_name' => 'Jack Campbell',
        'first_name' => 'Jack',
        'last_name' => 'Campbell',
        'position' => 'G',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'player_id' => $player->id,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'https://api.nhle.com/stats/rest/en/players*' => Http::response([
            'data' => [
                [
                    'id' => 8475789,
                    'firstName' => 'Jack',
                    'lastName' => 'Campbell',
                    'fullName' => 'Jack Campbell',
                    'positionCode' => 'G',
                ],
                [
                    'id' => 8499999,
                    'firstName' => 'Jack',
                    'lastName' => 'Campbell',
                    'fullName' => 'Jack Campbell',
                    'positionCode' => 'G',
                ],
            ],
        ]),
    ]);

    (new ResolveCanonicalPlayerNhlIdentityJob($player->id, $identity->id))
        ->handle(app(NhlPlayerIdentityLookup::class));

    $player->refresh();

    expect($player->nhl_id)->toBeNull();
    expect(Player::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::query()->where('provider', PlayerExternalIdentity::PROVIDER_NHL)->count())->toBe(2);
    expect(PlayerExternalIdentity::query()
        ->where('provider', PlayerExternalIdentity::PROVIDER_NHL)
        ->where('match_status', PlayerExternalIdentity::STATUS_CONFLICT)
        ->count())->toBe(2);
});

it('keeps NHL identity resolution jobs unique per canonical player', function () {
    $job = new ResolveCanonicalPlayerNhlIdentityJob(123, 456);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('123');
    expect($job->uniqueFor)->toBe(900);
});

it('queues NHL resolution jobs for canonical players without NHL ids', function () {
    Queue::fake();
    $queued = ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => 'Theo',
        'last_name' => 'Rochette',
        'full_name' => 'Theo Rochette',
    ]);
    ($this->makePlayer)([
        'nhl_id' => null,
        'first_name' => '',
        'last_name' => '',
        'full_name' => 'Single',
    ]);
    ($this->makePlayer)([
        'nhl_id' => 8478402,
        'first_name' => 'Auston',
        'last_name' => 'Matthews',
        'full_name' => 'Auston Matthews',
    ]);

    Artisan::call('nhl:resolve', ['--players' => true]);

    Queue::assertPushed(
        ResolveCanonicalPlayerNhlIdentityJob::class,
        fn (ResolveCanonicalPlayerNhlIdentityJob $job): bool => $job->playerId === $queued->id
            && $job->sourceIdentityId === null,
    );
    Queue::assertPushed(ResolveCanonicalPlayerNhlIdentityJob::class, 1);
});

it('requires a resolver target for the NHL resolve command', function () {
    Queue::fake();

    $exitCode = Artisan::call('nhl:resolve');

    expect($exitCode)->toBe(1);
    Queue::assertNothingPushed();
});

it('refreshes capwages detail and materializes contracts after a delayed identity link', function () {
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'test-player',
        'provider_slug' => 'test-player',
        'display_name' => 'Stale Test Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'raw_payload' => ['name' => 'Stale Test Player'],
        'first_seen_at' => now(),
        'last_seen_at' => now()->subDay(),
    ]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)([
        'name' => 'Fresh Test Player',
    ]));

    (new RefreshCapWagesContractsForIdentityJob($identity->id))->handle(new ImportCapWagesPlayer());

    expect($identity->refresh()->display_name)->toBe('Fresh Test Player');
    expect(CapWagesPlayer::first()->player_id)->toBe($player->id);
    expect(CapWagesPlayer::first()->name)->toBe('Fresh Test Player');
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('uses cached capwages payload when refresh detail is blocked by the provider', function () {
    $player = ($this->makePlayer)();
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
        'provider_player_id' => 'blocked-player',
        'provider_slug' => 'blocked-player',
        'display_name' => 'Blocked Player',
        'player_id' => $player->id,
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    CapWagesPlayer::create([
        'player_external_identity_id' => $identity->id,
        'slug' => 'blocked-player',
        'name' => 'Blocked Player',
        'raw_payload' => ($this->capWagesPayload)(),
    ]);
    Http::fake([
        'https://capwages.com/api/gateway/v1/players/blocked-player' => Http::response('<html>blocked</html>', 403),
    ]);

    (new RefreshCapWagesContractsForIdentityJob($identity->id))->handle(new ImportCapWagesPlayer());

    expect(CapWagesPlayer::first()->player_id)->toBe($player->id);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('keeps capwages contract refresh jobs unique per identity', function () {
    $job = new RefreshCapWagesContractsForIdentityJob(123);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('123');
    expect($job->uniqueFor)->toBe(900);
});

it('skips capwages contract refresh when the identity no longer exists', function () {
    (new RefreshCapWagesContractsForIdentityJob(999999))->handle(new ImportCapWagesPlayer());

    expect(Contract::query()->count())->toBe(0);
    expect(ContractSeason::query()->count())->toBe(0);
});

it('capwages matched identity applies contract data to the linked canonical player', function () {
    $player = ($this->makePlayer)(['nhl_id' => 123456]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->player_id)->toBe($player->id);
    expect(CapWagesPlayer::first()->player_id)->toBe($player->id);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('capwages import remains idempotent for identities contracts and seasons', function () {
    ($this->makePlayer)(['nhl_id' => 123456]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);
    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(CapWagesPlayer::query()->count())->toBe(1);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('capwages import uses cached raw payload before fetching detail', function () {
    ($this->makePlayer)(['nhl_id' => 123456]);
    CapWagesPlayer::create([
        'slug' => 'test-player',
        'name' => 'Test Player',
        'raw_payload' => ($this->capWagesPayload)(),
    ]);
    Http::fake();

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    Http::assertNothingSent();
    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('capwages page import dispatches the next page without delay after a successful page', function () {
    Queue::fake();
    Http::fake([
        'https://capwages.com/api/gateway/v1/players?page=1&limit=2' => Http::response([
            'data' => [],
            'meta' => [
                'pagination' => [
                    'totalPages' => 2,
                ],
            ],
        ]),
    ]);

    (new ImportCapWagesJob(1, 2, false))->handle();

    Queue::assertPushed(ImportCapWagesJob::class, function (ImportCapWagesJob $job): bool {
        $page = new ReflectionProperty($job, 'page');
        $delay = new ReflectionProperty($job, 'delay');

        return $page->getValue($job) === 2
            && $delay->getValue($job) === null;
    });
});

it('capwages page import skips player detail server errors and continues the page crawl', function () {
    Queue::fake();
    Http::fake([
        'https://capwages.com/api/gateway/v1/players?page=1&limit=2' => Http::response([
            'data' => [
                ['slug' => 'eric-gryba'],
            ],
            'meta' => [
                'pagination' => [
                    'totalPages' => 2,
                ],
            ],
        ]),
        'https://capwages.com/api/gateway/v1/players/eric-gryba' => Http::response([
            'message' => 'Internal Server Error',
        ], 500),
    ]);

    (new ImportCapWagesJob(1, 2, false))->handle();

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    Queue::assertPushed(ImportCapWagesJob::class, function (ImportCapWagesJob $job): bool {
        $page = new ReflectionProperty($job, 'page');

        return $page->getValue($job) === 2;
    });
});

it('capwages page import skips player detail connection failures and continues the page crawl', function () {
    Queue::fake();
    Http::fake([
        'https://capwages.com/api/gateway/v1/players?page=1&limit=2' => Http::response([
            'data' => [
                ['slug' => 'theodor-hallquisth'],
            ],
            'meta' => [
                'pagination' => [
                    'totalPages' => 2,
                ],
            ],
        ]),
        'https://capwages.com/api/gateway/v1/players/theodor-hallquisth' => function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds');
        },
    ]);

    (new ImportCapWagesJob(1, 2, false))->handle();

    expect(PlayerExternalIdentity::query()->count())->toBe(0);
    Queue::assertPushed(ImportCapWagesJob::class, function (ImportCapWagesJob $job): bool {
        $page = new ReflectionProperty($job, 'page');

        return $page->getValue($job) === 2;
    });
});

it('provider audit counts include every documented identity status', function () {
    foreach ([
        PlayerExternalIdentity::STATUS_MATCHED,
        PlayerExternalIdentity::STATUS_CANDIDATE,
        PlayerExternalIdentity::STATUS_UNMATCHED,
        PlayerExternalIdentity::STATUS_IGNORED,
        PlayerExternalIdentity::STATUS_CONFLICT,
    ] as $status) {
        PlayerExternalIdentity::create([
            'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'provider_player_id' => "fantrax-{$status}",
            'match_status' => $status,
            'player_id' => $status === PlayerExternalIdentity::STATUS_MATCHED
                ? ($this->makePlayer)(['nhl_id' => 900001])->id
                : null,
        ]);
    }

    $counts = app(PlayerIdentityResolver::class)->statusCountsByProvider();

    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_MATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_CANDIDATE])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_UNMATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_IGNORED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_CONFLICT])->toBe(1);
});
