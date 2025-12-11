<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use App\Http\Middleware\AdminLifecycleMiddleware;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    if (function_exists('withoutVite')) {
        withoutVite();
    }

    $this->withoutExceptionHandling();
    $this->withoutMiddleware([AdminLifecycleMiddleware::class]);

    $this->superAdmin = createSuperAdmin();
});

it('performs case-insensitive filtering before pagination', function (string $term) {
    createPlayers(60, ['full_name' => fn (int $i) => 'A Filler Player ' . $i]);
    $target = createPlayer([
        'full_name' => 'Mikael Granlund',
        'position' => 'C',
        'team_abbrev' => 'SJS',
    ]);

    actingAs($this->superAdmin);

    $unfiltered = getJson('/admin/api/players');
    expect(collect($unfiltered->json('data'))->pluck('full_name'))->not->toContain($target->full_name);

    $response = getJson('/admin/api/players?filter=' . $term);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.last_page'))->toBe(1);

    expect($response->json('data')[0])->toMatchArray([
        'id' => $target->id,
        'full_name' => 'Mikael Granlund',
        'position' => 'C',
        'team_abbrev' => 'SJS',
    ]);
})->with([
    'granl',
    'GRANL',
    'gRaNl',
]);

it('returns empty data when filter finds no players', function () {
    createPlayers(10);
    actingAs($this->superAdmin);

    $response = getJson('/admin/api/players?filter=nomatch');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.last_page'))->toBe(1);
});

it('matches filter against any part of the full_name', function (string $term, bool $shouldMatch) {
    $target = createPlayer(['full_name' => 'Mikael Granlund']);
    createPlayers(5);

    actingAs($this->superAdmin);

    $response = getJson('/admin/api/players?filter=' . $term);
    $names = collect($response->json('data'))->pluck('full_name');

    if ($shouldMatch) {
        expect($names)->toContain($target->full_name);
    } else {
        expect($names)->not->toContain($target->full_name)
            ->and($response->json('data'))->toBe([]);
    }
})->with([
    ['lund', true],
    ['mika', true],
    ['ael gr', true],
    ['xyz', false],
]);

it('paginates after filtering', function () {
    $matches = collect([
        createPlayer(['full_name' => 'Mikael Granlund']),
        createPlayer(['full_name' => 'Markus Granlund']),
    ]);

    createPlayers(28);

    actingAs($this->superAdmin);

    $filtered = getJson('/admin/api/players?filter=granl');

    $filtered->assertOk();
    expect($filtered->json('data'))->toHaveCount(2)
        ->and(collect($filtered->json('data'))->pluck('id'))->toEqualCanonicalizing($matches->pluck('id'))
        ->and($filtered->json('meta.total'))->toBe(2)
        ->and($filtered->json('meta.current_page'))->toBe(1)
        ->and($filtered->json('meta.last_page'))->toBe(1);

    $pageOne = getJson('/admin/api/players');
    expect($pageOne->json('data'))->toHaveCount(25)
        ->and($pageOne->json('meta.total'))->toBe(Player::count());

    $pageTwo = getJson('/admin/api/players?page=2');
    expect($pageTwo->json('data'))->toHaveCount(Player::count() - 25);
});

it('returns computed ages based on dob', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01'));

    $first = createPlayer([
        'full_name' => 'Mikael Granlund',
        'dob' => '1993-08-15',
    ]);
    $second = createPlayer([
        'full_name' => 'Markus Granlund',
        'dob' => '1992-04-01',
    ]);
    $third = createPlayer([
        'full_name' => 'Granlund With No DOB',
        'dob' => null,
    ]);

    actingAs($this->superAdmin);

    $response = getJson('/admin/api/players?filter=granlund');

    $data = collect($response->json('data'))->keyBy('id');

    expect($data[$first->id]['age'])->toBe(Carbon::parse($first->dob)->age)
        ->and($data[$second->id]['age'])->toBe(Carbon::parse($second->dob)->age)
        ->and($data[$third->id]['age'])->toBeNull();

    Carbon::setTestNow();
});

it('returns the expected JSON contract', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01'));

    $player = createPlayer([
        'id' => 1,
        'full_name' => 'Mikael Granlund',
        'position' => 'C',
        'team_abbrev' => 'SJS',
        'dob' => '1993-08-15',
    ]);

    actingAs($this->superAdmin);

    $response = getJson('/admin/api/players?filter=granlund');

    $response->assertOk();
    $response->assertExactJson([
        'data' => [
            [
                'id' => $player->id,
                'full_name' => 'Mikael Granlund',
                'position' => 'C',
                'team_abbrev' => 'SJS',
                'age' => 31,
            ],
        ],
        'meta' => [
            'total' => 1,
            'current_page' => 1,
            'last_page' => 1,
        ],
    ]);

    Carbon::setTestNow();
});

it('handles empty or missing filter strings without breaking', function () {
    createPlayers(3);

    actingAs($this->superAdmin);

    $noFilter = getJson('/admin/api/players');
    $emptyFilter = getJson('/admin/api/players?filter=');
    $spaceFilter = getJson('/admin/api/players?filter=%20');

    foreach ([$noFilter, $emptyFilter, $spaceFilter] as $response) {
        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3)
            ->and($response->json('meta.total'))->toBe(3)
            ->and($response->json('meta.current_page'))->toBe(1)
            ->and($response->json('meta.last_page'))->toBe(1);
    }
});

it('supports UTF-8 names and substring collisions case-insensitively', function (string $term) {
    $target = createPlayer(['full_name' => 'Elias Petteršon']);
    createPlayers(5);

    actingAs($this->superAdmin);

    $response = getJson('/admin/api/players?filter=' . $term);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($target->id);
})->with([
    'petter',
    'PETTER',
    'etter',
]);

function createSuperAdmin(): User
{
    $user = User::factory()->create();

    $role = Role::firstOrCreate(
        ['slug' => 'super-admin'],
        [
            'name' => 'Super Admin',
            'level' => 99,
            'is_active' => true,
            'scope' => 'global',
        ],
    );

    $user->roles()->syncWithoutDetaching($role);

    return $user;
}

function playerFactory(): Factory
{
    return new class extends Factory {
        protected $model = Player::class;

        public function definition(): array
        {
            $first = fake()->firstName();
            $last = fake()->lastName();

            return [
                'full_name' => $first . ' ' . $last,
                'first_name' => $first,
                'last_name' => $last,
                'country_code' => 'CA',
                'is_prospect' => false,
                'is_goalie' => false,
                'position' => fake()->randomElement(['C', 'LW', 'RW', 'D', 'G']),
                'pos_type' => null,
                'team_abbrev' => strtoupper(fake()->lexify('???')),
                'current_league_abbrev' => null,
                'status' => 'active',
                'meta' => null,
                'dob' => fake()->date(),
            ];
        }
    };
}

function createPlayer(array $attributes = []): Player
{
    $factory = playerFactory();

    $data = array_merge($factory->definition(), $attributes);

    if (isset($data['full_name'])) {
        [$first, $last] = array_pad(explode(' ', $data['full_name'], 2), 2, $data['full_name']);
        $data['first_name'] = $data['first_name'] ?? $first;
        $data['last_name'] = $data['last_name'] ?? $last;
    }

    return Player::query()->create($data);
}

function createPlayers(int $count, array $overrides = []): Collection
{
    return collect(range(1, $count))->map(function ($index) use ($overrides) {
        $attributes = [];

        foreach ($overrides as $key => $value) {
            $attributes[$key] = is_callable($value) ? $value($index) : $value;
        }

        return createPlayer($attributes);
    });
}
