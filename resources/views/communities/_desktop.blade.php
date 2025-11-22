{{-- resources/views/communities/partials/desktop.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
    $currentOrg = ($activeCommunity ?? null)
        ? $communities->firstWhere('id', $activeCommunity->id)
        : $communities->first();
    $user = auth()->user();

    // Highest org-scoped role (by numeric level, higher = higher)
    $highestRole = null;
    if ($user && $currentOrg) {
        $highestRole = $user->roles()
            ->wherePivot('organization_id', $currentOrg->id)
            ->orderByDesc('level')
            ->first(); // expects roles table has `level` and `name`
    }

    // Admin permission = level >= 10
    $canEdit = $highestRole && (int)($highestRole->level ?? 0) >= 10;

    // Connected Discord servers (from DB relation; respects eager load)
    $guilds = $currentOrg
        ? ($currentOrg->relationLoaded('discordServers')
            ? $currentOrg->discordServers
            : $currentOrg->discordServers()->get())
        : collect();
@endphp

<div class="grid grid-cols-[280px,1fr] gap-6">
    {{-- Sidebar: communities list --}}
    <aside class="rounded-2xl border border-slate-200 bg-white p-3">
        <div class="mb-2 px-2 text-xs font-semibold tracking-wider text-slate-600 uppercase">Communities</div>
        <ul id="communityList" class="space-y-1">
            @foreach ($communities as $i => $org)
                <li>
                    <button
                        type="button"
                        class="community-item group w-full rounded-xl px-3 py-2 text-left text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        data-slug="{{ $org->slug }}"
                        data-name="{{ $org->name }}"
                        data-org-id="{{ $org->id }}"
                        aria-current="{{ ($currentOrg && $currentOrg->id === $org->id) ? 'true' : 'false' }}"
                    >
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700">
                                {{ strtoupper(mb_substr($org->short_name ?? $org->name, 0, 2)) }}
                            </span>
                            <span class="flex-1 truncate">{{ $org->name }}</span>
                            <span class="hidden rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-600 group-aria-[current=true]:inline">
                                Active
                            </span>
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    </aside>

    {{-- Main: Community Manager Hub --}}
    <main class="rounded-2xl border border-slate-200 bg-white p-0 overflow-hidden">
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
            <div>
                <h2 id="desktopCommunityTitle" class="text-2xl font-semibold text-slate-900">
                    {{ $currentOrg?->name }}
                </h2>
                <div class="mt-2">
                    @if($highestRole)
                        <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700">
                            {{ ucfirst($highestRole->name) }}
                        </span>
                    @else
                        <span class="text-xs text-slate-500">No role assigned</span>
                    @endif
                </div>
            </div>

            {{-- Edit name (admins only) --}}
            @if($canEdit && $currentOrg)
                <button
                    type="button"
                    id="btnEditName"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.25 2.25 0 013.182 3.182L7.5 19.313l-4.5 1.125L4.125 16.5 16.862 3.487z"/>
                    </svg>
                    Edit name
                </button>
            @endif
        </div>

        {{-- Body --}}
        <div class="grid gap-6 p-6 lg:grid-cols-3">
            {{-- Card: Edit Community Name (admins only) --}}
            @if($canEdit && $currentOrg)
            <section class="lg:col-span-1 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold tracking-wider text-slate-600 uppercase">Community Details</h3>
                <form id="formEditName" class="space-y-3" method="POST"
                      action="{{ route('organizations.settings.update', ['organization' => $currentOrg->id]) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="enabled" value="1">
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Name</label>
                        <input
                            name="name"
                            value="{{ $currentOrg->name }}"
                            class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-slate-900 placeholder-slate-400 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200"
                        />
                    </div>
                    <div class="pt-2">
                        <button type="submit"
                            class="w-full rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                            Save Changes
                        </button>
                    </div>
                </form>
            </section>
            @endif




            @include('communities._desktop-connected-servers')

            @include('communities._desktop-memberships')

            {{-- Leagues: live-gated by commissioner_tools --}}
            <div
                class="col-span-full lg:col-span-3"
                x-data="{
                    enabled: {{ $currentOrg?->commissionerToolsEnabled() ? 'true' : 'false' }},
                    orgId: {{ $currentOrg?->id ?? 'null' }}
                }"
                x-show="enabled"
                x-cloak
                x-on:org:settings-updated.window="
                    if ($event.detail?.organization_id === orgId) {
                        const v = $event.detail?.settings?.commissioner_tools;
                        if (v !== undefined) enabled = !!v;
                    }
                "
            >
                @php
                    $currentOrg = $communities->first();
                    $leagues = $currentOrg ? ($currentOrg->relationLoaded('leagues')
                        ? $currentOrg->leagues
                        : $currentOrg->leagues()->get()) : collect();

                    $guilds = $currentOrg ? ($currentOrg->relationLoaded('discordServers')
                        ? $currentOrg->discordServers
                        : $currentOrg->discordServers()->get()) : collect();
                @endphp

                @include('communities._desktop-leagues', ['leagues' => $leagues, 'guilds' => $guilds, 'currentOrg' => $currentOrg, 'canEdit' => $canEdit])
            </div>


            {{-- Wide: Hub summary --}}
            <section class="lg:col-span-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Manager Hub</h3>
                    <div class="text-xs text-slate-600">Tools &amp; integrations for {{ $currentOrg?->short_name ?? $currentOrg?->name }}</div>
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-900">Role</div>
                        <p class="mt-1 text-xs text-slate-600">
                            {{ $highestRole ? ucfirst($highestRole->name) : '—' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-900">Discord</div>
                        <p class="mt-1 text-xs text-slate-600">{{ $guilds->count() }} server(s) connected.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-900">Branding</div>
                        <p class="mt-1 text-xs text-slate-600">Name updates reflect across the app.</p>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<!-- Keep your existing form markup with id="formEditName" -->

<script>
(() => {
  const form = document.getElementById('formEditName');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const url   = "{{ route('organizations.settings.update', ['organization' => $currentOrg->id]) }}";
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const name  = form.querySelector('input[name="name"]').value.trim();
    const btn   = form.querySelector('button[type="submit"]');

    btn.disabled = true;

    try {
      const res = await fetch(url, {
        method: 'PUT',
        headers: {
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ enabled: true, name })
      });

      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error('Save failed');

      // Update UI
      document.getElementById('desktopCommunityTitle').textContent = data.organization.name;
      form.querySelector('input[name="name"]').value = data.organization.name;

      const activeItem = document.querySelector('.community-item[aria-current="true"]');
        if (activeItem) {
          activeItem.dataset.name = data.organization.name;
          const nameSpan = activeItem.querySelector('.flex-1');
          if (nameSpan) nameSpan.textContent = data.organization.name;
        }

      window.toast?.success('Community name updated.');
    } catch (err) {
      window.toast?.error('Could not save changes.') ?? console.error(err);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>

