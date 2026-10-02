/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import { active, coverageShortfall, discoveryDefaults, number, qualified, runPayload } from './engine-ui';
import Index from './Index.vue';
import Workspace from './Workspace.vue';
import Run from './Run.vue';
import SettingsFields from './SettingsFields.vue';

const transport = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), delete: vi.fn(), get: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { h, reactive } = await import('vue');
    return {
        Head: { render: () => null },
        Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
        router: { get: (...args) => transport.get(...args), reload: (...args) => transport.reload(...args) },
        useForm: initial => {
            let transform = value => value;
            const keys = Object.keys(initial);
            const form = reactive({ ...structuredClone(initial), errors: {}, processing: false, recentlySuccessful: false });
            form.transform = callback => { transform = callback; return form; };
            form.clearErrors = () => { form.errors = {}; };
            for (const method of ['post', 'put', 'delete']) {
                form[method] = url => transport[method](url, transform(Object.fromEntries(keys.map(key => [key, form[key]]))));
            }
            return form;
        },
    };
});

let app;
const settings = { offense: 88, defense: 2, confidence_min: 67, confidence_max: 70, gap: 0 };
const models = [{ id: 1, name: 'Sep2026', status: 'complete', target_season_id: '20252026' }];
const mount = (component, props) => {
    const root = document.createElement('div');
    document.body.append(root);
    app = createApp({ render: () => h(component, props) });
    app.mount(root);
    const dialog = root.querySelector('dialog');
    if (dialog) {
        dialog.showModal = vi.fn(() => dialog.setAttribute('open', ''));
        dialog.close = vi.fn(() => dialog.removeAttribute('open'));
    }
    return root;
};
const workspace = () => ({ engine: null, models, defaults: settings, teams: ['TOR', 'MTL'], runs: { data: [], links: [] } });
const report = () => ({
    run: { id: 7, status: 'running', kind: 'discovery', game_count: 2, prediction_count: 2, predictions_completed: 1,
        candidate_count: 1, candidates_completed: 0, definition: { model_name: 'Sep2026', test_season: '20252026', desired_win_pct: 60, min_coverage_pct: 40 } },
    candidates: { data: [], links: [] }, games: { data: [], links: [] }, engines: [], candidate: null,
});
beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-01T12:00:00Z'));
    vi.stubGlobal('fetch', vi.fn(() => { throw new Error('Unexpected network request'); }));
    transport.reload.mockImplementation(options => options.onFinish());
});
afterEach(() => {
    app?.unmount(); app = null;
    vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = '';
});

