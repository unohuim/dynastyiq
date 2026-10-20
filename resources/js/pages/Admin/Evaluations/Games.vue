<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import TeamContext from './TeamContext.vue';
import ShellDisclosure from '../../../layouts/ShellDisclosure.vue';
import { aggregate, metrics, windows, teamQuarterGames, quarterDisplay, quarterDifference, gameAdjusted, gameAdjustedError } from './game-aggregates';

const props = defineProps({ evaluation: Object, filters: Object, games: Array, work: Array, selected: Object, players: Array, league: [Number, String], leagueSummaries: { type: Array, default: () => [] }, active: Object, dayWork: Object });
const base = `/admin/nhl-sat-models/next-game/${props.evaluation.id}`;
const date = ref(props.filters.date);
const busy = ref(false);
const error = ref('');
const openSection = ref('aggregate');
function toggleSection(key, open) { openSection.value = open ? key : null; }
watch(() => props.selected?.id, () => { openSection.value = 'aggregate'; });
const dayRunning = computed(() => props.dayWork?.status === 'running');
const selectedGame = computed(() => props.games.find(game => game.nhl_game_id === props.selected?.nhl_game_id));
const isActive = work => ['queued', 'running', 'comparing'].includes(work?.status);
const forGame = game => props.work.find(work => work.nhl_game_id === game.nhl_game_id);
const display = value => value === null || value === undefined ? '—' : Number(value).toFixed(2);
const percent = value => value == null ? '—' : `${display(value)}%`;
const signedPercent = value => value == null ? '—' : `${value >= 0 ? '+' : ''}${display(value)}%`;
const aggregateDisplay = value => display(value);
const leagueCoverage = (metric, key) => `${metric.coverage[key] ?? 0} saved team lineups`;
const isGoalie = row => row.inputs.context?.role === 'goalie';
const missingContext = computed(() => props.players.some(row => row.inputs.context?.version !== 6));
const contextRequested = new Set();
const sides = computed(() => ['away', 'home'].map(side => {
    const id = selectedGame.value?.[`${side}_team_id`];
    const rows = props.players.filter(row => Number(row.nhl_team_id) === Number(id));
    const order = { C: 0, LW: 1, RW: 2, D: 3 };
    return { id, name: selectedGame.value?.[`${side}_team_abbrev`], label: side === 'away' ? 'Visitors' : 'Home',
        skaters: rows.filter(row => !isGoalie(row)).sort((a, b) => (order[a.inputs.context?.position] ?? 4) - (order[b.inputs.context?.position] ?? 4) || (a.player_name ?? '').localeCompare(b.player_name ?? '')),
        goalies: rows.filter(isGoalie) };
}));
const summaries = computed(() => sides.value.map(side => ({ ...side,
    metrics: metrics.map(([key, label]) => ({ key, label, ...aggregate(side.skaters, key) })),
})));
let timer;
let disposed = false;

