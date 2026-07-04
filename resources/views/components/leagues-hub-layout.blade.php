{{-- resources/views/components/leagues-hub-layout.blade.php --}}
@php
    $list   = collect($leagues ?? []);
    $active = (string) ($activeId ?? '');
    $platformLabels = [
        'fantrax' => 'Fantrax',
        'yahoo' => 'Yahoo',
    ];
    $leagueCount = $list->count();
    $leagueCountLabel = $leagueCount === 1 ? 'league' : 'leagues';
@endphp



<x-app-layout>
    <div class="px-4 py-5 sm:px-6 lg:px-8" data-component="leagues-hub-layout">
        <div class="grid grid-cols-[18rem,1fr] gap-5">
            <aside class="flex min-h-[calc(100vh-7rem)] flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between px-1">
                    <div>
                        <span class="block text-xs font-semibold tracking-wider text-slate-600 uppercase">
                            My Leagues
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $leagueCount }} connected {{ $leagueCountLabel }}
                        </span>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm hover:bg-slate-50 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
                        data-provider-resync-button
                        data-provider-resync-url="{{ route('leagues.resync') }}"
                        data-provider-resync-label="all leagues"
                        aria-label="Refresh all leagues"
                        title="Refresh all leagues"
                    >
                        <svg
                            class="h-4 w-4"
                            data-provider-resync-icon
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </button>
                </div>
                <ul id="leagueList" class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                    @foreach ($list as $lg)
                        @php
                            $id    = (string) data_get($lg, 'id', '');
                            $name  = (string) data_get($lg, 'name', '');
                            $href  = (string) data_get($lg, 'href', route('leagues.index', ['active' => $id]));
                            $panel = route('leagues.panel', $id);
                            $platform = (string) data_get($lg, 'platform', '');
                            $platformLabel = $platformLabels[$platform] ?? ucfirst($platform);
                            $isActive = $id !== '' && $id === $active;
                        @endphp
                        <li>
                            <a
                                href="{{ $href }}"
                                class="league-item group relative block overflow-hidden rounded-xl border px-3 py-3 text-slate-700 transition hover:border-blue-200 hover:bg-blue-50/40 focus:outline-none focus:ring-2 focus:ring-indigo-200 {{ $isActive ? 'border-blue-400 bg-blue-50/70 shadow-sm ring-1 ring-blue-100' : 'border-transparent' }}"
                                data-league-id="{{ $id }}"
                                data-panel-url="{{ $panel }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                            >
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 group-aria-[current=page]:bg-slate-950 group-aria-[current=page]:text-white">
                                        {{ strtoupper(mb_substr($name, 0, 2)) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-slate-900">{{ $name }}</span>
                                        <span class="mt-1 flex items-center gap-2 text-[11px] font-medium text-slate-500">
                                            <span>{{ $platform !== '' ? $platformLabel : 'League' }}</span>
                                            <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                                            <span>League</span>
                                        </span>
                                    </span>
                                    <span class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full {{ $isActive ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                                </div>
                                <span
                                    class="absolute inset-x-0 bottom-0 hidden h-0.5 bg-slate-100"
                                    data-league-sync-progress
                                    aria-hidden="true"
                                >
                                    <span
                                        class="block h-full w-0 transition-[width,background-color] duration-300"
                                        data-league-sync-progress-bar
                                    ></span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </aside>

            <main id="leagueMain" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-app-layout>
