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
const pregame = (extra = {}) => ({ source: { id: 1, name: 'Sep2026', test: '20252026', train: ['20242025'] }, health: 'recorded', run: { id: 10, status: 'completed', completed: 20, total: 20, excluded: 0, failed: 0 }, issues: [], workers: [], coverage: [{ season: '20252026', games: 20, covered: 20 }], workstreams: [{ key: 'players', name: 'Player context', stage: 'context', state: 'completed', completed_days: 10, total_days: 10, active_claims: 0, snapshot_count: 500, description: 'Player form', note: 'Shares the context processing pool.' }], limits: { league: 4, context: 2 }, automation: model().automation, snapshots: { players: 500, teams: 40 }, history: [], workers_url: `${base}/pregame/workers`, inspector_url: `${base}/pregame/inspect`, ...extra });
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
    mount(); const control = host.querySelector('[aria-label="Expand Player context"]'); control.click(); await nextTick();
    expect(control.getAttribute('aria-expanded')).toBe('true');
    expect(host.querySelector('#workstream-players').className).toContain('grid-rows-[1fr]');
});
it('collapses a workstream without unmounting its details', async () => {
    mount(); const control = host.querySelector('[aria-label="Expand Player context"]'); control.click(); await nextTick(); control.click(); await nextTick();
    expect(control.getAttribute('aria-expanded')).toBe('false');
    expect(host.querySelector('#workstream-players').textContent).toContain('Shares the context processing pool.');
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
    mount(); await click('Update Missing Data'); expect(button('Update Missing Data').disabled).toBe(false);
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
    mount({ pregame: pregame({ source: null, health: 'unavailable' }) }); expect(button('Update Missing Data').disabled).toBe(true);
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
    expect(panel.textContent).toContain('Update missing Test context');
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
    expect(button('Update missing Test context').disabled).toBe(true);
    expect(button('Full Train + Test rebuild').disabled).toBe(true);
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
    expect(progress.querySelector('progress').value).toBe(40);
});

it('does not manufacture a percentage before game selection is known', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'queued', total: 0, processed: 0, progress_pct: null } }) });
    expect(host.querySelector('[data-update-percent]').textContent).toBe('Selecting games…');
    expect(host.querySelector('[aria-label="Current update processed games"]').getAttribute('value')).toBeNull();
});

it('shows independent league and context stage progress rather than averaging them', () => {
    mount({ pregame: pregame({ run: { id: 10, status: 'running', total: 10, processed: 0, progress_pct: 0,
        stages: { total: 4, league: 1, context: 0 } } }) });
    expect(host.querySelector('[aria-label="League averages stage progress"]').value).toBe(25);
    expect(host.querySelector('[aria-label="Context, rankings & deltas stage progress"]').value).toBe(0);
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
    expect(workers.textContent).toContain('0 observed running');
    expect(workers.textContent).toContain('1 date claims');
});

it('removes finished execution rows on updates while retaining the fixed activity region', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    const region = host.querySelector('[aria-label="Pregame worker activity"]');
    props.pregame = pregame({ worker_activity: activity([]) }); await nextTick();
    expect(host.querySelector('[aria-label="Pregame worker activity"]')).toBe(region);
    expect(region.querySelector('[data-pregame-execution]')).toBeNull();
    expect(region.querySelector('.h-\\[152px\\]')).not.toBeNull();
});

it('retains prior execution evidence as unknown when telemetry is unavailable', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    props.pregame = pregame({ worker_activity: { available: false, workers: [] } }); await nextTick();
    expect(host.querySelector('[data-pregame-execution]').textContent).toContain('queue-one');
    expect(host.querySelector('[data-pregame-execution]').textContent).toContain('Unknown');
    expect(host.querySelector('[aria-label="Pregame worker activity"]').textContent).toContain('— observed running');
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
    expect(workers.textContent).toContain('0 observed running'); expect(workers.textContent).toContain('Unconfirmed');
});

it('continues quiet fast polling while execution telemetry reports ongoing work', async () => {
    mount({ pregame: pregame({ worker_activity: activity([execution()]) }) });
    await vi.advanceTimersByTimeAsync(5000);
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ showProgress: false, preserveScroll: true, preserveState: true }));
});
