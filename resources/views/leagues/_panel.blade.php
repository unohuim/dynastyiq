{{-- resources/views/leagues/_panel.blade.php --}}
@php
  /** @var \App\Models\PlatformLeague $league */
  $displayId = $league->id;
  $authId = auth()->id();
@endphp

<div class="p-6">
  <div class="px-2 mb-4">
    <div class="text-sm font-semibold text-slate-900">{{ $league->name }}</div>
    <div class="mt-1 text-xs text-slate-500">ID: <span class="font-mono">{{ $displayId }}</span></div>
  </div>

  <x-card-section title="Teams" is-accordian="true" class="border-0">
    <div
      x-data="{
        teams: @js($teams ?? []),

        // pick my team as default when possible
        i: (() => {
          const teams = @js($teams ?? []);
          const me = @js($authId);
          // preferred: boolean flag
          let idx = teams.findIndex(t => t?.owned_by_me === true);
          if (idx !== -1) return idx;
          // fallback: owner_user_ids array
          idx = teams.findIndex(t => (t?.owner_user_ids || []).includes(me));
          return idx !== -1 ? idx : 0;
        })(),

        query: '',
        open: false,
        get current(){ return this.teams[this.i] ?? null },
        get filtered(){
          const q = (this.query || '').toLowerCase().trim();
          return (this.teams || [])
            .map((t, idx) => ({ t, idx }))
            .filter(o => q === '' || (o.t.name || '').toLowerCase().includes(q));
        },
        select(idx){
          this.i = idx;
          this.query = this.teams[idx]?.name ?? '';
          this.open = false;
        }
      }"
      class="space-y-5"
      x-cloak
    >
      {{-- Combobox --}}
      <div class="relative" @click.stop>
        <label for="team-combobox" class="block text-sm font-medium text-slate-900 sr-only">Team</label>
        <div class="flex items-center gap-2">
          <img x-show="current?.owner_avatar_url" :src="current?.owner_avatar_url" alt=""
               class="h-8 w-8 rounded-full object-cover ring-1 ring-slate-200">
          <div class="relative w-full">
            <input
              id="team-combobox"
              type="text"
              x-model="query"
              @focus="open = true; query = ''"  {{-- show all on focus --}}
              @keydown.escape.prevent.stop="open = false"
              class="block w-full rounded-md bg-white py-2 pl-3 pr-10 text-sm text-slate-900 outline outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
              :placeholder="current?.name ?? 'Select team…'"
              autocomplete="off"
            />
            <button type="button"
                    class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none"
                    @click.stop="open = !open" aria-label="Toggle team list">
              <svg viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-400">
                <path fill-rule="evenodd" clip-rule="evenodd"
                      d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"/>
              </svg>
            </button>

            {{-- Options --}}
            <div x-show="open" @click.outside="open = false" x-transition
                 class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg outline outline-1 outline-black/5">
              <template x-for="o in filtered" :key="o.idx">
                <button type="button"
                        class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-slate-900 hover:bg-indigo-600 hover:text-white"
                        @click="select(o.idx)">
                  <template x-if="o.t.owner_avatar_url">
                    <img :src="o.t.owner_avatar_url" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-black/5" alt="">
                  </template>
                  <span class="truncate" x-text="o.t.name ?? 'Team'"></span>
                </button>
              </template>
              <div x-show="filtered.length === 0" class="px-3 py-2 text-slate-500">No matches.</div>
            </div>
          </div>
        </div>
      </div>

      {{-- Roster list --}}
      <div>
        <div class="mb-2 text-xs font-semibold text-slate-700" x-text="current?.name ?? 'Roster'"></div>
        <ul class="divide-y divide-slate-200">
          <template x-for="p in (current?.players ?? [])" :key="p.id">
            <li class="flex items-center justify-between gap-4 px-3 py-2">
              <div class="min-w-0">
                <div class="truncate text-sm font-medium text-slate-900"
                     x-text="p.name || [p.first_name, p.last_name].filter(Boolean).join(' ')"></div>
                <div class="mt-0.5 text-xs text-slate-500">
                  <span x-text="p.position || ''"></span>
                  <span x-show="p.age !== undefined && p.age !== null"> • Age <span x-text="p.age"></span></span>
                </div>
              </div>
            </li>
          </template>
          <template x-if="(current?.players ?? []).length === 0">
            <li class="px-3 py-4 text-sm text-slate-500">No players.</li>
          </template>
        </ul>
      </div>
    </div>
  </x-card-section>
</div>
