<?php

use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function mockPlatformState(bool $seeded = true, bool $initialized = true, bool $upToDate = true): void
{
    $platform = Mockery::mock(PlatformState::class);
    $platform->shouldReceive('seeded')->andReturn($seeded);
    $platform->shouldReceive('initialized')->andReturn($initialized);
    $platform->shouldReceive('upToDate')->andReturn($upToDate);

    app()->instance(PlatformState::class, $platform);
}

beforeEach(function () {
    $this->superRole = Role::create([
        'name' => 'Super Admin',
        'slug' => 'super-admin',
        'level' => 99,
        'is_active' => true,
    ]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach($this->superRole->id);

    mockPlatformState();
});

function createPlayer(array $overrides = []): Player
{
    static $counter = 1;

    $defaults = [
        'full_name' => "Test Player {$counter}",
        'first_name' => 'Test',
        'last_name' => 'Player'.$counter,
        'position' => 'C',
        'team_abbrev' => 'TST',
        'dob' => '2000-01-01',
        'status' => 'active',
        'meta' => ['hidden' => 'value'],
    ];

    $counter++;

    return Player::create(array_merge($defaults, $overrides));
}

it('denies non-super-admin users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('admin.api.players'));

    $response->assertForbidden();
});

it('requires platform seeding before allowing access', function () {
    mockPlatformState(seeded: false, initialized: false, upToDate: false);

    $response = $this->actingAs($this->superAdmin)->get(route('admin.api.players'));

    $response->assertStatus(503);
    $response->assertSeeText('Fresh install detected');
});

it('redirects uninitialized platforms to initialization flow', function () {
    mockPlatformState(seeded: true, initialized: false, upToDate: false);

    $response = $this->actingAs($this->superAdmin)->get(route('admin.api.players'));

    $response->assertRedirect(route('admin.initialize.index'));
});

it('returns only the allowed player columns', function () {
    createPlayer(['full_name' => 'Allowed Columns']);

    $response = $this->actingAs($this->superAdmin)->getJson(route('admin.api.players'));

    $response->assertOk();
    $payload = $response->json('data.0');

    expect($payload)->toHaveKeys([
        'id',
        'full_name',
        'first_name',
        'last_name',
        'position',
        'team_abbrev',
        'dob',
        'age',
    ]);

    expect($payload)->not->toHaveKey('status');
    expect($payload)->not->toHaveKey('meta');
});

it('filters players case-insensitively across full, first, and last names', function () {
    createPlayer(['full_name' => 'John Doe', 'first_name' => 'John', 'last_name' => 'Doe']);
    createPlayer(['full_name' => 'Jane Smith', 'first_name' => 'Jane', 'last_name' => 'Smith']);

    $response = $this->actingAs($this->superAdmin)
        ->getJson(route('admin.api.players', ['filter' => 'doe']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    expect($response->json('data.0.full_name'))->toBe('John Doe');
});

it('orders players by last name then first name ascending', function () {
    createPlayer(['full_name' => 'Zara Adams', 'first_name' => 'Zara', 'last_name' => 'Adams']);
    createPlayer(['full_name' => 'Adam Brown', 'first_name' => 'Adam', 'last_name' => 'Brown']);
    createPlayer(['full_name' => 'Ben Brown', 'first_name' => 'Ben', 'last_name' => 'Brown']);

    $response = $this->actingAs($this->superAdmin)->getJson(route('admin.api.players'));

    $response->assertOk();
    expect($response->json('data.*.full_name'))
        ->toEqual([
            'Zara Adams',
            'Adam Brown',
            'Ben Brown',
        ]);
});

it('paginates results with metadata aligned to dataset size', function () {
    foreach (range(1, 30) as $i) {
        createPlayer(['full_name' => "Player {$i}", 'last_name' => "Last{$i}", 'first_name' => "First{$i}"]);
    }

    $response = $this->actingAs($this->superAdmin)
        ->getJson(route('admin.api.players', ['page' => 2]));

    $response->assertOk();
    $response->assertJsonPath('meta.current_page', 2);
    $response->assertJsonPath('meta.last_page', 2);
    $response->assertJsonPath('meta.total', 30);
    $response->assertJsonPath('meta.per_page', 25);
    $response->assertJsonCount(5, 'data');
});

it('exposes next and previous links for pagination', function () {
    foreach (range(1, 30) as $i) {
        createPlayer(['full_name' => "Link Player {$i}", 'last_name' => "L{$i}", 'first_name' => "F{$i}"]);
    }

    $firstPage = $this->actingAs($this->superAdmin)->getJson(route('admin.api.players'));
    $firstPage->assertJsonPath('links.prev', null);
    expect($firstPage->json('links.next'))->not->toBeNull();

    $secondPage = $this->actingAs($this->superAdmin)
        ->getJson(route('admin.api.players', ['page' => 2]));

    $secondPage->assertJsonPath('links.prev', route('admin.api.players', ['page' => 1]));
    $secondPage->assertJsonPath('links.next', null);
});

it('defaults to a 25 player page size', function () {
    foreach (range(1, 40) as $i) {
        createPlayer(['full_name' => "Sized Player {$i}", 'last_name' => "SZ{$i}", 'first_name' => "FN{$i}"]);
    }

    $response = $this->actingAs($this->superAdmin)->getJson(route('admin.api.players'));

    $response->assertJsonPath('meta.per_page', 25);
    $response->assertJsonCount(25, 'data');
});

it('formats dates consistently and calculates ages', function () {
    $player = createPlayer(['dob' => '1990-06-15', 'full_name' => 'Age Check']);

    $response = $this->actingAs($this->superAdmin)->getJson(route('admin.api.players'));

    $payload = $response->json('data.0');

    expect($payload['dob'])->toBe('1990-06-15');
    expect($payload['age'])->toBe(Carbon::parse($player->dob)->age);
});

it('returns an empty payload gracefully when filters exclude all players', function () {
    createPlayer(['full_name' => 'Someone Else']);

    $response = $this->actingAs($this->superAdmin)
        ->getJson(route('admin.api.players', ['filter' => 'nomatch']));

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
    $response->assertJsonPath('meta.total', 0);
});