function load(overrides = {}) {
    busy.value = true;
    error.value = '';
    router.get(`${base}/games`, { ...props.filters, forecast: undefined, ...overrides }, {
        preserveState: true, preserveScroll: true,
        onError: () => { error.value = 'Could not load games. Check the selected date and try again.'; },
        onFinish: () => { busy.value = false; },
    });
}
async function act(url, data) {
    busy.value = true;
    error.value = '';
    try {
        const response = await axios.post(url, data);
        load({ forecast: response.data.forecast_id, ...(response.data.date ? { date: response.data.date } : {}) });
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors ?? {}).flat().join(' ') || failure.response?.data?.message || 'Unable to start work. Please try again.';
        busy.value = false;
    }
}
function schedule() { if (!disposed) timer = setTimeout(poll, 5000); }
function poll() {
    if (document.hidden || busy.value || (!props.active && !dayRunning.value)) { schedule(); return; }
    const previous = props.selected?.status;
    const previousDayCursor = props.dayWork?.cursor;
    router.reload({ only: ['work', 'selected', 'active', 'dayWork'], preserveScroll: true, onFinish: () => {
        if (disposed) return;
        if ((previous !== props.selected?.status && ['ready', 'compared'].includes(props.selected?.status))
            || previousDayCursor !== props.dayWork?.cursor) {
            router.reload({ only: ['players', 'leagueSummaries'], preserveScroll: true, onFinish: schedule });
        } else schedule();
    } });
}
watch(() => props.filters.date, value => { date.value = value; });
watch(() => [props.selected?.id, props.selected?.status, missingContext.value, busy.value, props.active?.id, dayRunning.value], () => {
    const selected = props.selected;
    if (!selected || !['ready', 'compared'].includes(selected.status) || !missingContext.value
        || busy.value || props.active || dayRunning.value || contextRequested.has(selected.id)) return;
    contextRequested.add(selected.id);
    act(`${base}/forecasts/${selected.id}`, { action: 'context' });
}, { immediate: true, flush: 'post' });
onMounted(schedule);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <Head :title="`${evaluation.name} · Games`" />
    <main class="w-full min-w-0 space-y-6 px-4 py-6 text-gray-900 sm:px-6 sm:py-8">
        <header class="space-y-3">
            <Link href="/admin/nhl-sat-models/next-game" class="inline-flex min-h-11 items-center text-sm text-indigo-700 hover:underline">← Game Evaluations</Link>
            <h1 class="text-2xl font-semibold tracking-tight">{{ evaluation.name }}</h1>
            <p class="text-sm text-gray-500">Shot evidence uses this model’s buckets with confidence ≥50%, including exactly 50%. Saved projections and ice time are unchanged.</p>
        </header>
        <nav aria-label="Evaluation views" class="flex items-center gap-6 border-b border-gray-200">
            <Link :href="base" class="min-h-11 text-sm font-medium text-gray-600 hover:text-indigo-700">Outlook</Link>
            <span aria-current="page" class="border-b-2 border-indigo-600 px-1 pb-3 text-sm font-semibold text-indigo-700">Games</span>
        </nav>
        <section class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-4" aria-label="Game filters">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="load({ date })"><label class="text-sm">Game day<input v-model="date" type="date" required class="mt-2 block min-h-11 rounded-lg border-gray-300 text-sm" /></label><button :disabled="busy" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm disabled:opacity-50">Go</button><button type="button" :disabled="busy || evaluation.status !== 'ready' || !date || (dayRunning && !dayWork.can_resume)" class="min-h-11 rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-40 motion-reduce:transition-none" @click="act(`${base}/predict-day`, { date, strength: filters.strength, period: filters.period })">Predict Day</button><button type="button" :disabled="busy || evaluation.status !== 'ready' || !date || (dayRunning && !dayWork.can_resume)" class="min-h-11 rounded-lg border border-indigo-600 px-4 text-sm font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-50 disabled:opacity-40 motion-reduce:transition-none" @click="act(`${base}/simulate`, { date, strength: filters.strength, period: filters.period })">Simulate</button></form>
            <label class="text-sm">Strength<select :value="filters.strength" :disabled="busy" class="mt-2 block min-h-11 rounded-lg border-gray-300 text-sm" @change="load({ strength: $event.target.value })"><option v-for="strength in ['ev', 'pp', 'pk', 'all']" :key="strength" :value="strength">{{ strength.toUpperCase() }}</option></select></label>
            <label class="text-sm">Quarter reference<select :value="filters.period" :disabled="busy" class="mt-2 block min-h-11 rounded-lg border-gray-300 text-sm" @change="load({ period: $event.target.value })"><option v-for="period in ['q1', 'q2', 'q3', 'q4']" :key="period" :value="period">{{ period.toUpperCase() }}</option></select></label>
            <p class="max-w-lg text-xs leading-5 text-gray-500">Choose the saved quarter estimate to use. This first pass uses completed games’ skater lineups; forecast inputs come from before that game.</p>
        </section>
        <p v-if="evaluation.status !== 'ready'" role="status" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Wait for this evaluation’s initial outlooks to become Ready.</p>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>
        <section v-if="dayWork" aria-label="Predict Day progress" class="space-y-2 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <p role="status">{{ dayWork.mode === 'simulation' ? 'Simulation' : 'Predict Day' }} · {{ dayWork.start_date && dayWork.mode === 'simulation' ? `${dayWork.start_date} → ` : '' }}{{ dayWork.date }} · {{ dayWork.strength.toUpperCase() }} / {{ dayWork.period.toUpperCase() }} · {{ dayWork.cursor }} / {{ dayWork.total }} games · {{ dayWork.status }}</p>
            <p class="text-xs text-gray-500">One game at a time, including actuals comparison. Older saved games refresh their ≥50% bucket evidence without replacing projections. Processing continues if you leave this page.</p>
            <p v-if="dayWork.last_error" role="alert" class="text-red-700">{{ dayWork.last_error }}</p>
            <p v-else-if="dayWork.can_resume && dayRunning" class="text-amber-700">Progress has stalled. Press {{ dayWork.mode === 'simulation' ? 'Simulate' : 'Predict Day' }} again for this date and selection to resume.</p>
        </section>
        <div v-if="active" class="flex flex-wrap items-center gap-3 text-sm text-indigo-700"><p role="status">One game is processing · {{ active.strength.toUpperCase() }} / {{ active.period.toUpperCase() }} · game #{{ active.nhl_game_id }}.</p><button :disabled="busy" class="min-h-11 underline" @click="load({ date: active.game_date, strength: active.strength, period: active.period, forecast: active.id })">Open active work</button></div>
        <section aria-label="Games on selected day" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="game in games" :key="game.nhl_game_id" class="rounded-lg border bg-white p-4" :class="selected?.nhl_game_id === game.nhl_game_id ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200'">
                <h2 class="font-semibold">{{ game.away_team_abbrev }} @ {{ game.home_team_abbrev }}</h2>
                <p class="mt-1 text-xs capitalize text-gray-500">{{ forGame(game)?.status ?? 'No saved forecast' }}</p>
                <button v-if="forGame(game)" :disabled="busy" class="mt-3 min-h-11 rounded-lg border border-gray-300 px-4 text-sm hover:bg-gray-50 disabled:opacity-50" @click="load({ forecast: forGame(game).id })">Open forecast</button>
                <button v-else :disabled="busy || !!active || dayRunning || evaluation.status !== 'ready' || !['OFF', 'FINAL'].includes(game.game_state)" class="mt-3 min-h-11 rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40" @click="act(`${base}/games/${game.nhl_game_id}/forecast`, { strength: filters.strength, period: filters.period })">Predict game</button>
            </article>
            <p v-if="!games.length" class="py-6 text-sm text-gray-500">No regular-season games on this date.</p>
        </section>
        <section v-if="selected" class="space-y-4" aria-label="Saved game forecast">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4">
                <div><h2 class="font-semibold">{{ selectedGame?.away_team_abbrev }} @ {{ selectedGame?.home_team_abbrev }} · {{ filters.strength.toUpperCase() }}</h2><p role="status" class="mt-1 text-sm capitalize text-gray-500">{{ selected.status }} · {{ selected.cursor }} / {{ selected.total }} players · {{ selected.phase }}</p></div>
                <button v-if="selected.status === 'ready'" :disabled="busy || !!active" class="min-h-11 rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white disabled:opacity-50" @click="act(`${base}/forecasts/${selected.id}`, { action: 'compare' })">Compare actuals</button>
                <button v-if="selected.can_resume" :disabled="busy || (!!active && active.id !== selected.id)" class="min-h-11 rounded-lg border border-indigo-600 px-4 text-sm font-medium text-indigo-700 disabled:opacity-50" @click="act(`${base}/forecasts/${selected.id}`, { action: 'resume' })">Resume</button>
                <progress v-if="isActive(selected)" class="h-2 w-full" :max="selected.total || 1" :value="selected.cursor" aria-label="Players processed" />
                <p v-if="selected.last_error" role="alert" class="w-full text-sm text-red-700">{{ selected.last_error }}</p>
            </div>
            <template v-if="players.length">
                <p v-if="missingContext || (selected.phase === 'context' && isActive(selected))" role="status" class="text-sm text-indigo-700">Pregame context updates automatically. Saved predictions remain unchanged.</p>
                <p class="text-xs leading-5 text-gray-500">Train uses the model’s Train seasons; LSeason is the previous season. Vs opp. L10 uses this player’s last ten appearances against this opponent, across seasons and team changes. All windows end before this game. Team records are W–L; losses include OT/SO.</p>
                <ShellDisclosure :key="`aggregate-${selected.id}`" label="Team aggregates" :model-value="openSection === 'aggregate'" @update:model-value="toggleSection('aggregate', $event)" class="min-w-0 rounded-xl border border-gray-200 bg-white">
                    <div class="max-h-[70dvh] overflow-auto" tabindex="0" role="region" aria-label="Away and home team aggregates">
                        <table class="w-full whitespace-nowrap text-right text-xs tabular-nums">
                            <thead class="sticky top-0 z-20 bg-gray-50 text-gray-600"><tr><th class="px-3 py-3 text-left" scope="col">Team</th><th class="px-3 py-3" scope="col">Metric</th><th v-for="[key, label] in windows" :key="key" class="px-3 py-3" scope="col">{{ label }}</th><th class="px-3 py-3" scope="col">Previous</th><th class="px-3 py-3" scope="col">Proj {{ filters.period.toUpperCase() }}</th><th class="px-3 py-3" scope="col">Current {{ filters.period.toUpperCase() }}</th><th class="px-3 py-3" scope="col">{{ filters.period.toUpperCase() }} Diff</th><th class="px-3 py-3" scope="col">Game Adj</th><th class="bg-indigo-50 px-3 py-3" scope="col">Proj</th><th class="px-3 py-3" scope="col">Actual</th><th class="px-3 py-3" scope="col">err% Proj</th><th class="px-3 py-3" scope="col">err% Proj{{ filters.period.toUpperCase() }}</th><th class="px-3 py-3" scope="col">Game Adj %</th><th class="px-3 py-3" scope="col">p10</th><th class="px-3 py-3" scope="col">p20</th><th class="px-3 py-3" scope="col">{{ filters.period }}p10</th><th class="px-3 py-3" scope="col">{{ filters.period }}p20</th></tr></thead>
