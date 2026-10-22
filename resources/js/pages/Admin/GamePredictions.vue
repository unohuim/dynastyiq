<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ url: { type: String, required: true }, today: { type: String, required: true } });
const start = ref(props.today);
const end = ref(props.today);
const range = ref(false);
const applied = ref({ start: props.today, end: props.today });
const page = ref(1);
const runId = ref(null);
const data = ref({ games: [], runs: [], run: null, last_page: 1, total: 0 });
const loading = ref(false);
const submitting = ref(false);
const error = ref('');
const notice = ref('');
const histories = ref({});
const details = ref({});
const controllers = new Set();
const historyTokens = new Map();
let timer;
let loadController;
let disposed = false;
const validDates = computed(() => dateValue(start.value) !== null
    && (!range.value || (dateValue(end.value) !== null && end.value >= start.value)));
const base = computed(() => props.url.replace(/\/$/, ''));
const progress = computed(() => {
    const counts = data.value.run?.counts ?? {};
    return Number(counts.completed ?? 0) + Number(counts.unavailable ?? 0) + Number(counts.failed ?? 0);
});
const percent = value => value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(1)}%`;
const number = value => value === null || value === undefined ? '—' : Number(value).toFixed(2);
const dateTime = value => value ? new Date(/(?:Z|[+-]\d\d:\d\d)$/.test(value) ? value : `${value.replace(' ', 'T')}Z`).toLocaleString() : '—';
const score = revision => revision ? `${number(revision.away_goals)}–${number(revision.home_goals)}` : '—';

async function request(url, options = {}, controller = new AbortController()) {
    controllers.add(controller);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal,
            headers: { Accept: 'application/json', ...(options.headers ?? {}) } });
        const payload = await response.json();
        if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Unable to load predictions.');
        return payload;
    } finally {
        controllers.delete(controller);
    }
}

async function load() {
    clearTimeout(timer);
    loadController?.abort();
    const controller = new AbortController();
    loadController = controller;
    loading.value = true;
    const params = new URLSearchParams({ start_date: applied.value.start, end_date: applied.value.end, page: page.value });
    if (runId.value) params.set('run_id', runId.value);
    try {
        const payload = await request(`${base.value}?${params}`, {}, controller);
        if (!disposed && !controller.signal.aborted) {
            data.value = payload;
            runId.value = payload.run?.id ?? null;
            error.value = '';
        }
    } catch (failure) {
        if (failure.name !== 'AbortError' && !disposed && !controller.signal.aborted) error.value = failure.message;
    } finally {
        if (loadController === controller && !disposed) {
            loading.value = false;
            if (!submitting.value) timer = setTimeout(load, 5000);
        }
    }
}

function dateValue(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const date = new Date(`${value}T12:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
}

function shiftDate(field, days) {
    if (submitting.value) return;
    const selected = field === 'start' ? start : end;
    const date = dateValue(selected.value);
    if (!date) return;
    date.setUTCDate(date.getUTCDate() + days);
    selected.value = date.toISOString().slice(0, 10);
    applyDates();
}

function changeRange() {
    if (range.value && (!dateValue(end.value) || end.value < start.value)) end.value = start.value;
    applyDates();
}

function applyDates() {
    if (submitting.value) return;
    clearTimeout(timer);
    loadController?.abort();
    loadController = null;
    loading.value = false;
    page.value = 1;
    runId.value = null;
    histories.value = {};
    historyTokens.clear();
    details.value = {};
    notice.value = '';
    data.value = { ...data.value, games: [], run: null, total: 0, last_page: 1 };
    if (!validDates.value) {
        error.value = !dateValue(start.value) || (range.value && !dateValue(end.value))
            ? 'Choose valid dates to view games.' : 'End date must be on or after start date.';
        return;
    }
    error.value = '';
    applied.value = { start: start.value, end: range.value ? end.value : start.value };
    void load();
}

