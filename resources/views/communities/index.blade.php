{{-- resources/views/communities/index.blade.php --}}
@php /** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
    $mobileBreakpoint = config('viewports.mobile', 768);
    $activeCommunity = $activeCommunity ?? $communities->first();
@endphp

<x-app-layout>
    <div class="py-6 px-4 sm:px-6 lg:px-8">
        @if ($communities->isEmpty())
        <div
            class="rounded-2xl border border-white/10 bg-white/5 p-10 text-center text-gray-300"
        >
            <h2 class="text-xl font-semibold">No communities yet</h2>
            <p class="mt-2 text-sm text-gray-400">
                When you join communities, they’ll appear here.
            </p>
        </div>
        @else
        <div id="rootView"></div>

        {{-- MOBILE TEMPLATE --}}
        <template id="tpl-mobile">
            <div>
                <div
                    class="sticky top-0 z-10 -mx-4 border-b border-white/10 bg-black/60 px-4 py-3 backdrop-blur"
                >
                    <label
                        class="block text-xs font-semibold tracking-wider text-gray-400 uppercase"
                        >Community</label
                    >
                    <select
                        id="mobileCommunitySelect"
                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-gray-200 outline-none focus:border-white/20"
                        data-active-slug="{{ $activeCommunity?->slug }}"
                    >
                        @foreach ($communities as $org)
                        <option
                            value="{{ $org->slug }}"
                            data-name="{{ $org->name }}"
                        >
                            {{ $org->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-4">
                    <div
                        class="rounded-2xl border border-white/10 bg-white/5 p-6 shadow"
                    >
                        <h2
                            id="mobileCommunityTitle"
                            class="text-2xl font-semibold text-white"
                        >
                            {{ $activeCommunity?->name ?? $communities->first()->name }}
                        </h2>
                    </div>
                </div>
            </div>
        </template>

        {{-- DESKTOP TEMPLATE VIA PARTIAL --}}
        <template id="tpl-desktop">
            @include('communities._desktop', ['communities' => $communities, 'activeCommunity' => $activeCommunity])
        </template>
        @endif
    </div>

    @if (!$communities->isEmpty())
    <script>
        (function () {
            const mobileBreakpoint = {{ $mobileBreakpoint }};
            const root = document.getElementById('rootView');
            const tplDesktop = document.getElementById('tpl-desktop');
            const tplMobile  = document.getElementById('tpl-mobile');

            const state = {
                isMobile: window.innerWidth < mobileBreakpoint,
                activeCommunity: {
                    slug: @json($activeCommunity?->slug ?? $communities->first()->slug),
                    name: @json($activeCommunity?->name ?? $communities->first()->name),
                },
            };

            function bindDesktopEvents() {
                const title = root.querySelector('#desktopCommunityTitle');
                const items = root.querySelectorAll('.community-item');
                if (title) title.textContent = state.activeCommunity.name;

                items.forEach(btn => {
                    const isActive = btn.dataset.slug === state.activeCommunity.slug;
                    btn.setAttribute('aria-current', String(isActive));
                    btn.classList.toggle('ring-2', isActive);
                    btn.classList.toggle('ring-white/20', isActive);
                    btn.classList.toggle('bg-white/5', isActive);

                    btn.addEventListener('click', () => {
                        state.activeCommunity = { slug: btn.dataset.slug, name: btn.dataset.name };
                        render();
                    });
                });
            }

            function bindMobileEvents() {
                const title = root.querySelector('#mobileCommunityTitle');
                const select = root.querySelector('#mobileCommunitySelect');

                if (title) title.textContent = state.activeCommunity.name;
                if (select) {
                    if (select.value !== state.activeCommunity.slug) {
                        select.value = state.activeCommunity.slug;
                    }
                    select.addEventListener('change', (e) => {
                        const opt = e.target.selectedOptions[0];
                        state.activeCommunity = { slug: opt.value, name: opt.getAttribute('data-name') };
                        if (title) title.textContent = state.activeCommunity.name;
                    });
                }
            }

            function render() {
                const nextIsMobile = window.innerWidth < mobileBreakpoint;
                if (root.children.length === 0 || nextIsMobile !== state.isMobile) {
                    state.isMobile = nextIsMobile;
                    root.innerHTML = '';
                    const frag = (state.isMobile ? tplMobile : tplDesktop).content.cloneNode(true);
                    root.appendChild(frag);
                }
                if (state.isMobile) bindMobileEvents(); else bindDesktopEvents();
            }

            render();
            window.addEventListener('resize', () => render());
        })();
    </script>
    @endif
</x-app-layout>
