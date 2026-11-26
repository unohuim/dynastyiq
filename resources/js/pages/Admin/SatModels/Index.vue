<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription } from 'reka-ui';

const props = defineProps({ models: { type: Array, default: () => [] }, pagination: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) }, seasonOptions: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] }, pregame: { type: Object, default: null } });
const base = '/admin/nhl-sat-models';
const notify = inject('notify', () => {});
const tab = ref(new URLSearchParams(window.location.search).get('tab') === 'pregame' ? 'pregame' : 'models');
const search = ref('');
const sort = ref('updated_at');
const descending = ref(true);
const streamSort = ref('name');
const streamDescending = ref(false);
const expanded = ref(null);
const panel = ref(null);
const panelModel = ref(null);
watch(() => props.models, rows => {
    if (panel.value !== 'actions' || !panelModel.value) return;
    const current = rows.find(model => model.id === panelModel.value.id);
    if (current) panelModel.value = current;
});
const busy = ref(false);
const loading = ref(false);
const failure = ref('');
const errors = ref({});
const inspectResult = ref(null);
const metricSort = ref('metric_key');
const metricDescending = ref(false);
const metricRows = computed(() => [...(inspectResult.value?.metrics ?? [])].sort((a, b) =>
    String(metricSort.value === 'context' ? contextLabel(a) : a[metricSort.value] ?? '').localeCompare(String(metricSort.value === 'context' ? contextLabel(b) : b[metricSort.value] ?? ''), undefined, { numeric: true }) * (metricDescending.value ? -1 : 1)));
function contextLabel(metric) {
    const id = metric.team_context_id ?? metric.player_context_id;
    const context = inspectResult.value?.contexts.find(item => item.id === id);
    return context?.nhl_player_id ? `Player ${context.nhl_player_id} · team ${context.nhl_team_id}` : `Team ${context?.nhl_team_id ?? '—'}`;
}
function orderMetric(key) { metricDescending.value = metricSort.value === key ? !metricDescending.value : false; metricSort.value = key; }
const confirmation = ref(null);
const createForm = reactive({ name: '', model_version: 'sat_v2', train_season_ids: [], test_season_id: '', projection_season_id: '', notes: '' });
const settings = reactive({ frequency_hours: 24, start_time: '06:00', include_update_pregame: true });
const limits = reactive({ league: 4, context: 2 });
const inspector = reactive({ game_id: '', entity: 'team' });
const number = value => value == null ? '—' : Number(value).toLocaleString();
const time = value => !value || !Number.isFinite(Date.parse(value)) ? '—' : new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const label = value => String(value ?? 'unavailable').replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
const healthLabels = { recorded: 'Coverage recorded', attention: 'Needs attention', incomplete: 'Incomplete', unavailable: 'Source unavailable' };
const tone = state => ['failed', 'attention', 'expired'].includes(state) ? 'text-red-300 bg-red-400/10'
    : ['running', 'queued', 'claimed', 'updating'].includes(state) ? 'text-sky-300 bg-sky-400/10'
        : ['paused', 'pausing', 'stopped', 'incomplete', 'unavailable', 'waiting'].includes(state) ? 'text-amber-300 bg-amber-400/10' : 'text-emerald-300 bg-emerald-400/10';
const modelValue = (model, key) => key === 'train' ? model.train.join(', ') : key === 'training' ? model.training?.excluded_rate ?? -1 : model[key] ?? '';
const orderedModels = computed(() => props.models.filter(model => [model.name, model.version, model.train.join(' ')].join(' ').toLowerCase().includes(search.value.toLowerCase()))
    .sort((a, b) => String(modelValue(a, sort.value)).localeCompare(String(modelValue(b, sort.value)), undefined, { numeric: true }) * (descending.value ? -1 : 1)));
const streams = computed(() => [...(props.pregame?.workstreams ?? [])].sort((a, b) => String(a[streamSort.value] ?? '').localeCompare(String(b[streamSort.value] ?? ''), undefined, { numeric: true }) * (streamDescending.value ? -1 : 1)));
const pilotModel = computed(() => props.models.find(model => model.id === props.pregame?.source?.id)
    ?? (props.pregame?.source ? { ...props.pregame.source, automation: props.pregame.automation } : null));
