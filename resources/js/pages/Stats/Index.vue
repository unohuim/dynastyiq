<script setup>
import { computed, ref } from 'vue';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import StatsTable from './Table.vue';
import LeaderList from './LeaderList.vue';
import LeaderCardRow from './LeaderCardRow.vue';
import CategoryNav from './CategoryNav.vue';
import LoadingState from './LoadingState.vue';

const props = defineProps({
    initialPayload: { type: Object, default: null }, apiUrl: { type: String, required: true },
    connectedLeagues: { type: Array, default: () => [] }, perspectives: { type: Array, default: () => [] },
    selectedPerspective: { type: String, default: '' }, mobileBreakpoint: { type: Number, default: 640 },
    view: { type: String, default: 'leaders' }, leaders: { type: Object, default: () => ({}) },
    selectedSeason: { type: String, default: '' },
});
const hero = computed(() => props.view === 'goalies' ? props.leaders.goalie_hero : props.leaders.hero);
const compactHero = computed(() => ['skaters', 'goalies'].includes(props.view));
const categories = computed(() => props.view === 'goalies' ? (props.leaders.goalie_categories ?? []) : (compactHero.value ? ['goals', 'assists', 'ppp'] : ['goals', 'assists', 'ppp', 'sog', 'hits', 'blocks']).map(key => (props.leaders.categories ?? []).find(category => category.key === key)).filter(Boolean));
const stripCategories = computed(() => ['points', 'goals', 'assists', 'sog', 'ppg', 'hits'].map(key => (props.leaders.categories ?? []).find(category => category.key === key)).filter(Boolean));
const season = computed(() => props.leaders.season ? `${props.leaders.season.slice(0, 4)}–${props.leaders.season.slice(6)}` : 'Current season');
const pendingView = ref(null);
const failedView = ref(null);
const seamlessTableSwitch = computed(() => ['skaters', 'goalies'].includes(props.view)
    && ['skaters', 'goalies', 'prospects'].includes(pendingView.value) && props.initialPayload != null && props.leaders.season != null);
const activeView = computed(() => props.view === 'skaters' && props.selectedPerspective === 'prospects' ? 'prospects' : props.view);
const href = view => `/stats?view=${view === 'prospects' ? 'skaters&perspective=prospects' : view}${props.leaders.season || props.selectedSeason ? `&season_id=${props.leaders.season || props.selectedSeason}` : ''}`;
const navLinks = computed(() => ['Leaders', 'Skaters', 'Goalies', 'Prospects', 'Teams'].map(label => ({ label, view: label.toLowerCase(), href: href(label.toLowerCase()) })));
const startVisit = view => { pendingView.value = view; failedView.value = null; };
const finishVisit = () => { pendingView.value = null; };
const failVisit = view => { pendingView.value = null; failedView.value = view; };
const reload = key => router.reload({ only: [key] });
const metrics = computed(() => props.view === 'goalies'
    ? { quality_start_pct: 'QS%', gsax: 'GSAx', ev_sv_pct: 'EVSV%', saves: 'Saves' }
    : { points: 'Points', ppg: 'P/GP', sog: 'SOG', toi: 'TOI/GP' });
const format = (value, key) => !Number.isFinite(value) ? '—' : key === 'toi' || key === 'toi_seconds'
    ? `${Math.floor(value / 60)}:${String(Math.floor(value % 60)).padStart(2, '0')}`
    : key === 'gsax' ? `${value > 0 ? '+' : ''}${value.toFixed(2)}` : ['ppg', 'gaa'].includes(key) ? value.toFixed(2) : ['ev_sv_pct', 'quality_start_pct'].includes(key) ? `${value.toFixed(2)}%` : key === 'cf_pct' ? `${value.toFixed(1)}%` : String(Math.round(value));
const rank = value => !value ? '—' : `${value}${value % 100 >= 11 && value % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[value % 10] ?? 'th')} in NHL`;
const delta = value => Number.isFinite(value) ? `${value > 0 ? '+' : ''}${value.toFixed(1)}` : '—';
const colors = ['fill-rose-500', 'fill-amber-400', 'fill-emerald-500', 'fill-blue-500', 'fill-sky-600'];
const externalPlayers = computed(() => (props.leaders.skaters ?? []).map(row => ({ ...row, external: true })));
const smashPlayers = computed(() => externalPlayers.value.map(row => ({ ...row,
    name: row.position ? `${row.name}, ${row.position}` : row.name })));
