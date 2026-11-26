<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({ model: Object, readiness: Object, runs: Array, activeRunId: { type: Number, default: null }, runLinks: { type: Array, default: () => [] }, selected: Object, games: Object, summary: Object, comparison: Object });
const base = `/admin/nhl-sat-models/${props.model.id}/season-games`;
const busy = ref(false);
const checking = ref(false);
const error = ref('');
const expanded = ref(null);
const details = ref({});
const lane = ref('EV');
const comparisonId = ref(props.comparison?.baseline_id ?? '');
const active = computed(() => Boolean(props.activeRunId) || props.runs.some(run => run.definition?.entrypoint === 'season_games' && ['queued', 'running'].includes(run.status)));
const number = (value, places = 2) => value == null ? '—' : Number(value).toFixed(places);
const percent = value => value == null ? '—' : `${number(value)}%`;
const probability = value => value == null ? '—' : percent(100 * Number(value));
const minutes = value => value == null ? '—' : number(Number(value) / 60, 1);
const result = row => row.status !== 'complete' ? row.status.toUpperCase() : row.winner_correct == null ? 'NO PICK' : row.winner_correct ? 'WIN' : 'LOSS';
const compareOptions = computed(() => props.runs.filter(run => run.id !== props.selected?.id && run.season_id === props.selected?.season_id));
const phases = { collect: 'Collecting Train history', fit: 'Fitting Train defensive buckets', history: 'Preparing historical inputs', replay: 'Preparing historical inputs', predictions: 'Predicting Test games', complete: 'Predictions saved' };
const stage = computed(() => props.selected?.checkpoint?.stage ?? props.selected?.checkpoint?.phase);
const stageCount = computed(() => props.selected?.checkpoint?.processed ?? props.selected?.checkpoint?.cursor);
const stageTotal = computed(() => props.selected?.checkpoint?.total);
const stagePercent = computed(() => stageTotal.value > 0 && stageCount.value != null
    ? Math.max(0, Math.min(100, 100 * stageCount.value / stageTotal.value)) : null);