<tbody><template v-for="side in summaries" :key="side.id"><tr v-for="(metric, index) in side.metrics" :key="metric.key" class="border-t border-gray-100"><th v-if="index === 0" :rowspan="side.metrics.length" scope="rowgroup" class="px-3 py-4 text-left font-semibold">{{ side.name }}<span class="block text-xs font-normal text-gray-500">{{ side.label }} · {{ side.skaters.length }} skaters</span></th><th scope="row" class="px-3 py-3 font-normal">{{ metric.label }}</th><td v-for="[key] in windows" :key="key" class="px-3 py-3">{{ aggregateDisplay(metric.history[key], metric.estimated[key]) }}</td><td class="px-3 py-3">{{ display(metric.previous) }}</td><td class="px-3 py-3">{{ aggregateDisplay(metric.quarter, metric.estimated.quarter) }}</td><td class="px-3 py-3">{{ quarterDisplay(metric.current, teamQuarterGames(side.skaters, filters.period)) }}</td><td class="px-3 py-3">{{ display(quarterDifference(metric.quarter, metric.current)) }}</td><td class="px-3 py-3">{{ display(gameAdjusted(metric.quarter, metric.current)) }}</td><td class="bg-indigo-50/50 px-3 py-3 font-semibold">{{ aggregateDisplay(metric.predicted, metric.estimated.predicted) }}</td><td class="px-3 py-3">{{ aggregateDisplay(metric.measured, metric.estimated.measured) }}</td><td class="px-3 py-3">{{ signedPercent(metric.error) }}</td><td class="px-3 py-3">{{ signedPercent(metric.quarterError) }}</td><td class="px-3 py-3">{{ signedPercent(gameAdjustedError(metric.quarter, metric.current, metric.measured)) }}</td><td class="px-3 py-3" :title="`${metric.measuredPlayers} measured players`">{{ percent(metric.under10) }}</td><td class="px-3 py-3" :title="`${metric.measuredPlayers} measured players`">{{ percent(metric.under20) }}</td><td class="px-3 py-3" :title="`${metric.quarterMeasuredPlayers} measured players`">{{ percent(metric.quarterUnder10) }}</td><td class="px-3 py-3" :title="`${metric.quarterMeasuredPlayers} measured players`">{{ percent(metric.quarterUnder20) }}</td></tr></template>
                            <tr v-for="metric in leagueSummaries" :key="`league-${metric.key}`" class="border-t border-indigo-100 bg-indigo-50/50 font-medium">
                                <th scope="row" class="px-3 py-3 text-left">League Average<span class="block text-xs font-normal text-gray-500">{{ metric.teams }} saved team lineups · this game day</span></th>
                                <td class="px-3 py-3">{{ metric.label }}</td>
                                <td v-for="[key] in windows" :key="key" class="px-3 py-3" :title="leagueCoverage(metric, key)">{{ display(metric.history[key]) }}<span class="block text-[11px] font-normal text-gray-500">{{ metric.coverage[key] }} teams</span></td>
                                <td v-for="key in ['previous', 'quarter', 'current']" :key="key" class="px-3 py-3" :title="leagueCoverage(metric, key)">{{ display(metric[key]) }}<span class="block text-[11px] font-normal text-gray-500">{{ metric.coverage[key] }} teams</span></td>
                                <td class="px-3 py-3">{{ display(quarterDifference(metric.quarter, metric.current)) }}</td><td class="px-3 py-3">{{ display(gameAdjusted(metric.quarter, metric.current)) }}</td>
                                <td v-for="key in ['predicted', 'measured']" :key="key" class="px-3 py-3" :title="leagueCoverage(metric, key)">{{ display(metric[key]) }}<span class="block text-[11px] font-normal text-gray-500">{{ metric.coverage[key] }} teams</span></td>
                                <td class="px-3 py-3" :title="leagueCoverage(metric, 'error')">{{ signedPercent(metric.error) }}</td>
                                <td class="px-3 py-3" :title="leagueCoverage(metric, 'quarterError')">{{ signedPercent(metric.quarterError) }}</td>
                                <td class="px-3 py-3">{{ signedPercent(gameAdjustedError(metric.quarter, metric.current, metric.measured)) }}</td><td class="px-3 py-3" :title="`${metric.measuredPlayers} measured players`">{{ percent(metric.under10) }}</td>
                                <td class="px-3 py-3" :title="`${metric.measuredPlayers} measured players`">{{ percent(metric.under20) }}</td>
                                <td class="px-3 py-3" :title="`${metric.quarterMeasuredPlayers} measured players`">{{ percent(metric.quarterUnder10) }}</td>
                                <td class="px-3 py-3" :title="`${metric.quarterMeasuredPlayers} measured players`">{{ percent(metric.quarterUnder20) }}</td>
                            </tr></tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-500">Proj Q uses each player’s pregame average of LSeason and L20 for SAT/60 and TOI/GP; SAT/GP converts those two blended rates before summing players. Both errors compare against this game’s Actual, not a completed quarter. Proj remains the saved game prediction. Team totals fill missing players with the available lineup average for that stat and window. Entirely missing windows remain unavailable. Train H/A uses away history for visitors and home history for hosts. Vs opponent L10 uses each lineup player’s last ten appearances against this opponent. Individual error shares exclude missing observations. League rows average available saved lineup totals for this day, strength and quarter; coverage is shown per column. Error = (actual − prediction) ÷ prediction × 100; zero predictions or different prediction/actual team coverage have no percentage.</p>
                </ShellDisclosure>
                <ShellDisclosure v-for="side in sides" :key="`${selected.id}-${side.id}`" :label="`${side.name} · ${side.label}`" :model-value="openSection === side.id" @update:model-value="toggleSection(side.id, $event)" class="min-w-0 rounded-xl border border-gray-200 bg-white">
                    <TeamContext :name="side.name" :side="side.label" :rows="side.skaters" :league="league" :period="filters.period" />
                    <ShellDisclosure :label="`${side.name} · Goalies`">
                        <TeamContext :name="side.name" :side="side.label" :rows="side.goalies" :goalies="true" />
                    </ShellDisclosure>
                </ShellDisclosure>
                <p class="text-xs leading-5 text-gray-500">Goalie SV% and GSAx/100 xGA are historical context, not new goalie predictions. Goalie strength refers to the shooting team. Missing evidence stays unavailable. Within the player tables, League Average remains the saved per-player quarter SAT/60 reference.</p>
                <p class="text-xs text-gray-500">Errors = (Actual − Proj) ÷ Proj × 100. Quarter Diff = Proj quarter − Current quarter; Game Adj = Proj quarter + Quarter Diff; Game Adj % = (Actual − Game Adj) ÷ Game Adj × 100. Zero forecasts have no percentage. Quarter outlooks and saved predictions are unchanged.</p>
            </template>
        </section>
    </main>
</template>
