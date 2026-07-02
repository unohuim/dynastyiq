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
    <div class="py-6 px-4 sm:px-6 lg:px-8" data-component="leagues-hub-layout">
        <div class="grid grid-cols-[280px,1fr] gap-6">
            <aside class="rounded-lg border border-slate-200 bg-white p-3">
                <div class="mb-3 flex items-center justify-between border-b border-slate-100 px-2 pb-3">
                    <div>
                        <span class="block text-xs font-semibold tracking-wider text-slate-600 uppercase">
                            Leagues
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $leagueCount }} connected {{ $leagueCountLabel }}
                        </span>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
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
                <ul id="leagueList" class="space-y-1.5">
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
                                class="league-item group relative block overflow-hidden rounded-lg border border-transparent px-3 py-2.5 text-slate-700 transition hover:border-slate-200 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200 {{ $isActive ? 'ring-2 ring-indigo-200 bg-slate-50' : '' }}"
                                data-league-id="{{ $id }}"
                                data-panel-url="{{ $panel }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                            >
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-700 group-aria-[current=page]:bg-indigo-50 group-aria-[current=page]:text-indigo-700">
                                        {{ strtoupper(mb_substr($name, 0, 2)) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-slate-900">{{ $name }}</span>
                                        <span class="mt-1 block text-[11px] font-medium tracking-wide text-slate-400">
                                            {{ $platform !== '' ? strtoupper($platformLabel) : 'LEAGUE' }}
                                        </span>
                                    </span>
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

            <main id="leagueMain" class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-app-layout>
