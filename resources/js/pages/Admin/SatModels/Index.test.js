/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import Workspace from './Index.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), patch: vi.fn(), get: vi.fn(), reload: vi.fn(), navigate: vi.fn(), notify: vi.fn() }));
vi.mock('axios', () => ({ default: { post: mocks.post, put: mocks.put, patch: mocks.patch, get: mocks.get } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { reload: mocks.reload, get: mocks.navigate } }));
vi.mock('reka-ui', async () => {
    const { h } = await import('vue');
    const wrap = { setup: (_, { slots, attrs }) => () => h('div', attrs, slots.default?.()) };
    return { DialogRoot: { props: ['open'], setup: (props, { slots }) => () => props.open ? h('div', slots.default?.()) : null }, DialogPortal: wrap, DialogOverlay: wrap, DialogContent: wrap, DialogTitle: wrap, DialogDescription: wrap };
});
let app;
let host;
let props;
const base = '/admin/nhl-sat-models';
const model = (extra = {}) => ({ id: 1, name: 'Sep2026', version: 'sat_v1', train: ['20242025'], test: '20252026', projection: '20252026', status: 'complete', notes: '', updated_at: '2026-11-26T15:00:00Z', training: { excluded_rate: .1, excluded: 10, total: 100 }, build: {}, outdated: [], pregame: null, automation: { enabled: false, settings_url: `${base}/1/context/auto-sync`, toggle_url: `${base}/1/context/auto-sync` }, actions: [{ group: 'Analysis', label: 'Build Pregame', url: `${base}/1/context/build`, data: {}, enabled: true, link: false }, { group: 'Predict', label: 'Run Season Games', url: `${base}/1/season-games`, data: {}, enabled: false, link: true, reason: 'Build profiles first.' }], ...extra });
const pregame = (extra = {}) => ({ source: { id: 1, name: 'Sep2026', test: '20252026', train: ['20242025'] }, health: 'recorded', run: { id: 10, status: 'completed', completed: 20, total: 20, excluded: 0, failed: 0 }, issues: [], workers: [], worker_activity: { available: true, workers: [] }, coverage: [{ season: '20252026', games: 20, covered: 20 }], workstreams: [{ key: 'players', name: 'Player context', stage: 'context', state: 'completed', completed_days: 10, total_days: 10, active_claims: 0, snapshot_count: 500, description: 'Player form', note: 'Player, team, goalie, rankings and travel tasks share this context pool.' }], limits: { league: 4, context: 2 }, automation: model().automation, snapshots: { players: 500, teams: 40 }, history: [], workers_url: `${base}/pregame/workers`, inspector_url: `${base}/pregame/inspect`, ...extra });
const mount = (extra = {}) => {
    props = reactive({ models: [model()], pagination: [], statuses: ['complete', 'running'], filters: {}, seasonOptions: ['20242025'], pregame: pregame(), ...extra });
    host = document.createElement('div'); document.body.append(host);
    app = createApp({ render: () => h(Workspace, props) }); app.provide('notify', mocks.notify); app.mount(host);
};
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await nextTick(); };
const button = text => [...host.querySelectorAll('button')].find(node => node.getAttribute('aria-label') === text || node.textContent.trim() === text);
const click = async text => { button(text).click(); await settle(); };
const fill = async (selector, value) => { const input = host.querySelector(selector); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); await nextTick(); };
const submit = async () => { host.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); await settle(); };

beforeEach(() => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-11-26T16:00:00Z')); vi.clearAllMocks();
    window.history.replaceState({}, '', base);
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    mocks.post.mockResolvedValue({ data: { message: 'Queued.' } }); mocks.put.mockResolvedValue({ data: { message: 'Saved.' } });
    mocks.patch.mockResolvedValue({ data: { message: 'Enabled.', enabled: true } });
    mocks.get.mockResolvedValue({ data: { frequency_hours: 3, start_time: '06:30', include_update_pregame: true } });
    mocks.reload.mockImplementation(options => options.onFinish?.());
});
afterEach(() => { app?.unmount(); app = null; host?.remove(); vi.useRealTimers(); });

