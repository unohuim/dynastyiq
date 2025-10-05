{{-- Notifications (collapsible) with config fallbacks + AJAX user_preferences updates --}}
@php
    use Illuminate\Support\Facades\DB;

    $userId = auth()->id();
    $defaults = config('notifications.defaults.discord');

    $dmDefault = (bool) data_get($defaults, 'dm', false);
    $channelDefault = (bool) data_get($defaults, 'channel', false);
    $channelNameDefault = data_get($defaults, 'channel-name', null);

    $get = fn ($key) => $userId
    ? DB::table('user_preferences')->where('user_id', $userId)->where('key', $key)->value('value')
    : null;

    $dmRow = $get('notifications.discord.dm');
    $chanRow = $get('notifications.discord.channel');
    $chanNameRow = $get('notifications.discord.channel-name');

    $dmEffective = is_null($dmRow) ? $dmDefault : (bool) json_decode($dmRow, true);
    $channelEffective = is_null($chanRow) ? $channelDefault : (bool) json_decode($chanRow, true);
    $channelNameEffective = is_null($chanNameRow) ? ($channelNameDefault ?? '') : (string) json_decode($chanNameRow,
    true);
@endphp

<div class="rounded-xl" x-data="notificationsSection({
        dmDefault: @js($dmEffective),
        channelDefault: @js($channelEffective),
        channelNameDefault: @js($channelNameEffective),
        updateUrl: @js(route('user.preferences.update')),   // PUT endpoint
        csrf: @js(csrf_token())
     })">

    <button type="button" x-on:click="notifOpen=!notifOpen"
        class="w-full flex items-center justify-between px-3 py-2 rounded-xl hover:bg-white/5">
        <div class="flex items-center gap-3">
            <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M14.25 18.75a2.25 2.25 0 11-4.5 0m9-5.25V11a6.75 6.75 0 10-13.5 0v2.5L4.5 16.5h15l-1.5-3z" />
            </svg>
            <span class="text-sm">Notifications</span>
        </div>
        <svg class="h-4 w-4 text-gray-400 transition-transform duration-200" :class="notifOpen ? 'rotate-90' : ''"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
    </button>

    <div x-show="notifOpen" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1" class="mt-1 px-3">
        <div class="rounded-xl ring-1 ring-white/10 bg-white/5 p-3 space-y-4">

            {{-- Toggle: DIQ Bot Response to DMs --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-200">DIQ Bot Response to DMs</span>
                <button type="button" role="switch" :aria-checked="dmEnabled" x-on:click="dmEnabled=!dmEnabled"
                    class="relative inline-flex h-[17px] w-[32px] items-center rounded-full transition-colors duration-200"
                    :class="dmEnabled ? 'bg-indigo-500' : 'bg-gray-600'">
                    <span class="sr-only">Toggle DM response</span>
                    <span
                        class="h-[13px] w-[13px] bg-white rounded-full transform transition-transform duration-200 shadow"
                        :class="dmEnabled ? 'translate-x-[15px]' : 'translate-x-0'"></span>
                </button>
            </div>

            {{-- Toggle: DIQ Bot Response to private channel --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-200">DIQ Bot Response to private channel</span>
                <button type="button" role="switch" :aria-checked="pcEnabled" x-on:click="pcEnabled=!pcEnabled"
                    class="relative inline-flex h-[17px] w-[32px] items-center rounded-full transition-colors duration-200"
                    :class="pcEnabled ? 'bg-indigo-500' : 'bg-gray-600'">
                    <span class="sr-only">Toggle private-channel response</span>
                    <span
                        class="h-[13px] w-[13px] bg-white rounded-full transform transition-transform duration-200 shadow"
                        :class="pcEnabled ? 'translate-x-[15px]' : 'translate-x-0'"></span>
                </button>
            </div>

            {{-- Text: DIQ Bot Response Private Channel (only when pcEnabled) --}}
            <div class="flex flex-col gap-1" x-show="pcEnabled" x-transition>
                <label class="text-sm text-gray-300">DIQ Bot Response Private Channel</label>
                <input type="text" x-model="pcChannel" x-on:input.debounce.500ms="saveChannel()"
                    placeholder="e.g. stats-bot"
                    class="w-full rounded-lg bg-gray-800/80 border border-gray-700 text-sm px-3 py-2 text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
            </div>

        </div>
    </div>
</div>

<script>
    function notificationsSection(cfg) {
        return {
            notifOpen: false,

            // effective values (config defaults merged with sparse DB overrides)
            dmEnabled: !!cfg.dmDefault,
            pcEnabled: !!cfg.channelDefault,
            pcChannel: cfg.channelNameDefault || '',

            updateUrl: cfg.updateUrl,
            csrf: cfg.csrf,
            saving: false,

            async savePref(key, value) {
                this.saving = true;
                try {
                    await fetch(this.updateUrl, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            key,
                            value
                        }) // null => delete row (fallback to config)
                    });
                } finally {
                    this.saving = false;
                }
            },

            init() {
                this.$watch('dmEnabled', (v) => {
                    this.savePref('notifications.discord.dm', !!v);
                });

                this.$watch('pcEnabled', (v) => {
                    this.savePref('notifications.discord.channel', !!v);

                    if (v && !this.pcChannel) {
                        this.pcChannel = cfg.channelNameDefault || '';
                    }
                    // persist name when enabling; remove when disabling
                    this.savePref('notifications.discord.channel-name', v ? (this.pcChannel || null) : null);
                });
            },

            saveChannel() {
                if (!this.pcEnabled) return;
                this.savePref('notifications.discord.channel-name', this.pcChannel || null);
            }
        }
    }

</script>
