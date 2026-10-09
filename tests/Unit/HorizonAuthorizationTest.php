<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\HorizonServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Horizon;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['session.driver' => 'array', 'cache.default' => 'array']);

    $this->setEnvironment = function (string $environment): void {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
    };
});

it('boots the registered Horizon provider and defines its gate', function () {
    expect($this->app->getProvider(HorizonServiceProvider::class))->not->toBeNull()
        ->and(Gate::has('viewHorizon'))->toBeTrue();
});

it('serves the production dashboard without a Laravel login', function () {
    ($this->setEnvironment)('production');

    $this->get('https://horizon-diq.on-forge.com/horizon')
        ->assertOk()
        ->assertViewIs('horizon::layout');
    $this->assertGuest();
});

it('denies the dashboard on other hosts even with a Laravel login', function (string $host, bool $signedIn) {
    ($this->setEnvironment)('production');
    if ($signedIn) {
        $this->actingAs(new User(['name' => 'Dashboard user']));
    }

    $this->get('https://'.$host.'/horizon')->assertForbidden();
})->with([
    'main site' => 'dynastyiq.test',
    'unrelated host' => 'example.com',
    'subdomain' => 'other.horizon-diq.on-forge.com',
    'suffix attack' => 'horizon-diq.on-forge.com.example.com',
    'prefix attack' => 'fake-horizon-diq.on-forge.com',
    'direct IP' => '127.0.0.1',
])->with([false, true]);

it('denies non-production dashboard access including local', function (string $environment, bool $signedIn) {
    ($this->setEnvironment)($environment);
    if ($signedIn) {
        $this->actingAs(new User(['name' => 'Dashboard user']));
    }

    $this->get('https://horizon-diq.on-forge.com/horizon')->assertForbidden();
})->with(['local', 'testing', 'staging'])->with([false, true]);

it('allows a signed-in user only on the dedicated production dashboard', function () {
    ($this->setEnvironment)('production');
    $this->actingAs(new User(['name' => 'Dashboard user']));

    $this->get('https://horizon-diq.on-forge.com/horizon')->assertOk();
});

it('evaluates the viewHorizon gate for guests using the request host and environment', function (
    string $environment,
    string $host,
    bool $allowed,
) {
    ($this->setEnvironment)($environment);
    $request = Request::create('https://'.$host.'/horizon');

    expect(Gate::forUser(null)->allows('viewHorizon', [$request]))->toBe($allowed);
})->with([
    ['production', 'horizon-diq.on-forge.com', true],
    ['production', 'dynastyiq.test', false],
    ['local', 'horizon-diq.on-forge.com', false],
    ['staging', 'horizon-diq.on-forge.com', false],
]);

it('protects internal Horizon API reads on unauthorized hosts', function () {
    ($this->setEnvironment)('production');

    $this->getJson('https://example.com/horizon/api/stats')->assertForbidden();
});

it('does not let a permissive gate bypass the host or environment boundary', function (
    string $environment,
    string $host,
) {
    ($this->setEnvironment)($environment);
    Gate::before(fn (?User $user = null): bool => true);

    $request = Request::create('https://'.$host.'/horizon');
    $request->setUserResolver(fn () => null);

    expect(Horizon::check($request))->toBeFalse();
})->with([
    ['production', 'example.com'],
    ['local', 'horizon-diq.on-forge.com'],
]);

it('does not trust client forwarded-host headers on an unrelated host', function () {
    ($this->setEnvironment)('production');

    $this->withHeaders([
        'X-Forwarded-Host' => 'horizon-diq.on-forge.com',
        'Forwarded' => 'host=horizon-diq.on-forge.com;proto=https',
    ])->get('https://example.com/horizon')->assertForbidden();
});
