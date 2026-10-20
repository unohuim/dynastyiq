<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({ evaluation: Object, filters: Object, players: Object, league: Object });
const base = '/admin/nhl-sat-models/next-game';
const search = ref(props.filters.q);
const loading = ref(false);
const error = ref('');
const isToi = computed(() => props.filters.metric === 'toi_per_game_seconds');
const unit = computed(() => isToi.value ? 'minutes/game' : 'attempts/60');
const published = computed(() => ['ready', 'completed'].includes(props.evaluation.status));
const columns = [
    ['train', 'Train'], ['l5', 'L5'], ['l10', 'L10'], ['l20', 'L20'], ['last_season', 'LSeason'],
    ['q1', 'Q1'], ['q2', 'Q2'], ['q3', 'Q3'], ['q4', 'Q4'], ['season', 'Season est.'],
    ['test', 'Test'], ['et', 'ET'], ['els', 'ELS'],
];
const savedColumns = ['q1', 'q2', 'q3', 'q4', 'season'];
const season = value => value ? `${String(value).slice(0, 4)}–${String(value).slice(4)}` : '—';
const value = raw => raw === null || raw === undefined ? '—'
    : (Number(raw) / (isToi.value ? 60 : 1)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sortState = key => props.filters.sort === key ? (props.filters.direction === 'asc' ? 'ascending' : 'descending') : 'none';
const sortMark = key => props.filters.sort === key ? (props.filters.direction === 'asc' ? '↑' : '↓') : '↕';
let timer;
let disposed = false;

function load(overrides = {}) {
    loading.value = true;
    error.value = '';
    router.get(`${base}/${props.evaluation.id}`, { ...props.filters, page: 1, ...overrides }, {
        preserveState: true, preserveScroll: true,
        onError: () => { error.value = 'Could not update the outlook. Please try again.'; },
        onFinish: () => { loading.value = false; },
    });
}
function sort(key) {
    load({ sort: key, direction: props.filters.sort === key && props.filters.direction === 'asc' ? 'desc' : 'asc' });
}
function schedule() {
    if (!disposed) timer = setTimeout(poll, 5000);
}
function poll() {
    if (document.hidden || loading.value || !props.evaluation.active) { schedule(); return; }
    router.reload({ only: ['evaluation'], preserveScroll: true, onFinish: schedule });
}
watch(() => props.evaluation.status, (status, previous) => {
    if (status !== previous && ['ready', 'completed'].includes(status)) {
        router.reload({ only: ['players', 'league'], preserveScroll: true });
    }
});
watch(() => props.filters.q, q => { search.value = q; });
onMounted(schedule);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <Head :title="evaluation.name" />
    <main class="mx-auto max-w-screen-2xl space-y-6 px-4 py-6 text-gray-900 sm:px-6 sm:py-8">
        <header class="space-y-4">
            <Link :href="base" class="inline-flex min-h-11 items-center text-sm text-indigo-700 hover:underline">← Game Evaluations</Link>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="break-words text-2xl font-semibold tracking-tight">{{ evaluation.name }}</h1>
                <span role="status" class="rounded-full px-3 py-1 text-xs font-medium" :class="evaluation.active ? 'bg-blue-50 text-blue-800' : evaluation.status === 'failed' ? 'bg-red-50 text-red-800' : 'bg-emerald-50 text-emerald-800'">{{ evaluation.active ? 'Building' : evaluation.status === 'ready' ? 'Ready' : evaluation.status === 'failed' ? 'Failed' : 'Completed' }}</span>
            </div>
            <dl class="grid gap-5 border-y border-gray-200 py-5 text-sm sm:grid-cols-3 sm:gap-8 sm:[&>div+div]:border-l sm:[&>div+div]:border-gray-200 sm:[&>div+div]:pl-8">
                <div><dt class="text-gray-500">Model</dt><dd class="mt-1 font-medium">{{ evaluation.model_name }}</dd></div>
                <div><dt class="text-gray-500">Train seasons</dt><dd class="mt-1 font-medium">{{ evaluation.training_seasons.map(season).join(', ') || '—' }}</dd></div>
                <div><dt class="text-gray-500">Project season</dt><dd class="mt-1 font-medium">{{ season(evaluation.season_id) }}</dd></div>
            </dl>
        </header>
        <section v-if="!published" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4" aria-label="Preparation progress">
            <p role="status" class="text-sm">{{ evaluation.completed.toLocaleString() }} / {{ evaluation.total.toLocaleString() }} players prepared{{ evaluation.active ? ' · Updates automatically' : '' }}</p>
            <progress v-if="evaluation.total > 0" class="h-2 w-full" :value="evaluation.completed" :max="evaluation.total" aria-label="Players prepared" />
            <p class="text-sm text-gray-500">Initial estimates appear when preparation reaches Ready.</p>
            <p v-if="evaluation.error" role="alert" class="text-sm text-red-700">{{ evaluation.error }}</p>
            <Link v-if="evaluation.can_resume" :href="base" class="inline-flex min-h-11 items-center text-sm text-indigo-700 underline">Return to evaluations to resume preparation</Link>
        </section>
        <nav aria-label="Evaluation views" class="flex flex-wrap items-center gap-6 border-b border-gray-200"><span aria-current="page" class="inline-block border-b-2 border-indigo-600 px-1 pb-3 text-sm font-semibold text-indigo-700">Outlook</span><Link :href="`${base}/${evaluation.id}/games`" class="min-h-11 text-sm font-medium text-gray-600 hover:text-indigo-700">Games</Link><span class="ml-auto pb-3 text-xs text-gray-500">Initial estimates · Revision 1</span></nav>
        <section class="flex flex-wrap items-center gap-x-8 gap-y-5 rounded-lg border border-gray-200 bg-white p-4 sm:p-5" aria-label="Outlook filters">
            <fieldset class="flex flex-wrap items-center gap-2"><legend class="mb-2 text-xs font-medium text-gray-500">Metric</legend><button v-for="[key, label] in [['sat_per_60', 'SAT/60'], ['toi_per_game_seconds', 'TOI/GP']]" :key="key" type="button" :aria-pressed="filters.metric === key" :disabled="loading" class="min-h-11 rounded-lg border px-4 text-sm font-medium transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none" :class="filters.metric === key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50'" @click="load({ metric: key })">{{ label }}</button></fieldset>
            <fieldset class="flex flex-wrap items-center gap-2"><legend class="mb-2 text-xs font-medium text-gray-500">Strength</legend><button v-for="strength in ['ev', 'pp', 'pk', 'all']" :key="strength" type="button" :aria-pressed="filters.strength === strength" :disabled="loading" class="min-h-11 rounded-lg border px-4 text-sm font-medium transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none" :class="filters.strength === strength ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50'" @click="load({ strength })">{{ strength === 'all' ? 'All' : strength.toUpperCase() }}</button></fieldset>
            <form class="w-full sm:ml-auto sm:w-auto" @submit.prevent="load({ q: search })"><label class="mb-2 block text-xs font-medium text-gray-500" for="outlook-search">Player search</label><div class="flex gap-2"><input id="outlook-search" v-model="search" type="search" maxlength="100" placeholder="Search players" class="min-h-11 min-w-0 flex-1 rounded-lg border-gray-300 text-sm" /><button :disabled="loading" class="min-h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm disabled:opacity-50">Search</button></div></form>
        </section>
        <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
        <section class="space-y-3" aria-label="Initial player outlooks" :aria-busy="loading">
            <div class="flex flex-wrap items-end justify-between gap-3 pt-2">
                <div><h2 class="text-base font-semibold text-gray-950">Player outlook</h2><p class="mt-1 text-sm text-gray-500">{{ filters.strength === 'all' ? 'All strengths' : filters.strength.toUpperCase() }} · {{ isToi ? 'Minutes per game' : 'Shot attempts per 60 minutes' }}</p></div>
                <div class="flex items-center gap-2 text-xs text-gray-500"><span class="h-3 w-3 rounded-sm border border-indigo-100 bg-indigo-50" aria-hidden="true"></span>Saved estimates, not actual results</div>
            </div>
            <p class="text-xs text-gray-500 lg:hidden">Swipe the table to see every column. Player names stay visible.</p>
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white" tabindex="0" role="region" aria-label="Scrollable outlook table">
                <table class="w-full whitespace-nowrap text-right text-sm">
                    <caption class="sr-only">{{ filters.strength.toUpperCase() }} initial outlook · {{ unit }}</caption>
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-600">
                        <tr class="border-b border-gray-100">
                            <th rowspan="2" scope="col" class="sticky left-0 z-10 min-w-36 bg-gray-50 px-4 py-3 text-left align-bottom sm:min-w-44" :aria-sort="sortState('player_name')"><button class="min-h-11" @click="sort('player_name')">Name {{ sortMark('player_name') }}</button></th>
                            <th colspan="5" scope="colgroup" class="px-4 py-4 text-center font-medium">Train &amp; pregame</th>
                            <th colspan="5" scope="colgroup" class="border-x border-indigo-100 bg-indigo-50 px-4 py-4 text-center font-semibold text-indigo-800">Anticipated quarter &amp; season values</th>
                            <th colspan="3" scope="colgroup" class="px-4 py-4 text-center font-medium">Actuals &amp; comparison</th>
                        </tr>
                        <tr><th v-for="[key, label] in columns" :key="key" scope="col" class="px-4 py-2" :class="savedColumns.includes(key) ? 'bg-indigo-50 text-indigo-800' : ''" :aria-sort="savedColumns.includes(key) ? sortState(key) : undefined"><button v-if="savedColumns.includes(key)" class="min-h-11" @click="sort(key)">{{ label }} {{ sortMark(key) }}</button><span v-else>{{ label }}</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums">
                        <tr class="bg-gray-50 font-semibold"><th scope="row" class="sticky left-0 z-10 bg-gray-50 px-4 py-4 text-left">League Average</th><td v-for="[key] in columns" :key="key" class="px-4 py-4" :class="savedColumns.includes(key) ? 'bg-indigo-50' : ''">{{ savedColumns.includes(key) ? value(league?.[key]) : '—' }}</td></tr>
                        <tr v-for="player in players.data" :key="player.nhl_player_id" class="group transition-colors duration-150 hover:bg-gray-50 motion-reduce:transition-none"><th scope="row" class="sticky left-0 z-10 bg-white px-4 py-5 text-left font-medium text-gray-950 group-hover:bg-gray-50"><span class="block max-w-36 whitespace-normal sm:max-w-52">{{ player.player_name || `Player #${player.nhl_player_id}` }}</span></th><td v-for="[key] in columns" :key="key" class="px-4 py-5" :class="savedColumns.includes(key) ? 'bg-indigo-50/50 font-medium text-gray-900' : 'text-gray-400'">{{ savedColumns.includes(key) ? value(player[key]) : '—' }}</td></tr>
                        <tr v-if="!players.data.length"><td :colspan="columns.length + 1" class="px-4 py-10 text-left text-gray-500">{{ !published ? 'Waiting for prepared estimates.' : filters.q ? 'No players match your search.' : 'No saved player outlooks are available.' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Player pages" class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600"><span>{{ players.total.toLocaleString() }} players · Page {{ players.current_page }} of {{ players.last_page }}</span><div class="flex gap-2"><button :disabled="loading || players.current_page <= 1" class="min-h-11 rounded-lg border border-gray-300 px-3 disabled:opacity-40" @click="load({ page: players.current_page - 1 })">Previous</button><button :disabled="loading || players.current_page >= players.last_page" class="min-h-11 rounded-lg border border-gray-300 px-3 disabled:opacity-40" @click="load({ page: players.current_page + 1 })">Next</button></div></nav>
            <p class="text-xs leading-5 text-gray-500">League Average is the unweighted mean of available saved player estimates across this evaluation, not just this page or search. Missing values are excluded. Season estimate available for {{ league?.available_players ?? 0 }} of {{ league?.players ?? 0 }} players.</p>
            <p class="text-xs leading-5 text-gray-500">Train, L5, L10, L20 and LSeason have not yet been frozen into this evaluation. Test, ET, ELS and players below 10% / 20% error remain unavailable until comparisons exist. Missing strength values are never replaced with All-strength values.</p>
        </section>
    </main>
</template>
