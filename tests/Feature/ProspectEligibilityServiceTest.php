<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use App\Services\ProspectEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    $this->service = app(ProspectEligibilityService::class);
    $this->player = Player::query()->create([
        'nhl_id' => 9000001, 'full_name' => 'Young Player', 'first_name' => 'Young', 'last_name' => 'Player',
        'dob' => '2005-01-01', 'position' => 'C', 'is_prospect' => false,
    ]);
    $this->identity = PlayerExternalIdentity::query()->create([
        'player_id' => $this->player->id, 'provider' => PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
        'provider_player_id' => 'fixture-draft-1',
    ]);
    $this->stat = fn (array $overrides = []) => Stat::query()->create(array_merge([
        'player_id' => $this->player->id, 'season_id' => '20262027', 'league_abbrev' => 'NHL',
        'team_name' => 'Fixture Team', 'game_type_id' => 2, 'gp' => 5,
    ], $overrides));
});

afterEach(function (): void {
    DB::disableQueryLog();
    $this->travelBack();
});

it('keeps drafting mode as the default and excludes NHL-only activity', function (): void {
    ($this->stat)();
    expect($this->service->isProspect(9000001))->toBeFalse();
});

it('allows NHL-only activity when for_drafting is false', function (): void {
    ($this->stat)();
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('retains non-NHL eligibility when drafting is explicitly enabled', function (): void {
    ($this->stat)(['league_abbrev' => 'OHL']);
    expect($this->service->isProspect(9000001, for_drafting: true))->toBeTrue();
});

it('also permits non-NHL activity in reporting mode', function (): void {
    ($this->stat)(['league_abbrev' => 'OHL']);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('does not let current NHL activity replace expired non-NHL activity for drafting', function (): void {
    ($this->stat)();
    ($this->stat)(['league_abbrev' => 'OHL', 'season_id' => '20242025']);
    expect($this->service->isProspect(9000001))->toBeFalse();
});

it('requires positive games in the window in reporting mode', function (): void {
    ($this->stat)(['gp' => 0]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('accepts exactly 25 career NHL games in reporting mode', function (): void {
    ($this->stat)(['gp' => 25]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('rejects 26 career NHL games in reporting mode', function (): void {
    ($this->stat)(['gp' => 26]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('retains the career NHL limit in drafting mode', function (): void {
    ($this->stat)(['league_abbrev' => 'OHL']);
    ($this->stat)(['gp' => 26, 'season_id' => '20242025']);
    expect($this->service->isProspect(9000001))->toBeFalse();
});

it('sums career NHL regular-season games across seasons', function (): void {
    ($this->stat)(['gp' => 10]);
    ($this->stat)(['gp' => 16, 'season_id' => '20252026']);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('does not include playoff games in the career NHL regular-season limit', function (): void {
    ($this->stat)(['gp' => 5]);
    ($this->stat)(['gp' => 30, 'game_type_id' => 3]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('rejects a player aged exactly 26 on September 15', function (): void {
    ($this->stat)();
    $this->player->update(['dob' => '2000-09-15']);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('accepts a player still younger than 26 on September 15', function (): void {
    ($this->stat)();
    $this->player->update(['dob' => '2000-09-16']);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('requires a known birth date in both modes', function (): void {
    ($this->stat)(['league_abbrev' => 'OHL']);
    $this->player->update(['dob' => null]);
    expect($this->service->isProspect(9000001))->toBeFalse()
        ->and($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('retains the draft-evidence requirement in reporting mode', function (): void {
    ($this->stat)();
    $this->identity->delete();
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('accepts draft evidence from NHL landing metadata', function (): void {
    ($this->stat)();
    $this->identity->update(['provider' => 'nhl', 'raw_payload' => ['draftDetails' => ['year' => 2023]]]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('does not treat empty NHL landing draft details as draft evidence', function (): void {
    ($this->stat)();
    $this->identity->update(['provider' => 'nhl', 'raw_payload' => ['draftDetails' => []]]);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeFalse();
});

it('accepts prior-season NHL activity in the October window', function (): void {
    ($this->stat)(['season_id' => '20252026']);
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue();
});

it('rejects prior-season activity after the October window closes', function (): void {
    ($this->stat)(['season_id' => '20252026']);
    expect($this->service->isProspect(9000001, Carbon::parse('2026-11-01'), false))->toBeFalse();
});

it('preserves the existing asOf positional argument for summer evaluation', function (): void {
    ($this->stat)(['season_id' => '20252026', 'league_abbrev' => 'OHL']);
    expect($this->service->isProspect(9000001, Carbon::parse('2026-07-01')))->toBeTrue();
});

it('returns false for an unknown NHL identity', function (): void {
    expect($this->service->isProspect(9999999, for_drafting: false))->toBeFalse();
});

it('returns the same eligible identities from bulk and single-player evaluation', function (): void {
    ($this->stat)();
    expect($this->service->eligibleNhlPlayerIds([9000001, 9999999], for_drafting: false))->toBe([9000001])
        ->and($this->service->eligibleNhlPlayerIds([9000001]))->toBe([]);
});

it('does not persist reporting eligibility into drafting flags', function (): void {
    ($this->stat)();
    expect($this->service->isProspect(9000001, for_drafting: false))->toBeTrue()
        ->and((bool) $this->player->fresh()->is_prospect)->toBeFalse()
        ->and((bool) Stat::query()->first()->is_prospect)->toBeFalse();
});

it('uses a bounded number of queries for multiple candidates', function (): void {
    ($this->stat)();
    $second = Player::query()->create(['nhl_id' => 9000002, 'dob' => '2005-01-01', 'full_name' => 'Second Player']);
    PlayerExternalIdentity::query()->create([
        'player_id' => $second->id, 'provider' => 'nhl_draft', 'provider_player_id' => 'fixture-draft-2',
    ]);
    ($this->stat)(['player_id' => $second->id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $ids = $this->service->eligibleNhlPlayerIds([9000001, 9000002], for_drafting: false);
    $queryCount = count(DB::getQueryLog());
    expect($ids)->toContain(9000001, 9000002)->and($queryCount)->toBe(2);
});

it('does not query for an empty candidate list', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->service->eligibleNhlPlayerIds([], for_drafting: false))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});