const pilot = computed(() => props.pregame?.source);
const run = computed(() => props.pregame?.run);
const active = computed(() => props.models.some(model => model.status === 'running' || ['queued', 'running', 'pausing'].includes(model.pregame?.status)) || ['queued', 'running', 'pausing'].includes(run.value?.status));
const canUpdate = computed(() => pilot.value && !['queued', 'running', 'pausing', 'paused'].includes(run.value?.status));
const definitions = [
    ['Player context', 'Prior player results across last game, L5, L10, L20, last season, season-to-date and model Train. Strength and measure remain explicit.'],
    ['Team context', 'Each team’s own history, separate opponent history and target-game orientation.'],
    ['League comparisons', 'League average, population, rank and signed/absolute/percentage differences for the same metric, window and strength.'],
    ['Freshness', 'The statistical source cutoff, not the time a historical snapshot was rebuilt. Test coverage is not readiness for today’s production games.'],
    ['Coverage', 'The season counts below verify both team snapshots. They do not certify every player, goalie, source or metric.'],
    ['Claim limits', 'Maximum simultaneous league/context date claims. These controls neither provision nor stop Horizon worker processes.'],
];
let disposed = false;
let timer;
let refreshing = false;
const controller = new AbortController();
const requestOptions = { headers: { 'X-DIQ-SAT-Workspace': '1' }, signal: controller.signal };
function changeTab(value) {
    tab.value = value;
    const url = new URL(window.location.href); url.searchParams.set('tab', value); window.history.replaceState({}, '', url);
}
function order(key) { descending.value = sort.value === key ? !descending.value : false; sort.value = key; }
function orderStream(key) { streamDescending.value = streamSort.value === key ? !streamDescending.value : false; streamSort.value = key; }
function filterStatus(event) { router.get(base, { status: event.target.value, tab: tab.value }, { preserveState: true, preserveScroll: true }); }
function schedule() { if (!disposed) { clearTimeout(timer); timer = setTimeout(poll, active.value ? 5000 : 30000); } }
function refresh() {
    if (disposed || refreshing) return;
    refreshing = true;
    router.reload({ only: ['models', 'pregame', 'pagination'], preserveState: true, preserveScroll: true, showProgress: false,
        onFinish: () => { refreshing = false; schedule(); }, onError: () => {} });
}
function poll() { if (document.hidden) { schedule(); return; } refresh(); }
onMounted(schedule);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); controller.abort(); });
function closePanel() { if (busy.value) return; panel.value = null; panelModel.value = null; failure.value = ''; errors.value = {}; }
function openPanel(value, model = null) { panel.value = value; panelModel.value = model; failure.value = ''; errors.value = {}; confirmation.value = null; }
async function mutate(method, url, data = {}, after = null) {
    if (busy.value) return;
    busy.value = true; failure.value = ''; errors.value = {};
    try {
        const response = await axios[method](url, data, requestOptions);
        if (disposed) return;
        notify({ type: 'success', message: response.data.message ?? 'Saved.' });
        if (after) after(response.data);
        refresh();
    } catch (error) {
        if (disposed) return;
        errors.value = error.response?.data?.errors ?? {};
        failure.value = Object.values(errors.value).flat()[0] ?? error.response?.data?.message ?? 'The request could not be saved. Please try again.';
        notify({ type: 'error', message: failure.value });
    } finally { busy.value = false; }
}
function actionHref(action) { return action.url + (Object.keys(action.data ?? {}).length ? `?${new URLSearchParams(action.data)}` : ''); }
function modelAction(action) {
    if (!action.enabled || busy.value) return;
    if (action.link) { window.location.assign(actionHref(action)); return; }
    if (['Build Pregame', 'Eval SAT', 'Eval SOG', 'Build Predictions'].includes(action.label) && confirmation.value !== action.label) { confirmation.value = action.label; return; }
    confirmation.value = null; mutate('post', action.url, action.data);
}
function pilotAction(action) {
    if (!pilot.value) return;
    if (['build', 'stop'].includes(action) && confirmation.value !== action) { openPanel('repair'); confirmation.value = action; return; }
    confirmation.value = null;
    mutate('post', `${base}/${pilot.value.id}/context/${action}`);
}
async function openSettings(model) {
    if (!model || loading.value) return;
    openPanel('automation', model); loading.value = true;
    const identity = model.id;
    try {
        const { data } = await axios.get(model.automation?.settings_url ?? `${base}/${identity}/context/auto-sync`, requestOptions);
        if (!disposed && panel.value === 'automation' && panelModel.value?.id === identity) Object.assign(settings, { frequency_hours: data.frequency_hours, start_time: data.start_time, include_update_pregame: data.include_update_pregame });
    } catch { if (!disposed) failure.value = 'Unable to load automation settings. Close and reopen to retry.'; }
    finally { loading.value = false; }
}
function toggleAutomation(model) { mutate('patch', model.automation.toggle_url, { enabled: !model.automation.enabled }, data => {
    if (panelModel.value?.id === model.id) panelModel.value = { ...panelModel.value, automation: { ...model.automation, enabled: data.enabled } };
}); }
function workerPanel() { Object.assign(limits, props.pregame?.limits ?? {}); openPanel('workers'); }
async function inspect() {
    if (busy.value || !props.pregame?.inspector_url) return;
    busy.value = true; failure.value = ''; inspectResult.value = null;
    try {
        const { data } = await axios.get(props.pregame.inspector_url, { ...requestOptions, params: inspector });
        if (!disposed) inspectResult.value = data;
    } catch (error) { if (!disposed) failure.value = error.response?.data?.message ?? 'Unable to inspect saved context.'; }
    finally { busy.value = false; }
}
function saveModel() { mutate('post', base, { ...createForm, test_season_id: createForm.test_season_id || null, projection_season_id: createForm.projection_season_id || null }, () => { panel.value = null; }); }
const panelTitle = computed(() => ({ create: 'Create Model', actions: panelModel.value?.name ?? 'Model actions', automation: 'Automatic Pregame Updates', workers: 'Worker allocation', repair: 'Targeted repair', inspector: 'Context inspector', definitions: 'Metric definitions', history: 'Maintenance history' }[panel.value] ?? 'Pregame'));
</script>

