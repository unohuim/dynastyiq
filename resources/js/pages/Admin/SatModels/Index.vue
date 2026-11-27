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
const areaLimits = reactive({ players: 1, teams: 1, goalies: 1, league: 1, rankings: 1, travel: 1 });
const selectedAreaKey = ref(null);
const inspector = reactive({ game_id: '', entity: 'team' });
const number = value => value == null ? '—' : Number(value).toLocaleString();
const time = value => !value || !Number.isFinite(Date.parse(value)) ? '—' : new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const label = value => String(value ?? 'unavailable').replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
const healthLabels = { recorded: 'Coverage recorded', attention: 'Needs attention', incomplete: 'Incomplete', unavailable: 'Source unavailable' };
const tone = state => state === 'idle' ? 'text-slate-300 bg-slate-500/10' : ['failed', 'attention', 'expired', 'blocked', 'outdated'].includes(state) ? 'text-red-300 bg-red-400/10'
    : ['running', 'working', 'queued', 'claimed', 'updating'].includes(state) ? 'text-sky-300 bg-sky-400/10'
        : ['paused', 'pausing', 'stopped', 'incomplete', 'unavailable', 'waiting', 'unconfirmed', 'unknown', 'unassessed', 'assessing'].includes(state) ? 'text-amber-300 bg-amber-400/10' : 'text-emerald-300 bg-emerald-400/10';
const modelValue = (model, key) => key === 'train' ? model.train.join(', ') : key === 'training' ? model.training?.excluded_rate ?? -1 : model[key] ?? '';
const orderedModels = computed(() => props.models.filter(model => [model.name, model.version, model.train.join(' ')].join(' ').toLowerCase().includes(search.value.toLowerCase()))
    .sort((a, b) => String(modelValue(a, sort.value)).localeCompare(String(modelValue(b, sort.value)), undefined, { numeric: true }) * (descending.value ? -1 : 1)));
const streams = computed(() => [...(props.pregame?.workstreams ?? [])].sort((a, b) => String(a[streamSort.value] ?? '').localeCompare(String(b[streamSort.value] ?? ''), undefined, { numeric: true }) * (streamDescending.value ? -1 : 1)));
const pilotModel = computed(() => props.models.find(model => model.id === props.pregame?.source?.id)
    ?? (props.pregame?.source ? { ...props.pregame.source, automation: props.pregame.automation } : null));
const pilot = computed(() => props.pregame?.source);
const run = computed(() => props.pregame?.run);
const managedGoals = computed(() => props.pregame?.workstream_goals?.available === true);
const goalAreas = computed(() => props.pregame?.workstream_goals?.areas ?? []);
const selectedArea = computed(() => goalAreas.value.find(area => area.key === selectedAreaKey.value));
const active = computed(() => goalAreas.value.some(area => ['working', 'queued', 'assessing', 'outdated'].includes(area.state)) || (props.pregame?.worker_activity?.workers ?? []).some(worker => worker.state === 'running') || props.models.some(model => model.status === 'running' || ['queued', 'running', 'pausing'].includes(model.pregame?.status)) || ['queued', 'running', 'pausing'].includes(run.value?.status));
const lastExecutions = ref(null);
watch(() => [props.pregame?.source?.id, props.pregame?.worker_activity], ([source, activity], previous) => {
    if (source !== previous?.[0]) lastExecutions.value = null;
    if (source && activity?.available) lastExecutions.value = activity;
}, { immediate: true });
const executionAvailable = computed(() => managedGoals.value && !props.pregame?.workstream_goals?.legacy_active || props.pregame?.worker_activity?.available === true);
const executions = computed(() => {
    const latest = new Map();
    const observed = managedGoals.value ? goalAreas.value.flatMap(area => area.workers)
        .concat(props.pregame?.workstream_goals?.legacy_active ? (lastExecutions.value?.workers ?? []).map(worker => ({ ...worker, pool: 'legacy' })) : [])
        : lastExecutions.value?.workers ?? [];
    for (const worker of observed) {
        const key = `${worker.server}:${worker.pid}`;
        const previous = latest.get(key);
        if (!previous || Date.parse(worker.started_at) > Date.parse(previous.started_at)
            || (worker.started_at === previous.started_at && Number(worker.attempt) >= Number(previous.attempt))) latest.set(key, worker);
    }
    return [...latest.values()].map(worker => executionAvailable.value ? worker : { ...worker, state: 'unknown' });
});
const runningWorkers = computed(() => executionAvailable.value ? executions.value.filter(worker => worker.state === 'running').length : null);
const runActive = computed(() => ['queued', 'running', 'pausing'].includes(run.value?.status));
const executionPool = worker => worker.pool ?? (['CalculateNhlPregameLeagueBatchJob', 'CalculateNhlPregameContextRunDayLeagueAveragesJob'].includes(worker.job) ? 'league'
    : ['BuildNhlPregameContextGameJob', 'BuildNhlPregameContextDayJob', 'BuildNhlPregameContextDayBatchJob', 'CalculateNhlPregameContextLeagueMetricsJob', 'CompareNhlPregameContextRunDayJob'].includes(worker.job) ? 'context' : 'coordination');
