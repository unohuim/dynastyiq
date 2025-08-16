    <div>
    {{-- DESKTOP Top Nav --}}
    <nav class="hidden sm:flex items-center justify-between bg-white border-b shadow px-6 py-4">
        <div class="flex items-center space-x-8">
            <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
               class="text-lg font-semibold {{ request()->routeIs('welcome', 'dashboard') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">
                Home
            </a>

            <a href="{{ route('players.index') }}"
               class="text-lg font-semibold {{ request()->routeIs('players.index') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-500' }}">
                Players
            </a>


            @auth 
                <div>
                    <form method="POST" action="{{ route('logout') }}"> 
                        @csrf <button type="submit" class="text-lg font-semibold text-gray-600 hover:text-indigo-500"> Logout </button> 
                    </form> 
                </div>  
            @endauth

            
        </div>

        <div>
            @auth
                @php
                    $discordAvatar = auth()->user()
                        ->socialAccounts()
                        ->where('provider','discord')
                        ->value('avatar');

                    $avatarUrl = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                @endphp

                <a href="{{ route('profile.show') }}" class="block">
                    <img src="{{ $avatarUrl }}"
                         alt="Your avatar"
                         class="h-9 w-9 rounded-full ring-2 ring-gray-200 object-cover" />
                </a>
            @endauth

            @guest
                <a href="{{ route('discord.redirect') }}"
                   class="flex items-center space-x-2 px-3 py-2 bg-[#5865F2] text-white rounded-full hover:bg-[#4752C4]">
                    <img src="{{ asset('images/Discord-Symbol-White.svg') }}"
                         alt="Discord"
                         class="h-6 w-6">
                    <span class="text-sm font-medium">Sign in</span>
                </a>

            @endguest
        </div>
    </nav>


    {{-- MOBILE Bottom Nav --}}
    <nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-gray-900 text-gray-100 border-t shadow">
    <!-- <nav class="sm:hidden z-50 bg-white border-t shadow"> -->
        <ul class="flex items-center justify-between px-4 py-2 text-xs font-medium text-gray-300">

            {{-- Home (conditionally dashboard) --}}
            <li class="flex-1 text-center">
                <a href="{{ auth()->check() ? route('dashboard') : route('welcome') }}"
                   class="flex flex-col items-center {{ request()->routeIs('welcome', 'dashboard') ? 'text-gray-300' : '' }}">
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
                   class="flex flex-col items-center {{ request()->routeIs('profile.show') ? 'text-gray-300' : '' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
  <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
</svg>



                    Players
                </a>
            </li>

            @auth
                {{-- Profile --}}
                <li class="flex-1 text-center">
                    <a href="{{ route('profile.show') }}"
                       class="flex flex-col items-center {{ request()->routeIs('profile.show') ? 'text-gray-300' : '' }}">
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
                    <a href="{{ route('discord.redirect') }}"
                       class="flex flex-col items-center {{ request()->routeIs('login') ? 'text-indigo-600' : '' }}">
                        <img src="{{ asset('images/Discord-Symbol-White.svg') }}"
                            alt="Discord" class="h-6 w-6">
                        Sign In
                    </a>
                </li>
            @endguest
        </ul>
    </nav>
</div>
