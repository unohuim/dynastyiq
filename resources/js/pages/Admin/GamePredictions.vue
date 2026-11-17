<script setup>
import { computed, onBeforeUnmount, onMounted, nextTick, ref } from 'vue';

const props = defineProps({ url: { type: String, required: true }, today: { type: String, required: true } });
const automationOpen = ref(false);
const automationLoading = ref(false);
const automationSaving = ref(false);
const automationLoaded = ref(false);
const automationError = ref('');
const automationNotice = ref('');
const automation = ref({ enabled: false, trigger_mode: 'time', start_time: '06:00', frequency_hours: 24 });
const automationButton = ref(null);
const automationPanel = ref(null);
let automationController;
let previousOverflow;

/** Open first, then load saved settings; never start work from a settings request. */
async function openAutomation() {
    if (!automationOpen.value) previousOverflow = document.body.style.overflow;
    automationOpen.value = true;
    document.body.style.overflow = 'hidden';
    automationError.value = '';
    automationNotice.value = '';
    automationLoaded.value = false;
    automationLoading.value = true;
    await nextTick();
    if (disposed || !automationOpen.value) return;
    automationPanel.value?.focus();
    const controller = new AbortController();
    automationController = controller;
    try {
        const saved = await request(`${base.value}/automation`, {}, controller);
        if (disposed || controller.signal.aborted) return;
        automation.value = { enabled: saved.enabled, trigger_mode: saved.settings.trigger_mode,
            start_time: saved.start_time, frequency_hours: saved.frequency_hours };
        automationLoaded.value = true;
    } catch (failure) {
        if (!disposed && failure.name !== 'AbortError') automationError.value = failure.message;
    } finally {
        if (automationController === controller && !disposed) automationLoading.value = false;
    }
}

function closeAutomation() {
    if (automationSaving.value) return;
    automationOpen.value = false;
    automationController?.abort();
    document.body.style.overflow = previousOverflow ?? '';
    void nextTick(() => automationButton.value?.focus());
}

function trapAutomationFocus(event) {
    const panel = automationPanel.value;
    const controls = [...panel.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled)')];
    const first = controls[0];
    const last = controls.at(-1);
    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
        event.preventDefault(); last?.focus();
    } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === panel)) {
        event.preventDefault(); first?.focus();
    }
}

async function saveAutomation() {
    if (!automationLoaded.value || automationSaving.value) return;
    automationSaving.value = true;
    automationError.value = '';
    automationNotice.value = '';
    try {
        const saved = await request(`${base.value}/automation`, { method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' },
            body: JSON.stringify(automation.value) });
        if (disposed) return;
        automation.value = { enabled: saved.enabled, trigger_mode: saved.settings.trigger_mode,
            start_time: saved.start_time, frequency_hours: saved.frequency_hours };
        automationNotice.value = 'Prediction automation settings saved.';
        window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: automationNotice.value } }));
    } catch (failure) {
        if (!disposed && failure.name !== 'AbortError') automationError.value = failure.message;
    } finally {
        if (!disposed) automationSaving.value = false;
    }
}

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
const confidence = value => value === null || value === undefined ? '—' : `${number(value)}%`;
const dateTime = value => value ? new Date(/(?:Z|[+-]\d\d:\d\d)$/.test(value) ? value : `${value.replace(' ', 'T')}Z`).toLocaleString() : '—';
const score = revision => revision ? `${number(revision.away_goals)}–${number(revision.home_goals)}` : '—';
const spread = revision => revision?.home_goals == null || revision?.away_goals == null
    ? '—' : number(Math.abs(Number(revision.home_goals) - Number(revision.away_goals)));

function engineTime(seconds) {
    if (seconds === null || seconds === undefined || seconds === '' || !Number.isFinite(Number(seconds)) || Number(seconds) < 0) return '—';
    const total = Math.floor(Number(seconds));
    if (total < 60) return `${total}s`;
    const remainder = total % 60;
    return `${Math.floor(total / 60)}m${remainder ? ` ${remainder}s` : ''}`;
}

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
    if (automationOpen.value) document.body.style.overflow = previousOverflow ?? '';
    clearTimeout(timer);
    for (const controller of controllers) controller.abort();
});
</script>

