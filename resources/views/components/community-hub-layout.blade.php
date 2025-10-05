{{-- resources/views/components/community-hub-layout.blade.php --}}
@php
    $list   = collect($communities ?? []);
    $isHub  = (bool) ($onHub ?? false);
    $bp     = (int) ($mobileBreakpoint ?? 768);
    $init   = $initial ?? null;
    $active = $activeId ?? null;
@endphp

@once
  @vite('resources/js/community-hub.js')
@endonce

<x-app-layout>
    <div class="py-6 px-4 sm:px-6 lg:px-8"
         data-component="community-hub-layout"
         data-is-hub="{{ $isHub ? '1' : '0' }}"
         data-mobile-breakpoint="{{ $bp }}"
         data-initial='@json($init)'>
        <div id="rootView"></div>

        <div class="grid grid-cols-[280px,1fr] gap-6">
            {{-- Sidebar --}}
            <aside class="rounded-2xl border border-slate-200 bg-white p-3">
                <div class="mb-2 px-2 text-xs font-semibold tracking-wider text-slate-600 uppercase">
                    Communities
                </div>
                <ul id="communityList" class="space-y-1">
                    @foreach ($list as $org)
                        @php
                            $orgId    = data_get($org, 'id');
                            $orgSlug  = (string) data_get($org, 'slug', $orgId);
                            $orgName  = (string) data_get($org, 'name', '');
                            $orgHref  = (string) data_get($org, 'href', route('communities.index', ['active' => $orgId]));
                            $short    = (string) data_get($org, 'short_name', $orgName);
                            $isActive = (int) $active === (int) $orgId;
                        @endphp
                        <li>
                            <a
                                href="{{ $orgHref }}"
                                class="community-item group block rounded-xl px-3 py-2 text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200 {{ $isActive ? 'ring-2 ring-indigo-200 bg-slate-50' : '' }}"
                                data-slug="{{ $orgSlug }}"
                                data-name="{{ $orgName }}"
                                data-org-id="{{ $orgId }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700">
                                        {{ strtoupper(mb_substr($short, 0, 2)) }}
                                    </span>
                                    <span class="flex-1 truncate">{{ $orgName }}</span>
                                    <span class="hidden rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-600 group-aria-[current=page]:inline">
                                        Active
                                    </span>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </aside>

            {{-- Main --}}
            <main class="rounded-2xl border border-slate-200 bg-white p-0 overflow-hidden">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-app-layout>