const poolRows = computed(() => {
    if (!pilot.value) return [];
    if (managedGoals.value) {
        const rows = goalAreas.value.map(area => ({ ...area, description: area.goal, state: area.state === 'complete' ? 'idle' : area.state,
            workers: executions.value.filter(worker => executionPool(worker) === area.key),
            running: executions.value.filter(worker => executionPool(worker) === area.key && worker.state === 'running').length,
            complete: area.completed, categories: streams.value.filter(stream => stream.key === area.key),
            progressPercent: area.total > 0 ? 100 * area.completed / area.total : null,
            showProgress: area.remaining > 0 && area.total > 0, managed: true }));
        const legacy = executions.value.filter(worker => executionPool(worker) === 'legacy');
        if (legacy.length) rows.push({ key: 'legacy', name: 'Existing maintenance', description: 'Finishing its original checkpoints before area scheduling starts',
            workers: legacy, running: legacy.filter(worker => worker.state === 'running').length, state: 'working', categories: [], managed: false });
        return rows;
    }
    const rows = [{ key: 'league', name: 'League averages', description: 'League baselines and reference populations' },
        { key: 'context', name: 'Pregame context', description: 'Players · Teams · Goalies · Rankings · Travel' }];
    if (executions.value.some(worker => executionPool(worker) === 'coordination')) rows.push({ key: 'coordination', name: 'Coordination', description: 'Select games and schedule the next tasks' });
    return rows.map(row => {
        const workers = executions.value.filter(worker => executionPool(worker) === row.key);
        const running = workers.filter(worker => worker.state === 'running').length;
        const complete = Number(run.value?.stages?.[row.key] ?? 0);
        const total = Number(run.value?.stages?.total ?? 0);
        const claims = (props.pregame?.workers ?? []).filter(claim => claim.stage === row.key);
        let state = 'idle';
        if (running > 0) state = 'working';
        else if (!executionAvailable.value || workers.some(worker => ['unknown', 'unconfirmed'].includes(worker.state))) state = 'unknown';
        else if (run.value?.status === 'paused') state = 'paused';
        else if (run.value?.status === 'failed' || claims.some(claim => claim.state === 'expired')) state = 'blocked';
        else if (run.value?.status === 'pausing') state = 'pausing';
        else if (runActive.value && (!total || complete < total)) state = 'queued';
        else if ((row.key === 'context' && run.value?.excluded > 0) || (row.key === 'league' && run.value?.source_blocked_days > 0)) state = 'blocked';
        return { ...row, workers, running, claims, state, complete, total,
            categories: streams.value.filter(stream => stream.stage === row.key),
            showProgress: row.key !== 'coordination' && runActive.value && total > 0 && complete < total };
    });
});
const elapsed = (start, end = null) => {
    if (!start || !Number.isFinite(Date.parse(start))) return '—';
    const seconds = Math.max(0, Math.floor(((end ? Date.parse(end) : Date.now()) - Date.parse(start)) / 1000));
    if (!Number.isFinite(seconds)) return '—';
    if (seconds >= 3600) return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m`;
    if (seconds >= 60) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    return `${seconds}s`;
};
const stagePercent = stage => run.value?.stages?.total > 0 ? Math.min(100, 100 * Number(run.value.stages[stage] ?? 0) / run.value.stages.total) : null;
const canUpdate = computed(() => pilot.value && props.pregame?.workstream_goals?.available !== false && !['queued', 'running', 'pausing', 'paused'].includes(run.value?.status));
const issueAction = computed(() => ['paused', 'pausing'].includes(run.value?.status) || run.value?.retryable ? 'resume' : 'repair');
const issueActionLabel = computed(() => issueAction.value === 'resume' ? 'Continue repair' : runActive.value ? 'Repair in progress' : repairLabel.value);
const canRepairIssue = computed(() => pilot.value && (props.pregame?.issues?.length ?? 0) > 0
    && (issueAction.value === 'resume' || canUpdate.value));
const heroCards = computed(() => [
    { label: 'Total Models', icon: 'models', value: props.headerSummary.total ?? props.models.length },
    { label: 'Research', icon: 'research', value: props.headerSummary.research, help: 'Models with a Test season in the current registry filter' },
    { label: 'Production', icon: 'production', value: props.headerSummary.production, help: 'Models without a Test season in the current registry filter' },
    { label: 'Best Test Corr.', icon: 'check', value: props.headerSummary.best_test_correlation == null ? null : `${props.headerSummary.best_test_correlation}%`, help: 'Unavailable until a comparable correlation is recorded' },
    { label: 'Avg. Lift', icon: 'trend', value: props.headerSummary.average_lift == null ? null : `${props.headerSummary.average_lift}%`, sublabel: '(vs. Baseline)', help: 'Unavailable until a comparable baseline lift is recorded' },
]);
const testCoverage = computed(() => props.pregame?.coverage?.find(item => String(item.season) === String(pilot.value?.test)));
const seasonCoverage = computed(() => (props.pregame?.coverage ?? []).map(season => ({ ...season,
    scope: season.scope || (String(season.season) === String(pilot.value?.test) ? 'Test' : 'Train'),
    season_label: season.season_label ?? `${String(season.season).slice(0, 4)}–${String(season.season).slice(6, 8)}`,
    missing: Math.max(0, Number(season.games) - Number(season.covered)),
})));
const coverageGaps = computed(() => seasonCoverage.value.filter(season => season.missing > 0));
const healthCoverage = computed(() => seasonCoverage.value.filter(season => season.missing > 0 || season.scope.includes('Test')));
const repairLabel = computed(() => {
    if (!coverageGaps.value.length) return 'Repair missing context';
    const missing = coverageGaps.value.reduce((total, season) => total + season.missing, 0);
    const roles = ['Train', 'Test'].filter(role => coverageGaps.value.some(season => season.scope.includes(role)));
    return `Repair ${number(missing)} missing ${roles.join(' + ')} game${missing === 1 ? '' : 's'}`;
});
const claimLimit = computed(() => Number(props.pregame?.limits?.league ?? 0) + Number(props.pregame?.limits?.context ?? 0));
const assignedLimit = computed(() => managedGoals.value ? goalAreas.value.reduce((total, area) => total + area.worker_limit, 0) : claimLimit.value);
const liveClaims = computed(() => managedGoals.value
    ? goalAreas.value.reduce((total, area) => total + Number(area.counts.claimed ?? 0) + Number(area.counts.working ?? 0), 0)
    : (props.pregame?.workers ?? []).filter(item => item.state === 'claimed').length);
const claimUse = computed(() => assignedLimit.value ? Math.round(100 * (managedGoals.value ? goalAreas.value.reduce((total, area) => total + area.workers.length, 0) : liveClaims.value) / assignedLimit.value) : null);
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
const definitions = [
    ['Player context', 'Prior player results across last game, L5, L10, L20, last season, season-to-date and model Train. Strength and measure remain explicit.'],
    ['Team context', 'Each team’s own history, separate opponent history and target-game orientation.'],
    ['League comparisons', 'League average, population, rank and signed/absolute/percentage differences for the same metric, window and strength.'],
    ['Freshness', 'The statistical source cutoff, not the time a historical snapshot was rebuilt. Test coverage is not readiness for today’s production games.'],
    ['Coverage', 'The season counts below verify both team snapshots. They do not certify every player, goalie, source or metric.'],
    ['Worker allocations', 'Maximum simultaneous tasks in each enrolled area. Legacy runs retain league/context date claims. These controls neither provision nor stop Horizon worker processes.'],
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
    if (['build', 'stop'].includes(action) && confirmation.value !== action) { openPanel('maintenance'); confirmation.value = action; return; }
    confirmation.value = null;
    mutate('post', action === 'repair' ? (props.pregame.repair_url ?? `${base}/pregame/repair`) : `${base}/${pilot.value.id}/context/${action}`);
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
function workerPanel() {
    Object.assign(limits, props.pregame?.limits ?? {});
    for (const area of goalAreas.value) areaLimits[area.key] = area.worker_limit;
    openPanel('workers');
}
function allocateArea(area, value) {
    if (busy.value) return;
    const areas = Object.fromEntries(goalAreas.value.map(item => [item.key, item.key === area.key ? Number(value) : item.worker_limit]));
    mutate('put', props.pregame.goals_url, { areas });
}
function investigateArea(area) { selectedAreaKey.value = area.key; openPanel('area'); }
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
const panelTitle = computed(() => ({ create: 'Create Model', actions: panelModel.value?.name ?? 'Model actions', automation: 'Automatic Pregame Updates', workers: 'Worker allocation', area: selectedArea.value?.name ?? 'Area details', repair: 'Pregame issues', maintenance: 'Maintenance options', inspector: 'Context inspector', definitions: 'Metric definitions', history: 'Maintenance history' }[panel.value] ?? 'Pregame'));
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
                    <section aria-label="Current pregame update" class="mb-3 rounded-md border border-[#2b4358] bg-[#101d29] px-3 py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2"><h3 class="text-[12px] font-bold">{{ runActive ? 'Current update' : 'Last maintenance' }} <span v-if="run" class="text-[10px] font-normal text-slate-400">#{{ run.id }}</span></h3><span v-if="run" class="rounded-full px-2 py-0.5 text-[9px] font-semibold" :class="tone(run.status)">{{ label(run.status) }}</span><span v-if="run" data-update-percent class="text-[11px] text-slate-300">{{ run.progress_pct == null ? (runActive ? 'Selecting games…' : 'Selection unavailable') : `${number(run.progress_pct)}% processed` }}</span><span class="ml-auto text-[10px] text-slate-400">Executing workers: <strong class="text-sky-300">{{ runningWorkers ?? '—' }}</strong></span></div>
                        <div v-if="run" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-400"><span>{{ run.total > 0 || run.status === 'completed' ? `${number(run.processed)} / ${number(run.total)} games processed` : 'Preparing game selection' }}</span><span>{{ number(run.completed) }} updated</span><span class="text-amber-300">{{ number(run.excluded) }} excluded</span><span class="text-red-300">{{ number(run.failed) }} failed</span><span class="ml-auto">Elapsed {{ elapsed(run.created_at, ['completed','failed','stopped'].includes(run.status) ? run.updated_at : null) }}</span></div>
                        <p v-if="run?.source_blocked_days" class="mt-2 text-[10px] text-amber-300">{{ number(run.source_blocked_days) }} days skipped because all selected games lack required sources. See source blockers in View issues.</p><p v-if="run?.up_to_date" class="mt-2 text-[10px] text-slate-400">Already up to date; no missing games were selected.</p><p class="mt-1 text-[9px] text-slate-500">Processed includes excluded and failed games. Current activity is shown in the workstream rows below.</p>
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
                                <div class="border-[#293b4b] sm:border-l sm:pl-3"><p class="flex items-center gap-1 text-[9px] text-slate-400"><WorkspaceIcon name="workers" class="size-4" />Active Claims</p><p data-active-claims class="mt-1 text-[13px] font-bold">{{ liveClaims }} / {{ assignedLimit || '—' }}</p><p class="text-[8px] text-slate-500">Not OS workers</p></div>
                                <div class="border-[#293b4b] sm:border-l sm:pl-3"><p class="flex items-center gap-1 text-[9px] text-slate-400"><WorkspaceIcon name="alert" class="size-4 text-[#f7c548]" />Outstanding</p><button type="button" :aria-label="`View ${pregame.issues.length} outstanding pregame issues`" aria-haspopup="dialog" aria-controls="sat-pregame-issues-panel" :aria-expanded="panel === 'repair'" class="mt-1 inline-block rounded px-1.5 text-[12px] font-bold transition-[filter] duration-150 hover:brightness-125 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 motion-reduce:transition-none" :class="pregame.issues.length ? 'bg-red-500/20 text-red-300' : 'bg-emerald-500/20 text-emerald-300'" @click="openPanel('repair')">{{ pregame.issues.length }}</button></div>
                                <div v-if="seasonCoverage.length" data-coverage-diagnosis class="h-14 overflow-y-auto border-t border-[#293b4b] pt-2 text-[10px] sm:col-span-5"><p v-for="season in healthCoverage" :key="season.season" class="leading-5" :class="season.missing ? 'text-amber-200' : 'text-slate-400'"><span class="font-semibold">{{ season.scope }} · {{ season.season_label }}</span> — {{ season.missing ? `${number(season.missing)} game${season.missing === 1 ? '' : 's'} missing pregame context` : `all ${number(season.games)} games covered` }}</p></div>
                            </section>

                            <section aria-label="Pregame worker activity" class="overflow-hidden rounded-md border border-[#2b4358] bg-gradient-to-b from-[#101e2b] to-[#0a151e]">
                                <div class="flex flex-wrap items-center gap-3 border-b border-[#293b4b] bg-[#172735] px-3 py-2"><h3 class="text-[12px] font-bold">Workstreams &amp; active workers</h3><span class="text-[10px] text-sky-300">{{ runningWorkers ?? '—' }} {{ runningWorkers === 1 ? 'worker' : 'workers' }} executing</span><button type="button" :disabled="!pilot" class="ml-auto text-[10px] text-[#65b5ef] disabled:opacity-40" @click="workerPanel">Manage allocation →</button></div>
                                <p class="flex h-12 items-center overflow-hidden border-b border-[#293b4b] px-3 py-2 text-[10px] text-slate-400">{{ executionAvailable ? 'Workers appear under the task they are executing. Idle rows have no work running.' : 'Worker activity is unavailable. Previous observations are marked Unknown.' }}</p>
                                <p v-if="pregame.workstream_goals?.available === false" class="border-b border-slate-700 p-3 text-xs text-amber-200">Migration required to enable independent area goals and worker allocations.</p><p v-if="pregame.workstream_goals?.legacy_active" class="border-b border-slate-700 p-3 text-xs text-amber-200">Existing maintenance is finishing its original checkpoints. Area workers will start when it is idle.</p><div data-workstream-list class="overflow-y-auto" :class="managedGoals ? 'h-[540px]' : 'h-[360px]'">
                                    <article v-for="pool in poolRows" :key="pool.key" :data-workstream="pool.key" class="border-b border-[#293b4b] p-3">
                                        <div class="flex flex-wrap items-start gap-3"><WorkspaceIcon :name="pool.key === 'league' ? 'league' : 'workers'" class="size-5 shrink-0 text-slate-300" /><div class="min-w-0 flex-1"><h4 class="text-[12px] font-semibold">{{ pool.name }}</h4><p class="mt-1 text-[9px] text-slate-400">{{ pool.description }}</p></div><span class="rounded-full px-2 py-0.5 text-[10px]" :class="tone(pool.state)">{{ label(pool.state) }}</span><span class="text-[10px] text-slate-400">{{ executionAvailable ? pool.running : '—' }} executing</span><label v-if="pool.managed" class="flex items-center gap-1 text-[9px] text-slate-400">Assigned<input type="number" :aria-label="`Workers for ${pool.name}`" min="0" max="25" :value="pool.worker_limit" :disabled="busy" class="w-12 rounded border-slate-600 bg-[#101d29] px-1 py-0.5 text-[10px] text-white disabled:opacity-40" @change="allocateArea(pool, $event.target.value)" /></label><button v-if="pool.managed" type="button" class="text-[9px] text-sky-300" :aria-label="`Investigate ${pool.name}`" @click="investigateArea(pool)">Investigate</button></div>
                                        <div v-if="pool.showProgress" class="mt-3"><div class="mb-1 flex justify-between text-[9px] text-slate-400"><span>{{ pool.complete }} / {{ pool.total }} {{ pool.managed ? 'tasks completed' : 'dates processed' }}</span><span>{{ (pool.managed ? pool.progressPercent : stagePercent(pool.key))?.toFixed(1) }}%</span></div><progress :aria-label="`${pool.name} stage progress`" :value="(pool.managed ? pool.progressPercent : stagePercent(pool.key)) ?? undefined" max="100" class="block h-1 w-full accent-sky-500" /></div>
                                        <p v-if="!pool.workers.length" class="mt-2 text-[10px] text-slate-400">{{ pool.managed && pool.state === 'idle' ? 'Idle - goal satisfied. All selected tasks are complete.' : pool.state === 'idle' ? 'Idle - no work running. Saved data remains available.' : pool.state === 'unassessed' ? 'Not assessed yet. Repair or assign workers to assess and fill missing context.' : pool.state === 'assessing' ? 'Checking sources and selecting missing work.' : pool.state === 'outdated' ? 'Source data changed. Follow-up maintenance is being scheduled.' : pool.state === 'waiting' ? (pool.key === 'rankings' ? 'Waiting for completed metrics and matching league references.' : 'Waiting for same-game team context.') : pool.state === 'queued' ? 'Queued - waiting for an assigned worker.' : pool.state === 'blocked' ? (pool.managed ? 'Blocked - investigate this area for its source or task error.' : 'Blocked - see View issues for the cause.') : pool.state === 'paused' ? 'Paused - increase this area allocation or resume maintenance.' : pool.state === 'unknown' ? 'Execution status is unavailable.' : pool.state === 'stopped' ? 'Stopped - repair to start unfinished work again.' : 'Already-claimed work is finishing.' }}</p>
                                        <div v-for="worker in pool.workers" :key="`${worker.job_id}-${worker.server}-${worker.pid}-${worker.attempt}`" data-pregame-execution class="mt-2 rounded border border-[#2b4358] bg-[#0c1822] p-2.5">
                                            <div class="flex flex-wrap items-start gap-2"><span class="mt-1 size-1.5 rounded-full" :class="worker.state === 'running' ? 'bg-sky-400' : 'bg-amber-400'" /><div class="min-w-0 flex-1"><p class="text-[11px] font-semibold text-slate-200">{{ worker.task }}</p><p class="mt-1 text-[9px] text-slate-400">{{ worker.game_date ?? 'Run coordination' }}<span v-if="worker.game_id"> · Game {{ worker.game_id }}</span> · Run #{{ worker.run_id }}</p></div><span class="text-[10px] text-slate-400">{{ elapsed(worker.started_at) }}</span><span class="rounded px-1.5 text-[9px]" :class="tone(worker.state)">{{ label(worker.state) }}</span></div>
                                            <p class="mt-2 text-[9px] text-slate-500">{{ worker.server }} · PID {{ worker.pid }} · {{ worker.queue }}</p><div v-if="worker.state === 'running' && worker.work_progress" class="mt-2"><p class="text-[9px] text-slate-300">{{ worker.work_progress.label }}</p><progress v-if="worker.work_progress.percent != null" :value="worker.work_progress.percent" max="100" aria-label="Worker date progress" class="mt-1 block h-1 w-full accent-sky-500" /></div>
                                        </div>
                                        <p v-if="pool.managed" class="mt-2 text-[9px] text-slate-500">{{ pool.completed }} completed &middot; {{ pool.remaining }} remaining &middot; {{ Number(pool.counts.blocked ?? 0) + Number(pool.counts.failed ?? 0) }} blocked</p><div v-if="pool.categories.length" class="mt-3"><button type="button" :aria-label="`Expand ${pool.name}`" :aria-expanded="expanded === pool.key" :aria-controls="`workstream-${pool.key}`" class="flex items-center gap-1 text-[9px] text-[#80acd0]" @click="expanded = expanded === pool.key ? null : pool.key"><svg aria-hidden="true" viewBox="0 0 20 20" class="size-2.5 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="expanded === pool.key ? 'rotate-180' : ''" fill="currentColor"><path d="m5 7 5 5 5-5z" /></svg>Saved data &amp; inspection</button><div :id="`workstream-${pool.key}`" :aria-hidden="expanded !== pool.key" :inert="expanded !== pool.key" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded === pool.key ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"><div class="min-h-0 overflow-hidden"><p class="mt-2 text-[9px] text-slate-400">{{ pool.key === 'context' ? 'Player, team, goalie, rankings and travel tasks share this context pool.' : 'League references support context comparisons.' }} Production context remains independent.</p><button v-for="category in pool.categories" :key="category.key" type="button" :aria-label="`Inspect ${category.name}`" class="mr-2 mt-2 rounded border border-[#334c63] px-2 py-1 text-[9px] text-sky-300" @click="inspector.entity = ['players','goalies'].includes(category.key) ? 'player' : 'team'; openPanel('inspector')">{{ category.name }} · {{ number(category.snapshot_count) }} saved rows</button></div></div></div>
                                    </article>
                                    <p v-if="!poolRows.length" class="p-6 text-xs text-slate-400">No pilot workstreams are available.</p>
                                </div>
                                <p class="h-5 px-3 text-[8px] text-slate-500"><span v-if="pregame.worker_activity?.truncated">Showing the first 200 observed executions.</span></p>
                            </section>
                        </div>

                        <aside class="space-y-3">
                            <section class="space-y-2">
                                <div class="flex gap-2"><button type="button" :disabled="busy || !canUpdate" :aria-label="repairLabel" class="flex min-h-8 flex-1 items-center justify-center gap-2 rounded-md border border-[#4b91fa] bg-gradient-to-b from-[#2878fa] to-[#0754d4] px-3 py-2 text-[11px] font-semibold text-white shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.15)] hover:brightness-110 disabled:opacity-40" @click="pilotAction('repair')"><span class="text-lg leading-none">↻</span>{{ busy ? 'Submitting…' : repairLabel }}</button><button type="button" :disabled="!pilot" aria-label="Maintenance options" class="rounded-md border border-[#334c63] bg-gradient-to-b from-[#1b2c3d] to-[#0b1722] px-3 text-sm disabled:opacity-40" @click="openPanel('maintenance')">⋯</button></div>
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
                            <section aria-label="Queue claims" class="rounded-md border border-[#2b4358] bg-[#101d29] p-3"><div class="flex items-center gap-2"><h3 class="text-[12px] font-bold">Queue &amp; history</h3><button type="button" class="ml-auto text-[9px] text-[#65b5ef]" @click="openPanel('history')">View history →</button></div><p class="mt-2 text-[10px] text-slate-400">{{ managedGoals ? goalAreas.reduce((total, area) => total + Number(area.counts.claimed ?? 0), 0) : pregame.workers.length }} {{ managedGoals ? 'tasks queued for assigned workers' : 'date claims - queued or executing' }}</p><p class="mt-1 text-[9px] text-slate-500">{{ managedGoals ? 'Assigned area capacity' : 'Configured capacity' }}: {{ assignedLimit }} {{ managedGoals ? 'task slots' : 'simultaneous date claims' }}. Actual executing workers are listed above.</p><p v-if="!managedGoals && !pregame.workers.length" class="mt-3 text-[10px] text-slate-400">No date jobs are claimed. Saved data can remain usable while processing is idle.</p><details v-if="!managedGoals && pregame.workers.length" class="mt-3 text-[9px] text-[#80acd0]"><summary class="cursor-pointer">Inspect date claims</summary><div v-for="worker in pregame.workers" :key="worker.key" class="mt-2 flex justify-between gap-2"><span>{{ label(worker.stage) }} · {{ worker.date }}</span><span :class="tone(worker.state)">{{ label(worker.state) }}</span></div></details></section>
                            <section class="rounded-md border border-[#2b4358] bg-gradient-to-b from-[#142330] to-[#0a151e] p-3"><div class="flex items-center gap-2"><WorkspaceIcon name="workers" class="size-4 text-slate-300" /><h3 class="text-[12px] font-bold">Worker Allocation</h3><button type="button" :disabled="!pilot" aria-label="Edit claim allocation" class="ml-auto text-[9px] text-[#65b5ef] disabled:opacity-30" @click="workerPanel">Manage →</button></div><div class="mt-3 flex items-center gap-4"><div class="relative size-[112px] shrink-0"><svg viewBox="0 0 100 100" class="size-full" role="img" :aria-label="`${claimUse ?? 0}% of configured claim capacity in use`"><circle cx="50" cy="50" r="38" fill="none" stroke="#213444" stroke-width="10" /><circle cx="50" cy="50" r="38" fill="none" stroke="#22c575" stroke-width="10" :stroke-dasharray="`${Math.min(100, claimUse ?? 0) * 2.3876} 238.76`" transform="rotate(-90 50 50)" /></svg><div class="absolute inset-0 flex flex-col items-center justify-center"><strong class="text-xl">{{ claimUse == null ? '—' : `${claimUse}%` }}</strong><span class="text-[9px] text-slate-400">{{ managedGoals ? 'Assignment use' : 'Claim use' }}</span></div></div><div class="min-w-0 flex-1 space-y-2 text-[9px]"><p v-for="pool in (managedGoals ? goalAreas.map(area => area.key) : ['league','context'])" :key="pool" class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="size-2 rounded-full" :class="pool === 'league' ? 'bg-emerald-400' : 'bg-sky-400'" />{{ label(pool) }}</span><strong>{{ managedGoals ? goalAreas.find(area => area.key === pool)?.worker_limit ?? '—' : pregame.limits?.[pool] ?? '—' }}</strong></p><p class="border-t border-[#293b4b] pt-2 text-[8px] leading-relaxed text-slate-500">{{ managedGoals ? 'Assigned task capacity across six areas. Executing workers are shown in their area rows.' : 'Configured date claims, not physical worker processes. Context is shared across five workstreams.' }}</p></div></div></section>
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
                    <form v-if="panel === 'workers'" class="space-y-5" @submit.prevent="managedGoals ? mutate('put', pregame.goals_url, { areas: { ...areaLimits } }) : mutate('put', pregame.workers_url, { ...limits })">
                        <template v-if="managedGoals"><p class="text-xs leading-relaxed text-slate-400">Assign concurrent task slots to each area. Zero pauses new assignments. Claimed tasks finish their current page; changed limits apply to subsequent assignments.</p><label v-for="area in goalAreas" :key="area.key" class="block text-sm">{{ area.name }} workers<input v-model.number="areaLimits[area.key]" :aria-label="`${area.name} allocation`" type="number" min="0" max="25" required class="mt-2 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label><p class="text-xs text-slate-500">Maximum combined allocation: 25. These are task limits on existing Horizon workers.</p></template>
                        <template v-else><p class="text-xs leading-relaxed text-slate-400">Existing maintenance uses league/context date limits. This does not add Horizon workers.</p><label v-for="key in ['league','context']" :key="key" class="block text-sm">{{ label(key) }} claims<input v-model.number="limits[key]" type="number" min="1" max="25" required class="mt-2 w-full rounded-lg border-slate-700 bg-[#10151b] text-sm" /></label></template><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-4 py-2 text-sm disabled:opacity-40">Save allocation</button>
                    </form>
                    <section v-if="panel === 'area' && selectedArea" class="space-y-4 text-sm"><p class="text-slate-300">{{ selectedArea.goal }}</p><p class="text-xs text-slate-400">{{ label(selectedArea.state) }} &middot; {{ selectedArea.completed }} completed &middot; {{ selectedArea.remaining }} remaining &middot; {{ selectedArea.workers.length }} executing / {{ selectedArea.worker_limit }} assigned</p><p v-if="selectedArea.last_error" class="text-xs text-amber-200">{{ selectedArea.last_error }}</p><p v-if="!selectedArea.enrolled" class="text-xs text-amber-200">This area has not been assessed yet. Repair checks existing context before scheduling gaps.</p><p v-if="selectedArea.state === 'waiting'" class="text-xs text-amber-200">{{ selectedArea.key === 'rankings' ? 'Requires completed metrics and league references. Check the other area allocations.' : 'Requires same-game team context. Check the Teams allocation.' }}</p><article v-for="blocker in selectedArea.blockers" :key="blocker.id" class="rounded border border-amber-500/30 p-3 text-xs"><p class="font-semibold">{{ blocker.game_date ?? '' }} Task #{{ blocker.id }}<span v-if="blocker.nhl_game_id"> &middot; Game {{ blocker.nhl_game_id }}</span></p><p class="mt-1 text-slate-400">{{ blocker.last_error }}</p></article><p v-if="selectedArea.blockers_truncated" class="text-[10px] text-slate-500">Showing the first 50 blockers.</p><p v-if="selectedArea.state === 'complete'" class="text-xs text-emerald-300">Goal satisfied. No work is currently required.</p><button type="button" :disabled="busy || !pilot || ['paused','pausing'].includes(run?.status) || (!canUpdate && !selectedArea.blockers.length)" class="w-full rounded bg-sky-600 p-3 text-sm disabled:opacity-40" @click="mutate('post', pregame.repair_url, { area: selectedArea.key })">Assess and repair {{ selectedArea.name }}</button></section>
                    <section v-if="panel === 'repair'" class="space-y-4 text-sm"><p v-if="!pregame?.issues?.length" class="text-xs text-slate-400">No tracked pregame issues.</p><article v-for="issue in pregame?.issues ?? []" :key="issue.title" class="rounded border border-amber-500/20 p-3"><h3 class="text-xs font-semibold text-amber-200">{{ issue.title }}</h3><p class="mt-1 text-[11px] leading-relaxed text-slate-400">{{ issue.detail }}</p></article><section v-if="pregame?.blocked_games?.length" aria-label="Source blockers" class="space-y-2"><h3 class="text-xs font-semibold text-amber-200">Blocked games · latest run</h3><div class="max-h-64 overflow-y-auto"><article v-for="game in pregame.blocked_games" :key="game.nhl_game_id" class="border-b border-slate-700 py-2 text-[11px]"><p class="font-semibold text-slate-200">{{ game.game_date }} · {{ game.nhl_game_id }}</p><p class="mt-1 text-slate-400">{{ game.last_error ?? 'Source readiness unavailable; inspect this game before retrying.' }}</p></article></div><p v-if="pregame.blocked_games_truncated" class="text-[10px] text-slate-500">Showing the first 100 blocked games.</p></section><p class="text-xs leading-relaxed text-slate-400">Repair fills missing context. Games missing source data remain listed with their cause until those sources are imported.</p><button type="button" :disabled="busy || !canRepairIssue" class="block w-full rounded-lg bg-sky-600 p-3 text-sm disabled:opacity-40" @click="pilotAction(issueAction)">{{ busy ? 'Submitting…' : issueActionLabel }}</button></section>
                    <section v-if="panel === 'maintenance'" class="space-y-4 text-sm"><p class="text-xs text-slate-400">General maintenance for Sep2026. View issues provides the scoped repair action.</p><p v-if="confirmation" class="rounded border border-amber-500/30 p-3 text-xs text-amber-200">{{ confirmation === 'stop' ? 'Stop unfinished work while preserving committed context?' : 'Recalculate all Sep2026 Train and Test context?' }}</p><button type="button" :disabled="busy || !canUpdate" class="block w-full rounded border border-slate-700 p-3 text-sm disabled:opacity-40" @click="pilotAction('update')">Update Test / prediction season only</button><button type="button" :disabled="busy || !run?.retryable" class="block w-full rounded border border-slate-700 p-3 text-sm disabled:opacity-40" @click="pilotAction('resume')">Retry unfinished</button><button v-if="confirmation === 'stop'" type="button" :disabled="busy" class="block w-full rounded border border-red-600/50 p-3 text-red-300 disabled:opacity-40" @click="pilotAction('stop')">Confirm stop</button><button v-else type="button" :disabled="busy || !canUpdate" class="block w-full rounded border border-amber-600/40 p-3 text-amber-200 disabled:opacity-40" @click="pilotAction('build')">{{ confirmation === 'build' ? 'Confirm full rebuild' : 'Full Train + Test rebuild' }}</button></section>
                    <section v-if="panel === 'definitions'" class="space-y-5"><article v-for="[name,description] in definitions" :key="name"><h3 class="text-sm font-medium">{{ name }}</h3><p class="mt-2 text-xs leading-relaxed text-slate-400">{{ description }}</p></article></section>
                    <section v-if="panel === 'history'" class="space-y-3"><p v-if="!pregame?.history?.length" class="text-sm text-slate-400">No Sep2026 maintenance runs yet.</p><article v-for="item in pregame?.history ?? []" :key="item.id" class="rounded-lg border border-slate-700 p-3 text-xs"><div class="flex justify-between"><strong>Run #{{ item.id }}</strong><span :class="tone(item.status)" class="rounded-full px-2">{{ label(item.status) }}</span></div><p class="mt-2 text-slate-400">{{ item.completed }} / {{ item.total }} games · {{ item.excluded }} excluded · {{ item.failed }} failed</p><p class="mt-1 text-[10px] text-slate-500">{{ time(item.updated_at) }}</p><p v-if="item.error" class="mt-2 text-red-300">{{ item.error }}</p></article></section>
                    <section v-if="panel === 'inspector'" class="space-y-4"><form class="flex gap-2" @submit.prevent="inspect"><input v-model="inspector.game_id" type="number" min="1" required aria-label="NHL game ID" placeholder="NHL game ID" class="min-w-0 flex-1 rounded-lg border-slate-700 bg-[#10151b] text-xs" /><select v-model="inspector.entity" aria-label="Context entity" class="rounded-lg border-slate-700 bg-[#10151b] text-xs"><option value="team">Teams</option><option value="player">Players</option></select><button type="submit" :disabled="busy || !pilot" class="rounded-lg bg-sky-600 px-3 text-xs disabled:opacity-40">{{ busy ? 'Reading…' : 'Inspect' }}</button></form><template v-if="inspectResult"><p class="text-xs text-slate-400">{{ inspectResult.contexts.length }} contexts · Source model #{{ inspectResult.source_model_id }} · {{ inspectResult.truncated ? 'First 200 metric rows' : `${inspectResult.metrics.length} metric rows` }}</p><p v-if="!inspectResult.contexts.length" class="text-xs text-amber-300">No matching current-version Sep2026 context exists for this game.</p><div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-[10px]"><thead class="text-slate-500"><tr><th v-for="[key,title] in [['context','Entity'],['metric_key','Metric / unit'],['window_key','Window / strength'],['value','Value'],['league_average','League'],['signed_difference','Delta'],['percentage_difference','Delta %'],['league_rank','Rank'],['sample_games','Sample GP']]" :key="key" class="p-2"><button type="button" @click="orderMetric(key)">{{ title }} {{ metricSort === key ? metricDescending ? '↓' : '↑' : '' }}</button></th></tr></thead><tbody><tr v-for="metric in metricRows" :key="metric.id" class="border-t border-slate-800"><td class="p-2">{{ contextLabel(metric) }}</td><td class="p-2">{{ metric.metric_key }} / {{ metric.measure_key }}</td><td class="p-2">{{ metric.window_key }} / {{ metric.strength }}</td><td class="p-2">{{ metric.value ?? '—' }}</td><td class="p-2">{{ metric.league_average ?? '—' }}</td><td class="p-2">{{ metric.signed_difference ?? '—' }}</td><td class="p-2">{{ metric.percentage_difference == null ? '—' : `${Number(metric.percentage_difference).toFixed(2)}%` }}</td><td class="p-2">{{ metric.league_rank ?? '—' }} / {{ metric.rank_population ?? '—' }}</td><td class="p-2">{{ metric.sample_games ?? '—' }}</td></tr></tbody></table></div></template></section>
                </div>
            </DialogContent></Transition>
        </DialogPortal></DialogRoot>
    </main>
</template>
