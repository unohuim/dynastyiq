/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import Games from './Games.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), reload: vi.fn() }));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { template: '<a><slot /></a>' },
    router: { get: mocks.get, reload: mocks.reload },
}));

let app;
const mount = overrides => {
    const host = document.createElement('div');
    document.body.append(host);
    app = createApp({ render: () => h(Games, {
        evaluation: { id: 3, name: 'Evaluation', status: 'ready' },
        filters: { date: '2025-10-07', strength: 'ev', period: 'q1' },
        games: [], work: [], players: [], selected: null, active: null, ...overrides,
    }) });
    app.mount(host);
    return host;
};

beforeEach(() => { vi.useFakeTimers(); vi.clearAllMocks(); });
afterEach(() => { app?.unmount(); document.body.innerHTML = ''; vi.useRealTimers(); });

it('places Predict Day beside Go and sends the date strength and quarter once', async () => {
    mocks.post.mockResolvedValue({ data: { date: '2025-10-07' } });
    const host = mount();
    const button = [...host.querySelectorAll('button')].find(node => node.textContent === 'Predict Day');
    expect(button.previousElementSibling.textContent).toBe('Go');
    button.click();
    await nextTick();
    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(mocks.post).toHaveBeenCalledWith('/admin/nhl-sat-models/next-game/3/predict-day', {
        date: '2025-10-07', strength: 'ev', period: 'q1',
    });
    expect(button.disabled).toBe(true);
});

it('places Simulate beside Predict Day and sends the inclusive date and selected scope', async () => {
    mocks.post.mockResolvedValue({ data: { date: '2025-10-07' } });
    const host = mount();
    const button = [...host.querySelectorAll('button')].find(node => node.textContent === 'Simulate');
    expect(button.previousElementSibling.textContent).toBe('Predict Day');
    button.click();
    await nextTick();
    expect(mocks.post).toHaveBeenCalledWith('/admin/nhl-sat-models/next-game/3/simulate', {
        date: '2025-10-07', strength: 'ev', period: 'q1',
    });
    expect(button.disabled).toBe(true);
});

it('shows saved day progress and disables another active day request', () => {
    const host = mount({ dayWork: { date: '2025-10-07', strength: 'ev', period: 'q1',
        status: 'running', cursor: 1, total: 3, can_resume: false } });
    expect(host.querySelector('[aria-label="Predict Day progress"]').textContent).toContain('1 / 3 games');
    expect([...host.querySelectorAll('button')].find(node => node.textContent === 'Predict Day').disabled).toBe(true);
    expect(mocks.post).not.toHaveBeenCalled();
});

it('surfaces admission errors and allows another explicit attempt', async () => {
    mocks.post.mockRejectedValue({ response: { data: { message: 'Build pregame first.' } } });
    const host = mount();
    const button = [...host.querySelectorAll('button')].find(node => node.textContent === 'Predict Day');
    button.click();
    await Promise.resolve();
    await nextTick();
    expect(host.querySelector('[role="alert"]').textContent).toBe('Build pregame first.');
    expect(button.disabled).toBe(false);
});

it('renders server league summaries without needing other games player lineups', () => {
    const host = mount({
        selected: { id: 1, nhl_game_id: 1, status: 'compared', phase: 'compare', cursor: 1, total: 1 },
        games: [{ nhl_game_id: 1, away_team_id: 1, home_team_id: 2, away_team_abbrev: 'CHI', home_team_abbrev: 'FLA' }],
        players: [{ nhl_player_id: 1, nhl_team_id: 1, player_name: 'Player', sat_per_60: 20,
            toi_per_game_seconds: 900, inputs: { context: { version: 6, role: 'skater', windows: {} } } }],
        leagueSummaries: [{ key: 'sat_per_60', label: 'SAT/60', teams: 28, history: {}, quarter: null,
            current: null, predicted: 123.45, measured: null, error: null, estimated: {}, coverage: {},
            measuredPlayers: 0, under10: null, under20: null }],
    });
    const leagueRow = [...host.querySelectorAll('tr')].find(row => row.textContent.includes('28 saved team lineups'));
    expect(leagueRow.textContent).toContain('123.45');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('requests bounded reliable-bucket refresh when opening a saved version five forecast', async () => {
    mocks.post.mockResolvedValue({ data: { forecast_id: 1 } });
    mount({
        selected: { id: 1, nhl_game_id: 1, status: 'compared', phase: 'compare', cursor: 1, total: 1 },
        players: [{ nhl_player_id: 1, nhl_team_id: 1, player_name: 'Player',
            inputs: { context: { version: 5, role: 'skater', windows: {} } } }],
    });
    await nextTick();
    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(mocks.post).toHaveBeenCalledWith('/admin/nhl-sat-models/next-game/3/forecasts/1', { action: 'context' });
});

it('opens only one sibling evaluation section at a time', async () => {
    const host = mount({
        selected: { id: 1, nhl_game_id: 1, status: 'compared', phase: 'compare', cursor: 1, total: 1 },
        games: [{ nhl_game_id: 1, away_team_id: 1, home_team_id: 2, away_team_abbrev: 'CHI', home_team_abbrev: 'FLA' }],
    });
    const find = label => [...host.querySelectorAll('button')].find(button => button.textContent.trim() === label);
    expect(find('Team aggregates').getAttribute('aria-expanded')).toBe('true');
    find('CHI · Visitors').click();
    await nextTick();
    expect(find('Team aggregates').getAttribute('aria-expanded')).toBe('false');
    expect(find('CHI · Visitors').getAttribute('aria-expanded')).toBe('true');
    find('FLA · Home').click();
    await nextTick();
    expect(find('CHI · Visitors').getAttribute('aria-expanded')).toBe('false');
    expect(find('FLA · Home').getAttribute('aria-expanded')).toBe('true');
    expect(host.querySelector('[aria-label="Away and home team aggregates"]').classList.contains('overflow-auto')).toBe(true);
    expect([...host.querySelectorAll('thead')].every(header => header.classList.contains('sticky'))).toBe(true);
});