const count = value => Number(value).toLocaleString();
const duration = value => {
    if (value == null) return '—';
    const seconds = Math.max(0, Math.floor(Number(value)));
    if (seconds >= 3600) return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m`;
    if (seconds >= 60) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    return `${seconds}s`;
};
const activityTime = value => {
    if (!value || !Number.isFinite(Date.parse(value))) return '—';
    return new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
};
const windows = { train: 'Train', last_game: 'Last Game', last_5: 'Last 5', last_10: 'Last 10', last_20: 'Last 20', last_season: 'Last Season' };
const teamStats = { goals: 'EV + PP goals', ev_goals: 'EV goals', pp_goals: 'PP goals', sat: 'SAT', sog: 'SOG', pp_opportunities: 'PP opportunities', ev_toi: 'EV minutes', pp_toi: 'PP minutes', pk_toi: 'PK minutes' };
const teamValue = (values, metric) => metric === 'goals' ? (values.ev_goals == null || values.pp_goals == null ? null : Number(values.ev_goals) + Number(values.pp_goals)) : values[metric];
const teamDisplay = (values, metric) => metric.endsWith('_toi') ? minutes(teamValue(values, metric)) : number(teamValue(values, metric));
function selectRun(id, compare = '') {
    router.get(base, { run: id, ...(compare ? { compare } : {}) }, { preserveState: true, preserveScroll: true });
}
function checkReadiness() {
    if (checking.value) return;
    checking.value = true;
    router.reload({ only: ['readiness'], data: { refresh_readiness: 1 }, preserveScroll: true,
        onFinish: () => { checking.value = false; } });
}
async function action(path) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(path);
        selectRun(data.run_id);
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors ?? {}).flat()[0] ?? failure.response?.data?.message ?? 'Unable to queue this season run.';
    } finally { busy.value = false; }
}
async function toggleGame(game) {
    expanded.value = expanded.value === game.id ? null : game.id;
    if (expanded.value === null || details.value[game.id]?.teams || details.value[game.id]?.loading) return;
    const runId = props.selected.id;
    details.value[game.id] = { loading: true };
    try {
        const { data } = await axios.get(`${base}/runs/${runId}/games/${game.id}`);
        if (props.selected?.id === runId) details.value[game.id] = data;
    } catch {
        if (props.selected?.id === runId) details.value[game.id] = { error: 'Unable to load saved details. Close and reopen to retry.' };
    }
}
watch(() => props.selected?.id, () => { expanded.value = null; details.value = {}; });
watch(() => props.comparison?.baseline_id, value => { comparisonId.value = value ?? ''; });
let timer;
let disposed = false;
function schedule() { if (!disposed) timer = setTimeout(poll, 5000); }
function poll() {
    if (!active.value || document.hidden) { schedule(); return; }
    router.reload({ only: ['runs', 'selected', 'summary', 'games', 'comparison', 'activeRunId'], preserveScroll: true, preserveState: true, showProgress: false,
        onFinish: schedule, onError: () => {} });
}
onMounted(schedule);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <Head :title="`${model.name} · Season Games`" />
    <main class="min-h-screen bg-[#10151b] text-slate-100">
        <div class="mx-auto max-w-7xl space-y-5 p-3 sm:p-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div><Link href="/admin/nhl-sat-models" class="text-xs text-sky-400 hover:text-sky-200">← SAT Models</Link><h1 class="mt-2 text-xl font-semibold">Season Games <span class="font-normal text-slate-400">· {{ model.name }}</span></h1><p class="mt-1 text-xs text-slate-400">Regular-season Test {{ model.target_season_id ?? 'not selected' }} · Train {{ model.train_season_ids?.join(', ') }}</p></div>
            <button type="button" :disabled="busy || !readiness.ready || active" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold transition-colors duration-150 hover:bg-sky-500 focus-visible:ring-2 focus-visible:ring-sky-300 disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none" @click="action(`${base}/runs`)">{{ busy ? 'Queuing…' : 'Run Season Games' }}</button>
        </header>
        <p v-if="error" role="alert" class="rounded-lg border border-red-700/40 bg-red-950/40 p-3 text-sm text-red-200">{{ error }}</p>
        <section v-if="!readiness.ready" aria-label="Season readiness" class="rounded-xl border border-amber-500/30 bg-slate-900 p-4 text-sm">
            <div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-amber-200">Not ready to run</h2><button type="button" :disabled="checking" class="text-xs text-sky-300 hover:text-sky-100 disabled:opacity-40" @click="checkReadiness">{{ checking ? 'Checking…' : 'Check again' }}</button></div>
            <ul class="mt-2 list-inside list-disc text-slate-300"><li v-for="reason in readiness.reasons" :key="reason">{{ reason }}</li></ul>
            <details v-if="readiness.missing_games?.length" class="mt-3 text-xs"><summary class="cursor-pointer text-sky-300">Show affected games ({{ readiness.missing_games.length }})</summary><div class="mt-2 max-h-48 overflow-y-auto"><p v-for="game in readiness.missing_games" :key="game.game_id" class="py-1">{{ game.date }} · {{ game.game_id }} — {{ game.missing.join(', ') }}</p></div></details>
        </section>
        <div class="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="rounded-xl border border-slate-700 bg-slate-900 p-3">
                <h2 class="px-2 py-1 text-xs font-semibold uppercase tracking-wider text-slate-400">Run history</h2>
                <p v-if="!runs.length" class="p-2 text-sm text-slate-400">No saved runs yet. Your first run establishes the baseline.</p>
                <div class="mt-2 max-h-[32rem] space-y-2 overflow-y-auto">
                    <button v-for="run in runs" :key="run.id" type="button" :aria-pressed="selected?.id === run.id" class="w-full rounded-lg border p-3 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="selected?.id === run.id ? 'border-sky-500 bg-sky-950/50' : 'border-slate-800 bg-slate-950/50 hover:border-slate-600'" @click="selectRun(run.id)">
                        <div class="flex justify-between gap-2"><span class="text-sm font-medium">Run #{{ run.id }}</span><div class="shrink-0 text-right"><span class="block text-sm font-semibold text-sky-300">{{ percent(run.win_pct) }}</span><p :aria-label="`Run ${run.id} prediction record`" class="mt-0.5 text-[10px] leading-tight tabular-nums text-slate-400">{{ run.wins ?? 0 }}–{{ run.regulation_losses ?? run.losses ?? 0 }}–{{ run.overtime_losses ?? 0 }}</p></div></div>
                        <p class="mt-1 text-[11px] text-slate-300">{{ run.name }}</p><p class="mt-1 text-[11px] text-slate-400">{{ run.algorithm_version }} · {{ run.status }}</p><p class="mt-1 text-[11px] text-slate-400">{{ run.created_at }}</p><p class="mt-1 text-xs">{{ run.completed_games }} / {{ run.total_games }} games</p>
                    </button>
                </div>
                <nav v-if="runLinks.length > 3" aria-label="Run history pages" class="mt-3 flex flex-wrap gap-1"><Link v-for="(link, i) in runLinks" :key="i" :href="link.url ?? '#'" :aria-disabled="!link.url" class="rounded bg-slate-800 px-2 py-1 text-[11px]" :class="{ 'pointer-events-none opacity-40': !link.url, 'text-sky-300': link.active }" preserve-state preserve-scroll><span v-html="link.label" /></Link></nav>
            </aside>
            <div v-if="selected" class="min-w-0 space-y-4">
                <section aria-label="Run accuracy" class="rounded-xl border border-slate-700 bg-slate-900 p-4">
                    <div class="flex flex-wrap justify-between gap-3"><h2 class="font-semibold">Run #{{ selected.id }} <span class="text-xs font-normal text-slate-400">{{ selected.algorithm_version }}</span></h2><button v-if="selected.can_resume" type="button" :disabled="busy" class="rounded border border-sky-500 px-3 py-1 text-xs text-sky-200 disabled:opacity-40" @click="action(`${base}/runs/${selected.id}/resume`)">Resume run</button></div>
                    <p class="mt-2 text-xs text-slate-400">Saved Train: {{ selected.definition?.source?.train_seasons?.join(', ') ?? 'not archived' }} · Test {{ selected.season_id }}</p>
                    <details class="mt-2 text-xs text-slate-400"><summary class="cursor-pointer">Saved run settings</summary><pre class="mt-2 max-h-48 overflow-auto rounded bg-slate-950 p-3 text-[11px]">{{ JSON.stringify(selected.definition, null, 2) }}</pre></details>
                    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4"><div><p class="text-xs text-slate-400">Win%</p><p class="mt-1 text-3xl font-semibold text-sky-300">{{ percent(summary?.win_pct) }}</p><p aria-label="Prediction record" class="mt-1 text-xs tabular-nums text-slate-300">{{ summary?.wins ?? 0 }}–{{ summary?.regulation_losses ?? summary?.losses ?? 0 }}–{{ summary?.overtime_losses ?? 0 }}</p></div><div><p class="text-xs text-slate-400">Correct / picked games</p><p class="mt-2 text-lg">{{ summary?.wins ?? 0 }} / {{ (summary?.wins ?? 0) + (summary?.losses ?? 0) }}</p></div><div><p class="text-xs text-slate-400">Games predicted</p><p class="mt-2 text-lg">{{ summary?.predicted ?? 0 }} / {{ selected.total_games }}</p></div><div><p class="text-xs text-slate-400">Excluded / no pick</p><p class="mt-2 text-lg">{{ summary?.excluded ?? 0 }} / {{ summary?.unpicked ?? 0 }}</p></div></div>
                    <div class="mt-4 min-h-[5.5rem]" aria-label="Run progress">
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs">
                            <p class="font-medium text-slate-200">{{ phases[stage] ?? (selected.status === 'queued' ? 'Queued for a worker' : selected.status) }}</p>
                            <p class="tabular-nums text-slate-300"><template v-if="stageCount != null">{{ count(stageCount) }}<template v-if="stageTotal != null"> / {{ count(stageTotal) }}</template> {{ selected.checkpoint?.unit ?? (stage === 'fit' ? 'buckets' : 'games') }}</template><template v-else>{{ count((summary?.predicted ?? 0) + (summary?.excluded ?? 0)) }} / {{ count(selected.total_games) }} games</template></p>
                        </div>
                        <div role="progressbar" aria-label="Current stage progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="stagePercent == null ? undefined : Math.round(stagePercent)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-800"><div class="h-full rounded-full bg-sky-500 transition-[width] duration-300 ease-out motion-reduce:transition-none" :style="{ width: `${stagePercent ?? (selected.total_games ? 100 * ((summary?.predicted ?? 0) + (summary?.excluded ?? 0)) / selected.total_games : 0)}%` }" /></div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] tabular-nums text-slate-400"><span>{{ selected.status }}</span><span>Elapsed {{ duration(selected.elapsed_seconds) }}</span><span>Last activity <time :datetime="selected.last_activity_at">{{ activityTime(selected.last_activity_at) }}</time></span></div>
                    </div>
                    <p v-if="selected.requires_new_run && selected.status === 'failed'" class="mt-3 text-xs text-amber-200">The saved model, context or algorithm changed. Start a new run to preserve this record.</p>
                    <p v-if="selected.last_error" role="alert" class="mt-3 text-sm text-red-300">{{ selected.last_error }}</p>
                    <table class="mt-4 w-full text-left text-xs"><thead class="text-slate-400"><tr><th class="py-2 font-medium">Goal accuracy</th><th class="font-medium">Prediction</th><th class="font-medium">Actual</th><th class="font-medium">err%</th><th class="font-medium">p10</th><th class="font-medium">p20</th></tr></thead><tbody><tr v-for="[key, label] in [['team_goals', 'Per team'], ['game_goals', 'Per game']]" :key="key" class="border-t border-slate-800"><th class="py-2 font-normal">{{ label }}</th><td>{{ number(summary?.[key]?.prediction) }}</td><td>{{ number(summary?.[key]?.actual) }}</td><td>{{ percent(summary?.[key]?.err_pct) }}</td><td>{{ percent(summary?.[key]?.p10) }}</td><td>{{ percent(summary?.[key]?.p20) }}</td></tr></tbody></table>
                    <p class="mt-2 text-[11px] text-slate-400">Winner: official final result, including OT/SO. Goal errors: matching retained-bucket regulation EV + PP totals, excluding empty nets. err% compares summed totals; p10/p20 count individual team-games or games below 10%/20% error. Zero/zero omitted: {{ summary?.team_goals?.both_zero ?? 0 }} team comparisons, {{ summary?.game_goals?.both_zero ?? 0 }} game comparisons.</p>
                </section>
                <section class="rounded-xl border border-slate-700 bg-slate-900 p-4" aria-label="Compare runs">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-sm font-semibold">Compare with baseline run</h2><select v-model="comparisonId" aria-label="Compare with run" class="rounded-lg border-slate-600 bg-slate-950 py-1.5 text-xs" @change="selectRun(selected.id, comparisonId)"><option value="">Select a run</option><option v-for="run in compareOptions" :key="run.id" :value="run.id">#{{ run.id }} · {{ run.algorithm_version }} · {{ percent(run.win_pct) }}</option></select></div>
                    <div v-if="comparison" class="mt-3 text-sm"><p>{{ comparison.matched }} matching picked games: {{ percent(comparison.before_win_pct) }} → {{ percent(comparison.after_win_pct) }} <span class="text-xs text-slate-400">· {{ comparison.unmatched }} unmatched/no-pick games</span></p><div class="mt-2 max-h-44 overflow-y-auto text-xs"><p v-for="change in comparison.changes" :key="change.game_id" class="py-1">{{ change.date }} · {{ change.away }} @ {{ change.home }} · {{ change.game_id }} <span :class="change.after ? 'text-emerald-300' : 'text-red-300'">{{ change.before ? 'WIN' : 'LOSS' }} → {{ change.after ? 'WIN' : 'LOSS' }}</span></p><p v-if="!comparison.changes.length" class="text-slate-400">No winner-pick changes among the matching games.</p></div></div>
                </section>
                <section aria-label="Game predictions" class="overflow-hidden rounded-xl border border-slate-700 bg-slate-900">
                    <div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-xs"><thead class="bg-slate-950 text-slate-400"><tr><th class="px-3 py-3 font-medium">Game</th><th class="px-3 font-medium">Predicted</th><th class="px-3 font-medium">Final</th><th class="px-3 font-medium">Away / home probability</th><th class="px-3 font-medium">Pick</th><th class="px-3 font-medium">Result</th></tr></thead><tbody>
                        <template v-for="game in games.data" :key="game.id">
                            <tr class="border-t border-slate-800"><td class="px-3 py-3"><button type="button" :aria-expanded="expanded === game.id" :aria-controls="`season-game-${game.id}`" class="text-left text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400" @click="toggleGame(game)"><span class="mr-2 inline-block transition-transform duration-300 motion-reduce:transition-none" :class="{ 'rotate-90': expanded === game.id }">›</span>{{ game.away_team_abbrev }} @ {{ game.home_team_abbrev }}<span class="mt-1 block text-[11px] text-slate-400">{{ game.game_date }}</span></button></td><td class="px-3">{{ number(game.away_goals_prediction) }}–{{ number(game.home_goals_prediction) }}</td><td class="px-3">{{ game.away_actual_goals ?? '—' }}–{{ game.home_actual_goals ?? '—' }}</td><td class="px-3">{{ probability(game.away_win_probability) }} / {{ probability(game.home_win_probability) }}</td><td class="px-3">{{ game.recommendation ?? '—' }}</td><td class="px-3" :class="game.winner_correct == null ? 'text-slate-400' : game.winner_correct ? 'text-emerald-300' : 'text-red-300'">{{ result(game) }}</td></tr>
                            <tr><td colspan="6" class="p-0"><div :id="`season-game-${game.id}`" :aria-hidden="expanded !== game.id" :inert="expanded !== game.id" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded === game.id ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"><div class="min-h-0 overflow-hidden"><div class="whitespace-normal border-t border-slate-800 bg-slate-950/60 p-4">
                                <p v-if="game.last_error" class="text-red-300">{{ game.last_error }}</p><p v-if="details[game.id]?.loading" role="status" class="text-slate-400">Loading saved game details…</p><p v-if="details[game.id]?.error" role="alert" class="text-red-300">{{ details[game.id].error }}</p>
                                <div v-if="details[game.id]?.teams" class="space-y-5"><div class="flex gap-2"><button v-for="strength in ['EV', 'PP']" :key="strength" type="button" :aria-pressed="lane === strength" class="rounded border px-3 py-1 transition-colors duration-150 motion-reduce:transition-none" :class="lane === strength ? 'border-sky-500 bg-sky-950 text-sky-200' : 'border-slate-700 text-slate-400'" @click="lane = strength">{{ strength }}</button></div>
                                    <section v-for="side in ['away', 'home'].filter(key => details[game.id].teams[key])" :key="side" class="min-w-0">
                                        <h3 class="font-semibold">{{ details[game.id].teams[side].team_abbrev ?? side }} · {{ side }}</h3>
                                        <div class="mt-2 grid gap-4 xl:grid-cols-2"><table class="w-full text-xs"><thead class="text-slate-400"><tr><th class="py-1 text-left">Team</th><th>Prediction</th><th>Actual</th></tr></thead><tbody><tr v-for="(label, metric) in teamStats" :key="metric"><th class="py-1 text-left font-normal">{{ label }}</th><td class="text-center">{{ teamDisplay(details[game.id].teams[side].predicted, metric) }}</td><td class="text-center">{{ teamDisplay(details[game.id].teams[side].actual, metric) }}</td></tr></tbody></table>
                                            <div v-for="goalie in details[game.id].teams[side].players.filter(player => player.role === 'goalie')" :key="goalie.nhl_player_id" class="rounded-lg border border-slate-700 p-3"><h4 class="font-semibold">{{ goalie.name }} · Starting goalie</h4><p class="mt-1 text-slate-300">Actual appearance: {{ goalie.goalie.actual.saves ?? '—' }} SV · {{ goalie.goalie.actual.shots_against ?? '—' }} SA · {{ goalie.goalie.actual.goals_against ?? '—' }} GA · {{ minutes(goalie.goalie.actual.toi_seconds) }} min</p><p class="mt-1 text-[11px] text-slate-400">Neutral goalie baseline · opposition forecast: {{ number(goalie.goalie.prediction.sog_against) }} SOG / {{ number(goalie.goalie.prediction.goals_against) }} goals. Actuals include the starter’s full appearance.</p><div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px]"><span v-for="(label, window) in windows" :key="window">{{ label }} on-ice SV%: {{ percent(goalie.goalie.pregame.windows?.[window]?.on_ice_save_pct) }} ({{ goalie.goalie.pregame.windows?.[window]?.games ?? '—' }} context GP)</span></div></div>
                                        </div>
                                        <details class="mt-3 text-[11px] text-slate-400"><summary class="cursor-pointer">Saved team pregame context</summary><p class="mt-2">Back-to-back: {{ details[game.id].teams[side].schedule?.back_to_back == null ? '—' : details[game.id].teams[side].schedule?.back_to_back ? 'Yes' : 'No' }} · Games in 7 days, including target: {{ details[game.id].teams[side].schedule?.games_including_target_last_7_days ?? '—' }}</p><div class="mt-2 overflow-x-auto"><table class="w-full whitespace-nowrap text-left"><thead><tr><th class="py-1">Window</th><th>GP</th><th>Win%</th><th>Corsi%</th><th>SH%</th><th>PDO</th></tr></thead><tbody><tr v-for="(label, window) in windows" :key="window"><td class="py-1">{{ label }}</td><td>{{ details[game.id].teams[side].pregame?.[window]?.games ?? '—' }}</td><td>{{ percent(details[game.id].teams[side].pregame?.[window]?.win_pct) }}</td><td>{{ percent(details[game.id].teams[side].pregame?.[window]?.corsi_pct) }}</td><td>{{ percent(details[game.id].teams[side].pregame?.[window]?.shooting_pct) }}</td><td>{{ number(details[game.id].teams[side].pregame?.[window]?.pdo) }}</td></tr></tbody></table></div></details>
                                        <div class="mt-3 overflow-x-auto"><table class="w-full whitespace-nowrap text-xs"><thead class="text-slate-400"><tr><th class="py-2 text-left">{{ lane }} players</th><th>TOI P / A (min)</th><th>SAT P / A</th><th>SOG P / A</th><th>G P / A</th></tr></thead><tbody><tr v-for="player in details[game.id].teams[side].players.filter(row => row.role !== 'goalie')" :key="player.nhl_player_id" class="border-t border-slate-800"><td class="py-2">{{ player.name }}<details class="mt-1 text-[10px] text-slate-400"><summary class="cursor-pointer">Saved pregame rates</summary><p v-for="(label, window) in windows" :key="window" class="mt-1">{{ label }}: {{ player.history[lane]?.[window]?.games ?? '—' }} GP · {{ number(player.history[lane]?.[window]?.seconds > 0 ? 3600 * player.history[lane][window].sat / player.history[lane][window].seconds : null) }} SAT/60 · {{ percent(player.history[lane]?.[window]?.sog > 0 ? 100 * player.history[lane][window].goals / player.history[lane][window].sog : null) }} SH% · {{ number(player.history[lane]?.[window]?.games > 0 ? player.history[lane][window].goals / player.history[lane][window].games : null) }} G/GP</p></details></td><td class="text-center">{{ minutes(player.lanes[lane].prediction?.seconds) }} / {{ minutes(player.lanes[lane].actual.seconds) }}</td><td v-for="metric in ['sat', 'sog', 'goals']" :key="metric" class="text-center">{{ number(player.lanes[lane].prediction?.[metric]) }} / {{ number(player.lanes[lane].actual[metric]) }}</td></tr></tbody></table></div>
                                    </section><p class="text-[11px] text-slate-400">Player SAT/SOG/goals use retained regulation EV/PP buckets. Player TOI comes from imported strength summaries and may include overtime. Missing historical fields remain unknown.</p>
                                </div>
                            </div></div></div></td></tr>
                        </template>
                    </tbody></table></div><p v-if="!games.data.length" class="p-5 text-sm text-slate-400">No games saved yet. {{ selected.status === 'queued' ? 'Waiting for a projections worker.' : selected.status === 'running' ? 'Preparing Train history and defensive buckets before Test predictions.' : '' }}</p>
                    <nav v-if="games.links?.length > 3" aria-label="Game pages" class="flex flex-wrap gap-2 border-t border-slate-800 p-3"><Link v-for="(link, i) in games.links" :key="i" :href="link.url ?? '#'" :aria-disabled="!link.url" :class="[!link.url ? 'pointer-events-none opacity-40' : '', link.active ? 'bg-sky-700' : 'bg-slate-800']" class="rounded px-2 py-1 text-xs" preserve-scroll preserve-state><span v-html="link.label" /></Link></nav>
                </section>
            </div>
            <section v-else class="flex min-h-48 items-center justify-center rounded-xl border border-slate-700 bg-slate-900 p-6 text-center text-sm text-slate-400">Run the Test season to establish a saved game-by-game baseline.</section>
        </div>
        </div>
    </main>
</template>