const externalGoalies = computed(() => (props.leaders.goalies ?? []).map(row => ({ ...row, external: true })));
const teamRows = computed(() => (props.leaders.teams ?? []).map(row => ({ ...row, id: row.team,
    stats: { gf: row.gf, gf_pg: row.gf_pg, ga_pg: row.ga_pg, ppg_pg: row.ppg_pg, points: row.points, goals: row.gf, assists: row.assists, sog: row.sog, hits: row.hits } })));
const teamPanels = computed(() => [
    { title: 'Teams by Goals / Game', key: 'gf_pg', delta: 'gf_delta', low: false },
    { title: 'Teams by Goals Against / Game', key: 'ga_pg', delta: 'ga_delta', low: true },
].map(panel => ({ ...panel, rows: [...teamRows.value].sort((a, b) => panel.low ? a[panel.key] - b[panel.key] : b[panel.key] - a[panel.key]).slice(0, 5) })));
const trendRows = computed(() => (props.leaders.player_trends ?? []).map(row => ({ ...row, id: row.nhl_player_id,
    gp: null, external: true, logo: row.team ? `https://assets.nhle.com/logos/nhl/svg/${row.team}_light.svg` : null,
    stats: { ppg: row.current, up: row.delta > 0 ? row.delta : null, down: row.delta < 0 ? row.delta : null, breakout: row.breakout ? row.delta : null } })));
const smashTabs = [{ key: 'sog', label: 'SOG' }, { key: 'hits', label: 'Hits' }, { key: 'blocks', label: 'Blocks' }, { key: 'pim', label: 'PIMs' }];
const goalieTabs = [{ key: 'gaa', label: 'GAA', low: true, format: 'decimal' }, { key: 'sv_pct', label: 'SV%', format: 'save' }, { key: 'shutouts', label: 'Shutouts' }, { key: 'wins', label: 'Wins' }];
const rookieTabs = [{ key: 'points', label: 'Points' }, { key: 'goals', label: 'Goals' }, { key: 'assists', label: 'Assists' }, { key: 'sog', label: 'SOG' }];
const advancedTabs = [{ key: 'ppg', label: 'P/GP', format: 'decimal' }, { key: 'xgf', label: 'xGF', format: 'decimal' }, { key: 'corsi', label: 'Corsi' }, { key: 'cf_pct', label: 'CF%', format: 'percent' }, { key: 'toi', label: 'TOI', format: 'time' }];
const teamTabs = [{ key: 'points', label: 'Points' }, { key: 'goals', label: 'Goals' }, { key: 'assists', label: 'Assists' }, { key: 'sog', label: 'SOG' }, { key: 'hits', label: 'Hits' }];
const teamSort = ref('points');
const teamDescending = ref(true);
const teamColumns = [{ key: 'name', label: 'Team' }, { key: 'gp', label: 'GP' }, { key: 'wins', label: 'W' }, { key: 'gf', label: 'GF' }, { key: 'ga', label: 'GA' }, { key: 'gf_pg', label: 'GF/GP' }, { key: 'ga_pg', label: 'GA/GP' }, { key: 'points', label: 'PTS' }, { key: 'assists', label: 'A' }, { key: 'sog', label: 'SOG' }, { key: 'hits', label: 'Hits' }];
const sortedTeams = computed(() => [...teamRows.value].sort((a, b) => {
    const key = teamSort.value;
    if (a[key] == null || b[key] == null) return (a[key] == null) - (b[key] == null);
    return (key === 'name' ? a.name.localeCompare(b.name) : a[key] - b[key]) * (teamDescending.value ? -1 : 1);
}));
const sortTeams = key => { teamDescending.value = key === teamSort.value ? !teamDescending.value : key !== 'name'; teamSort.value = key; };
</script>

