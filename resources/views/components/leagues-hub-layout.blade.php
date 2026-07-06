{{-- resources/views/components/leagues-hub-layout.blade.php --}}
@php
    $list   = collect($leagues ?? []);
    $options = collect($leagueOptions ?? $list);
    $active = (string) ($activeId ?? '');
    $platformLabels = [
        'fantrax' => 'Fantrax',
        'yahoo' => 'Yahoo',
    ];
    $leagueCount = $list->count();
    $leagueCountLabel = $leagueCount === 1 ? 'league' : 'leagues';
@endphp



<x-app-layout>
    <div
        class="min-h-0 overflow-hidden px-4 py-4 sm:px-6 lg:px-8"
        style="height: calc(100vh - 5.5rem);"
        data-component="leagues-hub-layout"
        x-data="{ leagueOptionsOpen: false }"
        x-effect="document.documentElement.classList.toggle('overflow-hidden', leagueOptionsOpen); document.body.classList.toggle('overflow-hidden', leagueOptionsOpen)"
        @keydown.escape.window="leagueOptionsOpen = false"
    >
        <div class="grid h-full min-h-0 gap-4" style="grid-template-columns: 17rem minmax(0, 1fr);">
            <aside class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-3 py-3">
                    <div class="min-w-0">
                        <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                            My Leagues
                        </span>
                        <span class="mt-0.5 block text-[11px] text-slate-500">
                            {{ $leagueCount }} connected {{ $leagueCountLabel }}
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @can('refresh-leagues')
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
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
                        @endcan
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            data-leagues-options-open
                            aria-controls="leaguesOptionsDrawer"
                            :aria-expanded="leagueOptionsOpen ? 'true' : 'false'"
                            aria-label="League list options"
                            title="League list options"
                            @click="leagueOptionsOpen = true"
                        >
                            <svg
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.197.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.431.992a7.723 7.723 0 0 1 0 .255c-.007.378.138.75.43.991l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.073-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.197-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.241.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.991l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.073 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <ul id="leagueList" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-2 py-2">
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
                                class="league-item group relative block overflow-hidden rounded-md px-2.5 py-2 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200 {{ $isActive ? 'bg-indigo-50 text-indigo-950 ring-1 ring-inset ring-indigo-100' : '' }}"
                                data-league-id="{{ $id }}"
                                data-panel-url="{{ $panel }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                            >
                                <div class="flex items-center gap-2.5">
                                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200 group-aria-[current=page]:bg-indigo-600 group-aria-[current=page]:text-white group-aria-[current=page]:ring-indigo-600">
                                        {{ strtoupper(mb_substr($name, 0, 2)) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-medium leading-4 text-slate-900 group-aria-[current=page]:text-indigo-950">{{ $name }}</span>
                                        <span class="mt-0.5 flex items-center gap-1.5 text-[10px] font-medium leading-3 text-slate-500">
                                            <span>{{ $platform !== '' ? $platformLabel : 'League' }}</span>
                                            <span class="h-0.5 w-0.5 rounded-full bg-slate-300"></span>
                                            <span>League</span>
                                        </span>
                                    </span>
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $isActive ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
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

            <main id="leagueMain" class="min-h-0 min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {{ $slot }}
            </main>
        </div>

        <div
            x-cloak
            x-show="leagueOptionsOpen"
            class="pointer-events-none fixed inset-0 z-50"
            data-leagues-options-drawer
            :aria-hidden="leagueOptionsOpen ? 'false' : 'true'"
        >
            <div
                x-show="leagueOptionsOpen"
                x-transition.opacity.duration.300ms
                class="pointer-events-auto absolute inset-0 bg-slate-900/30"
                data-leagues-options-overlay
                @click="leagueOptionsOpen = false"
            ></div>
            <section
                id="leaguesOptionsDrawer"
                x-show="leagueOptionsOpen"
                x-transition:enter="transition-transform ease-out duration-500 motion-reduce:transition-none"
                x-transition:enter-start="translate-x-full motion-reduce:translate-x-0"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-300 motion-reduce:transition-none"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full motion-reduce:translate-x-0"
                class="pointer-events-auto absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-xl"
                data-leagues-options-panel
                role="dialog"
                aria-modal="true"
                aria-labelledby="leaguesOptionsTitle"
                tabindex="-1"
            >
                <div class="flex items-start justify-between border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="leaguesOptionsTitle" class="text-sm font-semibold text-slate-950">League list options</h2>
                        <p class="mt-1 text-xs text-slate-500">Choose which leagues appear in your list.</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        data-leagues-options-close
                        aria-label="Close league list options"
                        @click="leagueOptionsOpen = false"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3">
                    <div class="divide-y divide-slate-100">
                        @forelse ($options as $lg)
                            @php
                                $id = (string) data_get($lg, 'id', '');
                                $name = (string) data_get($lg, 'name', '');
                                $platform = (string) data_get($lg, 'platform', '');
                                $platformLabel = $platformLabels[$platform] ?? ucfirst($platform);
                                $isVisible = (bool) data_get($lg, 'is_visible', true);
                            @endphp
                            <div
                                class="flex items-center justify-between gap-3 px-2 py-2.5"
                                data-league-option-row
                                data-league-id="{{ $id }}"
                                data-league-name="{{ $name }}"
                                data-league-href="{{ route('leagues.index', ['active' => $id]) }}"
                                data-league-panel-url="{{ route('leagues.panel', $id) }}"
                                data-league-platform-label="{{ $platform !== '' ? $platformLabel : 'League' }}"
                                x-data="{
                                    isVisible: @js($isVisible),
                                    saving: false,
                                    leagueId: @js($id),
                                    visibilityUrl: @js(route('leagues.visibility.update', $id)),
                                    notify(type, message) {
                                        if (window.toast?.[type]) {
                                            window.toast[type](message);
                                            return;
                                        }

                                        if (window.toast?.show) {
                                            window.toast.show(message, { type });
                                            return;
                                        }

                                        window.dispatchEvent(new CustomEvent('toast', { detail: { type, message } }));
                                    },
                                    syncList(visible) {
                                        const root = document.querySelector('[data-component=\'leagues-hub-layout\']');
                                        const link = root?.querySelector(`#leagueList a.league-item[data-league-id='${this.leagueId}']`);
                                        const item = link?.closest('li');

                                        if (item) {
                                            item.classList.toggle('hidden', !visible);
                                        }
                                    },
                                    async toggle() {
                                        if (this.saving) return;

                                        const previous = this.isVisible;
                                        const next = !previous;
                                        this.saving = true;
                                        this.isVisible = next;

                                        try {
                                            const response = await fetch(this.visibilityUrl, {
                                                method: 'PUT',
                                                headers: {
                                                    Accept: 'application/json',
                                                    'Content-Type': 'application/json',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.content ?? '',
                                                    'X-Requested-With': 'XMLHttpRequest',
                                                },
                                                body: JSON.stringify({ is_visible: next }),
                                            });
                                            const payload = await response.json().catch(() => ({}));

                                            if (!response.ok) {
                                                throw new Error(payload.message || 'Could not update league visibility.');
                                            }

                                            this.syncList(next);
                                            this.notify('success', payload.message || 'League visibility updated.');
                                        } catch (error) {
                                            this.isVisible = previous;
                                            this.notify('error', error.message || 'Could not update league visibility.');
                                        } finally {
                                            this.saving = false;
                                        }
                                    },
                                }"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $name }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $platform !== '' ? $platformLabel : 'League' }}</p>
                                </div>
                                <form
                                    method="POST"
                                    action="{{ route('leagues.visibility.update', $id) }}"
                                    data-league-visibility-form
                                >
                                    @csrf
                                    @method('PUT')
                                    <input
                                        type="hidden"
                                        name="is_visible"
                                        value="{{ $isVisible ? '1' : '0' }}"
                                        :value="isVisible ? '1' : '0'"
                                        data-league-visibility-input
                                    >
                                    <button
                                        type="button"
                                        class="group relative inline-flex shrink-0 items-center rounded-full transition-colors duration-200 ease-out focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
                                        :class="isVisible ? 'bg-indigo-600' : 'bg-slate-200'"
                                        style="height: 14px; width: 28px;"
                                        data-league-visibility-toggle
                                        data-league-id="{{ $id }}"
                                        data-league-visibility-url="{{ route('leagues.visibility.update', $id) }}"
                                        :data-league-visible="isVisible ? 'true' : 'false'"
                                        :aria-pressed="isVisible ? 'true' : 'false'"
                                        :aria-label="(isVisible ? 'Hide' : 'Show') + ' ' + @js($name)"
                                        :disabled="saving"
                                        @click.stop.prevent="toggle()"
                                    >
                                        <span
                                            class="inline-block rounded-full bg-white shadow-sm transition-transform duration-200 ease-out motion-reduce:transition-none"
                                            style="height: 10px; width: 10px;"
                                            :style="`height: 10px; width: 10px; transform: translateX(${isVisible ? '16px' : '2px'});`"
                                            data-league-visibility-knob
                                            aria-hidden="true"
                                        ></span>
                                    </button>
                                    <noscript>
                                        <button type="submit">
                                            {{ $isVisible ? 'Hide' : 'Show' }}
                                        </button>
                                    </noscript>
                                </form>
                            </div>
                        @empty
                            <div class="px-2 py-8 text-sm text-slate-500">No leagues are available.</div>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
