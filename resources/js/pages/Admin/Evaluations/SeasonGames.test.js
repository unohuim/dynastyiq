/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import SeasonGames from './SeasonGames.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn(), detail: vi.fn(), navigate: vi.fn(), reload: vi.fn() }));
vi.mock('axios', () => ({ default: { post: mocks.post, get: mocks.detail } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.navigate, reload: mocks.reload } }));
let app;
let props;
const run = (id = 1, extra = {}) => ({ id, algorithm_version: 'custom_ev_pp_v4', season_id: '20252026', status: 'complete',
    total_games: 100, completed_games: 100, definition: { entrypoint: 'season_games' }, wins: 58, losses: 42, win_pct: 58, created_at: '2026-11-19 12:00:00', ...extra });
const game = () => ({ id: 42, nhl_game_id: 2025020001, status: 'complete', game_date: '2025-11-01',
    away_team_abbrev: 'BOS', home_team_abbrev: 'TOR', away_goals_prediction: 2.4, home_goals_prediction: 3.1,
    away_actual_goals: 2, home_actual_goals: 3, away_win_probability: .4, home_win_probability: .6,
    recommendation: 'TOR', winner_correct: true });
const summary = () => ({ wins: 58, losses: 42, win_pct: 58, predicted: 100, unpicked: 0, excluded: 0,
    team_goals: { prediction: 2.9, actual: 3.0, err_pct: 3.45, p10: 18, p20: 37, both_zero: 0 },
    game_goals: { prediction: 5.8, actual: 6.0, err_pct: 3.45, p10: 25, p20: 45, both_zero: 0 } });
const detail = () => ({ prediction_id: 42, teams: { away: { team_abbrev: 'BOS', predicted: { ev_goals: 2, pp_goals: .4 },
    actual: { ev_goals: 2, pp_goals: 0 }, players: [{ nhl_player_id: 101, name: 'Skater One', role: 'skater', history: {},
        lanes: { EV: { prediction: { seconds: 900, sat: 5, sog: 3, goals: .5 }, actual: { seconds: 1000, sat: 6, sog: 4, goals: 1 } },
            PP: { prediction: { seconds: 120, sat: 2, sog: 1, goals: .2 }, actual: { seconds: 100, sat: 3, sog: 2, goals: 0 } } } },
    { nhl_player_id: 102, name: 'Starter Two', role: 'goalie', goalie: { actual: { saves: 25, shots_against: 28, goals_against: 3 },
        prediction: { sog_against: 27, goals_against: 2.8 }, pregame: { windows: { last_10: { on_ice_save_pct: 91 } } } } }] } } });
const mount = (extra = {}) => {
    props = reactive({ model: { id: 7, name: 'Sep2026', target_season_id: '20252026', train_season_ids: ['20222023', '20232024', '20242025'] },
        readiness: { ready: true, reasons: [], total_games: 100 }, runs: [], selected: null,
        games: { data: [], links: [] }, summary: null, comparison: null, ...extra });
    const host = document.createElement('div'); document.body.append(host);
    app = createApp({ render: () => h(SeasonGames, props) }); app.mount(host); return host;
};
const selected = (extra = {}) => ({ runs: [run()], selected: run(), games: { data: [game()], links: [] }, summary: summary(), ...extra });
const button = (host, label) => [...host.querySelectorAll('button')].find(node => node.textContent.trim() === label);
const settle = async () => { await Promise.resolve(); await Promise.resolve(); await nextTick(); };

beforeEach(() => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-11-19T12:00:00Z')); vi.clearAllMocks();
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    mocks.detail.mockResolvedValue({ data: detail() });
});
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; vi.restoreAllMocks(); vi.useRealTimers(); });