async function run() {
    if (submitting.value || !validDates.value) return;
    submitting.value = true;
    error.value = '';
    notice.value = '';
    clearTimeout(timer);
    loadController?.abort();
    try {
        const result = await request(`${base.value}/runs`, { method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' },
            body: JSON.stringify({ start_date: start.value, end_date: range.value ? end.value : start.value }) });
        if (disposed) return;
        applied.value = { start: start.value, end: range.value ? end.value : start.value };
        runId.value = result.run_id;
        page.value = 1;
        notice.value = `Prediction run #${result.run_id} created for ${result.total_games} games.`;
        window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: notice.value } }));
        await load();
    } catch (failure) {
        if (failure.name !== 'AbortError' && !disposed) error.value = failure.message;
    } finally {
        if (!disposed) {
            submitting.value = false;
            clearTimeout(timer);
            timer = setTimeout(load, 5000);
        }
    }
}

async function chooseRun(event) {
    const selected = data.value.runs.find(item => String(item.id) === event.target.value);
    if (!selected) return;
    start.value = selected.start_date;
    end.value = selected.end_date;
    range.value = selected.start_date !== selected.end_date;
    applied.value = { start: selected.start_date, end: selected.end_date };
    runId.value = selected.id;
    page.value = 1;
    histories.value = {};
    historyTokens.clear();
    details.value = {};
    await load();
}

async function history(gameId, nextPage = 1) {
    const token = Symbol('history request');
    historyTokens.set(gameId, token);
    const previous = histories.value[gameId];
    if (previous?.open && nextPage === previous.current_page) {
        previous.open = false;
        return;
    }
    histories.value[gameId] = { ...previous, open: true, loading: true, error: '' };
    try {
        const result = await request(`${base.value}/games/${gameId}/revisions?page=${nextPage}`);
        if (!disposed && historyTokens.get(gameId) === token) histories.value[gameId] = { ...result, open: true, loading: false };
    } catch (failure) {
        if (failure.name !== 'AbortError' && !disposed && historyTokens.get(gameId) === token) histories.value[gameId] = { open: true, loading: false, error: failure.message };
    }
}

async function detail(id) {
    if (details.value[id]?.payload) {
        delete details.value[id];
        return;
    }
    details.value[id] = { loading: true };
    try {
        const result = await request(`${base.value}/revisions/${id}`);
        if (!disposed) details.value[id] = result;
    } catch (failure) {
        if (failure.name !== 'AbortError' && !disposed) details.value[id] = { error: failure.message };
    }
}

function movePage(next) {
    page.value = next;
    histories.value = {};
    void load();
}

onMounted(load);
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(timer);
    for (const controller of controllers) controller.abort();
});
</script>

