/* @vitest-environment jsdom */
import { afterEach, expect, it } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import ShellDisclosure from '../../../layouts/ShellDisclosure.vue';
import TeamContext from './TeamContext.vue';
import { aggregate, leagueAggregate, average, total, errorRate, historical, satPerGame, prediction, actual, previous, quarter, metrics, teamQuarterGames, quarterDisplay } from './game-aggregates';

let app;
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; });
const player = () => ({ sat_per_60: 20, toi_per_game_seconds: 900,
    inputs: { quarter_sat_per_60: 18, quarter_toi_seconds: 840,
        context: { windows: { train: { sat_per_60: 17, games: 2, toi_seconds: 1800 },
            last_season: { sat_per_60: 16, games: 1, toi_seconds: 780 },
            last_20: { sat_per_60: 20, games: 1, toi_seconds: 900 },
            current_quarter: { sat_per_60: 19, games: 1, toi_seconds: 960 } } } },
    comparison: { sat_per_60: 22, toi_seconds: 990 } });

it('displays Train TOI and SAT per game from the complete sample despite excluded appearances', () => {
    const row = player();
    row.inputs.context.windows.train = { games: 2, usable_games: 2, individual_missing_games: 14, toi_seconds: 1800, sat_per_60: 12 };
    expect(historical(row, 'train', 'toi')).toBe(15);
    expect(historical(row, 'train', 'sat_per_game')).toBe(3);
    delete row.inputs.context.windows.train.usable_games;
    expect(historical(row, 'train', 'toi')).toBeNull();
    row.inputs.context.windows.train = { games: 0, usable_games: 0, individual_missing_games: 14, toi_seconds: 0, sat_per_60: null };
    expect(historical(row, 'train', 'toi')).toBeNull();
    expect(historical(row, 'train', 'sat_per_game')).toBeNull();
});

it('appends only a parenthesized game count to available quarter production', () => {
    expect(quarterDisplay(215.03, 1)).toBe('215.03 (1)');
    expect(quarterDisplay(0, 2)).toBe('0.00 (2)');
    expect(quarterDisplay(null, 0)).toBe('—');
    expect(quarterDisplay(215.03, null)).toBe('215.03');
});

it('counts completed team games rather than adding player appearances', () => {
    const row = player();
    row.inputs.context.team_records = { season: { games: 3 } };
    expect(teamQuarterGames([row, row], 'q1')).toBe(3);
    expect(teamQuarterGames([row], 'q2')).toBe(0);
    row.inputs.context.team_records.season.games = 23;
    expect(teamQuarterGames([row], 'q1')).toBe(20);
    expect(teamQuarterGames([row], 'q2')).toBe(3);
    row.inputs.context.team_records.season.games = 82;
    expect(teamQuarterGames([row], 'q4')).toBe(22);
});

it('does not invent team counts from missing records or invalid quarters', () => {
    expect(teamQuarterGames([player()], 'q1')).toBeNull();
    expect(teamQuarterGames([], 'q1')).toBeNull();
    const row = player();
    row.inputs.context.team_records = { season: { games: 3 } };
    expect(teamQuarterGames([row], 'unknown')).toBeNull();
});

it('sums the same lineup across the aggregate columns', () => {
    const result = aggregate([player(), player()], 'sat_per_60');
    expect(result.history.train).toBe(34);
    expect(result.quarter).toBe(36);
    expect(result.current).toBe(38);
    expect(result.predicted).toBe(40);
    expect(result.measured).toBe(44);
    expect(result.error).toBe(10);
    expect(result.under10).toBe(0);
    expect(result.under20).toBe(100);
});