it('shows structured model information without legacy row HTML', () => {
    mount(); expect(host.querySelector('[data-model-id="1"]').textContent).toContain('Sep2026');
    expect(host.querySelector('[data-admin-sat-models]')).toBeNull();
});
it('keeps the compact HQ header separate from shared navigation', () => {
    mount(); expect(host.querySelector('h1').textContent).toBe('SAT Models Mission Control');
    expect(host.querySelector('[aria-label="SAT workspace"]')).not.toBeNull();
});
it('opens Pregame as a peer tab and retains its URL state', async () => {
    mount(); await click('Pregame'); expect(window.location.search).toBe('?tab=pregame');
    expect(button('Pregame').getAttribute('aria-current')).toBe('page');
});
it('restores the selected Pregame tab after navigation', () => {
    window.history.replaceState({}, '', `${base}?tab=pregame`); mount();
    expect(button('Pregame').getAttribute('aria-current')).toBe('page');
});
it('shows initial loading without declaring the pilot missing', () => {
    mount({ pregame: null }); expect(host.textContent).toContain('Loading pregame health…');
    expect(host.textContent).not.toContain('Source unavailable');
});
it('identifies historical pilot data instead of production readiness', () => {
    mount(); expect(host.textContent).toContain('Sep2026 · Test 20252026');
    expect(host.textContent).toContain('Production context remains independent.');
});
it('does not label complete team snapshots as full player readiness', () => {
    mount(); expect(host.textContent).toContain('Counts do not certify every player or metric.');
});
it('keeps idle processing distinct from usable data', () => {
    mount(); expect(host.textContent).toContain('Coverage recorded');
    expect(host.textContent).toContain('Saved data can remain usable while processing is idle.');
});
it('discloses a workstream with an accessible animated caret', async () => {
    mount(); const control = host.querySelector('[aria-label="Expand Pregame context"]'); control.click(); await nextTick();
    expect(control.getAttribute('aria-expanded')).toBe('true');
    expect(host.querySelector('#workstream-context').className).toContain('grid-rows-[1fr]');
});
it('collapses a workstream without unmounting its details', async () => {
    mount(); const control = host.querySelector('[aria-label="Expand Pregame context"]'); control.click(); await nextTick(); control.click(); await nextTick();
    expect(control.getAttribute('aria-expanded')).toBe('false');
    expect(host.querySelector('#workstream-context').textContent).toContain('Player, team, goalie, rankings and travel tasks share this context pool.');
});
it('opens model actions in a Vue-owned panel', async () => {
    mount(); host.querySelector('[aria-label="Actions for Sep2026"]').click(); await nextTick();
    expect(button('Build Pregame')).toBeDefined(); expect(button('Run Season Games').disabled).toBe(true);
});
it('requires confirmation before a broad model rebuild', async () => {
    mount(); host.querySelector('[aria-label="Actions for Sep2026"]').click(); await click('Build Pregame');
    expect(mocks.post).not.toHaveBeenCalled(); expect(host.textContent).toContain('Press the same action again to confirm.');
});
it('submits a confirmed action as JSON with the workspace contract header', async () => {
    mount(); host.querySelector('[aria-label="Actions for Sep2026"]').click(); await click('Build Pregame'); await click('Build Pregame');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/1/context/build`, {}, expect.objectContaining({ headers: { 'X-DIQ-SAT-Workspace': '1' } }));
});
it('preserves failed submissions and reports the scoped error', async () => {
    mocks.post.mockRejectedValue({ response: { data: { message: 'Build already active.' } } });
    mount(); await click('Repair missing context'); expect(button('Repair missing context').disabled).toBe(false);
    expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', message: 'Build already active.' }));
});
it('filters models without requesting another page', async () => {
    mount({ models: [model(), model({ id: 2, name: 'Production' })] }); await fill('[aria-label="Search models"]', 'Sep');
    expect(host.querySelectorAll('[data-model-id]').length).toBe(1); expect(mocks.navigate).not.toHaveBeenCalled();
});
it('submits the status filter through the existing route', async () => {
    mount(); const field = host.querySelector('[aria-label="Model status"]'); field.value = 'running'; field.dispatchEvent(new Event('change', { bubbles: true }));
    expect(mocks.navigate).toHaveBeenCalledWith(base, expect.objectContaining({ status: 'running' }), expect.any(Object));
});
it('shows an actionable empty model state', () => {
    mount({ models: [] }); expect(host.textContent).toContain('No SAT models yet'); expect(button('Create Model')).toBeDefined();
});
it('loads automation settings into a local form', async () => {
    mount(); host.querySelector('[aria-label="Automation settings for Sep2026"]').click(); await settle();
    expect(host.querySelector('input[type="time"]').value).toBe('06:30');
    expect(host.querySelector('input[type="number"]').value).toBe('3');
});
it('always displays automation start time for subdaily intervals', async () => {
    mount(); host.querySelector('[aria-label="Automation settings for Sep2026"]').click(); await settle();
    expect(host.querySelector('input[type="time"]')).not.toBeNull();
});
it('shows a toast when automation is toggled', async () => {
    mount(); host.querySelector('[aria-label="Toggle automatic pregame updates for Sep2026"]').click(); await settle();
    expect(mocks.patch).toHaveBeenCalledWith(`${base}/1/context/auto-sync`, { enabled: true }, expect.any(Object));
    expect(mocks.notify).toHaveBeenCalledWith({ type: 'success', message: 'Enabled.' });
});
it('keeps separate actual league and context allocation fields', async () => {
    mount(); await click('Manage allocation →'); expect(host.querySelectorAll('input[type="number"]').length).toBe(2);
    expect(host.textContent).toContain('This does not add Horizon workers.');
});
it('saves pilot allocation through the scoped endpoint', async () => {
    mount(); await click('Manage allocation →'); await submit();
    expect(mocks.put).toHaveBeenCalledWith(`${base}/pregame/workers`, { league: 4, context: 2 }, expect.any(Object));
});
it('uses the pilot even when it is absent from the current model page', async () => {
    mount({ models: [model({ id: 2, name: 'Production' })] }); await click('Settings ⚙');
    expect(mocks.get).toHaveBeenCalledWith(`${base}/1/context/auto-sync`, expect.any(Object));
});
it('disables pilot operations when the source is unavailable', () => {
    mount({ pregame: pregame({ source: null, health: 'unavailable' }) }); expect(button('Repair missing context').disabled).toBe(true);
});
it('retains visible progress after a quiet poll', async () => {
    mount({ models: [model({ status: 'running' })] }); await vi.advanceTimersByTimeAsync(5000);
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['models', 'pregame', 'pagination', 'headerSummary'], preserveState: true, preserveScroll: true, showProgress: false }));
    expect(host.querySelector('[data-model-id="1"]')).not.toBeNull();
});
it('does not poll hidden browser tabs', async () => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true }); mount(); await vi.advanceTimersByTimeAsync(30000);
    expect(mocks.reload).not.toHaveBeenCalled();
});
it('stops progress timers on unmount', async () => {
    mount(); app.unmount(); app = null; await vi.advanceTimersByTimeAsync(60000); expect(mocks.reload).not.toHaveBeenCalled();
});
it('preserves the open worker panel during incoming prop updates', async () => {
    mount(); await click('Manage allocation →'); await fill('input[type="number"]', '7'); props.pregame = pregame({ health: 'attention' }); await nextTick();
    expect(host.querySelector('input[type="number"]').value).toBe('7');
});
it('opens the context inspector without starting any jobs', async () => {
    mount(); await click('Context inspector'); expect(host.querySelector('[aria-label="NHL game ID"]')).not.toBeNull(); expect(mocks.post).not.toHaveBeenCalled();
});
it('renders backend errors as text rather than executable markup', async () => {
    mount({ pregame: pregame({ issues: [{ title: 'Error', detail: '<script>bad()</script>' }] }) });
    expect(host.querySelector('script')).toBeNull(); expect(host.textContent).toContain('<script>bad()</script>');
});


it('uses the reference white and gold italic title treatment', () => {
    mount(); const title = host.querySelector('h1');
    expect(title.className).toContain('italic');
    expect(title.querySelector('span').textContent).toBe('Control');
    expect(title.querySelector('span').className).toContain('text-[#f7c548]');
});

it('shows all five reference header cards with unavailable metrics left blank', () => {
    mount({ headerSummary: { total: 12, research: 8, production: 4 } });
    const hero = host.querySelector('[data-sat-hq-hero]');
    expect(hero.querySelectorAll('strong').length).toBe(5);
    expect(hero.textContent).toContain('Best Test Corr.');
    expect(hero.textContent).toContain('Avg. Lift');
    expect(hero.textContent).not.toContain('83.2%');
});

it('retains the reference tab order with Models first and Pregame as one peer', () => {
    mount(); const nav = host.querySelector('[aria-label="SAT workspace"]');
    expect([...nav.querySelectorAll('a,button')].map(item => item.textContent.trim())).toEqual([
        'Models', 'Research', 'Discovery', 'Evaluations', 'Projections', 'Pregame', 'Predictions', 'Production',
    ]);
    expect(nav.querySelector('[aria-current="page"]').textContent.trim()).toBe('Models');
});

it('uses the actual extracted rink artwork without adding another application header', () => {
    mount(); expect(host.querySelector('[data-sat-hq-hero] img').getAttribute('src')).toBe('/images/sat-models-rink.png');
    expect(host.querySelector('[aria-label="Primary navigation"]')).toBeNull();
});


it('opens all recorded issues from the Overall Health badge', async () => {
    const issues = [1, 2, 3, 4].map(id => ({ title: `Issue ${id}`, detail: `Saved cause ${id}` }));
    mount({ pregame: pregame({ health: 'attention', issues }) });
    expect(host.textContent).not.toContain('Saved cause 4');
    host.querySelector('[aria-label="View pregame health issues"]').click(); await nextTick();
    const panel = host.querySelector('#sat-pregame-issues-panel');
    expect(panel.textContent).toContain('Saved cause 4');
    expect(panel.textContent).not.toContain('Update Test / prediction season only');
    expect(panel.querySelectorAll('button')).toHaveLength(2);
});

it('opens the same issue panel from the Outstanding count without processing work', async () => {
    mount({ pregame: pregame({ health: 'attention', issues: [{ title: 'Missing sources', detail: 'Two games lack context.' }] }) });
    host.querySelector('[aria-label="View 1 outstanding pregame issues"]').click(); await nextTick();
    expect(host.querySelector('#sat-pregame-issues-panel').textContent).toContain('Two games lack context.');
    expect(mocks.post).not.toHaveBeenCalled(); expect(mocks.put).not.toHaveBeenCalled();
    expect(mocks.patch).not.toHaveBeenCalled(); expect(mocks.get).not.toHaveBeenCalled();
});

it('provides a visible native-button View issues affordance with dialog state', async () => {
    mount(); const trigger = button('View issues →');
    expect(trigger.tagName).toBe('BUTTON'); expect(trigger.type).toBe('button');
    expect(trigger.getAttribute('aria-haspopup')).toBe('dialog');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    trigger.click(); await nextTick();
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(host.querySelector(`#${trigger.getAttribute('aria-controls')}`)).not.toBeNull();
});

