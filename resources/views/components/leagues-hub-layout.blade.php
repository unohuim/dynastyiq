{{-- resources/views/components/leagues-hub-layout.blade.php --}}
@php
    $list   = collect($leagues ?? []);
    $active = (string) ($activeId ?? '');
@endphp

@once
  @vite('resources/js/leagues-hub.js')
@endonce

<x-app-layout>
    <div class="py-6 px-4 sm:px-6 lg:px-8" data-component="leagues-hub-layout">
        <div class="grid grid-cols-[280px,1fr] gap-6">
            <aside class="rounded-2xl border border-slate-200 bg-white p-3">
                <div class="mb-2 px-2 text-xs font-semibold tracking-wider text-slate-600 uppercase">
                    Leagues
                </div>
                <ul id="leagueList" class="space-y-1">
                    @foreach ($list as $lg)
                        @php
                            $id    = (string) data_get($lg, 'id', '');
                            $name  = (string) data_get($lg, 'name', '');
                            $href  = (string) data_get($lg, 'href', route('leagues.index', ['active' => $id]));
                            $panel = route('leagues.panel', $id);
                            $isActive = $id !== '' && $id === $active;
                        @endphp
                        <li>
                            <a
                                href="{{ $href }}"
                                class="league-item group block rounded-xl px-3 py-2 text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200 {{ $isActive ? 'ring-2 ring-indigo-200 bg-slate-50' : '' }}"
                                data-league-id="{{ $id }}"
                                data-panel-url="{{ $panel }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700">
                                        {{ strtoupper(mb_substr($name, 0, 2)) }}
                                    </span>
                                    <span class="flex-1 truncate">{{ $name }}</span>
                                    <span class="hidden rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-600 group-aria-[current=page]:inline">
                                        Active
                                    </span>
                                </div>
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