it('compares the pregame quarter blend separately without changing the saved game projection', () => {
    const row = player();
    row.inputs.quarter_sat_per_60 = 999;
    row.inputs.context.windows.last_season.sat_per_60 = 20;
    row.inputs.context.windows.last_20.sat_per_60 = 24;
    const result = aggregate([row], 'sat_per_60');
    expect(result.quarter).toBe(22);
    expect(result.predicted).toBe(20);
    expect(result.error).toBe(10);
    expect(result.quarterError).toBe(0);
    expect(result.quarterUnder10).toBe(100);
    expect(result.quarterUnder20).toBe(100);
    delete row.inputs.context.windows.last_season;
    expect(aggregate([row], 'sat_per_60').quarter).toBeNull();
    expect(aggregate([row], 'sat_per_60').quarterUnder10).toBeNull();
});
it('converts ice time to minutes per game for both forecast and actual', () => {
    const result = aggregate([player()], 'toi');
    expect(result.history.train).toBe(15);
    expect(result.quarter).toBe(14);
    expect(result.current).toBe(16);
    expect(result.predicted).toBe(15);
    expect(result.measured).toBe(16.5);
    expect(result.error).toBe(10);
});
it('fills incomplete totals using available lineup peers without inventing individual observations', () => {
    const missing = player();
    missing.sat_per_60 = null;
    missing.comparison = null;
    const result = aggregate([player(), missing], 'sat_per_60');
    expect(result.predicted).toBe(40);
    expect(result.measured).toBe(44);
    expect(result.error).toBe(10);
    expect(result.estimated.predicted).toBe(1);
    expect(result.estimated.measured).toBe(1);
    expect(result.measuredPlayers).toBe(1);
    expect(aggregate([], 'sat_per_60').under20).toBeNull();
});

it('excludes missing values from averages but fills missing contributions to totals', () => {
    const values = [10, 20, null];
    expect(average(values, value => value)).toBe(15);
    expect(total(values, value => value)).toBe(45);
    expect(total([null], value => value)).toBeNull();
    expect(total([0, null], value => value)).toBe(0);
});

it('uses each windows own available peers for venue Train and opponent history', () => {
    const first = player();
    const second = player();
    first.inputs.context.windows.venue_train = { sat_per_60: 12 };
    second.inputs.context.windows.opponent_last_10 = { sat_per_60: 25 };
    const result = aggregate([first, second], 'sat_per_60');
    expect(result.history.venue_train).toBe(24);
    expect(result.history.opponent_last_10).toBe(50);
    expect(result.estimated.venue_train).toBe(1);
    expect(result.estimated.opponent_last_10).toBe(1);
});

it('averages team totals equally rather than averaging individual player rates', () => {
    const result = leagueAggregate([[player(), player()], [player()]], 'sat_per_60');
    expect(result.predicted).toBe(30);
    expect(result.measured).toBe(33);
    expect(result.quarter).toBe(27);
    expect(result.error).toBe(10);
    expect(result.coverage.predicted).toBe(2);
    expect(result.measuredPlayers).toBe(3);
    expect(result.under10).toBe(0);
    expect(result.under20).toBe(100);
});

it('provides league ice time in minutes on the same summed lineup scale', () => {
    const result = leagueAggregate([[player(), player()], [player()]], 'toi');
    expect(result.predicted).toBe(22.5);
    expect(result.measured).toBe(24.75);
    expect(result.history.train).toBe(22.5);
    expect(result.current).toBe(24);
});

it('reports available team coverage and suppresses mismatched population errors', () => {
    const missing = player();
    missing.comparison = null;
    delete missing.inputs.context.windows.train;
    const result = leagueAggregate([[player()], [missing]], 'sat_per_60');
    expect(result.history.train).toBe(17);
    expect(result.coverage.train).toBe(1);
    expect(result.coverage.predicted).toBe(2);
    expect(result.coverage.measured).toBe(1);
    expect(result.error).toBeNull();
});