<template>
    <section aria-label="Game Predictions" class="space-y-4" :inert="automationOpen || undefined">
        <div class="border-y border-gray-200 bg-white px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-900">Run Game Predictions</h3>
                <button ref="automationButton" type="button" aria-label="Configure prediction automation" aria-haspopup="dialog" aria-controls="prediction-automation-panel" :aria-expanded="automationOpen" class="inline-flex size-8 items-center justify-center rounded border border-gray-300 text-gray-600 transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" @click="openAutomation">
                    <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m9.7 3-.5 2a7 7 0 0 0-1.6.9l-2-.6-2.3 4 1.5 1.4a7 7 0 0 0 0 1.8l-1.5 1.4 2.3 4 2-.6a7 7 0 0 0 1.6.9l.5 2h4.6l.5-2a7 7 0 0 0 1.6-.9l2 .6 2.3-4-1.5-1.4a7 7 0 0 0 0-1.8l1.5-1.4-2.3-4-2 .6a7 7 0 0 0-1.6-.9l-.5-2H9.7Z"/><circle cx="12" cy="11.6" r="3"/></svg>
                </button>
            </div>
            <p class="mt-1 text-sm text-gray-600">Use the default pregame engine. Each new calculation is saved; the latest successful prediction is shown below.</p>
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
                    <thead class="bg-gray-50 text-gray-600"><tr><th scope="col" class="p-3">Date / Game</th><th scope="col" class="p-3">Prediction<br /><span class="font-normal">Away–Home</span></th><th scope="col" class="p-3">Spread</th><th scope="col" class="p-3">Total goals</th><th scope="col" class="p-3">Pick</th><th scope="col" class="p-3">Win probability</th><th scope="col" class="p-3">Internal Conf</th><th scope="col" class="p-3">Public Conf</th><th scope="col" class="p-3">Days Away</th><th scope="col" class="p-3">Updated / Status</th><th scope="col" class="whitespace-nowrap p-3">Engine Time</th><th scope="col" class="p-3">History</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template v-for="game in data.games" :key="game.nhl_game_id">
                            <tr><td class="whitespace-nowrap p-3"><p class="text-gray-500">{{ game.date }}</p><b>{{ game.away }} @ {{ game.home }}</b></td><td class="p-3 tabular-nums">{{ score(game.revision) }}</td><td class="p-3 tabular-nums">{{ spread(game.revision) }}</td><td class="p-3 tabular-nums">{{ number(game.revision?.total_goals) }}</td><td class="p-3">{{ game.revision?.winner ?? '—' }}</td><td class="p-3">{{ percent(game.revision?.win_probability) }}</td><td class="p-3">{{ confidence(game.revision?.confidence) }}</td><td class="p-3" data-public-confidence>{{ confidence(game.revision?.public_confidence_score) }}</td><td class="p-3 tabular-nums" data-days-away>{{ game.revision?.days_away ?? '—' }}</td><td class="max-w-xs p-3"><p>{{ dateTime(game.revision?.predicted_at) }}</p><p v-if="game.work" class="mt-1 text-gray-500">{{ game.work.status }}</p><p v-if="game.work?.last_error" class="mt-1 text-red-700">{{ game.work.last_error }}</p></td><td class="whitespace-nowrap p-3 tabular-nums" data-engine-time title="Total elapsed time for this game in the selected run, including queue and preparation waits.">{{ engineTime(game.engine_time_seconds) }}</td><td class="p-3"><button type="button" :aria-expanded="!!histories[game.nhl_game_id]?.open" class="rounded text-indigo-700 hover:underline focus-visible:ring-2 focus-visible:ring-indigo-500" @click="history(game.nhl_game_id, histories[game.nhl_game_id]?.current_page ?? 1)">Revisions</button></td></tr>
                            <tr v-if="histories[game.nhl_game_id]?.open"><td colspan="12" class="bg-gray-50 p-4">
                                <p v-if="histories[game.nhl_game_id].loading" role="status">Loading revisions…</p>
                                <p v-if="histories[game.nhl_game_id].error" role="alert" class="text-red-700">{{ histories[game.nhl_game_id].error }}</p>
                                <div v-for="revision in histories[game.nhl_game_id].revisions" :key="revision.id" class="border-b border-gray-200 py-3">
                                    <div class="flex flex-wrap items-center gap-3"><b>#{{ revision.id }} · {{ score(revision) }}</b><span>Total {{ number(revision.total_goals) }}</span><span>{{ revision.winner ?? 'Unavailable' }} · {{ percent(revision.win_probability) }} win probability</span><span>{{ confidence(revision.confidence) }} internal confidence</span><span>{{ confidence(revision.public_confidence_score) }} public confidence</span><span>{{ revision.days_away ?? '—' }} days away</span><span>{{ dateTime(revision.predicted_at) }}</span><span>{{ revision.source }} · {{ revision.is_canonical ? 'Default inputs' : 'Input override' }}</span><span v-if="revision.reason">{{ revision.reason }}</span><button type="button" :aria-expanded="!!details[revision.id]?.payload" class="text-indigo-700 hover:underline" @click="detail(revision.id)">Saved inputs &amp; result</button></div>
                                    <p v-if="details[revision.id]?.loading" role="status">Loading saved evidence…</p><p v-if="details[revision.id]?.error" role="alert">{{ details[revision.id].error }}</p>
                                    <pre v-if="details[revision.id]?.payload" class="mt-2 max-h-80 overflow-auto rounded border border-gray-200 bg-white p-3 text-[11px]">{{ JSON.stringify(details[revision.id], null, 2) }}</pre>
                                </div>
                                <p v-if="histories[game.nhl_game_id].total === 0">No saved revisions.</p>
                                <div v-if="histories[game.nhl_game_id].last_page > 1" class="mt-2 flex items-center gap-3"><button type="button" :disabled="histories[game.nhl_game_id].current_page <= 1" class="text-indigo-700 disabled:text-gray-400" @click="history(game.nhl_game_id, histories[game.nhl_game_id].current_page - 1)">Newer</button><span>Page {{ histories[game.nhl_game_id].current_page }} / {{ histories[game.nhl_game_id].last_page }}</span><button type="button" :disabled="histories[game.nhl_game_id].current_page >= histories[game.nhl_game_id].last_page" class="text-indigo-700 disabled:text-gray-400" @click="history(game.nhl_game_id, histories[game.nhl_game_id].current_page + 1)">Older</button></div>
                            </td></tr>
                        </template>
                        <tr v-if="!data.games.length"><td colspan="12" class="p-6 text-center text-gray-500">{{ loading ? 'Loading games…' : 'No stored games for these dates.' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="data.last_page > 1" class="flex items-center justify-between text-sm"><button type="button" :disabled="page <= 1 || loading" class="text-indigo-700 disabled:text-gray-400" @click="movePage(page - 1)">Previous</button><span>Page {{ page }} / {{ data.last_page }}</span><button type="button" :disabled="page >= data.last_page || loading" class="text-indigo-700 disabled:text-gray-400" @click="movePage(page + 1)">Next</button></div>
        </div>
    </section>
    <Teleport to="body">
        <Transition enter-active-class="transition-opacity duration-300 ease-out motion-reduce:transition-none" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200 ease-in motion-reduce:transition-none" leave-to-class="opacity-0">
            <div v-if="automationOpen" class="fixed inset-0 z-[90] bg-gray-900/40" aria-hidden="true" @click="closeAutomation" />
        </Transition>
        <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" enter-from-class="translate-x-full" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" leave-to-class="translate-x-full">
            <section v-if="automationOpen" id="prediction-automation-panel" ref="automationPanel" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="prediction-automation-title" :aria-busy="automationLoading || automationSaving" class="fixed inset-y-0 right-0 z-[91] flex w-full max-w-md flex-col bg-white shadow-xl outline-none" @keydown.esc.stop.prevent="closeAutomation" @keydown.tab="trapAutomationFocus">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 p-5">
                    <div><h3 id="prediction-automation-title" class="text-base font-semibold text-gray-900">Prediction automation</h3><p class="mt-1 text-sm text-gray-500">Run today's predictions using the default engine.</p></div>
                    <button type="button" aria-label="Close prediction automation" :disabled="automationSaving" class="rounded p-1 text-gray-500 transition-colors duration-150 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" @click="closeAutomation"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg></button>
                </div>
                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveAutomation">
                    <div class="flex-1 space-y-6 overflow-y-auto p-5">
                        <p v-if="automationLoading" role="status" class="text-sm text-gray-500">Loading automation settings…</p>
                        <fieldset :disabled="!automationLoaded || automationSaving" class="space-y-6 disabled:opacity-60">
                            <label class="flex items-center justify-between gap-4 text-sm font-medium text-gray-900">Automation enabled<input v-model="automation.enabled" type="checkbox" role="switch" aria-label="Automation enabled" class="size-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /></label>
                            <div><label for="prediction-trigger-mode" class="block text-sm font-medium text-gray-700">Trigger</label><select id="prediction-trigger-mode" v-model="automation.trigger_mode" class="mt-2 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="time">Time-based</option><option value="event">Pregame context completed</option></select></div>
                            <div v-if="automation.trigger_mode === 'time'" class="space-y-4">
                                <label class="block text-sm font-medium text-gray-700">Start time · Eastern<input v-model="automation.start_time" type="time" required class="mt-2 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" /></label>
                                <label class="block text-sm font-medium text-gray-700">Frequency<input v-model.number="automation.frequency_hours" type="number" min="1" max="168" step="1" required class="mt-2 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" /><span class="mt-1 block text-xs font-normal text-gray-500">Hours between runs. 24 means once per day.</span></label>
                            </div>
                            <p v-else class="text-sm text-gray-600">Run when today's pregame context, league comparisons and rankings are complete for the engine's selected model.</p>
                        </fieldset>
                        <p class="text-xs text-gray-500">Automatic runs include today's upcoming games. Existing prediction history is preserved.</p>
                        <p v-if="automationError" role="alert" class="text-sm text-red-700">{{ automationError }}</p>
                        <button v-if="automationError && !automationLoaded" type="button" class="text-sm text-indigo-700 hover:underline focus-visible:ring-2 focus-visible:ring-indigo-500" @click="openAutomation">Retry</button>
                        <p v-if="automationNotice" role="status" class="text-sm text-emerald-700">{{ automationNotice }}</p>
                    </div>
                    <div class="border-t border-gray-200 p-5"><button type="submit" :disabled="!automationLoaded || automationSaving" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-50 motion-reduce:transition-none">{{ automationSaving ? 'Saving…' : 'Save settings' }}</button></div>
                </form>
            </section>
        </Transition>
    </Teleport>
</template>