it('allows issue inspection when the pilot is unavailable while repair remains disabled', async () => {
    mount({ pregame: pregame({ source: null, health: 'unavailable', run: null,
        issues: [{ title: 'Sep2026 source unavailable', detail: 'No unambiguous historical pilot exists.' }] }) });
    await click('View issues →');
    expect(host.querySelector('#sat-pregame-issues-panel').textContent).toContain('No unambiguous historical pilot exists.');
    expect(button('Repair missing context').disabled).toBe(true);
    expect(button('Full Train + Test rebuild')).toBeUndefined();
    expect(mocks.post).not.toHaveBeenCalled();
});

it('shows an explicit empty issue state when current health has no tracked problems', async () => {
    mount(); await click('View issues →');
    expect(host.querySelector('#sat-pregame-issues-panel').textContent).toContain('No tracked pregame issues.');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('restores closed issue-trigger state without removing the health summary', async () => {
    mount(); await click('View issues →');
    host.querySelector('[aria-label="Close panel"]').click(); await nextTick();
    expect(host.querySelector('#sat-pregame-issues-panel')).toBeNull();
    expect(button('View issues →').getAttribute('aria-expanded')).toBe('false');
    expect(host.querySelector('[data-pregame-health-strip]')).not.toBeNull();
});

const execution = (extra = {}) => ({ job_id: 'job-1', run_id: 10, task: 'Calculating league averages',
    job: 'CalculateNhlPregameLeagueBatchJob', game_date: '2025-10-10', game_id: null,
    server: 'queue-one', pid: 42, queue: 'projections', attempt: 1,
    started_at: '2026-11-26T15:58:30Z', deadline_at: '2026-11-26T16:03:00Z', state: 'running', ...extra });
const activity = (workers = []) => ({ available: true, workers, truncated: false, observed_at: '2026-11-26T16:00:00Z' });

it('shows a prominent processed percentage with success exclusion and failure counts separate', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'running', total: 100, processed: 40, progress_pct: 40,
        completed: 30, excluded: 5, failed: 5 } }) });
    const progress = host.querySelector('[aria-label="Current pregame update"]');
    expect(progress.textContent).toContain('40% processed');
    expect(progress.textContent).toContain('40 / 100 games processed');
    expect(progress.textContent).toContain('30 updated'); expect(progress.textContent).toContain('5 excluded');
    expect(progress.textContent).toContain('5 failed');
    expect(progress.querySelector('progress')).toBeNull();
});

