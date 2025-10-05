{{-- resources/views/components/new-league-modal.blade.php --}}
@props([
    // Visibility model (Alpine var name)
    'model'             => 'open',
    // Endpoint the form should post to (string)
    'actionUrl'         => '',
    // Options: [{ id, name, avatar? }]
    'guildOptions'      => [],
    // Options: [{ name, platform_league_id, sport? }] (or [{ id, name }])
    'fantraxOptions'    => [],
    // If null, inferred from auth()->user()->fantraxSecret()->exists()
    'fantraxConnected'  => null,

    // UI text
    'title'             => 'Create League',
    'subtitle'          => 'Name your league and choose a Discord server (optional).',
    'submitLabel'       => 'Create League',

    // Behavior flags
    'showDiscord'       => true,   // hide Discord picker (e.g., Fantrax connect flow)
    'allowRename'       => true,   // lock the name when false
    'initialName'       => '',     // prefill name (useful in connect flows)

    // Form id (lets caller bind listeners)
    'formId'            => 'createLeagueForm',
])

@php
    // Infer Fantrax connected if not explicitly provided
    if ($fantraxConnected === null) {
        $fantraxConnected = auth()->check() && method_exists(auth()->user(), 'fantraxSecret') && auth()->user()->fantraxSecret()->exists();
    }
@endphp

<div x-show="{{ $model }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
     role="dialog" aria-modal="true" @keydown.escape.window="{{ $model }} = false">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="{{ $model }} = false"></div>

    <div x-transition class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
                <h4 class="text-base font-semibold text-slate-900">{{ $title }}</h4>
                <p class="mt-0.5 text-xs text-slate-500">{{ $subtitle }}</p>
            </div>
            <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                    @click="{{ $model }} = false" aria-label="Close">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="{{ $formId }}"
              data-action="{{ $actionUrl }}"
              class="px-5 py-4 space-y-4" method="POST"
              x-data="{
                name: @js($initialName),
                lockName: {{ $allowRename ? 'false' : 'true' }},
                platform: '',
                // Fantrax
                fOpen: false,
                fSelected: null,
                fOptions: @js($fantraxOptions),
                manualId: '',
                onSelectFantrax(opt) {
                  this.fSelected = opt;
                  this.lockName = true;
                  this.name = opt?.name || this.name;
                  this.platform = 'fantrax';
                  this.manualId = opt.platform_league_id || opt.id || '';
                  this.fOpen = false;
                },
                onManualIdInput(e) {
                  this.manualId = e.target.value.trim();
                  if (this.manualId.length) {
                    this.fSelected = null;   // reset dropdown
                    this.platform = 'fantrax';
                  } else if (!this.fSelected) {
                    this.platform = '';
                  }
                }
              }">
            @csrf

            {{-- League name (lockable) --}}
            <div>
                <label class="block text-xs font-medium text-slate-700">League Name <span class="text-rose-500">*</span></label>
                <input name="name" x-model="name" :readonly="lockName"
                       placeholder="e.g. Valhalla Hockey League"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-slate-900 placeholder-slate-400 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200"/>
            </div>

            {{-- Discord Server (optional) --}}
            @if($showDiscord)
            <div x-data="dropdownSelect({ options: @js($guildOptions) })" class="relative">
                <label class="block text-xs font-medium text-slate-700">Discord Server</label>
                <input type="hidden" name="discord_server_id" :value="selected?.id || ''">

                <button type="button"
                        @click="openList = !openList"
                        @keydown.escape.window="openList = false"
                        class="relative mt-1 w-full rounded-xl border border-slate-300 bg-white pr-9 pl-3 py-2 text-left text-slate-900 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200">
                    <div class="flex items-center gap-3">
                        <template x-if="selected?.avatar">
                            <img :src="selected.avatar" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-slate-200">
                        </template>
                        <span class="block truncate" x-text="selected?.name || '— Choose a server —'"></span>
                    </div>
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2">
                        <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
                        </svg>
                    </span>
                </button>

                <div x-show="openList" x-cloak x-transition
                     @click.outside="openList=false"
                     class="absolute z-50 mt-2 w-full rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 max-h-72 overflow-auto">
                    <ul class="py-2">
                        <template x-for="opt in options" :key="opt.id">
                            <li>
                                <button type="button" @click="select(opt)"
                                        class="w-full px-3 py-2.5 flex items-center gap-3 hover:bg-indigo-50">
                                    <template x-if="opt.avatar">
                                        <img :src="opt.avatar" alt="" class="h-7 w-7 rounded-full object-cover ring-1 ring-slate-200">
                                    </template>
                                    <template x-if="!opt.avatar">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-200"></span>
                                    </template>
                                    <span class="text-sm text-slate-900" x-text="opt.name"></span>
                                    <svg x-show="selected?.id === opt.id" class="ml-auto h-5 w-5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
            @endif

            {{-- Fantrax League (dropdown only if connected) --}}
            @if($fantraxConnected)
            <div class="relative">
                <label class="block text-xs font-medium text-slate-700">Fantrax League</label>

                <button type="button"
                        @click="fOpen = !fOpen"
                        @keydown.escape.window="fOpen = false"
                        class="relative mt-1 w-full rounded-xl border border-slate-300 bg-white pr-9 pl-3 py-2 text-left text-slate-900 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200">
                    <div class="flex items-center gap-3">
                        <span class="block truncate" x-text="fSelected?.name || '— Select a Fantrax league —'"></span>
                    </div>
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2">
                        <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
                        </svg>
                    </span>
                </button>

                <div x-show="fOpen" x-cloak x-transition
                     @click.outside="fOpen=false"
                     class="absolute z-50 mt-2 w-full rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 max-h-72 overflow-auto">
                    <ul class="py-2">
                        <template x-for="opt in fOptions" :key="opt.platform_league_id || opt.id">
                            <li>
                                <button type="button" @click="onSelectFantrax(opt)"
                                        class="w-full px-3 py-2.5 flex items-center gap-3 hover:bg-indigo-50">
                                    <span class="text-sm text-slate-900" x-text="opt.name"></span>
                                    <span class="ml-auto text-[11px] text-slate-500" x-text="opt.platform_league_id || opt.id"></span>
                                    <svg x-show="fSelected && ((fSelected.platform_league_id||fSelected.id)===(opt.platform_league_id||opt.id))"
                                         class="h-5 w-5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
            @endif

            {{-- Always: Fantrax League ID (manual) --}}
            <div>
                <label class="block text-xs font-medium text-slate-700">Fantrax League ID</label>
                <input placeholder="Enter ID manually"
                       @input="onManualIdInput($event)"
                       :value="manualId"
                       class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 placeholder-slate-400 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200"/>
            </div>

            {{-- Hidden fields for controller --}}
            <input type="hidden" name="platform" :value="platform">
            <input type="hidden" name="platform_league_id"
                   :value="fSelected ? (fSelected.platform_league_id || fSelected.id) : manualId">

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" @click="{{ $model }} = false"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit"
                        class="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    {{ $submitLabel }}
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Lightweight helper (only define once) --}}
<script>
window.__dropdownSelectInit = window.__dropdownSelectInit || (function () {
  window.dropdownSelect = function ({ options = [] }) {
    return {
      openList: false,
      options,
      selected: null,
      select(opt) { this.selected = opt; this.openList = false; },
    };
  };
  return true;
})();
</script>