it('creates independent discovery scope for each page without manual search settings', () => {
    const first = discoveryDefaults(); first.scope.teams.push('TOR');
    expect(discoveryDefaults().scope.teams).toEqual([]);
    expect(first).not.toHaveProperty('search');
});
it('turns empty dates into nullable request values', () => {
    expect(runPayload(discoveryDefaults(settings)).scope.start_date).toBeNull();
});
it('parses explicit game IDs from commas and whitespace', () => {
    const data = discoveryDefaults(settings); data.scope.mode = 'selected';
    expect(runPayload(data, '123, 456\n789').scope.game_ids).toEqual([123, 456, 789]);
});
it('does not submit hidden selected IDs for season scope', () => {
    expect(runPayload(discoveryDefaults(settings), '123').scope.game_ids).toEqual([]);
});
it('includes the minimum confidence boundary', () => {
    expect(qualified({ status: 'complete', correct: true, confidence: 67, gap: 0.2 }, settings)).toBe(true);
});
it('includes the maximum confidence boundary', () => {
    expect(qualified({ status: 'complete', correct: false, confidence: 70, gap: 0.2 }, settings)).toBe(true);
});
it('excludes scores below the confidence minimum', () => {
    expect(qualified({ status: 'complete', correct: true, confidence: 66, gap: 2 }, settings)).toBe(false);
});
it('excludes equality at the configured score gap', () => {
    expect(qualified({ status: 'complete', correct: true, confidence: 68, gap: 0.5 }, { ...settings, gap: 0.5 })).toBe(false);
});
it('excludes exact ties and unavailable games', () => {
    expect(qualified({ status: 'complete', correct: null, confidence: 68, gap: 0 }, settings)).toBe(false);
    expect(qualified({ status: 'excluded', correct: true, confidence: 68, gap: 1 }, settings)).toBe(false);
});
it('displays missing values separately from valid zeros', () => {
    expect(number(null)).toBe('—'); expect(number(0)).toBe('0');
});
it('recognizes only active lifecycle states for polling', () => {
    expect(['queued', 'running', 'ranking'].every(active)).toBe(true);
    expect(['complete', 'failed', 'cancelled'].some(active)).toBe(false);
});
it('renders an empty index and discovery navigation', () => {
    const root = mount(Index, { engines: { data: [], links: [] }, models, defaults: settings });
    expect(root.textContent).toContain('No engines yet.');
    expect(root.querySelector('a').href).toContain('/admin/nhl-sat-engines/discover');
});
it('submits creation without starting a build', async () => {
    const root = mount(Index, { engines: { data: [], links: [] }, models, defaults: settings });
    root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await nextTick();
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines', expect.objectContaining({ settings }));
    expect(transport.post).toHaveBeenCalledTimes(1);
});
it('marks the current default and posts only the clicked engine as the new default', async () => {
    const root = mount(Index, { engines: { data: [
        { id: 1, name: 'Current', model_run_id: 1, settings, is_default: true },
        { id: 2, name: 'Other', model_run_id: 1, settings, is_default: false },
    ], links: [] }, models, defaults: settings });
    expect(root.textContent).toContain('Current');
    expect(root.textContent).toContain('Default');
    [...root.querySelectorAll('button')].find(button => button.textContent === 'Make default').click();
    await nextTick();
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines/2/default', {});
});
it('emits setting edits without mutating incoming props', async () => {
    const update = vi.fn();
    const root = mount(SettingsFields, { settings, 'onUpdate:settings': update });
    const input = root.querySelector('input'); input.value = '105'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    expect(update).toHaveBeenCalledWith({ ...settings, offense: 105 }); expect(settings.offense).toBe(88);
});
it('shows count only for game and game-day scope', async () => {
    const root = mount(Workspace, workspace());
    const scope = [...root.querySelectorAll('select')].find(select => select.textContent.includes('Entire test season'));
    scope.value = 'days'; scope.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    expect([...root.querySelectorAll('label')].some(label => label.textContent.trim() === 'Count')).toBe(true);
});
it('submits chosen teams and discovery scope', async () => {
    const root = mount(Workspace, workspace());
    const checkbox = root.querySelector('input[type=checkbox]'); checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs', expect.objectContaining({
        kind: 'discovery', scope: expect.objectContaining({ teams: ['TOR'], mode: 'season' }),
    }));
});
it('disables execution for a model without a test season', () => {
    const root = mount(Workspace, { ...workspace(), models: [{ ...models[0], target_season_id: null }] });
    const button = [...root.querySelectorAll('button')].find(item => item.textContent.includes('Discover settings'));
    expect(button.disabled).toBe(true);
});
it('requires confirmation before deleting an engine', async () => {
    const root = mount(Workspace, { ...workspace(), engine: { id: 4, name: 'Saved', model_run_id: 1, settings } });
    const button = [...root.querySelectorAll('button')].find(item => item.textContent === 'Delete engine');
    button.click(); await nextTick(); expect(transport.delete).not.toHaveBeenCalled();
    [...root.querySelectorAll('button')].find(item => item.textContent === 'Confirm delete').click();
    expect(transport.delete).toHaveBeenCalledWith('/admin/nhl-sat-engines/4', {});
});
it('polls active runs and stops after unmount', async () => {
    mount(Run, report()); await vi.advanceTimersByTimeAsync(5000);
    expect(transport.reload).toHaveBeenCalledTimes(1);
    app.unmount(); app = null; await vi.advanceTimersByTimeAsync(10000);
    expect(transport.reload).toHaveBeenCalledTimes(1);
});
it('stops polling when a refreshed run becomes complete', async () => {
    const props = reactive(report()); mount(Run, props);
    props.run.status = 'complete'; await nextTick(); await vi.advanceTimersByTimeAsync(10000);
    expect(transport.reload).not.toHaveBeenCalled();
});
it('shows failed-run diagnostics and hides adoption', () => {
    const props = report(); props.run.status = 'failed'; props.run.error = 'Evaluation failed';
    const root = mount(Run, props);
    expect(root.querySelector('[role=alert]').textContent).toBe('Evaluation failed');
    expect(root.textContent).not.toContain('Create engine from candidate');
});
it('renders predicted and actual totals for all eligible games', () => {
    const props = report(); props.run.status = 'complete';
    props.run.kind = 'build';
    props.candidate = { id: 8, settings, metrics: { pred_sat: 100, pred_sog: 50, pred_goals: 5, actual_sat: 110, actual_sog: 55, actual_goals: 6 } };
    const root = mount(Run, props);
    expect(root.textContent).toContain('including games that do not qualify');
    expect(root.textContent).toContain('Actual'); expect(root.textContent).toContain('110');
    expect(root.querySelector('h1').textContent).toBe('Evaluation 7 · Test');
});
it('submits a candidate as a new engine without running it', () => {
    const props = report(); props.run.status = 'complete'; props.candidate = { id: 8, settings, metrics: {} };
    const root = mount(Run, props);
    root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs/7/candidates/8/apply', { engine_id: null, name: '' });
});