it('flags imputed league inputs and preserves wholly unavailable columns', () => {
    const missing = player();
    missing.sat_per_60 = null;
    const result = leagueAggregate([[player(), missing]], 'sat_per_60');
    expect(result.predicted).toBe(40);
    expect(result.estimated.predicted).toBe(1);
    expect(result.history.last_5).toBeNull();
    expect(result.coverage.last_5).toBe(0);
    expect(leagueAggregate([], 'toi').predicted).toBeNull();
});
it('uses prediction as denominator and preserves error direction', () => {
    expect(errorRate(20, 18)).toBe(-10);
    expect(errorRate(20, 22)).toBe(10);
    expect(errorRate(0, 5)).toBeNull();
    expect(errorRate(20, null)).toBeNull();
});
it('does not invent missing quarter history or partial ice time', () => {
    const row = player();
    delete row.inputs.context.windows.current_quarter;
    row.inputs.context.windows.train.individual_missing_games = 1;
    expect(aggregate([row], 'sat_per_60').current).toBeNull();
    expect(historical(row, 'train', 'toi')).toBeNull();
});
it.each([true, false])('respects initial disclosure state %s and toggles accessibly', async defaultOpen => {
    const host = document.createElement('div');
    document.body.append(host);
    app = createApp({ render: () => h(ShellDisclosure, { label: 'Team', defaultOpen }, () => 'Contents') });
    app.mount(host);
    const button = host.querySelector('button');
    expect(button.getAttribute('aria-expanded')).toBe(String(defaultOpen));
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    expect(panel.getAttribute('aria-hidden')).toBe(String(!defaultOpen));
    button.click();
    await nextTick();
    expect(button.getAttribute('aria-expanded')).toBe(String(!defaultOpen));
    expect(panel.getAttribute('aria-hidden')).toBe(String(defaultOpen));
});

it('converts matching player rates and minutes without rounding intermediate values', () => {
    expect(satPerGame(18, 15)).toBe(4.5);
    expect(satPerGame('22.5', '16')).toBe(6);
    expect(satPerGame(0, 15)).toBe(0);
    expect(satPerGame(20, 0)).toBe(0);
    for (const missing of [null, undefined, NaN, Infinity]) {
        expect(satPerGame(missing, 15)).toBeNull();
        expect(satPerGame(18, missing)).toBeNull();
    }
});

it('derives SAT per game independently for every evidence source', () => {
    const row = player();
    row.inputs.previous = { sat_per_60: 12, toi_seconds: 1200 };
    expect(historical(row, 'train', 'sat_per_game')).toBe(4.25);
    expect(historical(row, 'current_quarter', 'sat_per_game')).toBeCloseTo(19 * 16 / 60);
    expect(quarter(row, 'sat_per_game')).toBe(4.2);
    expect(prediction(row, 'sat_per_game')).toBe(5);
    expect(actual(row, 'sat_per_game')).toBe(6.05);
    expect(previous(row, 'sat_per_game')).toBe(4);
    const result = aggregate([row], 'sat_per_game');
    expect(result.error).toBeCloseTo(21);
    expect(result.under10).toBe(0);
    expect(result.under20).toBe(0);
});

it('does not convert missing or incomplete player evidence into zero attempts', () => {
    const row = player();
    row.inputs.context.windows.train.individual_missing_games = 1;
    row.toi_per_game_seconds = null;
    row.comparison.toi_seconds = null;
    row.inputs.quarter_toi_seconds = null;
    expect(historical(row, 'train', 'sat_per_game')).toBeNull();
    expect(prediction(row, 'sat_per_game')).toBeNull();
    expect(actual(row, 'sat_per_game')).toBeNull();
    expect(quarter(row, 'sat_per_game')).toBeNull();
    expect(previous(row, 'sat_per_game')).toBeNull();
    expect(aggregate([row], 'sat_per_game').error).toBeNull();
});

it('sums player conversions instead of multiplying summed team rates and minutes', () => {
    const second = player();
    second.sat_per_60 = 12;
    second.toi_per_game_seconds = 1200;
    const result = aggregate([player(), second], 'sat_per_game');
    expect(result.predicted).toBe(9);
    expect(result.quarter).toBe(8.4);
    expect(result.history.train).toBe(8.5);
    expect(leagueAggregate([[player(), second], [player()]], 'sat_per_game').predicted).toBe(7);
});

it('marks existing lineup imputation after conversion without scoring missing players', () => {
    const missing = player();
    missing.toi_per_game_seconds = null;
    const result = aggregate([player(), missing], 'sat_per_game');
    expect(prediction(missing, 'sat_per_game')).toBeNull();
    expect(result.predicted).toBe(10);
    expect(result.estimated.predicted).toBe(1);
    expect(result.measuredPlayers).toBe(1);
    const league = leagueAggregate([[player(), missing]], 'sat_per_game');
    expect(league.estimated.predicted).toBe(1);
    expect(league.coverage.predicted).toBe(1);
});

