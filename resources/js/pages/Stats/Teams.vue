<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ rows: { type: Array, default: () => [] }, filters: { type: Object, default: () => ({}) }, seasons: { type: Array, default: () => [] }, season: { type: String, default: '' } });
const expanded = ref(false);
const search = ref('');
const selectedTeam = ref('');
const selection = ref({ season_id: props.season, game_type: 2, period: 'season', from: '', to: '', slice: 'total', ...props.filters });
watch(() => [props.season, props.filters], () => { selection.value = { ...selection.value, ...props.filters, season_id: props.season }; });
const sort = ref('points');
const descending = ref(true);
const pending = ref(false);
const columns = [['name', 'Team'], ['gp', 'GP'], ['wins', 'W'], ['points', 'PTS'], ['gf', 'GF'], ['ga', 'GA'], ['win_pct', 'W%'], ['gaa', 'GAA'], ['cf_pct', 'Corsi%'], ['hits', 'Hits'], ['blocks', 'Blocks'], ['sat', 'SAT'], ['hdsat', 'HDSAT'], ['saves', 'Saves'], ['sog', 'SOG'], ['pim', 'PIM'], ['pp_pct', 'PP%'], ['pk_pct', 'PK%']];
const counting = ['gf', 'ga', 'hits', 'blocks', 'sat', 'hdsat', 'saves', 'sog', 'pim'];
const slice = computed(() => props.filters.slice || 'total');
const teams = computed(() => props.rows.filter(row => (!selectedTeam.value || row.team === selectedTeam.value) && `${row.name} ${row.team}`.toLowerCase().includes(search.value.toLowerCase())).map(row => {
    const result = { ...row };
    if (slice.value !== 'total') {
        const denominator = slice.value === 'pgp' ? row.gp : row.toi_total_seconds / 3600;
        for (const key of counting) result[key] = row[key] != null && denominator > 0 ? row[key] / denominator : null;
    }
    return result;
}).sort((a, b) => {
    if (a[sort.value] == null || b[sort.value] == null) return (a[sort.value] == null) - (b[sort.value] == null);
    return (sort.value === 'name' ? a.name.localeCompare(b.name) : a[sort.value] - b[sort.value]) * (descending.value ? -1 : 1) || a.team.localeCompare(b.team);
}));
const format = (row, key) => row[key] == null ? '—' : key.endsWith('_pct') ? `${row[key].toFixed(1)}%` : key === 'gaa' || (slice.value !== 'total' && counting.includes(key)) ? row[key].toFixed(2) : String(row[key]);
const label = ([key, text]) => counting.includes(key) && slice.value !== 'total' ? `${text}/${slice.value === 'pgp' ? 'GP' : '60'}` : text;
const changeSort = key => { descending.value = sort.value === key ? !descending.value : !['name', 'gaa'].includes(key); sort.value = key; };
const apply = () => {
    if (selection.value.period === 'range' && (!selection.value.from || !selection.value.to || selection.value.to < selection.value.from)) return;
    router.get('/stats', { view: 'teams', ...selection.value, ...(selection.value.period === 'season' ? { from: '', to: '' } : {}) }, { only: ['leaders', 'selectedSeason', 'statsCacheMissing'], preserveState: true, preserveScroll: true, onStart: () => { pending.value = true; }, onFinish: () => { pending.value = false; } });
};
</script>

