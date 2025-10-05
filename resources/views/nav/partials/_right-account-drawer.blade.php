{{-- resources/views/partials/_right-account-drawer.blade.php --}}

{{-- RIGHT ACCOUNT DRAWER (desktop+mobile) --}}
<div class="fixed inset-0 z-50 pointer-events-none">
    {{-- overlay --}}
    <div x-show="accountOpen" x-transition.opacity.duration.250ms @click="accountOpen=false"
        class="absolute inset-0 bg-black/40 pointer-events-auto"></div>

    {{-- panel --}}
    <section x-show="accountOpen" x-transition:enter="transform ease-in-out duration-300"
        x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transform ease-in-out duration-300" x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute right-0 top-0 h-full w-full max-w-md overflow-hidden bg-[#0B1220] text-gray-200 shadow-2xl ring-1 ring-white/10 pointer-events-auto"
        style="will-change: transform;" @keydown.escape.window="accountOpen=false" @click.away="accountOpen=false"
        aria-modal="true" role="dialog" aria-labelledby="user-drawer-title">

        <div class="flex items-center justify-between px-5 py-4 border-b border-white/10">
            <div class="flex items-center gap-3">
                @php
                    $discordAvatar = auth()->user()?->socialAccounts()?->where('provider','discord')->value('avatar');
                    $avatarUrl = $discordAvatar ?: 'https://www.gravatar.com/avatar/?d=mp&s=64';
                @endphp
                <img src="{{ $avatarUrl }}" class="h-10 w-10 rounded-full ring-2 ring-white/10 object-cover" alt="">
                <div>
                    <h2 id="user-drawer-title" class="text-sm font-semibold">Account</h2>
                    <a href="{{ route('profile.show') }}"
                        class="text-xs text-indigo-300 hover:text-indigo-200">View profile</a>
                </div>
            </div>
            <button @click="accountOpen=false" class="p-2 rounded-lg hover:bg-white/5 focus:outline-none">
                <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="h-full overflow-y-auto px-3 py-4">
            @php
                $item = fn($href, $label, $icon, $active=false) => '
                <a href="'.$href.'"
                    class="group flex items-center gap-3 px-3 py-2 rounded-xl '.($active?'bg-white/10':'hover:bg-white/5').'">
                    '.$icon.'
                    <span class="text-sm">'.e($label).'</span>
                </a>';
                $ico = fn($d) => '<svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'" />
                </svg>';
            @endphp

            {{-- INTEGRATIONS --}}
            <div class="mb-6">
                <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Integrations</h3>
                <div class="space-y-1">
                    @include('nav.partials._fantrax')

                    <a href="{{ route('discord.join') }}" target="_blank" rel="noopener"
                        class="group flex items-center justify-between px-3 py-2 rounded-xl hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <img src="{{ asset('images/logo.png') }}" alt="DynastyIQ"
                                class="h-5 w-5 object-contain">
                            <span class="text-sm">Our Discord</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <template x-if="hasDiscord">
                                <span
                                    class="text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-200 ring-1 ring-emerald-400/20">Connected</span>
                            </template>
                            <template x-if="!hasDiscord">
                                <span
                                    class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-200 ring-1 ring-indigo-400/20">Join</span>
                            </template>
                            <svg class="h-4 w-4 text-gray-400 group-hover:text-gray-300" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </a>
                </div>
            </div>





            {{-- SETTINGS --}}
            <div class="mb-6" x-data="{ notifOpen:false, dmEnabled:true, pcEnabled:false, pcChannel:'' }">
                <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">User Settings
                </h3>

                <div class="space-y-1">
                    {!! $item('#','Theme', $ico('M12 3v18M3 12h18')) !!}

                    @include('nav.partials._right-account-drawer-notifications')


                    {!! $item('#','Timezone', $ico('M12 6v6l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0')) !!}
                </div>
            </div>

            {{-- CONFIG --}}
            <div class="mb-6">
                <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Configuration
                </h3>
                <div class="space-y-1">
                    {!! $item('#','Default Perspective', $ico('M4.5 6.75h15m-15 4.5h15m-15 4.5h15')) !!}
                    {!! $item('#','Table Density', $ico('M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5')) !!}
                    {!! $item('#','Data Refresh', $ico('M4.5 4.5v6h6M19.5 19.5v-6h-6')) !!}
                </div>
            </div>


            @include('nav.partials._right-account-drawer-org-options')




            {{-- ACCOUNT / SIGN OUT --}}
            <div class="mb-2">
                <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">Account</h3>
                <div class="space-y-1">
                    <a href="{{ route('profile.show') }}"
                        class="group flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/5">
                        <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0" />
                        </svg>
                        <span class="text-sm">Profile</span>
                    </a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                            class="w-full group flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-red-500/10 text-red-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3l3-3m-3 3l-3-3m3 3H9" />
                            </svg>
                            <span class="text-sm">Sign out</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>
