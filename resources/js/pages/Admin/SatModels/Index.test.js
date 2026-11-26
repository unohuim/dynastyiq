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
const button = text => [...host.querySelectorAll('button')].find(node => node.textContent.trim() === text);
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
    mount(); expect(host.querySelector('h1').textContent).toBe('Models & Pregame');
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
    mount(); expect(host.textContent).toContain('Shared workspace pilot · Sep2026 · Test 20252026');
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
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['models', 'pregame', 'pagination'], preserveState: true, preserveScroll: true, showProgress: false }));
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
