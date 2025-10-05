
@php
    $guildOptions = ($guilds ?? collect())->map(function ($g) {
        $icon = data_get($g->meta, 'icon');
        $ext  = $icon && str_starts_with($icon, 'a_') ? 'gif' : 'png';
        $avatar = $icon ? "https://cdn.discordapp.com/icons/{$g->discord_guild_id}/{$icon}.{$ext}?size=64" : null;

        return [
            'id'     => $g->id,
            'name'   => $g->discord_guild_name ?? ('Server '.$g->discord_guild_id),
            'avatar' => $avatar,
        ];
    })->values();
@endphp


<div x-data="dropdownSelect({ options: @js($guildOptions) })" class="relative">
    <label class="block text-xs font-medium text-slate-700">Discord Server</label>

    {{-- hidden input to submit the selected id --}}
    <input type="hidden" name="discord_server_id" :value="selected?.id || ''">

    <!-- trigger (no gray placeholder when nothing selected) -->
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :class="selected?.avatar ? 'pl-11 pr-9' : 'pl-4 pr-9'"
            class="relative mt-1 w-full rounded-xl border border-slate-300 bg-white py-2 text-left text-slate-900 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200">

        <!-- render avatar ONLY if selected -->
        <template x-if="selected?.avatar">
            <img :src="selected.avatar" alt=""
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-6 w-6 rounded-full object-cover ring-1 ring-slate-200">
        </template>

        <span class="block truncate" x-text="selected?.name || '— Choose a server —'"></span>

        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2">
            <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
            </svg>
        </span>
    </button>



    {{-- dropdown --}}
    <div x-show="open" x-cloak x-transition
         @click.outside="open=false"
         class="absolute z-50 mt-2 w-full rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 max-h-72 overflow-auto">
        <ul class="py-2">
            <template x-for="opt in options" :key="opt.id">
                <li>
                    <button type="button"
                            @click="select(opt)"
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

<script>
function dropdownSelect({ options = [] }) {
    return {
        open: false,
        options,
        selected: null,
        select(opt) { this.selected = opt; this.open = false; },
    };
}
</script>
