<?php

declare(strict_types=1);

use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\Role;
use App\Models\User;

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
    $this->get(route('admin.player-triage'))->assertRedirect(route('login'));
});

it('blocks authenticated non-admin users from the player triage inbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.player-triage'))
        ->assertForbidden();
});

it('allows super admins to view the player triage inbox', function () {
    $identity = ($this->makeIdentity)();

    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage', ['include_resolved' => 1]))
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
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage', ['include_resolved' => 1]))
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
        ->get(route('admin.player-triage', ['statuses' => [PlayerExternalIdentity::STATUS_MATCHED]]))
        ->assertOk()
        ->assertSee('Matched Player')
        ->assertDontSee('Candidate Player');
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
        ->get(route('admin.player-triage', ['provider' => PlayerExternalIdentity::PROVIDER_CAPWAGES]))
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
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage', ['source' => PlayerExternalIdentity::PROVIDER_FANTRAX]))
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
        ->get(route('admin.player-triage', [
            'source' => PlayerExternalIdentity::PROVIDER_CAPWAGES,
            'matched' => 1,
        ]))
        ->assertOk()
        ->assertSee('Player Inbox (1)')
        ->assertSee('Linked CapWages Player')
        ->assertDontSee('Open CapWages Player')
        ->assertDontSee('Linked Fantrax Source Player');
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', ['search' => 'searchable']))
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
        ->get(route('admin.player-triage', ['search' => '777']))
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
        ->get(route('admin.player-triage', ['reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
        ->assertOk()
        ->assertSee('95% recommendation')
        ->assertSee('recommends matched');

    expect($identity->refresh()->match_status)->toBe(PlayerExternalIdentity::STATUS_CANDIDATE);
    expect($identity->match_confidence)->toBe(75);
});

it('shows an empty inbox state when no identities match filters', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->get(route('admin.player-triage'))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', ['identity' => $identity->id]))
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->get(route('admin.player-triage', [
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
        ->assertSee('Fantrax Players')
        ->assertSee('Contracts')
        ->assertSee('Run workflow')
        ->assertSee('Retry failed');
});
