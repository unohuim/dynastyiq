<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

/**
 * Configures the shared Inertia browser shell for staged Vue page adoption.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template used for Inertia responses.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Return the current asset version for Inertia responses.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Return props shared by all Inertia pages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
            ],
            'shell' => fn (): array => $this->shell($request),
        ];
    }

    /** Build safe navigation/settings props; authorization remains on the existing endpoints. */
    private function shell(Request $request): array
    {
        $user = $request->user();
        $fantasy = $user ? app(\App\Services\FantasyIntegrationState::class)->forUser($user) : [];
        $organization = $user?->organizations()->first();
        $hasCommunities = $user?->organizations()->whereNotNull('organizations.settings')->exists() ?? false;
        // Match SuperAdminMiddleware and the existing account menu without changing access policy.
        $admin = $user && ($user->roles()->where('slug', 'super-admin')->exists()
            || $user->roles()->where('level', '>=', 99)->exists());
        $link = fn (string $label, string $route, string $pattern): array => [
            'label' => $label, 'href' => route($route), 'active' => $request->routeIs($pattern),
        ];
        $primary = [
            $link('Home', $user ? 'dashboard' : 'welcome', $user ? 'dashboard' : 'welcome'),
            $link('Games', 'games.index', 'games.*'),
            $link('Stats', 'stats.index', 'stats.index'),
        ];
        if ($user) {
            if (collect($fantasy)->contains(fn (array $state): bool => (bool) $state['show_leagues'])) {
                $primary[] = $link('Leagues', 'leagues.index', 'leagues.*');
            }
            $primary[] = $link('Line Combos', 'stats.units.index', 'stats.units.*');
            if ($hasCommunities) {
                $primary[] = $link('Communities', 'communities.index', 'communities.*')
                    + ['mobile' => $user->can('view-nav-communities')];
            }
        }
        $preferences = $user ? DB::table('user_preferences')->where('user_id', $user->id)
            ->whereIn('key', ['notifications.discord.dm', 'notifications.discord.channel', 'notifications.discord.channel-name'])
            ->pluck('value', 'key') : collect();
        $notifications = [];
        foreach (['dm', 'channel', 'channel-name'] as $key) {
            $value = $preferences->get('notifications.discord.' . $key);
            $notifications[$key] = $value === null
                ? config('notifications.defaults.discord.' . $key)
                : json_decode($value, true);
        }
        $flashes = collect([
            'success' => $request->session()->get('success') ?? $request->session()->get('status'),
            'error' => $request->session()->get('error'),
            'info' => $request->session()->get('info'),
        ])->filter()->map(fn ($message, string $type): array => [
            'type' => $type, 'message' => is_array($message) ? implode(' ', Arr::flatten($message)) : (string) $message,
        ])->values()->all();

        return [
            'csrf' => csrf_token(),
            'primary' => $primary,
            'news' => [
                $link('Transactions', 'transactions.index', 'transactions.*'),
                $link('Starting Goalies', 'starting-goalies.index', 'starting-goalies.*'),
                $link('Injuries', 'injuries.index', 'injuries.*'),
            ],
            'admin' => $admin ? [
                $link('Admin Control Panel', 'admin.dashboard', 'admin.dashboard'),
                $link('Admin Shot Attempts', 'admin.nhl-shot-attempts.index', 'admin.nhl-shot-attempts.*'),
                $link('SAT Models', 'admin.nhl-sat-models.index', 'admin.nhl-sat-models.*'),
                $link('SAT Engines', 'admin.nhl-sat-engines.index', 'admin.nhl-sat-engines.*'),
                $link('Admin Faceoffs', 'admin.nhl-faceoffs.index', 'admin.nhl-faceoffs.*'),
            ] : [],
            'avatar' => $user?->socialAccounts()->where('provider', 'discord')->value('avatar'),
            'login_url' => route('discord.redirect'),
            'profile_url' => route('profile.show'),
            'logout_url' => route('logout'),
            'fantasy' => $fantasy,
            'fantrax_url' => route('integrations.fantrax.save'),
            'yahoo_url' => route('integrations.yahoo.redirect', ['return_to' => $request->getRequestUri(), 'drawer' => 'account']),
            'discord_url' => route('discord.join'),
            'discord_connected' => (bool) $request->session()->get('diq-user.connected', false),
            'notifications' => $notifications,
            'preferences_url' => route('user.preferences.update'),
            'organization' => [
                'id' => $organization?->id, 'name' => $organization?->name ?? '',
                'enabled' => $organization?->settings !== null,
                'commissioner_tools' => (bool) data_get($organization?->settings, 'commissioner_tools', false),
                'creator_tools' => (bool) data_get($organization?->settings, 'creator_tools', false),
                'url' => route('organizations.settings.update', ['organization' => $organization?->id]),
            ],
            'flashes' => $flashes,
        ];
    }
}
