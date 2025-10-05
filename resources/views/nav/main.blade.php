
@php
    $hasFantrax      = auth()->check() && !empty(auth()->user()->fantraxSecret);
    $hasDiscord      = session('diq-user.connected', false);
    // Initial “Community Tools enabled” across any org the user has (cheap existence check)
    $hasCommunities  = auth()->check()
        ? auth()->user()->organizations()->whereNotNull('organizations.settings')->exists()
        : false;
@endphp

<div
    x-data="{
        accountOpen:false,
        leftOpen:false,
        hasFantrax: {{ $hasFantrax ? 'true':'false' }},
        hasDiscord: {{ $hasDiscord ? 'true':'false' }},
        hasCommunities: {{ $hasCommunities ? 'true':'false' }},
    }"
    x-init="
        window.addEventListener('fantrax:connected',   () => hasFantrax = true);
        window.addEventListener('discord:connected',   () => hasDiscord = true);

        // React to Community Tools changes from anywhere (drawer, server echo, etc.)
        window.addEventListener('org:settings-updated', (e) => {
            // Prefer explicit boolean if present; otherwise infer from settings presence
            const detail = e.detail || {};
            const v = (detail.enabled ?? (detail.settings !== undefined && detail.settings !== null));
            hasCommunities = !!v;
        });
    "
    class="relative"
    x-cloak