it('does not manufacture a percentage before game selection is known', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'queued', total: 0, processed: 0, progress_pct: null } }) });
    expect(host.querySelector('[data-update-percent]').textContent).toBe('Selecting games…');
    expect(host.querySelector('[aria-label="Current update processed games"]')).toBeNull();
});

it('shows independent league and context stage progress rather than averaging them', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'running', total: 10, processed: 0, progress_pct: 0,
        stages: { total: 4, league: 1, context: 0 } } }) });
    expect(host.querySelector('[aria-label="League averages stage progress"]').value).toBe(25);
    expect(host.querySelector('[aria-label="Pregame context stage progress"]').value).toBe(0);
    expect(host.querySelector('[data-update-percent]').textContent).toBe('0% processed');
});

it('shows actual worker identity task date queue and elapsed time', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    const worker = host.querySelector('[data-pregame-execution]');
    expect(worker.textContent).toContain('queue-one'); expect(worker.textContent).toContain('PID 42');
    expect(worker.textContent).toContain('Calculating league averages');
    expect(worker.textContent).toContain('projections'); expect(worker.textContent).toContain('2025-10-10');
    expect(worker.textContent).toContain('1m 30s');
});

it('keeps claimed dates distinct from observed executing workers', () => {
    mount({ pregame: pregame({ workers: [{ key: '1-context', stage: 'context', date: '2025-10-10', state: 'claimed' }],
        worker_activity: activity([]) }) });
    const workers = host.querySelector('[aria-label="Pregame worker activity"]');
    expect(workers.textContent).toContain('0 workers executing');
    expect(host.querySelector('[aria-label="Queue claims"]').textContent).toContain('1 date claims');
});