<template>
    <Head title="Statistics · DynastyIQ" />
    <main class="mx-auto flex min-w-0 max-w-[1800px] flex-col gap-3 bg-[#020e17] px-3 py-3 text-slate-100 md:px-5" aria-label="NHL statistics dashboard">
        <div class="overflow-hidden rounded-md border border-[#143144] bg-[#03131f] sm:hidden">
            <CategoryNav :links="navLinks" :view="pendingView || activeView" @start="startVisit" @finish="finishVisit" @failed="failVisit" />
        </div>
        <Deferred data="leaders">
            <template #fallback>
                <div class="sm:hidden"><LoadingState label="season leaders" hero /></div>
                <div class="hidden overflow-hidden rounded-lg border border-[#143144] sm:block">
                    <LoadingState label="season leaders" hero />
                    <CategoryNav :links="navLinks" :view="pendingView || activeView" @start="startVisit" @finish="finishVisit" @failed="failVisit" />
                </div>
            </template>
            <template #rescue="{ reloading }">
                <div class="sm:hidden"><LoadingState label="season leaders" failed :reloading="reloading" @retry="reload('leaders')" /></div>
                <div class="hidden overflow-hidden rounded-lg border border-[#143144] sm:block">
                    <LoadingState label="season leaders" failed :reloading="reloading" @retry="reload('leaders')" />
                    <CategoryNav :links="navLinks" :view="pendingView || activeView" @start="startVisit" @finish="finishVisit" @failed="failVisit" />
                </div>
            </template>
        <section class="hidden min-w-0 overflow-hidden rounded-lg border border-[#143144] bg-[#03131f] sm:grid lg:grid-cols-[1.6fr_1fr]" :class="compactHero ? 'stats-hero-compact lg:h-[128px]' : 'lg:h-[236px]'" aria-label="Statistics leaders">
            <div class="relative isolate flex min-w-0 flex-col overflow-hidden lg:min-h-0" :class="compactHero ? 'min-h-0' : 'min-h-[248px]'">
                <img v-if="view === 'goalies' && (hero?.hero || hero?.avatar)" :src="hero.hero || hero.avatar" alt="" class="absolute inset-0 -z-20 h-full w-full object-cover object-top opacity-30 blur-sm" />
                <img v-if="hero?.hero || hero?.avatar" :src="hero.hero || hero.avatar" alt="" class="absolute inset-0 -z-20 h-full w-full opacity-75" :class="view === 'goalies' ? (hero?.hero ? 'stats-goalie-hero-photo' : 'object-contain object-top') : 'object-cover object-top'" />
                <div class="absolute inset-0 -z-10 bg-gradient-to-r from-[#020e17]" :class="view === 'goalies' ? 'via-[#020e17]/55 to-[#020e17]/50' : 'via-[#020e17]/65 to-[#020e17]/80'" />
                <div class="stats-hero-content grid min-h-0 flex-1 grid-cols-[0.95fr_1.05fr] gap-3 p-4 md:gap-6 md:px-8 md:pt-5">
                    <div class="stats-hero-intro"><p class="text-[10px] font-bold uppercase tracking-[0.25em] text-amber-400">Statistics</p><h1 class="mt-1 text-4xl font-extrabold tracking-tight md:text-5xl">Leaders</h1><p class="mt-2 max-w-64 text-xs leading-relaxed text-slate-300 md:text-sm">League leaders and top performers across key statistics, with advanced metrics and fantasy-relevant insights.</p><p class="mt-2 text-[10px] text-slate-400">{{ season }} · Regular season</p></div>
                    <div v-if="hero" class="stats-hero-player flex min-w-0 flex-col justify-center pt-2"><p class="truncate text-[10px] uppercase tracking-[0.3em] text-slate-300">{{ hero.first_name }}</p><a :href="hero.url" target="_blank" rel="noopener noreferrer" class="truncate text-2xl font-extrabold uppercase leading-tight tracking-tight hover:text-sky-300 md:text-5xl">{{ hero.last_name }}</a><p class="mt-1 text-xs tracking-wider text-slate-300">{{ hero.team }} · {{ hero.position }}<template v-if="hero.number"> · #{{ hero.number }}</template></p><div class="stats-hero-metrics mt-4 grid grid-cols-2 gap-y-2 divide-[#143144] md:grid-cols-4 md:divide-x"><div v-for="(label, key) in metrics" :key="key" class="px-1 text-center"><p class="text-lg font-bold tabular-nums md:text-xl">{{ format(hero.stats[key], key) }}</p><p class="text-[9px] uppercase text-slate-200">{{ label }}</p><p class="mt-1 text-[8px] text-slate-400">{{ key === 'gsax' && hero.stats.gsax == null ? (hero.gsax_reason || 'GSAx unavailable') : rank(hero.ranks[key]) }}</p></div></div></div>
                    <p v-else class="self-center text-xs text-slate-400">Season leaders will appear after stats are imported.</p>
                </div>
                <CategoryNav :links="navLinks" :view="pendingView || activeView" @start="startVisit" @finish="finishVisit" @failed="failVisit" />
            </div>
            <section class="min-w-0 border-t border-[#143144] p-3 lg:border-l lg:border-t-0" aria-label="Category leaders"><div class="mb-2 flex items-center justify-between"><h2 class="text-[10px] font-semibold uppercase tracking-[0.2em]">Category Leaders</h2><Link :href="href(view === 'goalies' ? 'goalies' : 'skaters')" class="text-[10px] text-sky-400 hover:text-sky-200">View All Leaders →</Link></div><div class="grid gap-2" :class="compactHero ? 'grid-cols-3' : 'grid-cols-2 sm:grid-cols-3'"><a v-for="(category, index) in categories" :key="category.key" :href="category.player?.url || href(view === 'goalies' ? 'goalies' : 'skaters')" :target="category.player ? '_blank' : undefined" :rel="category.player ? 'noopener noreferrer' : undefined" class="flex min-h-[81px] min-w-0 items-center gap-2 rounded-md border border-[#143144] bg-[#03121c] pl-2 pr-1 hover:border-sky-600" :class="index % 3 === 0 ? 'border-l-[3px] border-l-amber-400' : index % 3 === 1 ? 'border-l-[3px] border-l-emerald-400' : 'border-l-[3px] border-l-rose-500'"><img v-if="category.player?.avatar" :src="category.player.avatar" alt="" class="h-20 w-16 shrink-0 object-contain object-bottom" loading="lazy" /><div class="min-w-0"><p class="text-[9px] uppercase text-slate-300">{{ category.label }}</p><p class="font-bold leading-5" :class="view === 'goalies' ? 'text-sm sm:text-lg' : 'text-lg'">{{ format(category.player?.stats[category.key], category.key) }}</p><p class="mt-1 truncate text-[10px] font-semibold" :title="category.unavailable_reason || category.player?.name">{{ category.player ? `${category.player.first_name?.slice(0, 1)}. ${category.player.last_name}` : (category.unavailable_label || 'Unavailable') }}</p><p class="text-[9px] text-slate-400">{{ category.player?.team }}<template v-if="category.player"> · {{ category.player.position }}</template></p></div></a></div></section>
        </section>

        <section v-if="view === 'leaders' && !pendingView" class="min-w-0 space-y-2 sm:hidden" aria-label="Mobile season leaders">
            <LeaderCardRow v-for="row in leaders.mobile_leaders || []" :key="row.title" :title="row.title" :href="row.href" :cards="row.cards" />
        </section>
        <div v-if="view === 'leaders' && !pendingView" class="hidden space-y-3 sm:block">
            <section aria-label="Season category leaders" class="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
                <a v-for="(category, index) in stripCategories" :key="category.key" :href="category.player?.url || href('skaters')" :target="category.player ? '_blank' : undefined" :rel="category.player ? 'noopener noreferrer' : undefined" class="flex h-[84px] min-w-0 items-center gap-2 overflow-hidden rounded-md border border-[#143144] bg-[#041724] px-2" :class="index % 3 === 0 ? 'border-l-[3px] border-l-amber-400' : index % 3 === 1 ? 'border-l-[3px] border-l-emerald-400' : 'border-l-[3px] border-l-sky-400'"><img v-if="category.player?.avatar" :src="category.player.avatar" alt="" class="h-20 w-16 shrink-0 object-contain object-bottom" loading="lazy" /><div class="min-w-0 flex-1"><p class="truncate text-[9px] font-semibold uppercase tracking-wide text-amber-400">{{ category.label }} Leader</p><p class="text-lg font-bold leading-5">{{ format(category.player?.stats[category.key], category.key) }}</p><p class="truncate text-[10px] font-semibold">{{ category.player?.name || 'Unavailable' }}</p><p class="text-[8px] text-slate-400">{{ category.player?.team }} · {{ category.player?.position }}</p></div><div class="w-14 shrink-0 text-right"><svg v-if="category.history.length" viewBox="0 0 60 34" class="ml-auto h-7 w-12" aria-label="Last five appearances"><rect v-for="(value, bar) in category.history" :key="bar" :x="bar * 12" :y="32 - (value / Math.max(1, ...category.history) * 29)" width="8" :height="value / Math.max(1, ...category.history) * 29" class="fill-blue-500" /></svg><p class="mt-1 text-[9px] font-semibold" :class="(category.player?.deltas[category.key] ?? 0) < 0 ? 'text-rose-400' : 'text-emerald-400'">{{ delta(category.player?.deltas[category.key]) }}<template v-if="Number.isFinite(category.player?.deltas[category.key])">%</template></p><p class="text-[7px] text-slate-400">vs. last season</p></div></a>
            </section>

            <div class="grid min-w-0 gap-3 md:grid-cols-2 xl:h-[214px] xl:grid-cols-[1.65fr_1fr_1fr_1.2fr]">
                <div class="grid min-w-0 gap-2 sm:grid-cols-2">
                    <section v-for="panel in teamPanels" :key="panel.key" class="min-w-0 rounded-lg border border-[#143144] bg-[#041724] p-3"><div class="mb-2 flex items-start justify-between gap-2"><h2 class="text-[11px] font-bold leading-tight">{{ panel.title }}</h2><Link :href="href('teams')" class="shrink-0 text-[9px] text-sky-400 hover:text-sky-200">View all ›</Link></div><div class="space-y-2.5"><Link v-for="(team, index) in panel.rows" :key="team.id" :href="href('teams')" class="flex min-w-0 items-center gap-1.5 text-[10px] tabular-nums"><span class="w-2 text-[8px] text-slate-400">{{ index + 1 }}</span><img v-if="team.logo" :src="team.logo" alt="" class="h-5 w-5 shrink-0 object-contain" loading="lazy" /><span class="w-7 text-[9px]">{{ team.team }}</span><svg class="h-2 min-w-4 flex-1" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="5" y="1.5" rx="2" class="fill-slate-800" /><rect :width="Math.min(100, team[panel.key] / Math.max(5, ...panel.rows.map(row => row[panel.key])) * 100)" height="5" y="1.5" rx="2" :class="colors[index]" /></svg><b class="w-6 text-right">{{ team[panel.key].toFixed(1) }}</b><span class="w-8 text-right text-[8px]" :class="team[panel.delta] === null ? 'text-slate-500' : (panel.low ? team[panel.delta] <= 0 : team[panel.delta] >= 0) ? 'text-emerald-400' : 'text-rose-400'" title="Change in goals per game versus last season">{{ delta(team[panel.delta]) }}</span></Link><p v-if="!panel.rows.length" class="py-8 text-center text-xs text-slate-400">No completed team results.</p></div></section>
                </div>
                <LeaderList title="Team Leaders" :rows="teamRows" entity="Team" :href="href('teams')" :tabs="[{key:'gf_pg',label:'Offense',heading:'GF/GP',format:'decimal'},{key:'ga_pg',label:'Defense',heading:'GA/GP',low:true,format:'decimal'},{key:'ppg_pg',label:'Special Teams',heading:'PPG/GP',format:'decimal'}]" />
                <LeaderList title="Player Trends" hide-gp :columns="[{key:'ppg',label:'P/GP',format:'decimal'}]" :rows="trendRows" :href="href('skaters')" :tabs="[{key:'up',label:'Trending Up',heading:'Δ P/GP',format:'decimal'},{key:'down',label:'Trending Down',heading:'Δ P/GP',low:true,format:'decimal'},{key:'breakout',label:'Breakout',heading:'Δ P/GP',format:'decimal'}]" />
                <LeaderList title="Smash Potatoes" :rows="smashPlayers" :tabs="smashTabs" :href="href('skaters')" />
            </div>
            <div class="grid min-w-0 gap-3 md:grid-cols-2 xl:h-[190px] xl:grid-cols-4">
                <LeaderList title="Goalie Leaders" :rows="externalGoalies" :tabs="goalieTabs" :href="href('goalies')" />
                <LeaderList title="Rookie Leaders" :rows="leaders.rookies" :tabs="rookieTabs" :href="href('skaters')" :empty="leaders.rookie_note" />
                <LeaderList title="Advanced Leaders" :rows="externalPlayers" :tabs="advancedTabs" :href="`${href('skaters')}&perspective=skaters-adv`" />
                <LeaderList title="Team Leaders" :rows="teamRows" :tabs="teamTabs" entity="Team" :href="href('teams')" />
            </div>
        </div>
        <section v-else-if="view === 'teams' && !pendingView" class="overflow-hidden rounded-lg border border-[#143144] bg-[#041724] sm:p-4"><h2 class="mb-3 hidden text-lg font-bold sm:block">Team statistics · {{ season }}</h2>
            <div class="sm:hidden" aria-label="Mobile team statistics">
                <div class="flex items-center justify-between gap-2 border-b border-[#143144] px-3 py-2 text-xs">
                    <h2 class="font-semibold">All Teams</h2>
                    <label class="flex min-w-0 items-center gap-2 text-slate-400">Sort
                        <select :value="teamSort" class="min-w-0 rounded border border-[#28516a] bg-[#101923] px-2 py-1 text-xs text-slate-100 focus-visible:ring-2 focus-visible:ring-sky-400" @change="sortTeams($event.target.value)">
                            <option v-for="column in teamColumns" :key="column.key" :value="column.key">{{ column.label }}</option>
                        </select>
                    </label>
                    <button type="button" class="rounded px-2 py-1 text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400" :aria-label="teamDescending ? 'Sort ascending' : 'Sort descending'" @click="teamDescending = !teamDescending">{{ teamDescending ? '↓' : '↑' }}</button>
                </div>
                <ul class="divide-y divide-[#233342] bg-[#101923] font-stats tabular-nums">
                    <li v-for="team in sortedTeams" :key="team.id" class="flex min-w-0 items-center gap-2 px-2 py-2">
                        <span class="w-7 shrink-0 text-center text-[10px] font-bold text-slate-400">{{ team.team }}</span>
                        <img v-if="team.logo" :src="team.logo" alt="" class="h-8 w-8 shrink-0 object-contain" loading="lazy" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2 text-xs"><span class="truncate font-semibold uppercase">{{ team.name }}</span><span class="shrink-0"><span class="text-[9px] text-slate-400">PTS</span> <b>{{ team.points ?? '—' }}</b></span></div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[9px]">
                                <span v-for="column in teamColumns.filter(column => !['name', 'points'].includes(column.key))" :key="column.key" class="whitespace-nowrap"><span class="text-slate-400">{{ column.label }}</span> {{ team[column.key] == null ? '—' : column.key.endsWith('_pg') ? team[column.key].toFixed(2) : team[column.key] }}</span>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-if="!sortedTeams.length" class="px-3 py-6 text-center text-xs text-slate-400">No completed team results for this season.</p>
            </div>
            <div class="hidden overflow-x-auto sm:block"><table class="w-full text-left text-xs tabular-nums"><thead class="border-b border-[#143144] text-slate-400"><tr><th v-for="column in teamColumns" :key="column.key" class="whitespace-nowrap p-2" :aria-sort="teamSort === column.key ? teamDescending ? 'descending' : 'ascending' : 'none'"><button type="button" class="rounded hover:text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400" @click="sortTeams(column.key)">{{ column.label }} <span v-if="teamSort === column.key">{{ teamDescending ? '↓' : '↑' }}</span></button></th></tr></thead><tbody><tr v-for="team in sortedTeams" :key="team.id" class="border-b border-[#143144]/60"><td v-for="column in teamColumns" :key="column.key" class="whitespace-nowrap p-2"><span v-if="column.key === 'name'" class="flex items-center gap-2"><img v-if="team.logo" :src="team.logo" alt="" class="h-6 w-6 object-contain" />{{ team.name }}</span><template v-else>{{ team[column.key] === null ? '—' : column.key.endsWith('_pg') ? team[column.key].toFixed(2) : team[column.key] }}</template></td></tr></tbody></table><p v-if="!sortedTeams.length" class="py-8 text-sm text-slate-400">No completed team results for this season.</p></div></section>
        </Deferred>
        <p v-if="seamlessTableSwitch" role="status" class="sr-only">Loading {{ pendingView }}…</p>
        <LoadingState v-if="pendingView && !seamlessTableSwitch" :label="pendingView" />
        <LoadingState v-if="failedView" :label="failedView" failed @retry="router.visit(href(failedView))" />
        <Deferred v-if="['skaters', 'goalies'].includes(view) && (!pendingView || seamlessTableSwitch)" data="initialPayload">
            <template #fallback><LoadingState :label="view" /></template>
            <template #rescue="{ reloading }"><LoadingState :label="view" failed :reloading="reloading" @retry="reload('initialPayload')" /></template>
        <section class="-mx-3 overflow-hidden bg-white text-slate-900 sm:mx-0 sm:rounded-lg sm:bg-[#101923]" :aria-label="`${activeView === 'prospects' ? 'Prospect' : view === 'goalies' ? 'Goalie' : 'Skater'} statistics`"><StatsTable :key="view + selectedPerspective" :report-view="view" :initial-payload="initialPayload" :api-url="apiUrl" :connected-leagues="connectedLeagues" :perspectives="perspectives" :selected-perspective="selectedPerspective" :mobile-breakpoint="mobileBreakpoint" /></section>
        </Deferred>
    </main>
</template>