it('identifies the selected SAT model and its Train and Test seasons', () => {
    const host = mount(); expect(host.textContent).toContain('Sep2026'); expect(host.textContent).toContain('20252026');
    expect(host.textContent).toContain('20222023, 20232024, 20242025');
});
it('disables Run Season Games with actionable readiness reasons', () => {
    const host = mount({ readiness: { ready: false, reasons: ['Missing starter context.'], missing_games: [] } });
    expect(button(host, 'Run Season Games').disabled).toBe(true); expect(host.textContent).toContain('Missing starter context.');
});
it('blocks a second run while this model already has active work', () => {
    const host = mount({ runs: [run(1, { status: 'running' })] });
    expect(button(host, 'Run Season Games').disabled).toBe(true);
});
it('queues through the model-owned endpoint and selects the returned run', async () => {
    mocks.post.mockResolvedValue({ data: { run_id: 12 } });
    const host = mount(); button(host, 'Run Season Games').click(); await settle();
    expect(mocks.post).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games/runs');
    expect(mocks.navigate).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games', { run: 12 }, expect.objectContaining({ preserveState: true }));
});
it('prevents repeated submissions while admission is pending', async () => {
    mocks.post.mockReturnValue(new Promise(() => {}));
    const host = mount(); const control = button(host, 'Run Season Games'); control.click(); control.click(); await nextTick();
    expect(mocks.post).toHaveBeenCalledTimes(1); expect(control.disabled).toBe(true); expect(control.textContent).toBe('Queuing…');
});
it('shows admission validation errors without removing saved results', async () => {
    mocks.post.mockRejectedValue({ response: { data: { errors: { run: ['Model inputs changed.'] } } } });
    const host = mount(selected()); button(host, 'Run Season Games').click(); await settle();
    expect(host.querySelector('[role="alert"]').textContent).toContain('Model inputs changed.'); expect(host.textContent).toContain('BOS @ TOR');
});
it('gives a scoped fallback when admission fails without a JSON error', async () => {
    mocks.post.mockRejectedValue(new Error('offline'));
    const host = mount(); button(host, 'Run Season Games').click(); await settle();
    expect(host.textContent).toContain('Unable to queue this season run.');
});
it('selects an existing run without requesting new predictions', () => {
    const host = mount({ runs: [run(3)] }); host.querySelector('aside button').click();
    expect(mocks.navigate).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games', { run: 3 }, expect.any(Object)); expect(mocks.post).not.toHaveBeenCalled();
});
it('shows win percentage and its actual picked-game denominator above the game list', () => {
    const host = mount(selected()); const accuracy = host.querySelector('[aria-label="Run accuracy"]');
    expect(accuracy.textContent).toContain('58.00%'); expect(accuracy.textContent).toContain('58 / 100');
});
it('preserves the three familiar goal accuracy columns and their comparison scope', () => {
    const host = mount(selected()); const section = host.querySelector('[aria-label="Run accuracy"]');
    for (const label of ['err%', 'p10', 'p20', 'Per team', 'Per game', 'retained-bucket regulation EV + PP']) expect(section.textContent).toContain(label);
});
it('shows preparation phase while no Test game has been saved yet', () => {
    const host = mount(selected({ selected: run(1, { status: 'running', checkpoint: { phase: 'fit', cursor: 12 } }), games: { data: [], links: [] } }));
    expect(host.textContent).toContain('Fitting Train defensive buckets'); expect(host.textContent).toContain('Preparing Train history');
});
it('exposes Resume only for an eligible saved run', () => {
    const host = mount(selected({ selected: run(1, { status: 'failed', can_resume: true, last_error: 'Worker timeout' }) }));
    expect(button(host, 'Resume run')).toBeTruthy(); expect(host.textContent).toContain('Worker timeout');
});
it('resumes the existing run rather than creating another run', async () => {
    mocks.post.mockResolvedValue({ data: { run_id: 1 } });
    const host = mount(selected({ selected: run(1, { status: 'failed', can_resume: true }) })); button(host, 'Resume run').click(); await settle();
    expect(mocks.post).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games/runs/1/resume');
});
it('limits comparison options to other runs for the same Test season', () => {
    const host = mount(selected({ runs: [run(1), run(2), run(3, { season_id: '20242025' })] }));
    const options = [...host.querySelectorAll('select option')].map(node => node.value); expect(options).toEqual(['', '2']);
});
it('selects a baseline for comparison without changing either algorithm', async () => {
    const host = mount(selected({ runs: [run(1), run(2)] })); const select = host.querySelector('select'); select.value = '2'; select.dispatchEvent(new Event('change')); await nextTick();
    expect(mocks.navigate).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games', { run: 1, compare: '2' }, expect.any(Object)); expect(mocks.post).not.toHaveBeenCalled();
});
it('shows exactly which matching picks were corrected or spoiled', () => {
    const host = mount(selected({ comparison: { baseline_id: 2, matched: 90, unmatched: 10, before_win_pct: 55, after_win_pct: 57,
        changes: [{ game_id: 2025020001, date: '2025-11-01', away: 'BOS', home: 'TOR', before: false, after: true }] } }));
    const section = host.querySelector('[aria-label="Compare runs"]'); expect(section.textContent).toContain('90 matching picked games'); expect(section.textContent).toContain('LOSS → WIN');
});
it('loads one games saved detail only when expanded', async () => {
    const host = mount(selected()); expect(mocks.detail).not.toHaveBeenCalled(); host.querySelector('[aria-label="Game predictions"] button').click(); await settle();
    expect(mocks.detail).toHaveBeenCalledWith('/admin/nhl-sat-models/7/season-games/runs/1/games/42'); expect(host.textContent).toContain('Skater One');
});
it('retains loaded details when a row is collapsed and expanded again', async () => {
    const host = mount(selected()); const control = host.querySelector('[aria-label="Game predictions"] button'); control.click(); await settle(); control.click(); await nextTick(); control.click(); await settle();
    expect(mocks.detail).toHaveBeenCalledTimes(1); expect(control.getAttribute('aria-expanded')).toBe('true');
});
it('offers scoped retry feedback when loading details fails', async () => {
    mocks.detail.mockRejectedValueOnce(new Error('offline'));
    const host = mount(selected()); const control = host.querySelector('[aria-label="Game predictions"] button'); control.click(); await settle();
    expect(host.textContent).toContain('Close and reopen to retry.'); control.click(); await nextTick(); control.click(); await settle(); expect(mocks.detail).toHaveBeenCalledTimes(2);
});
it('changes EV and PP detail without another server request', async () => {
    const host = mount(selected()); host.querySelector('[aria-label="Game predictions"] button').click(); await settle(); button(host, 'PP').click(); await nextTick();
    expect(host.textContent).toContain('PP players'); expect(button(host, 'PP').getAttribute('aria-pressed')).toBe('true'); expect(mocks.detail).toHaveBeenCalledTimes(1);
});
it('identifies the actual selected goalie while labeling the neutral baseline', async () => {
    const host = mount(selected()); host.querySelector('[aria-label="Game predictions"] button').click(); await settle();
    expect(host.textContent).toContain('Starter Two · Starting goalie'); expect(host.textContent).toContain('25 SV · 28 SA · 3 GA'); expect(host.textContent).toContain('Neutral goalie baseline · opposition forecast:'); expect(host.textContent).toContain('91.00%');
});
it('keeps missing historical values unknown rather than replacing them with zeros', async () => {
    const data = detail(); data.teams.away.players[0].lanes.EV.actual = { seconds: null, sat: null, sog: null, goals: null }; mocks.detail.mockResolvedValue({ data });
    const host = mount(selected()); host.querySelector('[aria-label="Game predictions"] button').click(); await settle(); expect(host.textContent).toContain('5.00 / —');
});
it('polls only saved progress and never starts another prediction', async () => {
    mount(selected({ runs: [run(1, { status: 'running' })] })); await vi.advanceTimersByTimeAsync(5000);
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['runs', 'selected', 'summary', 'games', 'comparison', 'activeRunId'], preserveScroll: true })); expect(mocks.post).not.toHaveBeenCalled();
});
it('does not poll completed runs or hidden tabs', async () => {
    mount(selected()); await vi.advanceTimersByTimeAsync(5000); expect(mocks.reload).not.toHaveBeenCalled();
    props.runs = [run(1, { status: 'running' })]; vi.spyOn(document, 'hidden', 'get').mockReturnValue(true); await vi.advanceTimersByTimeAsync(5000); expect(mocks.reload).not.toHaveBeenCalled();
});
it('clears its timer when leaving the page', async () => {
    mount(selected({ runs: [run(1, { status: 'running' })] })); app.unmount(); app = null; await vi.advanceTimersByTimeAsync(5000); expect(mocks.reload).not.toHaveBeenCalled();
});
it('rechecks readiness without starting engine work', () => {
    const host = mount({ readiness: { ready: false, reasons: ['Pregame not complete.'] } }); button(host, 'Check again').click();
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['readiness'], data: { refresh_readiness: 1 } })); expect(mocks.post).not.toHaveBeenCalled();
});
it('shows a truthful empty baseline rather than a fabricated zero win rate', () => {
    const host = mount(); expect(host.textContent).toContain('Your first run establishes the baseline.'); expect(host.textContent).not.toContain('0.00%');
});
it('disables admission when the active run is on another history page', () => {
    const host = mount({ activeRunId: 90, runs: [run(1)] });
    expect(button(host, 'Run Season Games').disabled).toBe(true);
});
it('shows saved team form and schedule context alongside component results', async () => {
    const data = detail(); data.teams.away.pregame = { last_5: { games: 5, win_pct: 60, corsi_pct: 54, shooting_pct: 11, pdo: 102 } };
    data.teams.away.schedule = { back_to_back: true, games_including_target_last_7_days: 4 };
    mocks.detail.mockResolvedValue({ data });
    const host = mount(selected()); host.querySelector('[aria-label="Game predictions"] button').click(); await settle();
    expect(host.textContent).toContain('Saved team pregame context'); expect(host.textContent).toContain('Back-to-back: Yes');
    expect(host.textContent).toContain('60.00%'); expect(host.textContent).toContain('102.00');
});