it('renders a third skater row with its own prediction actual and error', async () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = { ...player(), nhl_player_id: 1, player_name: 'Example Skater' };
    app = createApp({ render: () => h(TeamContext, { name: 'CHI', side: 'Visitors', rows: [row] }) });
    app.mount(host);
    expect(metrics.map(([key]) => key)).toEqual(['sat_per_60', 'toi', 'sat_per_game']);
    expect(host.querySelector('[scope="rowgroup"]').getAttribute('rowspan')).toBe('4');
    const derived = [...host.querySelectorAll('tbody tr')].find(tr => tr.querySelector('[scope="row"]')?.textContent === 'SAT/GP');
    expect(derived.textContent).toContain('4.25');
    expect(derived.textContent).toContain('5.00');
    expect(derived.textContent).toContain('6.05');
    expect(derived.textContent).toContain('+21.00%');
});

it('keeps goalie rows separate from skater attempt conversions', () => {
    const host = document.createElement('div');
    document.body.append(host);
    app = createApp({ render: () => h(TeamContext, { name: 'CHI', rows: [player()], goalies: true }) });
    app.mount(host);
    expect(host.querySelector('[scope="rowgroup"]').getAttribute('rowspan')).toBe('2');
    expect(host.textContent).toContain('GSAx/100 xGA');
    expect(host.textContent).not.toContain('SAT/GP');
    expect(host.querySelector('[data-toi-share]')).toBeNull();
});

it('shows player-only ice time shares using separate forecast and actual lineup totals', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const first = { ...player(), nhl_player_id: 1 };
    const second = { ...player(), nhl_player_id: 2 };
    second.toi_per_game_seconds = 1800;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [first, second] }) });
    app.mount(host);
    const headers = [...host.querySelectorAll('thead th')].map(cell => cell.textContent);
    const shares = [...host.querySelectorAll('[data-toi-share]')].map(row => [...row.querySelectorAll('td')].map(cell => cell.textContent));
    expect(shares[0][headers.indexOf('Proj') - 2]).toBe('33.33%');
    expect(shares[1][headers.indexOf('Proj') - 2]).toBe('66.67%');
    expect(shares[0][headers.indexOf('Actual') - 2]).toBe('50.00%');
    expect(shares[1][headers.indexOf('Actual') - 2]).toBe('50.00%');
    expect(metrics).toHaveLength(3);
});

it('leaves missing ice time and zero-denominator shares unavailable', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    row.toi_per_game_seconds = null;
    row.comparison.toi_seconds = 0;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row] }) });
    app.mount(host);
    const headers = [...host.querySelectorAll('thead th')].map(cell => cell.textContent);
    const cells = [...host.querySelectorAll('[data-toi-share] td')];
    expect(cells[headers.indexOf('Proj') - 2].textContent).toBe('—');
    expect(cells[headers.indexOf('Actual') - 2].textContent).toBe('—');
});

it('renders player quarter projections that reconcile with summed team projections and errors', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const first = { ...player(), nhl_player_id: 1, player_name: 'First' };
    const second = { ...player(), nhl_player_id: 2, player_name: 'Second' };
    second.inputs.context.windows.last_season.sat_per_60 = 12;
    second.inputs.context.windows.last_20.sat_per_60 = 16;
    second.sat_per_60 = 10;
    const rows = [first, second];
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows, period: 'q2' }) });
    app.mount(host);
    expect(host.textContent).toContain('Proj Q2');
    expect(host.textContent).toContain('err% ProjQ2');
    const values = [...host.querySelectorAll('[data-quarter-projection]')].map(cell => Number(cell.textContent));
    expect(values).toEqual([18, 14, 4.2, 14, 14, 3.27]);
    const errors = [...host.querySelectorAll('[data-quarter-error]')].map(cell => cell.textContent);
    expect(errors[0]).toBe('+22.22%');
    expect(errors[3]).toBe('+57.14%');
    const team = aggregate(rows, 'sat_per_60');
    expect(team.quarter).toBe(values[0] + values[3]);
    expect(team.predicted).toBe(30);
    expect(team.quarterError).toBe(37.5);
    expect(team.error).toBeCloseTo(46.6666667);
});

