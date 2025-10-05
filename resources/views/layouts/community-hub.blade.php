{{-- resources/views/components/communities-layout.blade.php --}}
@props([
    'communities' => collect(),
    'mobileBreakpoint' => config('viewports.mobile', 768),
])

<x-app-layout>
    <div class="py-6 px-4 sm:px-6 lg:px-8">
        <div id="rootView"></div>

        {{ $slot }}


        <script>
            (function () {
                const mobileBreakpoint = {{ $mobileBreakpoint }};
                const root = document.getElementById('rootView');
                const tplDesktop = document.getElementById('tpl-desktop');
                const tplMobile  = document.getElementById('tpl-mobile');

                const state = {
                    isMobile: window.innerWidth < mobileBreakpoint,
                    activeCommunity: {
                        slug: @json($communities->first()->slug),
                        name: @json($communities->first()->name),
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
    </div>
</x-app-layout>
