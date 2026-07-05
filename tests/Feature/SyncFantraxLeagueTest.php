<?php

declare(strict_types=1);

use App\Models\PlatformLeague;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\SyncFantraxLeague;
use App\Support\FantasyProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('fantrax league sync resolves roster memberships from matched fantrax identities when mirror row is stale', function (): void {
    config()->set('apiurls.fantrax.base', 'https://fantrax.test/fxea');

    $player = Player::create([
        'first_name' => 'Carter',
        'last_name' => 'Bear',
        'full_name' => 'Carter Bear',
        'position' => 'LW',
        'pos_type' => 'F',
        'status' => 'active',
    ]);

    $league = PlatformLeague::create([
        'platform' => FantasyProvider::FANTRAX,
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);

    PlayerExternalIdentity::create([
        'player_id' => $player->id,
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => '06rqm',
        'provider_slug' => '06rqm',
        'display_name' => 'Carter Bear',
        'normalized_name' => 'carter bear',
        'first_name' => 'Carter',
        'last_name' => 'Bear',
        'position' => 'C',
        'team' => 'DET',
        'raw_payload' => [
            'name' => 'Bear, Carter',
            'fantraxId' => '06rqm',
            'team' => 'DET',
            'position' => 'C',
        ],
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
        'match_confidence' => 100,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    DB::table('fantrax_players')->insert([
        'fantrax_id' => '06rqm',
        'player_id' => null,
        'name' => 'Bear, Carter',
        'position' => 'C',
        'team' => 'DET',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'https://fantrax.test/fxea/general/getLeagueInfo?leagueId=league-1' => Http::response([], 200),
        'https://fantrax.test/fxea/general/getTeamRosters?leagueId=league-1' => Http::response([
            'rosters' => [
                'team-1' => [
                    'teamName' => 'Okanagan Ogopogo',
                    'rosterItems' => [
                        [
                            'id' => '06rqm',
                            'name' => 'P3',
                            'position' => 'F',
                            'status' => 'MINORS',
                            'salary' => 1200,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    app(SyncFantraxLeague::class)->sync($league->id);

    $teamId = DB::table('platform_teams')
        ->where('platform_league_id', $league->id)
        ->where('platform_team_id', 'team-1')
        ->value('id');

    expect($teamId)->not->toBeNull();

    $this->assertDatabaseHas('fantrax_players', [
        'fantrax_id' => '06rqm',
        'player_id' => $player->id,
    ]);

    $this->assertDatabaseHas('platform_roster_memberships', [
        'platform_team_id' => $teamId,
        'player_id' => $player->id,
        'platform' => FantasyProvider::FANTRAX,
        'platform_player_id' => '06rqm',
        'slot' => 'MIN',
        'status' => 'na',
        'ends_at' => null,
    ]);
});