it('asks only for model scope and targets during discovery', () => {
    const root = mount(Workspace, workspace());
    expect(root.querySelector('input[aria-label="Confidence minimum min"]')).toBeNull();
    expect(root.querySelector('input[aria-label="Confidence maximum max"]')).toBeNull();
    expect(root.textContent).toContain('Discovery searches automatically');
    expect(root.querySelector('table')).toBeNull();
    expect([...root.querySelectorAll('input[type=number]')].map(input => input.parentElement.textContent.trim()))
        .toEqual(['Desired win %', 'Minimum game coverage %']);
    expect(discoveryDefaults()).not.toHaveProperty('search');
});

it('preserves explicit confidence settings on saved engines', () => {
    const root = mount(Workspace, { ...workspace(), engine: { id: 4, name: 'Saved', model_run_id: 1, settings },
        runs: { data: [{ id: 7, kind: 'build', status: 'complete', game_count: 5 }], links: [] } });
    expect(root.textContent).toContain('Confidence minimum');
    expect(root.textContent).toContain('Confidence maximum');
    expect(root.textContent).toContain('Run 7 · Test · complete · 5 games');
});

it('shows percentage-point shortfalls rather than relative percentages', () => {
    expect(coverageShortfall(38, 40)).toBe(2);
    expect(coverageShortfall('37.5', '40')).toBe(2.5);
    expect(coverageShortfall(42, 40)).toBe(0);
});

it('keeps below-target candidates in the main candidate table', () => {
    const props = report(); props.run.status = 'complete';
    props.candidates = { total: 1, links: [], data: [{ id: 12, settings,
        metrics: { wins: 18, losses: 1, all_wins: 18, all_losses: 1, eligible: 19, excluded: 0 }, win_pct: 94.7368, coverage_pct: 38, meets_targets: false }] };
    const root = mount(Run, props);
    expect(root.querySelector('[aria-label="Strong candidates below coverage target"]')).toBeNull();
    expect(root.querySelector('#candidates-title').parentElement.parentElement.textContent).toContain('Not met');
    expect(root.querySelector('#candidates-title').parentElement.parentElement.querySelector('a').href).toContain('candidate=12');
});

it('requests analysis-only stack recommendations and renders them below candidates', async () => {
    const props = reactive(report()); props.run.status = 'complete';
    props.stacks = [{ ids: [12, 13], wins: 16, losses: 4, eligible: 25, excluded: 0, win_pct: 80, coverage_pct: 80,
        candidates: [{ id: 12, settings, win_pct: 82, coverage_pct: 40 }, { id: 13, settings: { ...settings, confidence_min: 40, gap: 1 }, win_pct: 75, coverage_pct: 60 }] }];
    const root = mount(Run, props);
    [...root.querySelectorAll('button')].find(button => button.textContent === 'Stack').click();
    await nextTick();
    expect(transport.get).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs/7', expect.objectContaining({ stack: 1 }), expect.any(Object));
    props.stackRequested = true; await nextTick();
    expect(root.querySelector('#stacks-title').textContent).toContain('Stack recommendations');
    expect(root.textContent).toContain('Foundation');
    expect(root.textContent).toContain('Supplement 1');
    expect(root.textContent).toContain('80.0% coverage');
});