<template>
    <section class="-mx-3 min-w-0 overflow-hidden border-y border-[#143144] bg-[#03131f] sm:mx-0 sm:rounded-lg sm:border sm:bg-[#101923]" aria-label="Team statistics" :aria-busy="pending">
        <div class="flex min-h-11 items-center gap-2 border-b border-[#263747] px-2 py-1.5 text-[11px]">
            <div class="relative min-w-0 flex-1 overflow-hidden">
                <Transition mode="out-in" enter-active-class="transition-[transform,opacity] duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-[transform,opacity] duration-500 ease-out motion-reduce:transition-none" enter-from-class="translate-x-6 opacity-0" leave-to-class="-translate-x-6 opacity-0">
                    <div v-if="!expanded" key="search" class="flex items-center gap-2">
                        <label for="team-search" class="sr-only">Search teams</label><input id="team-search" v-model="search" type="search" placeholder="Search teams" class="h-7 w-full max-w-xs rounded border border-[#344655] bg-[#07121d] px-2 text-[11px]" />
                        <span class="hidden text-slate-400 sm:block">All Teams</span>
                    </div>
                    <div v-else key="filters" class="flex flex-wrap items-center gap-1.5">
                        <label class="sr-only" for="team-season">Season</label><select id="team-season" v-model="selection.season_id" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply"><option v-for="item in seasons" :key="item" :value="item">{{ String(item).slice(0,4) }}–{{ String(item).slice(6) }}</option></select>
                        <label class="sr-only" for="team-period">Period</label><select id="team-period" v-model="selection.period" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply"><option value="season">Full season</option><option value="range">Date range</option></select>
                        <template v-if="selection.period === 'range'"><input v-model="selection.from" type="date" aria-label="From date" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply" /><input v-model="selection.to" type="date" aria-label="To date" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply" /></template>
                        <select v-model="selection.game_type" aria-label="Game type" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply"><option :value="2">Regular season</option><option :value="3">Playoffs</option><option :value="1">Preseason</option></select>
                        <select v-model="selectedTeam" aria-label="Team" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200"><option value="">All teams</option><option v-for="team in rows" :key="team.team" :value="team.team">{{ team.team }}</option></select>
                        <select v-model="selection.slice" aria-label="Stat presentation" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 text-[10px] text-slate-200" @change="apply"><option value="total">Totals</option><option value="pgp">Per game</option><option value="p60">Per 60</option></select>
                    </div>
                </Transition>
            </div>
            <button type="button" class="flex h-7 shrink-0 items-center gap-1 rounded border px-2 text-[11px] transition-colors duration-300 focus-visible:ring-2 focus-visible:ring-sky-400" :class="expanded ? 'border-sky-500 bg-sky-600 text-white' : 'border-[#344655] text-slate-200'" :aria-expanded="expanded" @click="expanded = !expanded"><svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" class="h-3.5 w-3.5"><path d="M3 5h14M6 10h8M8 15h4" stroke-width="1.5" /></svg>Filters</button>
        </div>
        <p v-if="pending" role="status" class="px-3 py-1 text-[10px] text-sky-300">Updating team statistics…</p>
        <div class="flex items-center justify-between px-2 py-1 text-[10px] sm:hidden"><span>All Teams</span><select :value="sort" aria-label="Sort teams" class="h-7 max-w-full rounded border border-[#344655] bg-[#07121d] px-1.5 py-0 text-[10px] text-slate-200" @change="changeSort($event.target.value)"><option v-for="column in columns" :key="column[0]" :value="column[0]">{{ label(column) }}</option></select><button type="button" aria-label="Reverse sort order" @click="descending = !descending">{{ descending ? '↓' : '↑' }}</button></div>
        <div class="overflow-x-auto overscroll-x-contain" role="region" aria-label="Team statistics; scroll for more columns" tabindex="0" data-compact-team-table>
            <table class="w-full border-separate border-spacing-0 text-left text-[10px] tabular-nums sm:text-[11px]">
                <thead class="bg-[#071d2c] text-[9px] uppercase tracking-wide text-slate-400 sm:bg-transparent sm:text-[11px] sm:normal-case sm:tracking-normal"><tr><th v-for="column in columns" :key="column[0]" class="whitespace-nowrap border-b border-[#143144] px-2 py-1 font-medium sm:border-[#344655] sm:py-2 sm:font-bold" :class="column[0] === 'name' ? 'sticky left-0 z-20 min-w-[112px] bg-[#071d2c] sm:static sm:bg-transparent' : 'min-w-10'" :aria-sort="sort === column[0] ? descending ? 'descending' : 'ascending' : 'none'"><button type="button" class="flex h-6 items-center gap-1 focus-visible:ring-2 focus-visible:ring-sky-400 sm:h-auto" @click="changeSort(column[0])"><span v-if="column[0] === 'name'" class="w-4 text-[9px] sm:hidden" aria-hidden="true">#</span>{{ label(column) }} {{ sort === column[0] ? descending ? '↓' : '↑' : '' }}</button></th></tr></thead>
                <tbody><tr v-for="(team, index) in teams" :key="team.team" class="h-11 sm:h-12 sm:hover:bg-[#192836]" data-compact-team-row><td v-for="column in columns" :key="column[0]" class="whitespace-nowrap border-b border-[#143144] px-2 py-0 sm:border-[#263747] sm:py-1 sm:font-normal sm:text-inherit" :class="column[0] === 'name' ? 'sticky left-0 z-10 bg-[#03131f] sm:static sm:bg-transparent' : column[0] === sort ? 'font-semibold text-white' : 'text-slate-300'"><span v-if="column[0] === 'name'" class="flex items-center gap-1 sm:gap-2" :title="team.name"><span class="w-4 text-[10px] text-slate-400 sm:hidden">{{ index + 1 }}</span><span class="inline-flex h-9 items-center gap-1 text-[11px] font-medium sm:rounded sm:bg-[#223444] sm:px-1 sm:text-xs sm:font-semibold"><img v-if="team.logo" :src="team.logo" alt="" class="h-6 w-6 shrink-0 object-contain sm:h-8 sm:w-8" loading="lazy" />{{ team.team }}</span><span class="hidden sm:inline">{{ team.name }}</span></span><template v-else>{{ format(team, column[0]) }}</template></td></tr></tbody>
            </table>
        </div>
        <p v-if="!teams.length" class="px-3 py-8 text-center text-xs text-slate-400">No completed team results match these filters.</p>
        <p class="px-3 py-2 text-[9px] text-slate-400">GAA uses actual game time per 60 minutes. Corsi% uses even-strength attempts. — indicates unavailable source data.</p>
    </section>
</template>