it('removes finished execution rows on updates while retaining the fixed activity region', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    const region = host.querySelector('[aria-label="Pregame worker activity"]');
    props.pregame = pregame({ worker_activity: activity([]) }); await nextTick();
    expect(host.querySelector('[aria-label="Pregame worker activity"]')).toBe(region);
    expect(region.querySelector('[data-pregame-execution]')).toBeNull();
    expect(region.querySelector('[data-workstream-list]').className).toContain('h-[360px]');
});

it('retains prior execution evidence as unknown when telemetry is unavailable', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    props.pregame = pregame({ worker_activity: { available: false, workers: [] } }); await nextTick();
    expect(host.querySelector('[data-pregame-execution]').textContent).toContain('queue-one');
    expect(host.querySelector('[data-pregame-execution]').textContent).toContain('Unknown');
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('— workers executing');
});

it('clears retained telemetry when the selected source changes', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    props.pregame = pregame({ source: { id: 99, name: 'Different source', test: '20252026' },
        worker_activity: { available: false, workers: [] } }); await nextTick();
    expect(host.querySelector('[data-pregame-execution]')).toBeNull();
});

it('does not count unconfirmed executions as running workers', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution({ state: 'unconfirmed' })]) }) });
    const workers = host.querySelector('[aria-label="Pregame worker activity"]');
    expect(workers.textContent).toContain('0 workers executing'); expect(workers.textContent).toContain('Unconfirmed');
});

it('continues quiet fast polling while execution telemetry reports ongoing work', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    await vi.advanceTimersByTimeAsync(5000);
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ showProgress: false, preserveScroll: true, preserveState: true }));
});

it('uses the historical gap repair endpoint from the primary repair button', async () => {
    mount(); await click('Repair missing context');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/pregame/repair`, {}, expect.any(Object));
    expect(mocks.post).not.toHaveBeenCalledWith(`${base}/1/context/update`, expect.anything(), expect.anything());
});

it('offers Train and Test repair separately from the Test-only update', async () => {
    mount(); await click('Maintenance options');
    expect(button('Full Train + Test rebuild')).toBeDefined();
    await click('Update Test / prediction season only');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/1/context/update`, {}, expect.any(Object));
});

it('shows blocked game dates identifiers and specific missing-source reasons', async () => {
    mount({ pregame: pregame({ blocked_games: [{ nhl_game_id: 2022020001, game_date: '2022-10-10',
        last_error: 'Missing away player summaries (team 2)' }] }) });
    await click('View issues →');
    const sources = host.querySelector('[aria-label="Source blockers"]');
    expect(sources.textContent).toContain('2022-10-10'); expect(sources.textContent).toContain('2022020001');
    expect(sources.textContent).toContain('Missing away player summaries (team 2)');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('explains skipped source-blocked dates without claiming calculated data coverage', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'completed', total: 97, processed: 97,
        completed: 0, excluded: 97, failed: 0, progress_pct: 100, source_blocked_days: 60 } }) });
    expect(host.querySelector('[aria-label="Current pregame update"]').textContent)
        .toContain('60 days skipped because all selected games lack required sources');
    expect(host.querySelector('[aria-label="Current pregame update"]').textContent).toContain('0 updated');
});

it('labels bounded source blocker lists instead of suggesting all rows are displayed', async () => {
    mount({ pregame: pregame({ blocked_games: [{ nhl_game_id: 1, game_date: '2022-10-10', last_error: 'Missing source' }],
        blocked_games_truncated: true }) });
    await click('View issues →');
    expect(host.querySelector('[aria-label="Source blockers"]').textContent).toContain('Showing the first 100 blocked games');
});

it('disables historical gap repair while the pilot has active maintenance', async () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'running' } }) });
    expect(button('Repair missing context').disabled).toBe(true);
    await click('View issues →');
    expect(button('Repair in progress').disabled).toBe(true);
});

it('names the missing Train season beside health and confirms Test coverage', () => {
    mount({ pregame: pregame({ health: 'attention', coverage: [
        { season: '20222023', scope: 'Train', games: 1312, covered: 1215 },
        { season: '20252026', scope: 'Test', games: 1312, covered: 1312 },
    ] }) });
    const diagnosis = host.querySelector('[data-coverage-diagnosis]');
    expect(diagnosis.textContent).toContain('Train · 2022–23 — 97 games missing pregame context');
    expect(diagnosis.textContent).toContain('Test · 2025–26 — all 1,312 games covered');
    expect(button('Repair 97 missing Train games')).toBeDefined();
});