it('sorts a newly selected candidate column descending first', async () => {
    const props = report(); props.run.status = 'complete';
    props.candidates = { data: [{ id: 8, settings, metrics: {}, win_pct: 60, coverage_pct: 40, meets_targets: true }], links: [] };
    props.candidateSort = { key: 'targets', direction: 'desc' };
    const root = mount(Run, props);
    [...root.querySelectorAll('button')].find(button => button.textContent.includes('Coverage %')).click();
    await nextTick();
    expect(transport.get).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs/7', expect.objectContaining({ sort: 'coverage_pct', direction: 'desc' }), expect.any(Object));
});

it('toggles an active candidate column from descending to ascending', async () => {
    const props = report(); props.run.status = 'complete';
    props.candidates = { data: [{ id: 8, settings, metrics: {}, win_pct: 60, coverage_pct: 40, meets_targets: true }], links: [] };
    props.candidateSort = { key: 'coverage_pct', direction: 'desc' };
    const root = mount(Run, props);
    [...root.querySelectorAll('button')].find(button => button.textContent.includes('Coverage %')).click();
    await nextTick();
    expect(transport.get).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs/7', expect.objectContaining({ sort: 'coverage_pct', direction: 'asc' }), expect.any(Object));
});

it('shows the active candidate sort icon and accessible direction', () => {
    const props = report(); props.run.status = 'complete';
    props.candidates = { data: [{ id: 8, settings, metrics: {}, win_pct: 60, coverage_pct: 40, meets_targets: true }], links: [] };
    props.candidateSort = { key: 'coverage_pct', direction: 'desc' };
    const root = mount(Run, props);
    const coverage = [...root.querySelectorAll('button')].find(button => button.textContent.includes('Coverage %'));
    expect(coverage.textContent).toContain('↓');
    expect(coverage.getAttribute('aria-label')).toContain('Sorted descending');
});

it('refreshes only the remaining run data', async () => {
    mount(Run, report()); await vi.advanceTimersByTimeAsync(5000);
    expect(transport.reload.mock.calls[0][0].only).not.toContain('nearMisses');
});

it('labels automatic ranking progress as weight and gap searches', () => {
    const props = report(); props.run.definition.confidence_search = 'automatic';
    const root = mount(Run, props);
    expect(root.textContent).toContain('Weight/gap searches');
    expect(root.textContent).toContain('All confidence ranges are evaluated');
});

it('defaults counted selection to first', () => {
    expect(discoveryDefaults(settings).scope.selection).toBe('first');
});

it('places selection immediately after count for counted games', async () => {
    const root = mount(Workspace, workspace());
    const scope = [...root.querySelectorAll('select')].find(select => select.textContent.includes('Entire test season'));
    scope.value = 'games'; scope.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    const count = [...root.querySelectorAll('label')].find(label => label.textContent.trim() === 'Count');
    const selection = count.nextElementSibling.querySelector('select');
    expect([...selection.options].map(option => option.value)).toEqual(['first', 'last', 'random']);
    expect(selection.value).toBe('first');
});

it('submits random selection for counted games', async () => {
    const root = mount(Workspace, workspace());
    const scope = [...root.querySelectorAll('select')].find(select => select.textContent.includes('Entire test season'));
    scope.value = 'games'; scope.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    const selection = [...root.querySelectorAll('select')].find(select => select.textContent.includes('Random'));
    selection.value = 'random'; selection.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs', expect.objectContaining({
        scope: expect.objectContaining({ mode: 'games', count: 5, selection: 'random' }),
    }));
});

it('keeps starting and ending dates together on a separate row', () => {
    const root = mount(Workspace, workspace());
    const dates = root.querySelectorAll('input[type=date]');
    expect(dates).toHaveLength(2);
    expect(dates[0].parentElement.parentElement).toBe(dates[1].parentElement.parentElement);
    expect(dates[0].parentElement.parentElement.children).toHaveLength(2);
});

it('clears an irrelevant selection when returning to entire-season scope', () => {
    const data = discoveryDefaults(settings); data.scope.selection = 'random';
    expect(runPayload(data).scope.selection).toBe('first');
    data.scope.mode = 'days';
    expect(runPayload(data).scope.selection).toBe('random');
});

it('drops stale manual ranges from discovery requests', () => {
    expect(runPayload({ ...discoveryDefaults(), search: { offense: { min: 88, max: 88, step: 1 } } }))
        .not.toHaveProperty('search');
});