it('renders unavailable player quarter evidence as dashes rather than zero errors', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    delete row.inputs.context.windows.last_season;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row] }) });
    app.mount(host);
    expect([...host.querySelectorAll('[data-quarter-projection]')].map(cell => cell.textContent)).toEqual(['—', '—', '—']);
    expect([...host.querySelectorAll('[data-quarter-error]')].map(cell => cell.textContent)).toEqual(['—', '—', '—']);
});

it('shows selected quarter actual averages and prior appearances for all three player metrics', () => {
    const host = document.createElement('div');
    document.body.append(host);
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [player()], period: 'q2' }) });
    app.mount(host);
    expect(host.textContent).toContain('Current Q2');
    expect([...host.querySelectorAll('[data-quarter-test]')].map(cell => cell.textContent))
        .toEqual(['19.00 (1)', '16.00 (1)', '5.07 (1)']);
});

it('leaves quarter Test blank when no prior quarter production exists', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    delete row.inputs.context.windows.current_quarter;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row] }) });
    app.mount(host);
    expect(host.textContent).toContain('Current Q1');
    expect([...host.querySelectorAll('[data-quarter-test]')].map(cell => cell.textContent))
        .toEqual(['—', '—', '—']);
});

it('shows Game Adj and measures its error using the adjusted value as denominator', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row], period: 'q1' }) });
    app.mount(host);
    const headers = [...host.querySelectorAll('thead th')].map(cell => cell.textContent);
    expect(headers).toContain('Proj');
    expect(headers).toContain('Actual');
    expect(headers).toContain('Current Q1');
    expect(headers).toContain('Game Adj %');
    expect(headers).not.toContain('Forecast');
    expect(host.querySelector('[data-quarter-difference]').textContent).toBe('-1.00');
    expect(host.querySelector('[data-game-adjusted]').textContent).toBe('17.00');
    expect(host.querySelector('[data-game-adjusted-error]').textContent).toBe('+29.41%');
});

it('reports minus one hundred percent for zero actuals against a nonzero Game Adj', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    row.comparison.sat_per_60 = 0;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row] }) });
    app.mount(host);
    expect(host.querySelector('[data-quarter-difference]').textContent).toBe('-1.00');
    expect(host.querySelector('[data-game-adjusted-error]').textContent).toBe('-100.00%');
});

it('leaves Game Adj error unavailable for a zero adjusted value or missing quarter evidence', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const zero = { ...player(), nhl_player_id: 1 };
    zero.inputs.context.windows.current_quarter.sat_per_60 = 36;
    const missing = { ...player(), nhl_player_id: 2 };
    delete missing.inputs.context.windows.current_quarter;
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [zero, missing] }) });
    app.mount(host);
    expect(host.querySelector('[data-game-adjusted]').textContent).toBe('0.00');
    const errors = [...host.querySelectorAll('[data-game-adjusted-error]')];
    expect(errors[0].textContent).toBe('—');
    expect(errors[3].textContent).toBe('—');
});

it('aligns skater columns with team columns and sums previous player values', () => {
    const host = document.createElement('div');
    document.body.append(host);
    const row = player();
    row.inputs.previous = { sat_per_60: 12, toi_seconds: 900 };
    row.inputs.context.windows.venue_last_season = { sat_per_60: 16, games: 2, toi_seconds: 1800 };
    app = createApp({ render: () => h(TeamContext, { name: 'MTL', rows: [row], period: 'q1' }) });
    app.mount(host);
    expect([...host.querySelectorAll('thead th')].map(cell => cell.textContent)).toEqual([
        'Player', 'Metric', 'Train', 'Train H/A', 'L5', 'L10', 'L20', 'LSeason', 'LSeason H/A',
        'Vs opponent L10', 'Previous', 'Proj Q1', 'Current Q1', 'Q1 Diff', 'Game Adj', 'Proj', 'Actual',
        'err% Proj', 'err% ProjQ1', 'Game Adj %',
    ]);
    expect(aggregate([row, row], 'sat_per_60').previous).toBe(24);
    expect(aggregate([row, row], 'toi').previous).toBe(30);
    expect(aggregate([row, row], 'sat_per_game').previous).toBe(6);
    expect(aggregate([row, row], 'sat_per_60').history.venue_last_season).toBe(32);
});
