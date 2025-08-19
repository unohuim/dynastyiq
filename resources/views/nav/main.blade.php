

{{-- resources/views/livewire/navigation-menu.blade.php (replace content) --}}
<div x-data="{ open:false }">

    {{-- DESKTOP Top Nav --}}
    <nav class="hidden sm:flex items-center justify-between bg-white border-b shadow px-6 py-2">
        <div 
            x-data="{ hasFantrax: {{ auth()->check() && auth()->user()->fantraxSecret ? 'true' : 'false' }} }"
            
            x-init="window.addEventListener('fantrax:connected', () => hasFantrax = true)"
            class="flex items-center space-x-8">

            <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
               class="text-lg font-semibold {{ request()->routeIs('welcome', 'dashboard') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">Home</a>

            <a href="{{ route('players.index') }}"
               class="text-lg font-semibold {{ request()->routeIs('players.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">Players</a>

            @auth
                <a href="{{ route('players.index') }}"
                    class="text-lg font-semibold {{ request()->routeIs('players.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">Perspectives</a>

                <a 
                    x-cloak 
                    x-show="hasFantrax"
                    href="{{ route('leagues.index') }}"
                    class="text-lg font-semibold {{ request()->routeIs('leagues.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}"
                >
                    Leagues
                </a>
            @endauth
        </div>

        <div>
            @auth
                @php
                    $discordAvatar = auth()->user()->socialAccounts()->where('provider','discord')->value('avatar');
                    $avatarUrl = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                @endphp

                <button type="button" @click="open = true"
                        class="block focus:outline-none">
                    <img src="{{ $avatarUrl }}" alt="Open settings"
                         class="h-9 w-9 rounded-full ring-2 ring-gray-200 object-cover"/>
                </button>
            @endauth

            @guest
                <a href="{{ route('discord.redirect') }}"
                   class="flex items-center space-x-2 px-3 py-2 bg-[#5865F2] text-white rounded-full hover:bg-[#4752C4]">
                    <img src="{{ asset('images/Discord-Symbol-White.svg') }}" alt="Discord" class="h-6 w-6">
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2 7-7 7 7 2 2M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3"/>
                    </svg>Home
                </a>
            </li>

            <li class="flex-1 text-center">
                <a href="{{ route('players.index') }}" class="flex flex-col items-center">
                    <svg class="h-6 w-6 mb-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75A1.125 1.125 0 016.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125Z"/>
                    </svg>Players
                </a>
            </li>

            @auth
              @php
                $discordAvatar = auth()->user()->socialAccounts()->where('provider','discord')->value('avatar');
                $avatarUrl = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
              @endphp


                <li class="flex-1 text-center">
                    <a href="{{ route('players.index') }}" class="flex flex-col items-center">
                        <svg class="h-6 w-6 mb-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                        Menu
                    </a>
                </li>




              <li class="flex-1 text-center">
                <a href="{{ route('profile.show') }}"
                   @click.prevent="open = true"
                   class="flex flex-col items-center">
                  <img src="{{ $avatarUrl }}"
                       alt="Your avatar"
                       class="h-9 w-9 rounded-full ring-2 ring-gray-200 object-cover" />
                  <span class="sr-only"></span>
                </a>
              </li>
            @endauth

            @guest
                <li class="flex-1 text-center">
                    <a href="{{ route('discord.redirect') }}" class="flex flex-col items-center">
                        <img src="{{ asset('images/Discord-Symbol-White.svg') }}" alt="Discord" class="h-6 w-6">Sign In
                    </a>
                </li>
            @endguest
        </ul>
    </nav>

    {{-- RIGHT DRAWER (desktop+mobile) --}}
    <div
        x-show="open"
        x-transition.opacity
        @click.self="open=false"
        @keydown.escape.window="open=false"
        class="fixed inset-0 z-50 flex"
        aria-modal="true" role="dialog" aria-labelledby="user-drawer-title">

        {{-- Overlay --}}
        <div class="absolute inset-0 bg-black/40"></div>

        {{-- Panel --}}
        <section
            x-show="open"
            x-transition:enter="transform transition ease-in-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in-out duration-300"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="ml-auto relative h-full w-full max-w-md overflow-hidden bg-[#0B1220] text-gray-200 shadow-2xl ring-1 ring-white/10">

            {{-- Header --}}
            <div class="flex items-center justify-between px-5 py-4 border-b border-white/10">
                <div class="flex items-center gap-3">
                    @php
                        $discordAvatar = auth()->user()?->socialAccounts()?->where('provider','discord')->value('avatar');
                        $avatarUrl = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                    @endphp
                    <img src="{{ $avatarUrl }}" class="h-10 w-10 rounded-full ring-2 ring-white/10 object-cover" alt="">
                    <div>
                        <h2 id="user-drawer-title" class="text-sm font-semibold">Account</h2>
                        <a href="{{ route('profile.show') }}" class="text-xs text-indigo-300 hover:text-indigo-200">View profile</a>
                    </div>
                </div>
                <button @click="open=false" class="p-2 rounded-lg hover:bg-white/5 focus:outline-none">
                    <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Content --}}
            <div class="h-full overflow-y-auto px-3 py-4">
                {{-- Section helper --}}
                @php
                    $item = fn($href, $label, $icon, $active=false) => '
                        <a href="'.$href.'" class="group flex items-center gap-3 px-3 py-2 rounded-xl '.($active?'bg-white/10':'hover:bg-white/5').'">
                            '.$icon.'
                            <span class="text-sm">'.e($label).'</span>
                        </a>';
                    $ico = fn($d) => '<svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/></svg>';
                @endphp

                {{-- INTEGRATIONS --}}
                <div class="mb-6">
                    <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Integrations</h3>
                    <div class="space-y-1">
                        {!! $item(route('discord.redirect'),'Discord',
                            $ico('M20 7.5c-1.125-.5-2.25-.875-3.5-1 0 0-.5.875-.75 1.25a9.5 9.5 0 00-3.5 0C11 7.375 10.5 6.5 10.5 6.5c-1.25.125-2.375.5-3.5 1a11.5 11.5 0 00-2.5 13.5s1.125-.5 2.75-1.25l.5-.25.375-.25c.75.5 1.75.875 2.875 1 1.875.25 3.75 0 5.5-.5.5-.125 1.125-.375 1.75-.75l.375.25.5.25c1.625.75 2.75 1.25 2.75 1.25A11.5 11.5 0 0020 7.5z') ) !!}

                        @include('nav.partials._fantrax')

                        
                    </div>
                </div>

                {{-- SETTINGS --}}
                <div class="mb-6">
                    <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">User Settings</h3>
                    <div class="space-y-1">
                        {!! $item('#','Theme',
                            $ico('M12 3v18M3 12h18')) !!}
                        {!! $item('#','Notifications',
                            $ico('M14.25 18.75a2.25 2.25 0 11-4.5 0m9-5.25V11a6.75 6.75 0 10-13.5 0v2.5L4.5 16.5h15l-1.5-3z')) !!}
                        {!! $item('#','Timezone',
                            $ico('M12 6v6l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0')) !!}
                    </div>
                </div>

                {{-- CONFIG --}}
                <div class="mb-6">
                    <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Configuration</h3>
                    <div class="space-y-1">
                        {!! $item('#','Default Perspective',
                            $ico('M4.5 6.75h15m-15 4.5h15m-15 4.5h15')) !!}
                        {!! $item('#','Table Density',
                            $ico('M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5')) !!}
                        {!! $item('#','Data Refresh',
                            $ico('M4.5 4.5v6h6M19.5 19.5v-6h-6')) !!}
                    </div>
                </div>

                {{-- ACCOUNT / SIGN OUT --}}
                <div class="mb-2">
                    <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Account</h3>
                    <div class="space-y-1">
                        <a href="{{ route('profile.show') }}" class="group flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/5">
                            <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/>
                            </svg>
                            <span class="text-sm">Profile</span>
                        </a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full group flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-red-500/10 text-red-300">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3l3-3m-3 3l-3-3m3 3H9"/>
                                </svg>
                                <span class="text-sm">Sign out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