it('submits automatic discovery without saved engine settings or ranges', async () => {
    const root = mount(Workspace, { ...workspace(), engine: { id: 4, name: 'Saved', model_run_id: 1, settings } });
    const process = [...root.querySelectorAll('select')].find(select => select.textContent.includes('Test saved settings'));
    process.value = 'discovery'; process.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    const form = root.querySelector('form');
    expect(form.textContent).not.toContain('Confidence minimum');
    expect(form.querySelector('table')).toBeNull();
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const payload = transport.post.mock.calls[0][1];
    expect(payload.kind).toBe('discovery');
    expect(payload).not.toHaveProperty('search');
    expect(payload).not.toHaveProperty('settings');
    expect(root.textContent).not.toContain('Confidence minimum');
    process.value = 'build'; process.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    expect(root.querySelectorAll('form')[0].textContent).toContain('Offense %');
    expect([...root.querySelectorAll('button')].some(button => button.textContent === 'Test engine')).toBe(true);
    expect(root.textContent).not.toContain('Build engine');
});

it('explains refinement progress and the limits of automatic weight search', () => {
    const props = report();
    props.run.definition.automatic_search = { strategy: 'coarse_to_fine_v1', stage: 1 };
    const root = mount(Run, props);
    expect(root.textContent).toContain('Search stage 2 of 3');
    expect(root.textContent).toContain('Splits evaluated');
    expect(root.textContent).toContain('Work totals grow');
    expect(root.textContent).toContain('does not evaluate every possible weight pair');
});

it('allows saving a discovered gap at persisted prediction precision', () => {
    const root = mount(SettingsFields, { settings: { ...settings, gap: 0.432123 } });
    const input = [...root.querySelectorAll('input')].at(-1);
    expect(input.validity.stepMismatch).toBe(false);
    expect(input.value).toBe('0.432123');
});

it('creates from the clicked row rather than the selected detail candidate while discovery runs', async () => {
    const props = report();
    props.candidate = { id: 8, settings, metrics: {} };
    props.candidates.data = [{ id: 12, settings: { ...settings, offense: 175, gap: 0.4985 }, metrics: {} }];
    const root = mount(Run, props);
    [...root.querySelectorAll('button')].find(button => button.textContent === 'Create engine').click();
    await nextTick(); await nextTick();
    const dialog = root.querySelector('dialog');
    expect(dialog.hasAttribute('open')).toBe(true);
    expect(dialog.textContent).toContain('175O');
    expect(dialog.textContent).toContain('0.4985');
    expect(document.activeElement).toBe(dialog.querySelector('input'));
    const input = dialog.querySelector('input'); input.value = 'My candidate'; input.dispatchEvent(new Event('input'));
    dialog.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(transport.post).toHaveBeenCalledWith('/admin/nhl-sat-engines/runs/7/candidates/12/apply', { name: 'My candidate' });
});

it('does not offer creation for pending candidates or failed and cancelled runs', async () => {
    const props = reactive(report());
    props.candidates.data = [{ id: 12, settings, metrics: null }];
    const root = mount(Run, props);
    const buttons = () => [...root.querySelectorAll('button')].filter(button => button.textContent === 'Create engine');
    expect(buttons()).toHaveLength(0);
    props.candidates.data[0].metrics = {}; props.run.status = 'failed'; await nextTick();
    expect(buttons()).toHaveLength(0);
    props.run.status = 'cancelled'; await nextTick(); expect(buttons()).toHaveLength(0);
    props.run.status = 'complete'; await nextTick(); expect(buttons()).toHaveLength(1);
});

it('keeps the chosen creation candidate stable across polling updates', async () => {
    const props = reactive(report()); props.candidates.data = [{ id: 12, settings, metrics: {} }];
    const root = mount(Run, props);
    [...root.querySelectorAll('button')].find(button => button.textContent === 'Create engine').click();
    await nextTick(); await nextTick();
    props.candidates.data = [{ id: 99, settings: { ...settings, offense: 200 }, metrics: {} }];
    await nextTick();
    root.querySelector('dialog form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(transport.post.mock.calls[0][0]).toBe('/admin/nhl-sat-engines/runs/7/candidates/12/apply');
});