<template>
    <Head title="SAT Models HQ" />
    <main data-page="sat-models-workspace" class="min-h-screen bg-[#10151b] pb-8 text-slate-100">
        <div class="mx-auto max-w-screen-2xl space-y-4 p-3 sm:p-5">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-4">
                <div><p class="text-[10px] font-semibold uppercase tracking-widest text-sky-400">SAT Models HQ</p><h1 class="mt-1 text-xl font-semibold sm:text-2xl">Models &amp; Pregame</h1><p class="mt-1 text-xs text-slate-400">Model research and pregame health, in one workspace.</p></div>
                <div class="flex gap-5 text-right"><div><p class="text-xl font-semibold">{{ models.length }}</p><p class="text-[10px] text-slate-400">Models on this page</p></div><div><p class="text-xl font-semibold text-sky-300">{{ models.filter(model => model.status === 'running').length }}</p><p class="text-[10px] text-slate-400">Building models</p></div></div>
            </header>
            <nav aria-label="SAT workspace" class="flex items-center gap-1 overflow-x-auto rounded-lg border border-slate-800 bg-[#171d23] p-1 text-xs">
                <button v-for="item in ['models', 'pregame']" :key="item" type="button" :aria-current="tab === item ? 'page' : undefined" class="min-h-10 rounded-md border-b-2 px-5 transition-colors duration-150 motion-reduce:transition-none" :class="tab === item ? 'border-sky-400 bg-sky-400/10 text-sky-200' : 'border-transparent text-slate-400 hover:text-white'" @click="changeTab(item)">{{ label(item) }}</button>
                <a :href="`${base}/next-game`" class="px-4 py-3 text-slate-400 hover:text-white">Evaluations</a><a href="/nhl-sat-engines" class="px-4 py-3 text-slate-400 hover:text-white">Engines &amp; stacks</a>
                <a href="/admin/nhl-shot-attempts" class="ml-auto shrink-0 px-3 py-3 text-slate-400 hover:text-white">Shot Attempts ↗</a>
            </nav>

            <section v-show="tab === 'models'" aria-label="SAT models">
                <div class="mb-3 flex flex-wrap items-center gap-2"><h2 class="mr-auto text-sm font-semibold">Models</h2><input v-model="search" type="search" aria-label="Search models" placeholder="Search models" class="w-36 rounded-lg border-slate-700 bg-[#171d23] text-xs sm:w-52" /><select aria-label="Model status" :value="filters.status ?? ''" class="rounded-lg border-slate-700 bg-[#171d23] text-xs" @change="filterStatus"><option value="">All statuses</option><option v-for="state in statuses" :key="state" :value="state">{{ label(state) }}</option></select><button type="button" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-medium hover:bg-sky-500" @click="openPanel('create')">Create Model</button></div>
                <div v-if="!models.length" class="rounded-xl border border-slate-800 bg-[#171d23] p-10 text-center"><h3 class="font-medium">No SAT models {{ filters.status ? 'match this status' : 'yet' }}</h3><p class="mt-2 text-xs text-slate-400">Create a model to define its Train and Test seasons.</p><button type="button" class="mt-4 text-sm text-sky-300" @click="openPanel('create')">Create Model</button></div>
                <div v-else class="rounded-xl border border-slate-800 bg-[#171d23]">
                    <div class="hidden grid-cols-[1.3fr_.7fr_1.3fr_1fr_.7fr_1.2fr_.8fr_100px] gap-3 border-b border-slate-700 px-4 py-3 text-[10px] uppercase tracking-wide text-slate-400 xl:grid"><button v-for="[key, title] in [['name','Name'],['version','Version'],['train','Training seasons'],['test','Test / Projection'],['training','Excluded'],['status','Status'],['updated_at','Updated']]" :key="key" type="button" class="text-left" @click="order(key)">{{ title }} {{ sort === key ? descending ? '↓' : '↑' : '' }}</button><span class="text-right">Actions</span></div>
                    <article v-for="model in orderedModels" :key="model.id" :data-model-id="model.id" class="grid min-w-0 grid-cols-2 items-start gap-3 border-b border-slate-800 p-4 last:border-0 xl:grid-cols-[1.3fr_.7fr_1.3fr_1fr_.7fr_1.2fr_.8fr_100px]">
                        <div class="col-span-2 xl:col-span-1"><h3 class="text-sm font-semibold">{{ model.name }}</h3><p v-if="model.notes" class="mt-1 truncate text-[11px] text-slate-400">{{ model.notes }}</p><p v-if="model.outdated?.length" class="mt-1 text-[11px] text-amber-300">Profiles outdated · {{ model.outdated.join(', ') }}</p></div>
                        <p class="text-xs text-slate-400">{{ model.version }}</p><p class="text-xs text-slate-300">{{ model.train.join(', ') }}</p><div class="text-xs"><p>{{ model.test ?? 'No Test season' }}</p><p class="mt-1 text-[10px] text-slate-500">Projection: {{ model.projection }}</p></div>
                        <div class="text-xs"><p>{{ model.training?.excluded_rate == null ? '—' : `${(100 * model.training.excluded_rate).toFixed(1)}%` }}</p><p class="mt-1 text-[10px] text-slate-500">{{ number(model.training?.excluded) }} of {{ number(model.training?.total) }}</p></div>
                        <div class="text-xs"><span class="rounded-full px-2 py-1 text-[10px]" :class="tone(model.status)">{{ label(model.status) }}</span><p v-if="model.build?.status" class="mt-2 text-[11px] text-slate-400">{{ model.build.kind === 'profiles' ? 'Profiles' : 'Build Predictions' }}{{ model.build.status === 'failed' ? ' failed' : '' }} · {{ model.build.stage === 'rates' ? '/60' : label(model.build.stage) }} · {{ model.build.completed }}/{{ model.build.total }}<span v-if="model.build.discovering"> · Discovering entities</span></p><p v-if="model.build?.error" class="mt-1 text-[11px] text-red-300">{{ model.build.error }}</p><p v-if="model.pregame" class="mt-2 text-[11px] text-slate-400">Pregame {{ model.pregame.status }} · {{ model.pregame.completed }}/{{ model.pregame.total }}</p></div>
                        <p class="text-[10px] text-slate-500">{{ time(model.updated_at) }}</p>
                        <div class="col-span-2 flex items-center justify-end gap-1 xl:col-span-1"><button type="button" :aria-label="`Toggle automatic pregame updates for ${model.name}`" :aria-pressed="model.automation.enabled" :disabled="busy" class="rounded-md p-2 text-lg disabled:opacity-40" :class="model.automation.enabled ? 'text-emerald-400' : 'text-slate-400'" @click="toggleAutomation(model)">↻</button><button type="button" :aria-label="`Actions for ${model.name}`" class="rounded-md px-2 py-1 text-lg text-slate-300 hover:bg-slate-800" @click="openPanel('actions', model)">⋮</button><button type="button" :aria-label="`Automation settings for ${model.name}`" class="rounded-md p-2 text-slate-400 hover:bg-slate-800" @click="openSettings(model)">⚙</button></div>
                    </article>
                    <p v-if="!orderedModels.length" class="p-6 text-center text-xs text-slate-400">No models match your search.</p>
                </div>
                <nav aria-label="Model pages" class="mt-3 flex flex-wrap justify-end gap-2"><Link v-for="(page, index) in pagination" :key="index" :href="page.url ?? '#'" :aria-disabled="!page.url" :aria-current="page.active ? 'page' : undefined" class="rounded-md px-3 py-2 text-xs" :class="[page.active ? 'bg-sky-600 text-white' : 'bg-[#171d23] text-slate-400', !page.url ? 'pointer-events-none opacity-40' : '']" preserve-scroll><span v-html="page.label" /></Link></nav>
            </section>

            <section v-show="tab === 'pregame'" aria-label="Pregame health">
                <div class="mb-3 flex items-center justify-between gap-3"><div><h2 class="text-base font-semibold">Pregame health</h2><p class="mt-1 text-xs text-slate-400">{{ pilot ? `Shared workspace pilot · ${pilot.name} · Test ${pilot.test}` : 'Shared workspace pilot' }}</p></div><span class="text-[10px] text-slate-500">Updates automatically</span></div>
                <div v-if="!pregame" role="status" class="rounded-xl border border-slate-800 bg-[#171d23] p-8 text-sm text-slate-400"><span class="mr-2 inline-block size-3 animate-pulse rounded-full bg-sky-400 motion-reduce:animate-none" />Loading pregame health…</div>
                <template v-else>
                    <div class="mb-4 grid gap-3 rounded-xl border border-slate-700 bg-[#171d23] p-4 sm:grid-cols-[1.4fr_1fr_1fr_1fr]">
                        <div><span class="rounded-full px-3 py-1 text-xs font-medium" :class="tone(pregame.health)">{{ healthLabels[pregame.health] }}</span><p class="mt-3 text-[11px] text-slate-400">Historical evidence stays in Sep2026. Production context remains independent.</p></div>
                        <div><p class="text-[10px] uppercase tracking-wide text-slate-500">Statistical cutoff</p><p class="mt-2 text-sm">{{ time(pregame.source_cutoff_at) }}</p><p class="mt-1 text-[10px] text-slate-500">Not the rebuild timestamp</p></div>
                        <div><p class="text-[10px] uppercase tracking-wide text-slate-500">Claimed jobs</p><p class="mt-2 text-xl font-semibold text-sky-300">{{ pregame.workers.length }}</p><p class="text-[10px] text-slate-500">Queued or executing · not OS worker count</p></div>
                        <div><p class="text-[10px] uppercase tracking-wide text-slate-500">Needs attention</p><p class="mt-2 text-xl font-semibold" :class="pregame.issues.length ? 'text-amber-300' : 'text-emerald-300'">{{ pregame.issues.length }}</p><p class="text-[10px] text-slate-500">{{ run ? `Maintenance ${run.status}` : 'No maintenance run' }}</p></div>
                    </div>
                    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
                        <div class="min-w-0 space-y-4">
                            <section class="overflow-hidden rounded-xl border border-slate-800 bg-[#171d23]"><div class="flex items-center justify-between border-b border-slate-800 px-4 py-3"><h3 class="text-sm font-semibold">Workstreams</h3><button type="button" :disabled="!pilot" class="text-xs text-sky-300 disabled:opacity-40" @click="workerPanel">Manage allocation →</button></div>
                                <div class="hidden grid-cols-[1.4fr_.7fr_.7fr_.7fr_.7fr] gap-3 px-4 py-2 text-[10px] uppercase text-slate-500 lg:grid"><button v-for="[key, title] in [['name','Workstream'],['stage','Processing pool'],['state','Activity'],['completed_days','Latest run days'],['active_claims','Claims']]" :key="key" type="button" class="text-left" @click="orderStream(key)">{{ title }} {{ streamSort === key ? streamDescending ? '↓' : '↑' : '' }}</button></div>
                                <div v-for="stream in streams" :key="stream.key" class="border-t border-slate-800 first:border-0"><button type="button" :aria-label="`Expand ${stream.name}`" :aria-expanded="expanded === stream.key" :aria-controls="`workstream-${stream.key}`" class="grid w-full grid-cols-[1.4fr_1fr] items-center gap-3 px-4 py-3 text-left hover:bg-slate-800/50 lg:grid-cols-[1.4fr_.7fr_.7fr_.7fr_.7fr]" @click="expanded = expanded === stream.key ? null : stream.key"><span class="flex items-center gap-2"><svg aria-hidden="true" viewBox="0 0 20 20" class="size-3 shrink-0 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="expanded === stream.key ? 'rotate-180' : ''" fill="currentColor"><path d="m5 7 5 5 5-5z" /></svg><span><span class="block text-xs font-medium">{{ stream.name }}</span><span class="mt-1 block text-[10px] text-slate-500">{{ stream.description }}</span></span></span><span class="text-[11px] text-slate-400">{{ label(stream.stage) }}</span><span class="w-fit rounded-full px-2 py-1 text-[10px]" :class="tone(stream.state)">{{ label(stream.state) }}</span><span class="text-[11px] text-slate-400">{{ number(stream.completed_days) }} / {{ number(stream.total_days) }}</span><span class="text-xs text-sky-300">{{ stream.active_claims }} shared</span></button>
                                    <div :id="`workstream-${stream.key}`" :aria-hidden="expanded !== stream.key" :inert="expanded !== stream.key" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded === stream.key ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"><div class="min-h-0 overflow-hidden"><div class="space-y-3 border-t border-slate-800 bg-[#10151b]/40 p-4 text-xs text-slate-400"><p>{{ stream.note }}</p><div class="flex flex-wrap gap-5"><p>Saved snapshots: <strong class="text-slate-200">{{ number(stream.snapshot_count) }}</strong></p><p>Last maintenance activity: <strong class="text-slate-200">{{ time(pregame.last_activity) }}</strong></p><p>Speed / ETA: —</p></div><p v-if="run">Latest run: {{ number(run.completed) }} / {{ number(run.total) }} games · {{ run.excluded }} excluded · {{ run.failed }} failed</p><progress v-if="stream.total_days" :value="stream.completed_days ?? 0" :max="stream.total_days" class="h-1.5 w-full accent-sky-500" /><p v-if="run?.error" class="text-red-300">{{ run.error }}</p><button type="button" class="text-sky-300" @click="openPanel('inspector')">Inspect saved metrics →</button></div></div></div>
                                </div>
                                <p v-if="!streams.length" class="p-6 text-xs text-slate-400">No pilot workstreams are available.</p>
                            </section>
                            <div class="grid gap-4 lg:grid-cols-2">
                                <section class="rounded-xl border border-slate-800 bg-[#171d23] p-4"><h3 class="text-sm font-semibold">Job activity</h3><p class="mt-1 text-[10px] text-slate-500">Live database claims · {{ time(pregame.last_activity) }}</p><p v-if="!pregame.workers.length" class="mt-5 text-xs text-slate-400">No date jobs are claimed. Saved data can remain usable while processing is idle.</p><div v-for="worker in pregame.workers" :key="worker.key" class="mt-3 flex justify-between gap-3 border-t border-slate-800 pt-3 text-xs"><div><p>{{ label(worker.stage) }} · {{ worker.date }}</p><p class="mt-1 text-[10px] text-slate-500">Last activity {{ time(worker.last_activity) }}</p></div><span class="h-fit rounded-full px-2 py-1 text-[10px]" :class="tone(worker.state)">{{ label(worker.state) }}</span></div></section>
                                <section class="rounded-xl border border-slate-800 bg-[#171d23] p-4"><h3 class="text-sm font-semibold">Season coverage</h3><p class="mt-1 text-[10px] text-slate-500">Completed games with both team snapshots · checked {{ time(pregame.counts_checked_at) }}</p><div v-for="season in pregame.coverage" :key="season.season" class="mt-4"><div class="mb-1 flex justify-between text-xs"><span>{{ season.season }}</span><span class="text-slate-400">{{ number(season.covered) }} / {{ number(season.games) }}</span></div><progress :value="season.covered" :max="season.games || 1" class="h-2 w-full accent-sky-500" /></div><p class="mt-4 text-[10px] text-slate-500">Counts do not certify every player or metric. No data means unavailable, not zero performance.</p></section>
                            </div>
                        </div>
                        <aside class="space-y-4">
                            <section class="space-y-2"><button type="button" :disabled="busy || !canUpdate" class="w-full rounded-lg bg-sky-600 px-4 py-2.5 text-xs font-semibold hover:bg-sky-500 disabled:opacity-40" @click="pilotAction('update')">{{ busy ? 'Submitting…' : 'Update Missing Data' }}</button><div class="flex gap-2"><button type="button" :disabled="busy || !['queued','running'].includes(run?.status)" class="flex-1 rounded-lg border border-slate-700 p-2 text-xs disabled:opacity-30" @click="pilotAction('pause')">Pause</button><button type="button" :disabled="busy || !(['paused','pausing'].includes(run?.status) || run?.retryable)" class="flex-1 rounded-lg border border-slate-700 p-2 text-xs disabled:opacity-30" @click="pilotAction('resume')">Resume</button><button type="button" :disabled="busy || !['queued','running','pausing','paused'].includes(run?.status)" class="flex-1 rounded-lg border border-red-500/30 p-2 text-xs text-red-300 disabled:opacity-30" @click="pilotAction('stop')">Stop</button></div><button type="button" :disabled="!pilot" class="w-full rounded-lg border border-slate-700 p-2 text-xs disabled:opacity-40" @click="openPanel('repair')">Targeted repair</button></section>
                            <section class="rounded-xl border border-slate-800 bg-[#171d23] p-4"><h3 class="text-sm font-semibold">Needs attention <span class="text-slate-500">({{ pregame.issues.length }})</span></h3><p v-if="!pregame.issues.length" class="mt-3 text-xs text-slate-400">No tracked maintenance errors or team-coverage gaps. Inspect metrics to verify individual quality.</p><article v-for="issue in pregame.issues" :key="issue.title" class="mt-3 border-t border-slate-800 pt-3"><h4 class="text-xs text-amber-300">{{ issue.title }}</h4><p class="mt-1 text-[11px] leading-relaxed text-slate-400">{{ issue.detail }}</p></article></section>
                            <section class="rounded-xl border border-slate-800 bg-[#171d23] p-4"><div class="flex items-center justify-between"><h3 class="text-sm font-semibold">Automation</h3><button type="button" :disabled="!pilot" class="text-xs text-sky-300 disabled:opacity-30" @click="openSettings(pilotModel ?? { id: pilot.id })">Settings ⚙</button></div><p class="mt-2 text-[11px] text-slate-400">Sep2026 only. View timing and enablement in settings.</p><button type="button" :disabled="!pilot" class="mt-4 text-xs text-sky-300 disabled:opacity-30" @click="workerPanel">Worker allocation →</button></section>
                            <section class="rounded-xl border border-slate-800 bg-[#171d23] p-2"><h3 class="px-2 py-2 text-xs font-semibold">Supporting tools</h3><button v-for="[key,title] in [['definitions','Metric definitions'],['inspector','Context inspector'],['history','Maintenance history']]" :key="key" type="button" class="flex w-full justify-between rounded-md px-2 py-3 text-left text-xs text-slate-300 hover:bg-slate-800" @click="openPanel(key)">{{ title }} <span>→</span></button></section>
                        </aside>
                    </div>
                </template>
            </section>
        </div>

        <DialogRoot :open="panel !== null" @update:open="value => { if (!value) closePanel(); }"><DialogPortal>
            <Transition enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay class="fixed inset-0 z-[60] bg-black/70 motion-reduce:transition-none" /></Transition>
            <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" enter-from-class="translate-x-full" leave-to-class="translate-x-full"><DialogContent class="fixed inset-y-0 right-0 z-[61] flex w-full max-w-xl flex-col border-l border-slate-700 bg-[#171d23] text-slate-100 shadow-xl focus:outline-none" @escape-key-down="event => { if (busy) event.preventDefault(); }" @interact-outside="event => { if (busy) event.preventDefault(); }">
                <header class="flex items-start justify-between gap-4 border-b border-slate-800 p-5"><div><DialogTitle class="text-lg font-semibold">{{ panelTitle }}</DialogTitle><DialogDescription class="mt-1 text-xs text-slate-400">{{ panelModel ? panelModel.name : pilot ? `${pilot.name} · Historical Pregame pilot` : 'SAT Models HQ' }}</DialogDescription></div><button type="button" :disabled="busy" aria-label="Close panel" class="rounded-md px-2 py-1 text-slate-400 disabled:opacity-40" @click="closePanel">✕</button></header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 pb-24 sm:pb-5"><p v-if="failure" role="alert" class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-200">{{ failure }}</p>
                    <form v-if="panel === 'create'" class="space-y-4" @submit.prevent="saveModel"><label class="block text-xs">Name<input v-model="createForm.name" required maxlength="160" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Version<input v-model="createForm.model_version" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><fieldset><legend class="mb-2 text-xs">Training seasons</legend><label v-for="season in seasonOptions" :key="season" class="mr-4 inline-flex items-center gap-2 py-2 text-xs"><input v-model="createForm.train_season_ids" type="checkbox" :value="season" class="rounded border-slate-700 bg-[#10151b] text-sky-500" />{{ season }}</label></fieldset><label v-for="[key,title] in [['test_season_id','Test season'],['projection_season_id','Projection season']]" :key="key" class="block text-xs">{{ title }}<input v-model="createForm[key]" pattern="[0-9]{8}" placeholder="20252026" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Notes<textarea v-model="createForm.notes" rows="3" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><button type="submit" :disabled="busy" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">{{ busy ? 'Saving…' : 'Create Model' }}</button></form>
                    <template v-if="panel === 'actions'"><p v-if="confirmation" class="rounded-lg border border-amber-500/30 p-3 text-xs text-amber-200">{{ confirmation }} will recalculate this model’s selected scope. Press the same action again to confirm.</p><section v-for="group in [...new Set(panelModel.actions.map(action => action.group))]" :key="group"><h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ group }}</h3><div class="divide-y divide-slate-800"><template v-for="action in panelModel.actions.filter(item => item.group === group)" :key="action.label"><a v-if="action.link && action.enabled" :href="actionHref(action)" class="block rounded-md px-3 py-2.5 text-xs text-slate-200 hover:bg-slate-800">{{ action.label }} ↗</a><button v-else type="button" :disabled="busy || !action.enabled" :title="action.reason || (!action.enabled ? 'Required inputs or an idle build are needed.' : '')" class="block w-full rounded-md px-3 py-2.5 text-left text-xs text-slate-200 hover:bg-slate-800 disabled:opacity-30" @click="modelAction(action)">{{ action.label }}</button></template></div></section></template>
                    <template v-if="panel === 'automation'"><p v-if="loading" role="status" class="text-sm text-slate-400">Loading settings…</p><form v-else-if="!failure" class="space-y-5" @submit.prevent="mutate('put', panelModel.automation?.settings_url ?? `${base}/${panelModel.id}/context/auto-sync`, { ...settings })"><label class="flex items-center justify-between text-sm">Include Update Pregame<input v-model="settings.include_update_pregame" type="checkbox" class="rounded border-slate-700 bg-[#10151b] text-sky-500" /></label><label class="block text-xs">Frequency (hours)<input v-model.number="settings.frequency_hours" type="number" min="1" max="168" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Start time · America/Toronto<input v-model="settings.start_time" type="time" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><p class="text-xs text-slate-400">Update fills missing Test context, or prediction-season context when there is no Test season. Train remains an input.</p><button type="submit" :disabled="busy" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">Save settings</button><button v-if="panelModel.automation" type="button" :disabled="busy" class="ml-3 text-xs text-sky-300" @click="toggleAutomation(panelModel)">{{ panelModel.automation.enabled ? 'Disable automation' : 'Enable automation' }}</button></form></template>
                    <form v-if="panel === 'workers'" class="space-y-5" @submit.prevent="mutate('put', pregame.workers_url, { ...limits })"><p class="text-xs leading-relaxed text-slate-400">Sep2026 only. Set simultaneous date-job limits for the two real processing pools. Player, team, goalie, ranking and travel work share the context pool. This does not add Horizon workers.</p><label v-for="key in ['league','context']" :key="key" class="block text-sm">{{ label(key) }} claims<input v-model.number="limits[key]" type="number" min="1" max="25" required class="mt-2 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><p class="text-xs text-slate-500">Maximum combined limit: 25. A lower limit applies as already-claimed jobs finish.</p><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">Save allocation</button></form>
                    <section v-if="panel === 'repair'" class="space-y-4 text-sm"><p class="text-xs leading-relaxed text-slate-400">Use the existing Sep2026 maintenance pipeline. Updating fills missing Test games; retry continues unfinished work. A full rebuild recalculates Sep2026 Train and Test context.</p><p v-if="confirmation" class="rounded-lg border border-amber-500/30 p-3 text-xs text-amber-200">{{ confirmation === 'stop' ? 'Stop unfinished work while preserving committed context?' : 'Recalculate all Sep2026 Train and Test context?' }}</p><button type="button" :disabled="busy || !canUpdate" class="block w-full rounded-lg bg-sky-600 p-3 text-sm disabled:opacity-40" @click="pilotAction('update')">Update missing Test context</button><button type="button" :disabled="busy || !run?.retryable" class="block w-full rounded-lg border border-slate-700 p-3 text-sm disabled:opacity-40" @click="pilotAction('resume')">Retry unfinished</button><button v-if="confirmation === 'stop'" type="button" :disabled="busy" class="block w-full rounded-lg border border-red-600/50 p-3 text-sm text-red-300" @click="pilotAction('stop')">Confirm stop</button><button v-else type="button" :disabled="busy || !canUpdate" class="block w-full rounded-lg border border-amber-600/40 p-3 text-sm text-amber-200 disabled:opacity-40" @click="pilotAction('build')">{{ confirmation === 'build' ? 'Confirm full rebuild' : 'Full Train + Test rebuild' }}</button></section>
                    <section v-if="panel === 'definitions'" class="space-y-5"><article v-for="[name,description] in definitions" :key="name"><h3 class="text-sm font-medium">{{ name }}</h3><p class="mt-2 text-xs leading-relaxed text-slate-400">{{ description }}</p></article></section>
                    <section v-if="panel === 'history'" class="space-y-3"><p v-if="!pregame?.history?.length" class="text-sm text-slate-400">No Sep2026 maintenance runs yet.</p><article v-for="item in pregame?.history ?? []" :key="item.id" class="rounded-lg border border-slate-700 p-3 text-xs"><div class="flex justify-between"><strong>Run #{{ item.id }}</strong><span :class="tone(item.status)" class="rounded-full px-2">{{ label(item.status) }}</span></div><p class="mt-2 text-slate-400">{{ item.completed }} / {{ item.total }} games · {{ item.excluded }} excluded · {{ item.failed }} failed</p><p class="mt-1 text-[10px] text-slate-500">{{ time(item.updated_at) }}</p><p v-if="item.error" class="mt-2 text-red-300">{{ item.error }}</p></article></section>
                    <section v-if="panel === 'inspector'" class="space-y-4"><form class="flex gap-2" @submit.prevent="inspect"><input v-model="inspector.game_id" type="number" min="1" required aria-label="NHL game ID" placeholder="NHL game ID" class="min-w-0 flex-1 rounded-lg border-slate-700 bg-[#10151b] text-xs" /><select v-model="inspector.entity" aria-label="Context entity" class="rounded-lg border-slate-700 bg-[#10151b] text-xs"><option value="team">Teams</option><option value="player">Players</option></select><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-3 text-xs disabled:opacity-40">{{ busy ? 'Reading…' : 'Inspect' }}</button></form><template v-if="inspectResult"><p class="text-xs text-slate-400">{{ inspectResult.contexts.length }} contexts · Source model #{{ inspectResult.source_model_id }} · {{ inspectResult.truncated ? 'First 200 metric rows' : `${inspectResult.metrics.length} metric rows` }}</p><p v-if="!inspectResult.contexts.length" class="text-xs text-amber-300">No matching current-version Sep2026 context exists for this game.</p><div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-[10px]"><thead class="text-slate-500"><tr><th v-for="[key,title] in [['context','Entity'],['metric_key','Metric / unit'],['window_key','Window / strength'],['value','Value'],['league_average','League'],['signed_difference','Delta'],['percentage_difference','Delta %'],['league_rank','Rank'],['sample_games','Sample GP']]" :key="key" class="p-2"><button type="button" @click="orderMetric(key)">{{ title }} {{ metricSort === key ? metricDescending ? '↓' : '↑' : '' }}</button></th></tr></thead><tbody><tr v-for="metric in metricRows" :key="metric.id" class="border-t border-slate-800"><td class="p-2">{{ contextLabel(metric) }}</td><td class="p-2">{{ metric.metric_key }} / {{ metric.measure_key }}</td><td class="p-2">{{ metric.window_key }} / {{ metric.strength }}</td><td class="p-2">{{ metric.value ?? '—' }}</td><td class="p-2">{{ metric.league_average ?? '—' }}</td><td class="p-2">{{ metric.signed_difference ?? '—' }}</td><td class="p-2">{{ metric.percentage_difference == null ? '—' : `${Number(metric.percentage_difference).toFixed(2)}%` }}</td><td class="p-2">{{ metric.league_rank ?? '—' }} / {{ metric.rank_population ?? '—' }}</td><td class="p-2">{{ metric.sample_games ?? '—' }}</td></tr></tbody></table></div></template></section>
                </div>
            </DialogContent></Transition>
        </DialogPortal></DialogRoot>
    </main>
</template>
