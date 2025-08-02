<div>
    {{-- DESKTOP Top Nav --}}
    <nav class="hidden sm:flex items-center justify-between bg-white border-b shadow px-6 py-4">
        <div class="flex items-center space-x-8">
            <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
               class="text-lg font-semibold {{ request()->routeIs('welcome', 'dashboard') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">
                Home
            </a>

            <a href="{{ route('players.index') }}"
               class="text-lg font-semibold {{ request()->routeIs('profile.show') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">
                Players
            </a>

            @auth
                <a href="{{ route('profile.show') }}"
                   class="text-lg font-semibold {{ request()->routeIs('profile.show') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">
                    Profile
                </a>
            @endauth
        </div>

        <div>
            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="text-lg font-semibold text-gray-600 hover:text-indigo-500">
                        Logout
                    </button>
                </form>
            @endauth

            @guest
                <a href="{{ route('login') }}"
                   class="text-lg font-semibold text-gray-600 hover:text-indigo-500">
                    Log In
                </a>
            @endguest
        </div>
    </nav>

    {{-- MOBILE Bottom Nav --}}
    <nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-gray-900 text-gray-100 border-t shadow">
    <!-- <nav class="sm:hidden z-50 bg-white border-t shadow"> -->
        <ul class="flex items-center justify-between px-4 py-2 text-xs font-medium text-gray-600">

            {{-- Home (conditionally dashboard) --}}
            <li class="flex-1 text-center">
                <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
                   class="flex flex-col items-center {{ request()->routeIs('welcome', 'dashboard') ? 'text-indigo-600' : '' }}">
                    <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 12l2-2 7-7 7 7 2 2M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3"/>
                    </svg>
                    Home
                </a>
            </li>

            {{-- Players --}}
            <li class="flex-1 text-center">
                <a href="{{ route('players.index') }}"
                   class="flex flex-col items-center {{ request()->routeIs('profile.show') ? 'text-indigo-600' : '' }}">
                    <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                      <!-- head -->
                      <circle cx="16" cy="6" r="2"/>
                      <!-- torso, legs, stick -->
                      <path d="M16 8l-2 5m0 0l-3 4m3-4l4 4m1-7l4 3m0 0l1 3M4 21h16"/>
                    </svg>

                    Players
                </a>
            </li>

            @auth
                {{-- Profile --}}
                <li class="flex-1 text-center">
                    <a href="{{ route('profile.show') }}"
                       class="flex flex-col items-center {{ request()->routeIs('profile.show') ? 'text-indigo-600' : '' }}">
                        <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2"
                             viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 4 4 4 4zM4 20v-1a4 4 0 014-4h8a4 4 0 014 4v1"/>
                        </svg>
                        Profile
                    </a>
                </li>

                {{-- Logout --}}
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex flex-col items-center">
                            <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2"
                                 viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/>
                            </svg>
                            Logout
                        </button>
                    </form>
                </li>
            @endauth

            @guest
                {{-- Log In --}}
                <li class="flex-1 text-center">
                    <a href="{{ route('login') }}"
                       class="flex flex-col items-center {{ request()->routeIs('login') ? 'text-indigo-600' : '' }}">
                        <svg class="h-6 w-6 mb-0.5" fill="none" stroke="currentColor" stroke-width="2"
                             viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15 12H3m6-6l-6 6 6 6"/>
                        </svg>
                        Log In
                    </a>
                </li>
            @endguest
        </ul>
    </nav>
</div>