it('names a Test-only gap in the repair action', () => {
    mount({ pregame: pregame({ coverage: [{ season: '20252026', scope: 'Test', games: 100, covered: 90 }] }) });
    expect(button('Repair 10 missing Test games')).toBeDefined();
});

it('names both scopes when Train and Test have missing games', async () => {
    mount({ pregame: pregame({ coverage: [
        { season: '20222023', scope: 'Train', games: 100, covered: 95 },
        { season: '20252026', scope: 'Test', games: 100, covered: 90 },
    ] }) });
    await click('Repair 15 missing Train + Test games');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/pregame/repair`, {}, expect.any(Object));
});

it('keeps covered seasons out of the issues drawer while showing the affected cause', async () => {
    mount({ pregame: pregame({ issues: [{ title: 'Train · 2022–23 — 97 games missing pregame context', detail: 'Missing source summaries.' }], coverage: [
        { season: '20222023', scope: 'Train', games: 1312, covered: 1215 },
        { season: '20232024', scope: 'Train', games: 1312, covered: 1312 },
        { season: '20252026', scope: 'Test', games: 1312, covered: 1312 },
    ] }) });
    await click('View issues →');
    const coverage = host.querySelector('#sat-pregame-issues-panel');
    expect(coverage.textContent).not.toContain('Train · 2023–24 — all 1,312 games covered');
    expect(coverage.textContent).toContain('Train · 2022–23 — 97 games missing pregame context');
    expect(coverage.textContent).not.toContain('Test · 2025–26 — all 1,312 games covered');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('updates gap labels quietly when refreshed coverage closes the Train gap', async () => {
    mount({ pregame: pregame({ coverage: [{ season: '20222023', scope: 'Train', games: 100, covered: 90 }] }) });
    const health = host.querySelector('[data-pregame-health-strip]');
    expect(button('Repair 10 missing Train games')).toBeDefined();
    props.pregame = pregame({ coverage: [{ season: '20222023', scope: 'Train', games: 100, covered: 100 }] });
    await nextTick();
    expect(button('Repair missing context')).toBeDefined();
    expect(host.querySelector('[data-pregame-health-strip]')).toBe(health);
    expect(host.textContent).not.toContain('10 games missing pregame context');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('counts an overlapping Train and Test season once in the repair label', () => {
    mount({ pregame: pregame({ coverage: [{ season: '20252026', scope: 'Train + Test', games: 100, covered: 99 }] }) });
    expect(button('Repair 1 missing Train + Test game')).toBeDefined();
});

it('shows completed maintenance as idle pools without active progress bars', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'completed', stages: { total: 10, league: 10, context: 10 } } }) });
    const list = host.querySelector('[data-workstream-list]');
    expect(list.querySelectorAll('[data-workstream]')).toHaveLength(2);
    expect(list.querySelectorAll('progress')).toHaveLength(0);
    expect(list.textContent).toContain('Idle - no work running');
    expect(list.textContent).toContain('0 executing');
});

it('lists eight executing processes once each beneath their actual pools', () => {
    const workers = Array.from({ length: 8 }, (_, i) => execution({ job_id: `job-${i}`, pid: 100 + i,
        pool: i < 4 ? 'league' : 'context' }));
    mount({ pregame: pregame({ worker_activity: activity(workers) }) });
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('8 workers executing');
    expect(host.querySelector('[data-workstream="league"]').querySelectorAll('[data-pregame-execution]')).toHaveLength(4);
    expect(host.querySelector('[data-workstream="context"]').querySelectorAll('[data-pregame-execution]')).toHaveLength(4);
    expect(host.querySelectorAll('[data-pregame-execution]')).toHaveLength(8);
    expect(host.querySelector('[aria-label="Queue claims"]').textContent).toContain('Configured capacity: 6');
});

it('uses the latest observation per host and process for concurrent worker counts', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution(), execution({ job_id: 'new-job',
        started_at: '2026-11-26T15:59:00Z', task: 'Newer task' })]) }) });
    expect(host.querySelectorAll('[data-pregame-execution]')).toHaveLength(1);
    expect(host.querySelector('[data-pregame-execution]').textContent).toContain('Newer task');
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('1 worker executing');
});

it('shows measured progress inside the worker row', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution({ pool: 'context', work_progress: {
        label: '3 / 5 games processed on this date', percent: 60,
    } })]) }) });
    const worker = host.querySelector('[data-workstream="context"] [data-pregame-execution]');
    expect(worker.textContent).toContain('3 / 5 games processed on this date');
    expect(worker.querySelector('progress').value).toBe(60);
});

it('shows league checkpoint text without inventing percentage progress', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution({ work_progress: {
        label: '2 league populations completed · reading peer histories', percent: null,
    } })]) }) });
    const worker = host.querySelector('[data-pregame-execution]');
    expect(worker.textContent).toContain('2 league populations completed');
    expect(worker.querySelector('progress')).toBeNull();
});

it('shows queued work without pretending queued date claims are executing processes', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'running', stages: { total: 5, league: 0, context: 0 } },
        workers: [{ key: 'context-1', stage: 'context', state: 'claimed', date: '2025-10-10' }] }) });
    expect(host.querySelector('[data-workstream="context"]').textContent).toContain('Queued');
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('0 workers executing');
});

it('shows paused pools explicitly when no executions are observed', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'paused' } }) });
    expect(host.querySelector('[data-workstream="context"]').textContent).toContain('Paused');
});

it('shows source-blocked context as blocked after a maintenance attempt completes', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'completed', excluded: 97, source_blocked_days: 60 } }) });
    expect(host.querySelector('[data-workstream="context"]').textContent).toContain('Blocked - see View issues');
    expect(host.querySelector('[data-workstream="league"]').textContent).toContain('Blocked - see View issues');
});

it('keeps coordination workers visible without duplicating them under league or context', () => {
    mount({ pregame: pregame({ worker_activity: activity([execution({ job: 'PrepareNhlPregameContextRunJob',
        pool: 'coordination', task: 'Preparing game selection' })]) }) });
    expect(host.querySelector('[data-workstream="coordination"]').textContent).toContain('Preparing game selection');
    expect(host.querySelectorAll('[data-pregame-execution]')).toHaveLength(1);
});

it('presents one repair action and no maintenance menu inside View issues', async () => {
    mount({ pregame: pregame({ issues: [{ title: 'Missing Train context', detail: '97 games require context.' }] }) });
    await click('View issues →');
    const panel = host.querySelector('#sat-pregame-issues-panel');
    const actions = [...panel.querySelectorAll('button')].filter(node => node.getAttribute('aria-label') !== 'Close panel');
    expect(actions).toHaveLength(1);
    expect(actions[0].textContent).toContain('Repair missing context');
    expect(panel.textContent).not.toContain('Full Train + Test rebuild');
    expect(panel.textContent).not.toContain('Update Test / prediction season only');
    actions[0].click(); await settle();
    expect(mocks.post).toHaveBeenCalledWith(`${base}/pregame/repair`, {}, expect.any(Object));
});

it('uses the single issue action to resume retryable unfinished repair', async () => {
    mount({ pregame: pregame({ issues: [{ title: 'Repair interrupted', detail: 'Checkpoint saved.' }],
        run: { id: 10, status: 'failed', retryable: true } }) });
    await click('View issues →'); await click('Continue repair');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/1/context/resume`, {}, expect.any(Object));
});