it('shows historical input counts separately from the empty Test prediction count', () => {
    const host = mount(selected({ selected: run(1, { status: 'running', checkpoint: { phase: 'replay', stage: 'history', processed: 318, total: 3936, unit: 'games' } }), summary: { ...summary(), predicted: 0 } }));
    const progress = host.querySelector('[aria-label="Run progress"]');
    expect(progress.textContent).toContain('Preparing historical inputs');
    expect(progress.textContent).toContain('318 / 3,936 games');
    expect(progress.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('8');
});

it('shows defensive fit progress using bucket units', () => {
    const host = mount(selected({ selected: run(1, { status: 'running', checkpoint: { stage: 'fit', processed: 12, total: 24, unit: 'buckets' } }) }));
    const progress = host.querySelector('[aria-label="Run progress"]');
    expect(progress.textContent).toContain('Fitting Train defensive buckets');
    expect(progress.textContent).toContain('12 / 24 buckets');
    expect(progress.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('50');
});

it('switches from historical preparation to Test progress when new props arrive', async () => {
    const host = mount(selected({ selected: run(1, { status: 'running', checkpoint: { stage: 'history', processed: 3936, total: 3936, unit: 'games' } }) }));
    const progress = host.querySelector('[aria-label="Run progress"]');
    props.selected = run(1, { status: 'running', checkpoint: { stage: 'predictions', processed: 12, total: 1312, unit: 'games' } });
    await nextTick();
    expect(host.querySelector('[aria-label="Run progress"]')).toBe(progress);
    expect(progress.textContent).toContain('Predicting Test games');
    expect(progress.textContent).toContain('12 / 1,312 games');
});

it('displays elapsed minutes and seconds alongside last activity', () => {
    const host = mount(selected({ selected: run(1, { elapsed_seconds: 325, last_activity_at: '2026-11-19T11:59:58Z' }) }));
    const progress = host.querySelector('[aria-label="Run progress"]');
    expect(progress.textContent).toContain('Elapsed 5m 25s');
    expect(progress.querySelector('time').getAttribute('datetime')).toBe('2026-11-19T11:59:58Z');
});

it('formats longer elapsed runs as hours and minutes', () => {
    const host = mount(selected({ selected: run(1, { elapsed_seconds: 7560 }) }));
    expect(host.querySelector('[aria-label="Run progress"]').textContent).toContain('Elapsed 2h 6m');
});

it('keeps legacy checkpoint totals unknown rather than inventing a percentage', () => {
    const host = mount(selected({ selected: run(1, { status: 'running', checkpoint: { phase: 'replay', cursor: 318, total: null } }) }));
    const progress = host.querySelector('[aria-label="Run progress"]');
    expect(progress.textContent).toContain('Preparing historical inputs');
    expect(progress.textContent).toContain('318 games');
    expect(progress.querySelector('[role="progressbar"]').hasAttribute('aria-valuenow')).toBe(false);
});

it('polls repeatedly without global loading indicators or prediction submissions', async () => {
    mocks.reload.mockImplementationOnce(options => options.onFinish?.())
        .mockImplementationOnce(options => options.onFinish?.());
    mount(selected({ runs: [run(1, { status: 'running' })] }));
    await vi.advanceTimersByTimeAsync(10000);
    expect(mocks.reload).toHaveBeenCalledTimes(2);
    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ showProgress: false, preserveScroll: true, preserveState: true }));
    expect(mocks.post).not.toHaveBeenCalled();
});

it('preserves expanded game details while checkpoint progress updates', async () => {
    const host = mount(selected());
    const control = host.querySelector('[aria-label="Game predictions"] button');
    control.click(); await settle();
    props.selected = run(1, { status: 'running', checkpoint: { stage: 'predictions', processed: 2, total: 100, unit: 'games' } });
    await nextTick();
    expect(host.querySelector('[aria-label="Game predictions"] button')).toBe(control);
    expect(control.getAttribute('aria-expanded')).toBe('true');
    expect(host.textContent).toContain('Skater One');
    expect(mocks.detail).toHaveBeenCalledTimes(1);
});