<template>
    <section aria-label="Game Predictions" class="space-y-4">
        <div class="border-y border-gray-200 bg-white px-4 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Run Game Predictions</h3>
            <p class="mt-1 text-sm text-gray-600">Use the current default stack. Each new calculation is saved; the latest successful prediction is shown below.</p>
            <p class="mt-1 text-xs text-gray-500">Past dates use currently available inputs. These runs are not historical backtests.</p>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent>
                <div>
                    <label for="prediction-start-date" class="block text-sm font-medium text-gray-700">{{ range ? 'Start date' : 'Date' }}</label>
                    <div class="mt-1 flex items-center rounded-md border border-gray-300 bg-white focus-within:ring-2 focus-within:ring-indigo-500">
                        <button type="button" :aria-label="range ? 'Previous start date' : 'Previous day'" :disabled="submitting || !dateValue(start)" class="flex h-10 w-10 items-center justify-center rounded-l-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="shiftDate('start', -1)"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg></button>
                        <input id="prediction-start-date" v-model="start" type="date" required :disabled="submitting" class="min-w-0 border-0 bg-transparent text-sm focus:ring-0" @change="applyDates" />
                        <button type="button" :aria-label="range ? 'Next start date' : 'Next day'" :disabled="submitting || !dateValue(start) || (range && start >= end)" class="flex h-10 w-10 items-center justify-center rounded-r-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="shiftDate('start', 1)"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg></button>
                    </div>
                </div>
                <div v-if="range">
                    <label for="prediction-end-date" class="block text-sm font-medium text-gray-700">End date</label>
                    <div class="mt-1 flex items-center rounded-md border border-gray-300 bg-white focus-within:ring-2 focus-within:ring-indigo-500">
                        <button type="button" aria-label="Previous end date" :disabled="submitting || !dateValue(end) || end <= start" class="flex h-10 w-10 items-center justify-center rounded-l-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="shiftDate('end', -1)"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg></button>
                        <input id="prediction-end-date" v-model="end" type="date" required :min="start" :disabled="submitting" class="min-w-0 border-0 bg-transparent text-sm focus:ring-0" @change="applyDates" />
                        <button type="button" aria-label="Next end date" :disabled="submitting || !dateValue(end)" class="flex h-10 w-10 items-center justify-center rounded-r-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="shiftDate('end', 1)"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg></button>
                    </div>
                </div>
                <label class="flex min-h-10 items-center gap-2 text-sm text-gray-700"><input v-model="range" type="checkbox" @change="changeRange" :disabled="submitting" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Date range</label>
                <button type="button" :disabled="submitting || !validDates" @click="run" class="min-h-10 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-60 motion-reduce:transition-none">{{ submitting ? 'Starting…' : 'Run Predictions' }}</button>
            </form>
            <p v-if="notice" role="status" class="mt-3 text-sm text-emerald-700">{{ notice }}</p>
            <p v-if="error" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
        </div>
        <div class="space-y-3 bg-white px-4 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-900">Latest Predictions <span class="font-normal text-gray-500">({{ data.total }} games)</span></h3>
                <label v-if="data.runs.length" class="text-xs text-gray-600">Run
                    <select :value="runId ?? ''" :disabled="submitting" class="ml-2 rounded-md border-gray-300 text-sm" @change="chooseRun"><option value="" disabled>Select a run</option><option v-for="item in data.runs" :key="item.id" :value="item.id">#{{ item.id }} · {{ item.start_date }}–{{ item.end_date }} · {{ item.status.replaceAll('_', ' ') }}</option></select>
                </label>
            </div>
            <div v-if="data.run" role="status" class="space-y-2 rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                <p>Run #{{ data.run.id }} · {{ data.run.status.replaceAll('_', ' ') }} · {{ progress }} / {{ data.run.total_games }} games</p>
                <progress :value="progress" :max="data.run.total_games" aria-label="Prediction run progress" class="h-2 w-full accent-indigo-600" />
                <p>{{ data.run.counts.completed ?? 0 }} predicted · {{ data.run.counts.unavailable ?? 0 }} unavailable · {{ data.run.counts.failed ?? 0 }} failed</p>
                <p>Results show the latest saved predictions, including calculations after this run.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                    <thead class="bg-gray-50 text-gray-600"><tr><th scope="col" class="p-3">Date / Game</th><th scope="col" class="p-3">Prediction<br /><span class="font-normal">Away–Home</span></th><th scope="col" class="p-3">Pick</th><th scope="col" class="p-3">Win probability</th><th scope="col" class="p-3">Confidence</th><th scope="col" class="p-3">Qualified</th><th scope="col" class="p-3">Actual<br /><span class="font-normal">Away–Home</span></th><th scope="col" class="p-3">Updated / Status</th><th scope="col" class="p-3">History</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template v-for="game in data.games" :key="game.nhl_game_id">
                            <tr><td class="whitespace-nowrap p-3"><p class="text-gray-500">{{ game.date }}</p><b>{{ game.away }} @ {{ game.home }}</b></td><td class="p-3 tabular-nums">{{ score(game.revision) }}</td><td class="p-3">{{ game.revision?.winner ?? '—' }}</td><td class="p-3">{{ percent(game.revision?.win_probability) }}</td><td class="p-3">{{ game.revision?.confidence === null || !game.revision ? '—' : `${number(game.revision.confidence)}%` }}</td><td class="p-3">{{ !game.revision ? '—' : game.revision.qualified ? 'Yes' : 'No' }}</td><td class="p-3 tabular-nums">{{ game.actual_away === null ? '—' : `${game.actual_away}–${game.actual_home}` }}</td><td class="max-w-xs p-3"><p>{{ dateTime(game.revision?.predicted_at) }}</p><p v-if="game.work" class="mt-1 text-gray-500">{{ game.work.status }}</p><p v-if="game.work?.last_error" class="mt-1 text-red-700">{{ game.work.last_error }}</p></td><td class="p-3"><button type="button" :aria-expanded="!!histories[game.nhl_game_id]?.open" class="rounded text-indigo-700 hover:underline focus-visible:ring-2 focus-visible:ring-indigo-500" @click="history(game.nhl_game_id, histories[game.nhl_game_id]?.current_page ?? 1)">Revisions</button></td></tr>
                            <tr v-if="histories[game.nhl_game_id]?.open"><td colspan="9" class="bg-gray-50 p-4">
                                <p v-if="histories[game.nhl_game_id].loading" role="status">Loading revisions…</p>
                                <p v-if="histories[game.nhl_game_id].error" role="alert" class="text-red-700">{{ histories[game.nhl_game_id].error }}</p>
                                <div v-for="revision in histories[game.nhl_game_id].revisions" :key="revision.id" class="border-b border-gray-200 py-3">
                                    <div class="flex flex-wrap items-center gap-3"><b>#{{ revision.id }} · {{ score(revision) }}</b><span>{{ revision.winner ?? 'Unavailable' }} · {{ percent(revision.win_probability) }} win probability</span><span>{{ number(revision.confidence) }} confidence</span><span>{{ dateTime(revision.predicted_at) }}</span><span>{{ revision.source }} · {{ revision.is_canonical ? 'Default inputs' : 'Input override' }}</span><span v-if="revision.reason">{{ revision.reason }}</span><button type="button" :aria-expanded="!!details[revision.id]?.payload" class="text-indigo-700 hover:underline" @click="detail(revision.id)">Saved inputs &amp; result</button></div>
                                    <p v-if="details[revision.id]?.loading" role="status">Loading saved evidence…</p><p v-if="details[revision.id]?.error" role="alert">{{ details[revision.id].error }}</p>
                                    <pre v-if="details[revision.id]?.payload" class="mt-2 max-h-80 overflow-auto rounded border border-gray-200 bg-white p-3 text-[11px]">{{ JSON.stringify(details[revision.id], null, 2) }}</pre>
                                </div>
                                <p v-if="histories[game.nhl_game_id].total === 0">No saved revisions.</p>
                                <div v-if="histories[game.nhl_game_id].last_page > 1" class="mt-2 flex items-center gap-3"><button type="button" :disabled="histories[game.nhl_game_id].current_page <= 1" class="text-indigo-700 disabled:text-gray-400" @click="history(game.nhl_game_id, histories[game.nhl_game_id].current_page - 1)">Newer</button><span>Page {{ histories[game.nhl_game_id].current_page }} / {{ histories[game.nhl_game_id].last_page }}</span><button type="button" :disabled="histories[game.nhl_game_id].current_page >= histories[game.nhl_game_id].last_page" class="text-indigo-700 disabled:text-gray-400" @click="history(game.nhl_game_id, histories[game.nhl_game_id].current_page + 1)">Older</button></div>
                            </td></tr>
                        </template>
                        <tr v-if="!data.games.length"><td colspan="9" class="p-6 text-center text-gray-500">{{ loading ? 'Loading games…' : 'No stored games for these dates.' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="data.last_page > 1" class="flex items-center justify-between text-sm"><button type="button" :disabled="page <= 1 || loading" class="text-indigo-700 disabled:text-gray-400" @click="movePage(page - 1)">Previous</button><span>Page {{ page }} / {{ data.last_page }}</span><button type="button" :disabled="page >= data.last_page || loading" class="text-indigo-700 disabled:text-gray-400" @click="movePage(page + 1)">Next</button></div>
        </div>
    </section>
</template>