it('preserves full-rebuild confirmation in the separate maintenance panel', async () => {
    mount(); await click('Maintenance options'); await click('Full Train + Test rebuild');
    expect(mocks.post).not.toHaveBeenCalled();
    await click('Confirm full rebuild');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/1/context/build`, {}, expect.any(Object));
});

const goalFixture = (overrides = {}) => ({ available: true, areas: [
    ['players', 'Players'], ['teams', 'Teams'], ['goalies', 'Goalies'], ['league', 'League averages'], ['rankings', 'Rankings'], ['travel', 'Travel'],
].map(([key, name]) => ({ key, name, goal: `Keep ${name} context complete.`, enrolled: true, state: 'complete',
    worker_limit: 1, completed: 10, remaining: 0, total: 10, counts: { complete: 10 }, workers: [], blockers: [], ...overrides[key] })) });

it('restores all six areas as independently managed workstreams', () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture({ players: { worker_limit: 3, counts: { claimed: 2, working: 1 } } }) }) });
    expect(host.querySelectorAll('[data-workstream]')).toHaveLength(6);
    expect(host.querySelectorAll('[aria-label^="Workers for "]')).toHaveLength(6);
    expect(host.querySelector('[data-workstream="goalies"]').textContent).toContain('Goalies');
    expect(host.querySelector('[data-active-claims]').textContent).toBe('3 / 8');
});

it('sends an inline area allocation change with all six current limits', async () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture(), goals_url: `${base}/pregame/goals` }) });
    const input = host.querySelector('[aria-label="Workers for Players"]');
    input.value = '3'; input.dispatchEvent(new Event('change', { bubbles: true })); await settle();
    expect(mocks.put).toHaveBeenCalledWith(`${base}/pregame/goals`, { areas: {
        players: 3, teams: 1, goalies: 1, league: 1, rankings: 1, travel: 1,
    } }, expect.any(Object));
});

it('offers six area controls in the allocation drawer and permits zero', async () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture(), goals_url: `${base}/pregame/goals` }) });
    await click('Manage allocation →');
    const form = host.querySelector('form');
    expect(form.querySelectorAll('input[type="number"]')).toHaveLength(6);
    expect(form.querySelector('[aria-label="Players allocation"]').min).toBe('0');
    await submit();
    expect(mocks.put).toHaveBeenCalledWith(`${base}/pregame/goals`, expect.objectContaining({ areas: expect.objectContaining({ players: 1, goalies: 1 }) }), expect.any(Object));
});

it('places several executing workers inside their own area rather than a combined context row', () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture({ players: { state: 'working', remaining: 2,
        completed: 8, workers: [execution({ pid: 41, pool: 'players' }), execution({ pid: 42, pool: 'players' })] } }) }) });
    expect(host.querySelector('[data-workstream="players"]').querySelectorAll('[data-pregame-execution]')).toHaveLength(2);
    expect(host.querySelector('[data-workstream="goalies"]').querySelectorAll('[data-pregame-execution]')).toHaveLength(0);
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('2 workers executing');
});

it('states that a completed area goal is satisfied without showing an active bar', () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture() }) });
    const players = host.querySelector('[data-workstream="players"]');
    expect(players.textContent).toContain('goal satisfied');
    expect(players.querySelector('progress')).toBeNull();
});

it('investigates an area blocker and sends an area-specific repair request', async () => {
    mount({ pregame: pregame({ repair_url: `${base}/pregame/repair`, workstream_goals: goalFixture({ goalies: {
        state: 'blocked', blockers: [{ id: 33, nhl_game_id: 2022020001, last_error: 'Missing goalie summaries.' }],
    } }) }) });
    await click('Investigate Goalies');
    expect(host.textContent).toContain('Missing goalie summaries.');
    await click('Assess and repair Goalies');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/pregame/repair`, { area: 'goalies' }, expect.any(Object));
});

