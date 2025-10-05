{{-- resources/views/communities/leagues/show.blade.php --}}
<x-community-hub-layout
    :communities="$vm['sidebar']"
    :mobileBreakpoint="$vm['meta']['mobile_breakpoint']">

    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
        <h2 id="desktopCommunityTitle" class="text-2xl font-semibold text-slate-900">
            {{ $vm['header']['title'] }}
        </h2>

        @if ($vm['header']['can_edit'])
            <button
                type="button"
                id="btnEditName"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200"
            >
                <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.25 2.25 0 013.182 3.182L7.5 19.313l-4.5 1.125L4.125 16.5 16.862 3.487z"/>
                </svg>
                Edit name
            </button>
        @endif
    </div>

    {{-- Body --}}
    <div x-data="{ openFantrax:false, openDiscord:false }">
        <div class="grid gap-6 p-6 lg:grid-cols-3">


            <x-card-section title="Connections" is-accordian="true">
                <ul class="space-y-3">
                    {{-- Row: Fantasy platform --}}
                    <li class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="h-10 w-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center" aria-hidden="true">
                                    @if ($vm['platform']['connected'])
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 text-emerald-600"><path fill="currentColor" d="M3 12 12 3l9 9-9 9-9-9Z"/></svg>
                                    @else
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 text-slate-500"><circle cx="12" cy="12" r="8" fill="currentColor"/></svg>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[15px] font-semibold text-slate-900">
                                        {{ $vm['platform']['title'] }}
                                    </div>
                                    <div class="mt-0.5 text-xs">
                                        <span class="{{ $vm['platform']['status_class'] }}">{{ $vm['platform']['status_text'] }}</span>
                                        <span class="text-slate-400"> • {{ $vm['platform']['subtext'] }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="shrink-0">
                                @if (! $vm['platform']['connected'])
                                    <button
                                        type="button"
                                        @click="openFantrax = true"
                                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                                        {{ $vm['platform']['action_label'] }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                                        {{ $vm['platform']['action_label'] }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </li>

                    {{-- Row: Discord --}}
                    <li class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="h-10 w-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center" aria-hidden="true">
                                    @if ($vm['discord']['avatar_url'])
                                        <img src="{{ $vm['discord']['avatar_url'] }}" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-slate-200">
                                    @else
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 text-indigo-600"><path fill="currentColor" d="M7 5h10a2 2 0 0 1 2 2v10l-3-2-2 2-3-2-2 2-2-2-3 2V7a2 2 0 0 1 2-2z"/></svg>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[15px] font-semibold text-slate-900">
                                        {{ $vm['discord']['title'] }}
                                    </div>
                                    <div class="mt-0.5 text-xs">
                                        <span class="{{ $vm['discord']['status_class'] }}">{{ $vm['discord']['status_text'] }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="shrink-0">
                                @if (! $vm['discord']['connected'])
                                    <button type="button"
                                            @click="openDiscord = true"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                                        Connect server
                                    </button>
                                @elseif ($vm['discord']['can_change'])
                                    <button type="button"
                                            @click="openDiscord = true"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                                        Change
                                    </button>
                                @endif
                            </div>
                        </div>
                    </li>
                </ul>
            </x-card-section>

            {{-- Teams --}}
            @if ($vm['platform']['connected'])
                <x-card-section title="Teams" is-accordian="true">
                    <ul class="space-y-3">
                        @foreach ($vm['teams'] as $team)
                            <li class="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="h-10 w-10 rounded-full bg-white border border-slate-200 flex items-center justify-center" aria-hidden="true">
                                            @if (! empty($team['owner_avatar_url']))
                                                <img src="{{ $team['owner_avatar_url'] }}" alt="" class="h-8 w-8 rounded-full object-cover ring-1 ring-slate-200">
                                            @else
                                                <svg viewBox="0 0 24 24" class="h-5 w-5 text-indigo-600"><path fill="currentColor" d="M7 5h10a2 2 0 0 1 2 2v10l-3-2-2 2-3-2-2 2-2-2-3 2V7a2 2 0 0 1 2-2z"/></svg>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-[15px] font-semibold text-slate-900">
                                                {{ $team['name'] }}
                                            </div>
                                            <div class="mt-0.5 text-xs text-gray-400">
                                                {{ $team['id'] }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-card-section>
            @endif
        </div>

        {{-- Fantrax modal (only when not connected) --}}
        @if (! $vm['platform']['connected'])
            <x-new-league-modal
                :model="'openFantrax'"
                title="Connect Fantrax"
                subtitle="Link this league to your Fantrax league."
                :actionUrl="$vm['fantrax_modal']['action_url']"
                :fantraxOptions="$vm['fantrax_modal']['options']"
                :fantraxConnected="$vm['fantrax_modal']['connected']"
                :showDiscord="false"
                :allowRename="false"
                :initialName="$vm['fantrax_modal']['initial_name']"
                submitLabel="Save"
                formId="connectFantraxForm"
            />
        @endif

        {{-- Discord chooser modal --}}
        <div x-cloak x-show="openDiscord"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40"
             @keydown.escape.window="openDiscord=false"
             @click.self="openDiscord=false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" x-trap.noscroll="openDiscord" tabindex="-1">
                <h3 class="mb-2 text-base font-semibold text-slate-900">Select a Discord server</h3>

                <form id="changeDiscordForm" data-action="{{ $vm['discord']['action_url'] }}">
                    <div class="mb-4 max-h-64 space-y-2 overflow-auto">
                        @forelse ($vm['discord']['options'] as $opt)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-2">
                                <input type="radio"
                                       name="discord_server_id"
                                       value="{{ $opt['id'] }}"
                                       @checked($opt['selected'])
                                       required>
                                @if (! empty($opt['avatar_url']))
                                    <img src="{{ $opt['avatar_url'] }}" class="h-6 w-6 rounded-full ring-1 ring-slate-200" alt="">
                                @endif
                                <span class="text-sm text-slate-900">{{ $opt['name'] }}</span>
                            </label>
                        @empty
                            <div class="text-sm text-slate-600">No connected Discord servers.</div>
                        @endforelse
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button"
                                @click="openDiscord=false"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                            Cancel
                        </button>
                        <button type="submit"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
        <h1 class="text-2xl font-semibold text-gray-600"></h1>
        <p class="mt-2 text-sm text-gray-400">League: {{ $vm['header']['title'] }}</p>
    </div>
</x-community-hub-layout>

{{-- Inline JS (form submits) --}}
<script>
(() => {
  const form = document.getElementById('connectFantraxForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const url = form.dataset.action || form.getAttribute('data-action') || '{{ $vm['fantrax_modal']['action_url'] }}';
    if (!url) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const name  = form.querySelector('[name="name"]')?.value?.trim() || '';

    const platform         = form.querySelector('[name="platform"]')?.value || '';
    const platformLeagueId = form.querySelector('[name="platform_league_id"]')?.value || '';
    const discordId        = form.querySelector('[name="discord_server_id"]')?.value || '';

    if (!name) {
      alert('Please enter a league name.');
      return;
    }
    if (platform && !platformLeagueId) {
      alert('Please select or enter a Fantrax league ID.');
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    const payload = {
      name,
      ...(discordId ? { discord_server_id: discordId } : {}),
      ...(platform ? { platform, platform_league_id: platformLeagueId } : {}),
    };

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error('Save failed');
      window.location.reload();
    } catch (err) {
      alert('Could not save changes.');
    } finally {
      btn.disabled = false;
    }
  });
})();

(() => {
  const form = document.getElementById('changeDiscordForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = form.dataset.action || form.getAttribute('data-action') || '{{ $vm['discord']['action_url'] }}';
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const discordId = form.querySelector('input[name="discord_server_id"]:checked')?.value;
    if (!discordId) return;

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token
        },
        body: JSON.stringify({ discord_server_id: discordId })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok !== true) throw new Error('Save failed');
      window.location.reload();
    } catch (err) {
      alert('Could not update Discord server.');
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
