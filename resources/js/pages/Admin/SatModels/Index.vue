<script setup>
import { computed, h, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription } from 'reka-ui';

const props = defineProps({ models: { type: Array, default: () => [] }, pagination: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) }, seasonOptions: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] }, headerSummary: { type: Object, default: () => ({}) }, pregame: { type: Object, default: null } });
const heroArtwork = '/images/sat-models-rink.png';
const iconPaths = {
    models: ['m12 3 9 5-9 5-9-5 9-5Z', 'm3 12 9 5 9-5', 'm3 16 9 5 9-5'],
    research: ['M9 3h6', 'M10 3v6L4 19a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2L14 9V3', 'M8 14h8'],
    discovery: ['M8 7a3 3 0 1 0 0 .01', 'M17 17a3 3 0 1 0 0 .01', 'M5 18a2 2 0 1 0 0 .01', 'm10 9 5 6', 'm7 10-1 6'],
    evaluations: ['M4 14h3v7H4z', 'M11 9h3v12h-3z', 'M18 3h3v18h-3z'],
    projections: ['M12 3a9 9 0 1 0 .01 0', 'M12 7a5 5 0 1 0 .01 0', 'M12 10a2 2 0 1 0 .01 0'],
    predictions: ['m8 4 12 8-12 8V4Z'], production: ['M5 8a8 8 0 0 0 0 8', 'M8 10a4 4 0 0 0 0 4', 'M19 8a8 8 0 0 1 0 8', 'M16 10a4 4 0 0 1 0 4', 'M12 11v10', 'M12 7h.01'],
    check: ['M12 3a9 9 0 1 0 .01 0', 'm8 12 3 3 5-6'],
    trend: ['m3 17 6-6 4 3 7-10', 'M15 4h5v5'],
    calendar: ['M5 5h14v16H5z', 'M8 2v6', 'M16 2v6', 'M5 10h14'],
    workers: ['M9 9a3 3 0 1 0 .01 0', 'M17 10a2.5 2.5 0 1 0 .01 0', 'M3 21v-3a6 6 0 0 1 12 0v3Z', 'M16 15a4 4 0 0 1 5 4v2h-4'],
    alert: ['m12 3 10 18H2L12 3Z', 'M12 9v5', 'M12 17h.01'],
    players: ['M12 4a4 4 0 1 0 .01 0', 'M4 21v-2a8 8 0 0 1 16 0v2H4Z'],
    teams: ['m12 3 9 5-9 5-9-5 9-5Z', 'm3 12 9 5 9-5', 'm3 16 9 5 9-5'],
    league: ['m12 3 9 5-9 5-9-5 9-5Z', 'm3 12 9 5 9-5', 'm3 16 9 5 9-5'],
    rankings: ['M12 3v18', 'M5 7h14', 'm5 7-4 8h8L5 7Z', 'm19 7-4 8h8l-4-8Z', 'M7 21h10'],
    travel: ['M5 5h14v16H5z', 'M8 2v6', 'M16 2v6', 'M5 10h14', 'M8 14h3', 'M8 17h7'],
    goalies: ['M6 12V8a6 6 0 0 1 12 0v4l-2 8-4 2-4-2-2-8Z', 'M6 10h12', 'M9 10v7', 'M15 10v7', 'M7 14h10'],
    gear: ['M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1 1-3Z', 'M12 8a4 4 0 1 0 .01 0'],
    book: ['M3 4h7l2 2 2-2h7v16h-7l-2 2-2-2H3V4Z', 'M12 6v16'],
    search: ['M10 3a7 7 0 1 0 .01 0', 'm15 15 6 6'],
    history: ['M3 3v6h6', 'M3 9a9 9 0 1 1 0 6', 'M12 7v6l4 2'],
    pause: ['M8 4v16', 'M16 4v16'], stop: ['M5 5h14v14H5z'],
};
const WorkspaceIcon = (icon, { attrs }) => h('svg', { ...attrs, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.6, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, (iconPaths[icon.name] ?? iconPaths.models).map(d => h('path', { d })));
WorkspaceIcon.props = ['name'];
const navigation = [
    { key: 'models', label: 'Models', icon: 'models' },
    { key: 'research', label: 'Research', icon: 'research', disabled: true },
    { key: 'discovery', label: 'Discovery', icon: 'discovery', href: '/admin/nhl-sat-engines/discover' },
    { key: 'evaluations', label: 'Evaluations', icon: 'evaluations', href: '/admin/nhl-sat-models/next-game' },
    { key: 'projections', label: 'Projections', icon: 'projections', href: '/admin/nhl-shot-attempts?tab=projections' },
    { key: 'pregame', label: 'Pregame', icon: 'models' },
    { key: 'predictions', label: 'Predictions', icon: 'predictions', href: '/admin?tab=game-predictions' },
    { key: 'production', label: 'Production', icon: 'production', href: '/admin/nhl-sat-engines' },
];
const base = '/admin/nhl-sat-models';
const notify = inject('notify', () => {});
const tab = ref(new URLSearchParams(window.location.search).get('tab') === 'pregame' ? 'pregame' : 'models');
const search = ref('');
const sort = ref('updated_at');
const descending = ref(true);
const streamSort = ref('name');
const streamDescending = ref(false);
const expanded = ref('goalies');
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
        : ['paused', 'pausing', 'stopped', 'incomplete', 'unavailable', 'waiting', 'unconfirmed', 'unknown'].includes(state) ? 'text-amber-300 bg-amber-400/10' : 'text-emerald-300 bg-emerald-400/10';
const modelValue = (model, key) => key === 'train' ? model.train.join(', ') : key === 'training' ? model.training?.excluded_rate ?? -1 : model[key] ?? '';
const orderedModels = computed(() => props.models.filter(model => [model.name, model.version, model.train.join(' ')].join(' ').toLowerCase().includes(search.value.toLowerCase()))
    .sort((a, b) => String(modelValue(a, sort.value)).localeCompare(String(modelValue(b, sort.value)), undefined, { numeric: true }) * (descending.value ? -1 : 1)));
const streams = computed(() => [...(props.pregame?.workstreams ?? [])].sort((a, b) => String(a[streamSort.value] ?? '').localeCompare(String(b[streamSort.value] ?? ''), undefined, { numeric: true }) * (streamDescending.value ? -1 : 1)));
const pilotModel = computed(() => props.models.find(model => model.id === props.pregame?.source?.id)
    ?? (props.pregame?.source ? { ...props.pregame.source, automation: props.pregame.automation } : null));
const pilot = computed(() => props.pregame?.source);
const run = computed(() => props.pregame?.run);
const active = computed(() => (props.pregame?.worker_activity?.workers ?? []).some(worker => worker.state === 'running') || props.models.some(model => model.status === 'running' || ['queued', 'running', 'pausing'].includes(model.pregame?.status)) || ['queued', 'running', 'pausing'].includes(run.value?.status));
const lastExecutions = ref(null);
watch(() => [props.pregame?.source?.id, props.pregame?.worker_activity], ([source, activity], previous) => {
    if (source !== previous?.[0]) lastExecutions.value = null;
    if (source && activity?.available) lastExecutions.value = activity;
}, { immediate: true });
const executionAvailable = computed(() => props.pregame?.worker_activity?.available === true);
const executions = computed(() => (lastExecutions.value?.workers ?? []).map(worker =>
    executionAvailable.value ? worker : { ...worker, state: 'unknown' }));
const runningWorkers = computed(() => executionAvailable.value ? executions.value.filter(worker => worker.state === 'running').length : null);
const elapsed = (start, end = null) => {
    if (!start || !Number.isFinite(Date.parse(start))) return '—';
    const seconds = Math.max(0, Math.floor(((end ? Date.parse(end) : Date.now()) - Date.parse(start)) / 1000));
    if (!Number.isFinite(seconds)) return '—';
    if (seconds >= 3600) return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m`;
    if (seconds >= 60) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    return `${seconds}s`;
};
const stagePercent = stage => run.value?.stages?.total > 0 ? Math.min(100, 100 * Number(run.value.stages[stage] ?? 0) / run.value.stages.total) : null;
const canUpdate = computed(() => pilot.value && !['queued', 'running', 'pausing', 'paused'].includes(run.value?.status));
const heroCards = computed(() => [
    { label: 'Total Models', icon: 'models', value: props.headerSummary.total ?? props.models.length },
    { label: 'Research', icon: 'research', value: props.headerSummary.research, help: 'Models with a Test season in the current registry filter' },
    { label: 'Production', icon: 'production', value: props.headerSummary.production, help: 'Models without a Test season in the current registry filter' },
    { label: 'Best Test Corr.', icon: 'check', value: props.headerSummary.best_test_correlation == null ? null : `${props.headerSummary.best_test_correlation}%`, help: 'Unavailable until a comparable correlation is recorded' },
    { label: 'Avg. Lift', icon: 'trend', value: props.headerSummary.average_lift == null ? null : `${props.headerSummary.average_lift}%`, sublabel: '(vs. Baseline)', help: 'Unavailable until a comparable baseline lift is recorded' },
]);
const testCoverage = computed(() => props.pregame?.coverage?.find(item => String(item.season) === String(pilot.value?.test)));
const claimLimit = computed(() => Number(props.pregame?.limits?.league ?? 0) + Number(props.pregame?.limits?.context ?? 0));
const liveClaims = computed(() => (props.pregame?.workers ?? []).filter(item => item.state === 'claimed').length);
const claimUse = computed(() => claimLimit.value ? Math.round(100 * liveClaims.value / claimLimit.value) : null);
const dateMonths = computed(() => {
    const groups = new Map();
    for (const day of props.pregame?.dates ?? []) {
        const key = day.date.slice(0, 7);
        if (!groups.has(key)) groups.set(key, { key, label: new Date(`${day.date}T12:00:00Z`).toLocaleDateString('en', { month: 'short', timeZone: 'UTC' }), days: [] });
        groups.get(key).days.push(day);
    }
    return [...groups.values()];
});
const dateState = day => day.covered >= day.games ? 'bg-emerald-500' : day.covered > 0 ? 'bg-amber-400' : 'bg-slate-600';
const shortDate = value => value ? new Date(String(value).slice(0, 10) + 'T12:00:00Z').toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }) : '—';
const streamHealth = stream => stream.state === 'failed' ? 'Needs attention' : stream.snapshot_count > 0 ? 'Recorded' : 'Unverified';
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
    router.reload({ only: ['models', 'pregame', 'pagination', 'headerSummary'], preserveState: true, preserveScroll: true, showProgress: false,
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
    <main data-page="sat-models-workspace" class="min-h-screen bg-[#07111b] pb-8 text-[#e8edf3] [font-family:Arial,Helvetica,sans-serif]">
        <div class="mx-auto max-w-screen-2xl space-y-2.5 px-3 py-2 sm:px-5 sm:py-2.5">
            <header data-sat-hq-hero class="flex flex-col gap-3 lg:flex-row lg:items-stretch">
                <div class="relative min-h-[92px] min-w-0 overflow-hidden lg:w-1/2">
                    <img :src="heroArtwork" alt="" class="pointer-events-none absolute inset-y-0 right-0 h-full w-[56%] object-cover [mask-image:linear-gradient(to_right,transparent,black_20%,black_85%,transparent)]" />
                    <div class="relative py-2">
                        <p class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-[#f7c548]"><WorkspaceIcon name="models" class="size-4" />SAT MODELS HQ <span class="italic tracking-[-2px]">///</span></p>
                        <h1 class="mt-1 text-[23px] font-extrabold italic leading-tight tracking-[-0.035em] sm:text-[26px]">SAT Models Mission <span class="text-[#f7c548]">Control</span></h1>
                        <p class="mt-1 max-w-[430px] text-[10px] leading-[1.35] text-[#bec8d3]">The command center for Shot Attempts (SAT) model research, relationship discovery, evaluations, projections, and production predictions.</p>
                    </div>
                </div>
                <div class="grid grid-cols-5 gap-2 lg:w-1/2">
                    <div v-for="card in heroCards" :key="card.label" :title="card.help" class="flex min-w-0 flex-col items-center justify-center rounded-md border border-[#2b4055] bg-gradient-to-b from-[#1a293b] to-[#08121d] px-1 py-2 text-center shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.05)]">
                        <WorkspaceIcon :name="card.icon" class="mb-1 size-[19px]" :class="card.icon === 'check' ? 'text-emerald-400' : 'text-[#f7c548]'" />
                        <strong class="text-[18px] leading-5 sm:text-[20px]">{{ card.value ?? '—' }}</strong>
                        <span class="mt-0.5 text-[9px] font-medium leading-3 text-[#d4dce5]">{{ card.label }}</span><span v-if="card.sublabel" class="text-[8px] leading-3 text-slate-400">{{ card.sublabel }}</span>
                    </div>
                </div>
            </header>
            <nav aria-label="SAT workspace" class="flex items-stretch overflow-x-auto rounded-t-lg border border-[#2b4055] bg-gradient-to-b from-[#243346] to-[#14202d] px-1 text-[11px] font-semibold shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.06)]">
                <template v-for="item in navigation" :key="item.key">
                    <a v-if="item.href" :href="item.href" class="flex min-h-[37px] min-w-[120px] flex-1 items-center justify-center gap-2 whitespace-nowrap px-3 text-[#c5ceda] transition-colors duration-150 hover:bg-[#233a4d] hover:text-white motion-reduce:transition-none"><WorkspaceIcon :name="item.icon" class="size-[18px]" />{{ item.label }}</a>
                    <button v-else type="button" :disabled="item.disabled" :title="item.disabled ? 'Research workspace is not implemented yet.' : undefined" :aria-current="tab === item.key ? 'page' : undefined" class="flex min-h-[37px] min-w-[120px] flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-t-md border-b-2 px-3 transition-colors duration-150 disabled:cursor-not-allowed motion-reduce:transition-none" :class="tab === item.key ? 'border-[#f7c548] bg-gradient-to-b from-[#102131] to-[#020c16] text-white' : 'border-transparent text-[#c5ceda] hover:bg-[#233a4d]'" @click="changeTab(item.key)"><WorkspaceIcon :name="item.icon" class="size-[18px]" :class="tab === item.key ? 'text-[#f7c548]' : ''" />{{ item.label }}</button>
                </template>
            </nav>

            <section v-show="tab === 'models'" aria-label="SAT models">
                <div class="mb-3 flex flex-wrap items-center gap-2"><h2 class="mr-auto text-sm font-semibold">Models</h2><input v-model="search" type="search" aria-label="Search models" placeholder="Search models" class="w-36 rounded-lg border-slate-700 bg-[#171d23] text-xs sm:w-52" /><select aria-label="Model status" :value="filters.status ?? ''" class="rounded-lg border-slate-700 bg-[#171d23] text-xs" @change="filterStatus"><option value="">All statuses</option><option v-for="state in statuses" :key="state" :value="state">{{ label(state) }}</option></select><button type="button" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-medium hover:bg-sky-500" @click="openPanel('create')">Create Model</button></div>
                <div v-if="!models.length" class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-10 text-center"><h3 class="font-medium">No SAT models {{ filters.status ? 'match this status' : 'yet' }}</h3><p class="mt-2 text-xs text-slate-400">Create a model to define its Train and Test seasons.</p><button type="button" class="mt-4 text-sm text-sky-300" @click="openPanel('create')">Create Model</button></div>
                <div v-else class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e]">
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
                <div v-if="!pregame" role="status" class="rounded-md border border-[#263d52] bg-[#0d1925] p-8 text-xs text-slate-400"><span class="mr-2 inline-block size-3 animate-pulse rounded-full bg-sky-400 motion-reduce:animate-none" />Loading pregame health…</div>
                <template v-else>
                    <section aria-label="Current pregame update" class="mb-3 rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] px-3 py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2"><h3 class="text-[12px] font-bold">Current Update <span v-if="run" class="text-[10px] font-normal text-slate-400">#{{ run.id }}</span></h3><span v-if="run" class="rounded-full px-2 py-0.5 text-[9px] font-semibold" :class="tone(run.status)">{{ label(run.status) }}</span><span v-if="run" data-update-percent class="text-[16px] font-bold text-white">{{ run.progress_pct == null ? (['queued','running'].includes(run.status) ? 'Selecting games…' : 'Selection unavailable') : `${number(run.progress_pct)}% processed` }}</span><span class="ml-auto text-[10px] text-slate-400">Running workers: <strong class="text-sky-300">{{ runningWorkers ?? '—' }}</strong></span></div>
                        <template v-if="run"><progress aria-label="Current update processed games" :value="run.progress_pct ?? undefined" max="100" class="mt-2 block h-2 w-full accent-sky-500" /><div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-400"><span>{{ run.total > 0 || run.status === 'completed' ? `${number(run.processed)} / ${number(run.total)} games processed` : 'Preparing game selection' }}</span><span class="text-emerald-300">{{ number(run.completed) }} updated</span><span class="text-amber-300">{{ number(run.excluded) }} excluded</span><span class="text-red-300">{{ number(run.failed) }} failed</span><span class="ml-auto">Elapsed {{ elapsed(run.created_at, ['completed','failed','stopped'].includes(run.status) ? run.updated_at : null) }}</span></div><p v-if="run.up_to_date" class="mt-2 text-[10px] text-slate-400">Already up to date; no missing games were selected.</p><p class="mt-1 text-[9px] text-slate-500">Processed includes excluded and failed games. Data coverage is measured separately below.</p><div class="mt-2 grid gap-3 sm:grid-cols-2"><div v-for="[stage,title] in [['league','League averages'],['context','Context, rankings & deltas']]" :key="stage"><div class="mb-1 flex justify-between gap-2 text-[9px] text-slate-400"><span>{{ title }}</span><span>{{ number(run.stages?.[stage]) }} / {{ number(run.stages?.total) }} days<span v-if="stagePercent(stage) != null"> · {{ stagePercent(stage).toFixed(1) }}%</span></span></div><progress :aria-label="`${title} stage progress`" :value="stagePercent(stage) ?? undefined" max="100" class="block h-1 w-full accent-emerald-500" /></div></div></template>
                        <p v-else class="mt-2 text-[10px] text-slate-400">No maintenance run yet. Update Missing Data starts a scoped update.</p>
                    </section>
                    <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_300px]">
                        <div class="min-w-0 space-y-3">
                            <section data-pregame-health-strip class="grid gap-3 rounded-md border border-[#2b4358] bg-gradient-to-b from-[#132330] to-[#0b1620] px-3 py-3 sm:grid-cols-[1.7fr_1fr_1fr_.75fr_.65fr]">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-[43px] shrink-0 items-center justify-center rounded-full border-[3px]" :class="pregame.health === 'recorded' ? 'border-emerald-500/70 bg-emerald-500/10 text-emerald-400' : pregame.health === 'attention' ? 'border-red-500/70 bg-red-500/10 text-red-400' : 'border-amber-500/70 bg-amber-500/10 text-amber-400'"><WorkspaceIcon :name="pregame.health === 'recorded' ? 'check' : 'alert'" class="size-7" /></div>
                                    <div><div class="flex flex-wrap items-center gap-x-2"><p class="text-[10px] font-semibold">Overall Health</p><button type="button" aria-haspopup="dialog" aria-controls="sat-pregame-issues-panel" :aria-expanded="panel === 'repair'" class="rounded text-[9px] text-[#65b5ef] transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none" @click="openPanel('repair')">View issues →</button></div><button type="button" aria-label="View pregame health issues" aria-haspopup="dialog" aria-controls="sat-pregame-issues-panel" :aria-expanded="panel === 'repair'" class="mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold transition-[filter] duration-150 hover:brightness-125 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none" :class="tone(pregame.health)" @click="openPanel('repair')">{{ healthLabels[pregame.health] }}</button><p class="mt-1 text-[9px] text-slate-400">{{ pilot ? `${pilot.name} · Test ${pilot.test}` : 'Sep2026 source unavailable' }}</p></div>
                                </div>
                                <div class="flex gap-2 border-[#293b4b] sm:border-l sm:pl-3"><WorkspaceIcon name="calendar" class="mt-0.5 size-4 shrink-0 text-slate-400" /><div><p class="text-[9px] text-slate-400">Statistical Cutoff</p><p class="mt-1 text-[11px] font-semibold">{{ shortDate(pregame.source_cutoff_at) }}</p><p class="mt-1 text-[8px] text-slate-500">Historical source boundary</p></div></div>
                                <div class="flex gap-2 border-[#293b4b] sm:border-l sm:pl-3"><WorkspaceIcon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-400" /><div><p class="text-[9px] text-slate-400">Test Team Coverage</p><p class="mt-1 text-[11px] font-semibold">{{ number(testCoverage?.covered) }} / {{ number(testCoverage?.games) }}</p><p class="mt-1 text-[8px] text-slate-500">Completed games only</p></div></div>
                                <div class="border-[#293b4b] sm:border-l sm:pl-3"><p class="flex items-center gap-1 text-[9px] text-slate-400"><WorkspaceIcon name="workers" class="size-4" />Active Claims</p><p class="mt-1 text-[13px] font-bold">{{ liveClaims }} / {{ claimLimit || '—' }}</p><p class="text-[8px] text-slate-500">Not OS workers</p></div>
                                <div class="border-[#293b4b] sm:border-l sm:pl-3"><p class="flex items-center gap-1 text-[9px] text-slate-400"><WorkspaceIcon name="alert" class="size-4 text-[#f7c548]" />Outstanding</p><button type="button" :aria-label="`View ${pregame.issues.length} outstanding pregame issues`" aria-haspopup="dialog" aria-controls="sat-pregame-issues-panel" :aria-expanded="panel === 'repair'" class="mt-1 inline-block rounded px-1.5 text-[12px] font-bold transition-[filter] duration-150 hover:brightness-125 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none" :class="pregame.issues.length ? 'bg-red-500/20 text-red-300' : 'bg-emerald-500/20 text-emerald-300'" @click="openPanel('repair')">{{ pregame.issues.length }}</button></div>
                            </section>

                            <section class="overflow-hidden rounded-md border border-[#2b4358] bg-gradient-to-b from-[#101e2b] to-[#0a151e]">
                                <div class="flex flex-wrap items-center gap-3 border-b border-[#293b4b] bg-gradient-to-b from-[#1b2b3a] to-[#12202c] px-3 py-2"><h3 class="text-[12px] font-bold">Workstreams</h3><span class="border-l border-[#35485a] pl-3 text-[9px] text-slate-400">{{ streams.length }} workstreams · {{ run ? `Maintenance ${run.status}` : 'No maintenance run' }}</span><button type="button" :disabled="!pilot" class="ml-auto text-[10px] text-[#65b5ef] disabled:opacity-40" @click="workerPanel">Manage allocation →</button></div>
                                <div class="hidden grid-cols-[1.65fr_.8fr_.8fr_.7fr_1fr_.65fr_.65fr_.7fr_.85fr_20px] items-center gap-2 bg-[#172735] px-3 py-2 text-[8px] font-bold uppercase text-[#b5c3d1] xl:grid"><button v-for="[key,title] in [['name','Workstream'],['snapshot_count','Context'],['health','Health'],['freshness','Freshness'],['completed_days','Coverage'],['throughput','Throughput'],['active_claims','Claims'],['state','Status'],['updated','Last Update']]" :key="key" type="button" class="text-left" :disabled="['health','freshness','throughput','updated'].includes(key)" @click="orderStream(key)">{{ title }} {{ streamSort === key ? streamDescending ? '↓' : '↑' : '' }}</button><span aria-hidden="true" /></div>
                                <div v-for="stream in streams" :key="stream.key" class="border-t border-[#213545] first:border-0">
                                    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 px-3 py-2.5 xl:grid-cols-[1.65fr_.8fr_.8fr_.7fr_1fr_.65fr_.65fr_.7fr_.85fr_20px]">
                                        <button type="button" :aria-label="`Expand ${stream.name}`" :aria-expanded="expanded === stream.key" :aria-controls="`workstream-${stream.key}`" class="flex min-w-0 items-center gap-2 text-left" @click="expanded = expanded === stream.key ? null : stream.key"><svg aria-hidden="true" viewBox="0 0 20 20" class="size-2.5 shrink-0 text-slate-400 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="expanded === stream.key ? 'rotate-180' : ''" fill="currentColor"><path d="m5 7 5 5 5-5z" /></svg><WorkspaceIcon :name="stream.key" class="size-6 shrink-0 text-[#d1dce7]" /><span class="min-w-0"><span class="block truncate text-[11px] font-semibold">{{ stream.name }}</span><span class="mt-0.5 block truncate text-[8px] text-slate-400">{{ stream.description }}</span></span></button>
                                        <span class="hidden w-fit rounded-full border border-[#334b5e] bg-[#0a1620] px-1.5 py-0.5 text-[8px] text-slate-300 xl:inline-block">{{ stream.snapshot_count == null ? '—' : `${number(stream.snapshot_count)} rows` }}</span>
                                        <span class="hidden w-fit rounded-full px-1.5 py-0.5 text-[8px] font-semibold xl:inline-block" :class="stream.state === 'failed' ? 'bg-red-500/20 text-red-300' : 'bg-slate-500/15 text-slate-300'">{{ streamHealth(stream) }}</span>
                                        <span class="hidden text-[9px] text-slate-400 xl:block">Historical</span>
                                        <span class="hidden xl:block"><span class="block text-[9px] font-semibold">{{ number(stream.completed_days) }} / {{ number(stream.total_days) }}</span><progress v-if="stream.total_days" :value="stream.completed_days ?? 0" :max="stream.total_days" class="mt-0.5 block h-1 w-full accent-emerald-500" /><span class="text-[7px] text-slate-500">Latest run days</span></span>
                                        <span class="hidden text-[9px] text-slate-400 xl:block">—</span>
                                        <span class="hidden text-[9px] font-semibold xl:block">{{ stream.active_claims }}<span class="block text-[7px] font-normal text-slate-500">{{ stream.stage }} · shared</span></span>
                                        <span class="w-fit rounded-full px-1.5 py-0.5 text-[8px] font-semibold" :class="tone(stream.state)">{{ label(stream.state) }}</span>
                                        <span class="hidden text-[8px] leading-3 text-slate-400 xl:block">{{ time(pregame.last_activity) }}</span>
                                        <button type="button" :aria-label="`Inspect ${stream.name}`" class="hidden rounded p-1 text-slate-400 hover:text-white xl:block" @click="inspector.entity = stream.key === 'players' || stream.key === 'goalies' ? 'player' : 'team'; openPanel('inspector')">⋮</button>
                                    </div>
                                    <div :id="`workstream-${stream.key}`" :aria-hidden="expanded !== stream.key" :inert="expanded !== stream.key" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded === stream.key ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"><div class="min-h-0 overflow-hidden"><div class="grid gap-3 border-t border-[#2a4051] bg-[#152431] p-3 text-[9px] text-[#aebdcc] lg:grid-cols-[1.15fr_1fr_1.25fr]">
                                        <div><h4 class="mb-1 text-[10px] font-bold text-slate-200">Details</h4><p class="leading-relaxed">{{ stream.note }}</p><p class="mt-2 text-[8px] text-slate-500">Historical evidence stays in Sep2026. Production context remains independent.</p></div>
                                        <div><h4 class="mb-1 text-[10px] font-bold text-slate-200">Maintenance Coverage</h4><progress v-if="stream.total_days" :value="stream.completed_days ?? 0" :max="stream.total_days" class="h-2 w-full accent-emerald-500" /><p class="mt-1">{{ number(stream.completed_days) }} / {{ number(stream.total_days) }} days · {{ number(stream.snapshot_count) }} snapshots</p><p class="mt-1">{{ run?.excluded ?? 0 }} excluded · {{ run?.failed ?? 0 }} failed</p><p class="mt-1 text-slate-500">Speed / ETA: —</p></div>
                                        <div><h4 class="mb-1 text-[10px] font-bold text-slate-200">{{ run?.error ? 'Last Error' : 'Saved Context' }}</h4><p v-if="run?.error" class="text-red-300">{{ run.error }}</p><p v-else>Inspect saved metric values, league comparisons and samples for a specific game.</p><button type="button" class="mt-2 rounded border border-[#b38d32] bg-[#8e6b17]/15 px-3 py-1 text-[9px] text-[#f7d378]" @click="openPanel('inspector')">Inspect saved metrics →</button></div>
                                    </div></div></div>
                                </div>
                                <p v-if="!streams.length" class="p-6 text-xs text-slate-400">No pilot workstreams are available.</p>
                            </section>
                        </div>

                        <aside class="space-y-3">
                            <section class="space-y-2">
                                <div class="flex gap-2"><button type="button" :disabled="busy || !canUpdate" aria-label="Update Missing Data" class="flex min-h-8 flex-1 items-center justify-center gap-2 rounded-md border border-[#4b91fa] bg-gradient-to-b from-[#2878fa] to-[#0754d4] px-3 py-2 text-[11px] font-semibold text-white shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.15)] hover:brightness-110 disabled:opacity-40" @click="pilotAction('update')"><span class="text-lg leading-none">↻</span>{{ busy ? 'Submitting…' : 'Update Missing Data' }}</button><button type="button" :disabled="!pilot" aria-label="Targeted repair" class="rounded-md border border-[#334c63] bg-gradient-to-b from-[#1b2c3d] to-[#0b1722] px-3 text-sm disabled:opacity-40" @click="openPanel('repair')">⋯</button></div>
                                <div class="flex gap-2"><button type="button" :disabled="busy || !['queued','running'].includes(run?.status)" aria-label="Pause" class="flex min-h-7 flex-1 items-center justify-center gap-1 rounded border border-[#334c63] bg-[#101d29] text-[10px] disabled:opacity-30" @click="pilotAction('pause')"><WorkspaceIcon name="pause" class="size-3" />Pause</button><button type="button" :disabled="busy || !(['paused','pausing'].includes(run?.status) || run?.retryable)" aria-label="Resume" class="flex min-h-7 flex-1 items-center justify-center gap-1 rounded border border-[#334c63] bg-[#101d29] text-[10px] disabled:opacity-30" @click="pilotAction('resume')"><WorkspaceIcon name="predictions" class="size-3 text-emerald-400" />Resume</button><button type="button" :disabled="busy || !['queued','running','pausing','paused'].includes(run?.status)" aria-label="Stop" class="flex min-h-7 flex-1 items-center justify-center gap-1 rounded border border-[#334c63] bg-[#101d29] text-[10px] disabled:opacity-30" @click="pilotAction('stop')"><WorkspaceIcon name="stop" class="size-3 text-red-400" />Stop</button></div>
                            </section>
                            <section class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="mb-2 flex items-center gap-2"><WorkspaceIcon name="alert" class="size-[18px] text-red-400" /><h3 class="text-[12px] font-bold">Needs Attention <span class="text-slate-400">({{ pregame.issues.length }})</span></h3><button type="button" class="ml-auto text-[9px] text-[#65b5ef]" @click="openPanel('repair')">View all →</button></div>
                                <p v-if="!pregame.issues.length" class="py-2 text-[10px] leading-relaxed text-slate-400">No tracked maintenance errors or team-coverage gaps. Inspect metrics to verify individual quality.</p>
                                <article v-for="issue in pregame.issues.slice(0, 3)" :key="issue.title" class="flex gap-2 border-t border-[#253847] py-2"><WorkspaceIcon name="alert" class="mt-0.5 size-5 shrink-0 text-[#e4b53d]" /><div class="min-w-0"><h4 class="text-[10px] font-semibold text-slate-200">{{ issue.title }}</h4><p class="mt-1 text-[9px] leading-[1.4] text-[#a7b7c7]">{{ issue.detail }}</p></div></article>
                            </section>
                            <section class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="flex items-center gap-2"><WorkspaceIcon name="gear" class="size-[18px] text-slate-300" /><h3 class="text-[12px] font-bold">Automation</h3><span class="rounded-full px-1.5 py-0.5 text-[8px] font-bold" :class="pregame.automation?.enabled ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-600/20 text-slate-400'">{{ pregame.automation?.enabled ? 'ON' : 'OFF' }}</span><button type="button" :disabled="!pilot" class="ml-auto text-[9px] text-[#65b5ef] disabled:opacity-30" @click="openSettings(pilotModel ?? { id: pilot.id })">Settings ⚙</button></div>
                                <div class="mt-3 space-y-2 text-[9px] text-slate-300"><p class="flex items-center justify-between"><span class="flex items-center gap-2"><WorkspaceIcon name="check" class="size-3 text-emerald-400" />Pregame context refresh</span><span class="text-slate-400">Sep2026</span></p><p class="flex items-center justify-between"><span class="flex items-center gap-2"><WorkspaceIcon name="check" class="size-3 text-emerald-400" />Committed work preserved</span><span class="text-slate-400">On resume</span></p><p class="flex items-center justify-between"><span class="flex items-center gap-2"><WorkspaceIcon name="workers" class="size-3 text-slate-400" />League / context limits</span><button type="button" :disabled="!pilot" class="text-[#65b5ef]" @click="workerPanel">{{ pregame.limits?.league ?? '—' }} / {{ pregame.limits?.context ?? '—' }}</button></p></div>
                            </section>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-1"><button v-for="[key,title,icon] in [['definitions','Metric definitions','book'],['inspector','Context inspector','search'],['history','Maintenance history','history']]" :key="key" type="button" class="flex items-center gap-1 text-[9px] text-[#80acd0] hover:text-white" @click="openPanel(key)"><WorkspaceIcon :name="icon" class="size-3" />{{ title }}</button></div>
                        </aside>

                        <div class="grid gap-3 lg:grid-cols-[1.12fr_.88fr_1.25fr] xl:col-span-2">
                            <section aria-label="Pregame worker activity" class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="flex items-center gap-2"><WorkspaceIcon name="production" class="size-4 text-slate-300" /><h3 class="text-[12px] font-bold">Worker Activity</h3><span class="text-[8px] text-slate-400">{{ runningWorkers ?? '—' }} observed running</span><button type="button" class="ml-auto text-[9px] text-[#65b5ef]" @click="openPanel('history')">View history →</button></div>
                                <div class="mt-2 grid grid-cols-[.9fr_1.7fr_.65fr_.75fr] gap-2 border-b border-[#293b4b] pb-1 text-[8px] font-semibold uppercase text-slate-400"><span>Server / PID</span><span>Task / Queue</span><span>Elapsed</span><span>Status</span></div>
                                <div class="h-[152px] overflow-y-auto"><p v-if="!executions.length" class="py-5 text-[10px] leading-relaxed text-slate-400">{{ executionAvailable ? 'No running jobs observed. Tracking begins when workers load this deployment; date claims are listed below.' : 'Worker execution data is unavailable. Saved date claims remain visible below.' }}</p>
                                    <div v-for="worker in executions" :key="`${worker.job_id}-${worker.server}-${worker.pid}-${worker.attempt}`" data-pregame-execution class="grid grid-cols-[.9fr_1.7fr_.65fr_.75fr] items-start gap-2 border-b border-[#213545] py-2 text-[9px]"><div class="min-w-0"><p :title="worker.server" class="truncate font-semibold">{{ worker.server }}</p><p class="mt-1 text-[8px] text-slate-500">PID {{ worker.pid }}</p></div><div class="min-w-0"><p :title="worker.job" class="text-slate-200">{{ worker.task }}</p><p class="mt-1 text-[8px] text-slate-500">{{ worker.queue }} · Run #{{ worker.run_id }}</p><p v-if="worker.game_date || worker.game_id" class="mt-0.5 text-[8px] text-slate-400">{{ worker.game_date ?? '—' }}<span v-if="worker.game_id"> · {{ worker.game_id }}</span></p></div><span class="text-[9px] text-slate-400">{{ elapsed(worker.started_at) }}</span><span class="w-fit rounded-full px-1.5 py-0.5 text-[8px]" :class="tone(worker.state)">{{ label(worker.state) }}</span></div>
                                </div><p class="mt-1 h-3 text-[8px] text-slate-500"><span v-if="pregame.worker_activity?.truncated">Showing the first 200 executions.</span></p>
                                <details class="mt-2 border-t border-[#293b4b] pt-2 text-[9px] text-[#80acd0]"><summary class="cursor-pointer">{{ pregame.workers.length }} date claims · queued or executing</summary><p v-if="!pregame.workers.length" class="py-3 text-[10px] leading-relaxed text-slate-400">No date jobs are claimed. Saved data can remain usable while processing is idle.</p><div v-for="worker in pregame.workers" :key="worker.key" class="mt-2 flex items-center justify-between gap-2 text-[9px]"><span>{{ label(worker.stage) }} · {{ worker.date }}</span><span class="text-[8px] text-slate-500">{{ time(worker.last_activity) }}</span><span class="rounded-full px-1.5" :class="tone(worker.state)">{{ label(worker.state) }}</span></div></details>
                            </section>
                            <section class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="flex items-center gap-2"><WorkspaceIcon name="workers" class="size-4 text-slate-300" /><h3 class="text-[12px] font-bold">Worker Allocation</h3><button type="button" :disabled="!pilot" aria-label="Edit claim allocation" class="ml-auto text-[9px] text-[#65b5ef] disabled:opacity-30" @click="workerPanel">Manage →</button></div><div class="mt-3 flex items-center gap-4"><div class="relative size-[112px] shrink-0"><svg viewBox="0 0 100 100" class="size-full" role="img" :aria-label="`${claimUse ?? 0}% of configured claim capacity in use`"><circle cx="50" cy="50" r="38" fill="none" stroke="#213444" stroke-width="10" /><circle cx="50" cy="50" r="38" fill="none" stroke="#22c575" stroke-width="10" :stroke-dasharray="`${Math.min(100, claimUse ?? 0) * 2.3876} 238.76`" transform="rotate(-90 50 50)" /></svg><div class="absolute inset-0 flex flex-col items-center justify-center"><strong class="text-xl">{{ claimUse == null ? '—' : `${claimUse}%` }}</strong><span class="text-[9px] text-slate-400">Claim use</span></div></div><div class="min-w-0 flex-1 space-y-2 text-[9px]"><p v-for="pool in ['league','context']" :key="pool" class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="size-2 rounded-full" :class="pool === 'league' ? 'bg-emerald-400' : 'bg-sky-400'" />{{ label(pool) }}</span><strong>{{ pregame.limits?.[pool] ?? '—' }}</strong></p><p class="border-t border-[#293b4b] pt-2 text-[8px] leading-relaxed text-slate-500">Configured date claims, not physical worker processes. Context is shared across five workstreams.</p></div></div></section>
                            <section class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="flex items-center gap-2"><WorkspaceIcon name="calendar" class="size-4 text-slate-300" /><h3 class="text-[12px] font-bold">Coverage &amp; Freshness</h3><span class="ml-auto text-[8px] text-slate-400">{{ pilot?.test ?? 'Historical' }}</span></div>
                                <div v-if="dateMonths.length" class="mt-3"><div class="mb-1 flex pl-12"><span v-for="month in dateMonths" :key="month.key" class="flex-1 text-center text-[8px] uppercase text-slate-400">{{ month.label }}</span></div><div class="flex items-center gap-2"><span class="w-10 shrink-0 text-[8px] text-slate-300">Coverage</span><div class="flex min-w-0 flex-1 gap-1"><div v-for="month in dateMonths" :key="month.key" class="flex min-w-0 flex-1 gap-px"><span v-for="day in month.days" :key="day.date" :title="`${day.date}: ${day.covered}/${day.games} games with both team snapshots`" class="h-3 min-w-0 flex-1 rounded-[1px]" :class="dateState(day)" /></div></div></div><div class="mt-1 flex items-center gap-2"><span class="w-10 shrink-0 text-[8px] text-slate-300">Freshness</span><div class="flex min-w-0 flex-1 gap-1"><div v-for="month in dateMonths" :key="month.key" class="flex min-w-0 flex-1 gap-px"><span v-for="day in month.days" :key="day.date" title="Source freshness has not been independently revalidated" class="h-3 min-w-0 flex-1 rounded-[1px] bg-[#263b4d]" /></div></div></div></div>
                                <div class="mt-3 grid grid-cols-2 gap-2"><div class="rounded border border-[#2a4051] bg-[#0d1a26] p-2"><p class="text-[8px] text-slate-400">Test Team Coverage</p><p class="mt-1 text-[12px] font-semibold">{{ number(testCoverage?.covered) }} / {{ number(testCoverage?.games) }}</p></div><div class="rounded border border-[#2a4051] bg-[#0d1a26] p-2"><p class="text-[8px] text-slate-400">Freshness</p><p class="mt-1 text-[10px] text-slate-400">Not independently measured</p></div></div><p class="mt-2 text-[8px] leading-relaxed text-slate-500">Counts do not certify every player or metric. Checked {{ time(pregame.counts_checked_at) }}.</p>
                                <details class="mt-2 text-[9px] text-[#80acd0]"><summary class="cursor-pointer">All season coverage</summary><div v-for="season in pregame.coverage" :key="season.season" class="mt-2"><div class="mb-1 flex justify-between text-[9px]"><span>{{ season.season }}</span><span class="text-slate-400">{{ number(season.covered) }} / {{ number(season.games) }}</span></div><progress :value="season.covered" :max="season.games || 1" class="h-1 w-full accent-emerald-500" /></div></details>
                            </section>
                        </div>
                    </div>
                </template>
            </section>
        </div>

        <DialogRoot :open="panel !== null" @update:open="value => { if (!value) closePanel(); }"><DialogPortal>
            <Transition enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay class="fixed inset-0 z-[60] bg-black/70 motion-reduce:transition-none" /></Transition>
            <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" enter-from-class="translate-x-full" leave-to-class="translate-x-full"><DialogContent :id="panel === 'repair' ? 'sat-pregame-issues-panel' : undefined" class="fixed inset-y-0 right-0 z-[61] flex w-full max-w-xl flex-col border-l border-slate-700 bg-[#171d23] text-slate-100 shadow-xl focus:outline-none" @escape-key-down="event => { if (busy) event.preventDefault(); }" @interact-outside="event => { if (busy) event.preventDefault(); }">
                <header class="flex items-start justify-between gap-4 border-b border-slate-800 p-5"><div><DialogTitle class="text-lg font-semibold">{{ panelTitle }}</DialogTitle><DialogDescription class="mt-1 text-xs text-slate-400">{{ panelModel ? panelModel.name : pilot ? `${pilot.name} · Historical Pregame pilot` : 'SAT Models HQ' }}</DialogDescription></div><button type="button" :disabled="busy" aria-label="Close panel" class="rounded-md px-2 py-1 text-slate-400 disabled:opacity-40" @click="closePanel">✕</button></header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 pb-24 sm:pb-5"><p v-if="failure" role="alert" class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-200">{{ failure }}</p>
                    <form v-if="panel === 'create'" class="space-y-4" @submit.prevent="saveModel"><label class="block text-xs">Name<input v-model="createForm.name" required maxlength="160" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Version<input v-model="createForm.model_version" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><fieldset><legend class="mb-2 text-xs">Training seasons</legend><label v-for="season in seasonOptions" :key="season" class="mr-4 inline-flex items-center gap-2 py-2 text-xs"><input v-model="createForm.train_season_ids" type="checkbox" :value="season" class="rounded border-slate-700 bg-[#10151b] text-sky-500" />{{ season }}</label></fieldset><label v-for="[key,title] in [['test_season_id','Test season'],['projection_season_id','Projection season']]" :key="key" class="block text-xs">{{ title }}<input v-model="createForm[key]" pattern="[0-9]{8}" placeholder="20252026" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Notes<textarea v-model="createForm.notes" rows="3" class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><button type="submit" :disabled="busy" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">{{ busy ? 'Saving…' : 'Create Model' }}</button></form>
                    <template v-if="panel === 'actions'"><p v-if="confirmation" class="rounded-lg border border-amber-500/30 p-3 text-xs text-amber-200">{{ confirmation }} will recalculate this model’s selected scope. Press the same action again to confirm.</p><section v-for="group in [...new Set(panelModel.actions.map(action => action.group))]" :key="group"><h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ group }}</h3><div class="divide-y divide-slate-800"><template v-for="action in panelModel.actions.filter(item => item.group === group)" :key="action.label"><a v-if="action.link && action.enabled" :href="actionHref(action)" class="block rounded-md px-3 py-2.5 text-xs text-slate-200 hover:bg-slate-800">{{ action.label }} ↗</a><button v-else type="button" :disabled="busy || !action.enabled" :title="action.reason || (!action.enabled ? 'Required inputs or an idle build are needed.' : '')" class="block w-full rounded-md px-3 py-2.5 text-left text-xs text-slate-200 hover:bg-slate-800 disabled:opacity-30" @click="modelAction(action)">{{ action.label }}</button></template></div></section></template>
                    <template v-if="panel === 'automation'"><p v-if="loading" role="status" class="text-sm text-slate-400">Loading settings…</p><form v-else-if="!failure" class="space-y-5" @submit.prevent="mutate('put', panelModel.automation?.settings_url ?? `${base}/${panelModel.id}/context/auto-sync`, { ...settings })"><label class="flex items-center justify-between text-sm">Include Update Pregame<input v-model="settings.include_update_pregame" type="checkbox" class="rounded border-slate-700 bg-[#10151b] text-sky-500" /></label><label class="block text-xs">Frequency (hours)<input v-model.number="settings.frequency_hours" type="number" min="1" max="168" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><label class="block text-xs">Start time · America/Toronto<input v-model="settings.start_time" type="time" required class="mt-1 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><p class="text-xs text-slate-400">Update fills missing Test context, or prediction-season context when there is no Test season. Train remains an input.</p><button type="submit" :disabled="busy" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">Save settings</button><button v-if="panelModel.automation" type="button" :disabled="busy" class="ml-3 text-xs text-sky-300" @click="toggleAutomation(panelModel)">{{ panelModel.automation.enabled ? 'Disable automation' : 'Enable automation' }}</button></form></template>
                    <form v-if="panel === 'workers'" class="space-y-5" @submit.prevent="mutate('put', pregame.workers_url, { ...limits })"><p class="text-xs leading-relaxed text-slate-400">Sep2026 only. Set simultaneous date-job limits for the two real processing pools. Player, team, goalie, ranking and travel work share the context pool. This does not add Horizon workers.</p><label v-for="key in ['league','context']" :key="key" class="block text-sm">{{ label(key) }} claims<input v-model.number="limits[key]" type="number" min="1" max="25" required class="mt-2 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><p class="text-xs text-slate-500">Maximum combined limit: 25. A lower limit applies as already-claimed jobs finish.</p><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">Save allocation</button></form>
                    <section v-if="panel === 'repair'" class="space-y-4 text-sm"><p v-if="!pregame?.issues?.length" class="text-xs text-slate-400">No tracked pregame issues.</p><article v-for="issue in pregame?.issues ?? []" :key="issue.title" class="rounded border border-amber-500/20 p-3"><h3 class="text-xs font-semibold text-amber-200">{{ issue.title }}</h3><p class="mt-1 text-[11px] leading-relaxed text-slate-400">{{ issue.detail }}</p></article><p class="text-xs leading-relaxed text-slate-400">Use the existing Sep2026 maintenance pipeline. Updating fills missing Test games; retry continues unfinished work. A full rebuild recalculates Sep2026 Train and Test context.</p><p v-if="confirmation" class="rounded-lg border border-amber-500/30 p-3 text-xs text-amber-200">{{ confirmation === 'stop' ? 'Stop unfinished work while preserving committed context?' : 'Recalculate all Sep2026 Train and Test context?' }}</p><button type="button" :disabled="busy || !canUpdate" class="block w-full rounded-lg bg-sky-600 p-3 text-sm disabled:opacity-40" @click="pilotAction('update')">Update missing Test context</button><button type="button" :disabled="busy || !run?.retryable" class="block w-full rounded-lg border border-slate-700 p-3 text-sm disabled:opacity-40" @click="pilotAction('resume')">Retry unfinished</button><button v-if="confirmation === 'stop'" type="button" :disabled="busy" class="block w-full rounded-lg border border-red-600/50 p-3 text-sm text-red-300" @click="pilotAction('stop')">Confirm stop</button><button v-else type="button" :disabled="busy || !canUpdate" class="block w-full rounded-lg border border-amber-600/40 p-3 text-sm text-amber-200 disabled:opacity-40" @click="pilotAction('build')">{{ confirmation === 'build' ? 'Confirm full rebuild' : 'Full Train + Test rebuild' }}</button></section>
                    <section v-if="panel === 'definitions'" class="space-y-5"><article v-for="[name,description] in definitions" :key="name"><h3 class="text-sm font-medium">{{ name }}</h3><p class="mt-2 text-xs leading-relaxed text-slate-400">{{ description }}</p></article></section>
                    <section v-if="panel === 'history'" class="space-y-3"><p v-if="!pregame?.history?.length" class="text-sm text-slate-400">No Sep2026 maintenance runs yet.</p><article v-for="item in pregame?.history ?? []" :key="item.id" class="rounded-lg border border-slate-700 p-3 text-xs"><div class="flex justify-between"><strong>Run #{{ item.id }}</strong><span :class="tone(item.status)" class="rounded-full px-2">{{ label(item.status) }}</span></div><p class="mt-2 text-slate-400">{{ item.completed }} / {{ item.total }} games · {{ item.excluded }} excluded · {{ item.failed }} failed</p><p class="mt-1 text-[10px] text-slate-500">{{ time(item.updated_at) }}</p><p v-if="item.error" class="mt-2 text-red-300">{{ item.error }}</p></article></section>
                    <section v-if="panel === 'inspector'" class="space-y-4"><form class="flex gap-2" @submit.prevent="inspect"><input v-model="inspector.game_id" type="number" min="1" required aria-label="NHL game ID" placeholder="NHL game ID" class="min-w-0 flex-1 rounded-lg border-slate-700 bg-[#10151b] text-xs" /><select v-model="inspector.entity" aria-label="Context entity" class="rounded-lg border-slate-700 bg-[#10151b] text-xs"><option value="team">Teams</option><option value="player">Players</option></select><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-3 text-xs disabled:opacity-40">{{ busy ? 'Reading…' : 'Inspect' }}</button></form><template v-if="inspectResult"><p class="text-xs text-slate-400">{{ inspectResult.contexts.length }} contexts · Source model #{{ inspectResult.source_model_id }} · {{ inspectResult.truncated ? 'First 200 metric rows' : `${inspectResult.metrics.length} metric rows` }}</p><p v-if="!inspectResult.contexts.length" class="text-xs text-amber-300">No matching current-version Sep2026 context exists for this game.</p><div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-[10px]"><thead class="text-slate-500"><tr><th v-for="[key,title] in [['context','Entity'],['metric_key','Metric / unit'],['window_key','Window / strength'],['value','Value'],['league_average','League'],['signed_difference','Delta'],['percentage_difference','Delta %'],['league_rank','Rank'],['sample_games','Sample GP']]" :key="key" class="p-2"><button type="button" @click="orderMetric(key)">{{ title }} {{ metricSort === key ? metricDescending ? '↓' : '↑' : '' }}</button></th></tr></thead><tbody><tr v-for="metric in metricRows" :key="metric.id" class="border-t border-slate-800"><td class="p-2">{{ contextLabel(metric) }}</td><td class="p-2">{{ metric.metric_key }} / {{ metric.measure_key }}</td><td class="p-2">{{ metric.window_key }} / {{ metric.strength }}</td><td class="p-2">{{ metric.value ?? '—' }}</td><td class="p-2">{{ metric.league_average ?? '—' }}</td><td class="p-2">{{ metric.signed_difference ?? '—' }}</td><td class="p-2">{{ metric.percentage_difference == null ? '—' : `${Number(metric.percentage_difference).toFixed(2)}%` }}</td><td class="p-2">{{ metric.league_rank ?? '—' }} / {{ metric.rank_population ?? '—' }}</td><td class="p-2">{{ metric.sample_games ?? '—' }}</td></tr></tbody></table></div></template></section>
                </div>
            </DialogContent></Transition>
        </DialogPortal></DialogRoot>
    </main>
</template>