it('explains waiting dependencies instead of reporting an idle incomplete area', () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture({ rankings: { state: 'waiting', remaining: 3 } }) }) });
    expect(host.querySelector('[data-workstream="rankings"]').textContent).toContain('Waiting for completed metrics and matching league references');
});

it('disables new repair controls with an explicit migration message before schema deployment', () => {
    mount({ pregame: pregame({ workstream_goals: { available: false, areas: [] } }) });
    expect(host.textContent).toContain('Migration required to enable independent area goals');
    expect(button('Repair missing context').disabled).toBe(true);
});

it('keeps original legacy maintenance visible while new area admission waits', () => {
    mount({ pregame: pregame({ workstream_goals: { ...goalFixture(), legacy_active: true },
        worker_activity: activity([execution()]) }) });
    expect(host.textContent).toContain('finishing its original checkpoints');
    expect(host.querySelector('[data-workstream="legacy"]').querySelectorAll('[data-pregame-execution]')).toHaveLength(1);
});

it('shows per game import and context progress inside the Pregame area drawer', async () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture(), source_repairs: [
        { game_id: 2022020038, date: '2022-10-15', matchup: 'CHI @ SJS', import_run_id: 88, state: 'importing', imported: false },
    ] }) });
    await click('Investigate Teams');
    const progress = host.querySelector('[aria-label="Game repair progress"]');
    expect(progress.textContent).toContain('0 / 1 games repaired');
    expect(progress.textContent).toContain('CHI @ SJS');
    expect(progress.textContent).toContain('Importing');
    expect(progress.querySelectorAll('[data-source-repair]')).toHaveLength(1);
});

it('updates repaired game counts without closing the current area drawer', async () => {
    mount({ pregame: pregame({ workstream_goals: goalFixture(), source_repairs: [
        { game_id: 1, matchup: 'A @ B', state: 'building_context', imported: true },
    ] }) });
    await click('Investigate Players');
    props.pregame.source_repairs[0].state = 'repaired'; await nextTick();
    expect(host.querySelector('[aria-label="Game repair progress"]').textContent).toContain('1 / 1 games repaired');
});

it('sends a failed game retry without leaving Pregame', async () => {
    mount({ pregame: pregame({ repair_url: `${base}/pregame/repair`, workstream_goals: goalFixture(), source_repairs: [
        { game_id: 38, matchup: 'CHI @ SJS', state: 'failed', imported: false, error: 'Source unavailable.' },
    ] }) });
    await click('Investigate Teams'); await click('Retry CHI @ SJS');
    expect(mocks.post).toHaveBeenCalledWith(`${base}/pregame/repair`, { game_id: 38 }, expect.any(Object));
    expect(mocks.navigate).not.toHaveBeenCalled();
});