>
    {{-- DESKTOP Top Nav --}}
    <nav class="hidden sm:flex items-center justify-between bg-white border-b shadow px-6 py-2">
        <div class="flex items-center space-x-8">
            <x-nav-link
                href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
                class="text-lg font-semibold {{ request()->routeIs('welcome', 'dashboard') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
            >Home</x-nav-link>

            <x-nav-link
                href="{{ route('stats.index') }}"
                class="text-lg font-semibold {{ request()->routeIs('stats.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
            >Stats</x-nav-link>

            @auth
                <x-nav-link
                    x-show="hasFantrax"
                    x-cloak
                    href="{{ route('leagues.index') }}"
                    class="text-lg font-semibold {{ request()->routeIs('leagues.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
                >Leagues</x-nav-link>

                <x-nav-link
                    href="{{ route('stats.units.index') }}"
                    class="text-lg font-semibold {{ request()->routeIs('stats.units.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
                >Line Combos</x-nav-link>


                <x-nav-link
                    x-show="hasCommunities"
                    href="{{ route('communities.index') }}"
                    class="text-lg font-semibold {{ request()->routeIs('communities.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
                    >
                    Communities
                </x-nav-link>
            @endauth
        </div>

        <div>
            @auth
                @php
                    $discordAvatar = auth()->user()->socialAccounts()->where('provider','discord')->value('avatar');
                    $avatarUrl     = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                @endphp

                <button type="button" @click="accountOpen = true" class="block focus:outline-none">
                    <img src="{{ $avatarUrl }}" alt="Open settings"
                         class="h-9 w-9 rounded-full ring-2 ring-gray-200 object-cover" />
                </button>
            @endauth

            @guest
                <a href="{{ route('discord.redirect') }}"
                   class="flex items-center space-x-2 px-3 py-2 bg-[#5865F2] text-white rounded-full hover:bg-[#4752C4]">
                    <img src="{{ asset('images/Discord-Symbol-White.svg') }}" alt="Discord" class="h-6 w-6" />
                    <span class="text-sm font-medium">Sign in</span>
                </a>
            @endguest
        </div>
    </nav>

    {{-- MOBILE Bottom Nav --}}
    <nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-gray-900 text-gray-100 border-t shadow">
        <ul class="flex items-center justify-between px-4 py-2 text-xs font-medium text-gray-300">
            <li class="flex-1 text-center">
                <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
                   class="flex flex-col items-center {{ request()->routeIs('welcome', 'dashboard') ? 'text-gray-300' : '' }}">
                    <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 12l2-2 7-7 7 7 2 2M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3"/>
                    </svg>
                    Home
                </a>
            </li>

            <li class="flex-1 text-center">
                <a href="{{ route('stats.index') }}" class="flex flex-col items-center">
                    <svg class="h-6 w-6 mb-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75A1.125 1.125 0 016.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125Z"/>
                    </svg>
                    Stats
                </a>
            </li>

            @auth
                @php
                    $discordAvatar = auth()->user()->socialAccounts()->where('provider','discord')->value('avatar');
                    $avatarUrl     = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                @endphp

                <li class="flex-1 text-center">
                    <button type="button" @click="leftOpen = true" class="flex flex-col items-center w-full">
                        <svg class="h-6 w-6 mb-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/>
                        </svg>
                        Menu
                    </button>
                </li>

                <li class="flex-1 text-center">
                    <a @click="accountOpen = true" class="flex flex-col items-center">
                        <img src="{{ $avatarUrl }}" alt="Your avatar"
                             class="h-9 w-9 rounded-full ring-2 ring-gray-200 object-cover" />
                        <span class="sr-only">Account</span>
                    </a>
                </li>
            @endauth

            @guest
                <li class="flex-1 text-center">
                    <a href="{{ route('discord.redirect') }}" class="flex flex-col items-center">
                        <img src="{{ asset('images/Discord-Symbol-White.svg') }}" alt="Discord" class="h-6 w-6" />
                        Sign In
                    </a>
                </li>
            @endguest
        </ul>
    </nav>

    {{-- GLOBAL SIDE EFFECTS: lock body scroll while any drawer open --}}
    <div x-effect="document.body.style.overflow = (accountOpen || leftOpen) ? 'hidden' : ''"></div>

    {{-- LEFT NAV DRAWER (mobile) --}}
    @auth
    <div class="fixed inset-0 z-50 sm:hidden pointer-events-none">
        {{-- overlay --}}
        <div x-show="leftOpen" x-transition.opacity.duration.250ms @click="leftOpen=false"
             class="absolute inset-0 bg-black/40 pointer-events-auto"></div>

        {{-- panel --}}
        <section
            x-show="leftOpen"
            x-transition:enter="transform ease-in-out duration-300"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform ease-in-out duration-300"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="absolute left-0 top-0 h-full w-[88%] max-w-sm overflow-hidden bg-[#0B1220] text-gray-200 shadow-2xl ring-1 ring-white/10 rounded-r-2xl pointer-events-auto"
            style="will-change: transform"
            @keydown.escape.window="leftOpen=false"
            @click.away="leftOpen=false"
            aria-modal="true" role="dialog" aria-labelledby="mobile-left-drawer-title"
        >
            <div class="flex items-center justify-between px-4 py-4 border-b border-white/10">
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-indigo-500/20 flex items-center justify-center ring-1 ring-white/10">
                        <svg class="h-5 w-5 text-indigo-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/>
                        </svg>
                    </div>
                    <div>
                        <h2 id="mobile-left-drawer-title" class="text-sm font-semibold">Navigation</h2>
                        <p class="text-xs text-gray-400">Quick access</p>
                    </div>
                </div>
                <button @click="leftOpen=false" class="p-2 rounded-lg hover:bg-white/5 focus:outline-none">
                    <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="h-full overflow-y-auto py-3">
                @php
                    $item = fn($href, $label, $icon, $active=false) => '
                        <a href="'.$href.'"
                           class="group flex items-center gap-3 px-4 py-3 rounded-xl '.($active?'bg-white/10':'hover:bg-white/5').'">
                            '.$icon.'
                            <span class="text-sm">'.e($label).'</span>
                            <svg class="ml-auto h-4 w-4 text-gray-400 group-hover:text-gray-300"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>';
                    $ico  = fn($d) => '<svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/></svg>';
                @endphp

                <div class="px-2 space-y-2">
                    <template x-if="hasFantrax">
                        {!! $item(route('leagues.index'), 'Leagues',
                            $ico('M4.5 6.75h15m-15 4.5h15m-15 4.5h15'),
                            request()->routeIs('leagues.index')) !!}
                    </template>

                    {!! $item(route('stats.units.index'),'Line Combos',
                        $ico('M12 6v12m6-6H6'),
                        request()->routeIs('stats.units.index')) !!}

                    @can('view-nav-communities')
                        <template x-if="hasCommunities">
                            {!! $item(route('communities.index'),'Communities',
                                $ico('M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5'),
                                request()->routeIs('communities.index')) !!}
                        </template>
                    @endcan
                </div>

                <div class="mt-4 mb-2 px-4"><div class="h-px bg-white/10"></div></div>

                <div class="px-2 space-y-2">
                    {!! $item(route('profile.show'),'Profile',
                        $ico('M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0')) !!}

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="w-full group flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-red-500/10 text-red-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3l3-3m-3 3l-3-3m3 3H9"/>
                            </svg>
                            <span class="text-sm">Sign out</span>
                        </button>
                    </form>
                </div>

                <div class="mt-6 px-4 pb-6 text-[10px] text-gray-500">
                    <span>&copy; {{ date('Y') }} DynastyIQ</span>
                </div>
            </div>
        </section>
    </div>
    @endauth

    @include('nav.partials._right-account-drawer')
</div>
