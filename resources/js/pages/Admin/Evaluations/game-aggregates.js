export const metrics = [['sat_per_60', 'SAT/60'], ['toi', 'TOI min/GP'], ['sat_per_game', 'SAT/GP']];
export const windows = [['train', 'Train'], ['venue_train', 'Train H/A'], ['last_5', 'L5'], ['last_10', 'L10'], ['last_20', 'L20'], ['last_season', 'LSeason'], ['venue_last_season', 'LSeason H/A'], ['opponent_last_10', 'Vs opponent L10']];
const numeric = value => value == null || !Number.isFinite(Number(value)) ? null : Number(value);
export function teamQuarterGames(rows, period) {
    const seasonGames = rows.map(row => numeric(row.inputs.context?.team_records?.season?.games))
        .find(value => value !== null);
    const index = ['q1', 'q2', 'q3', 'q4'].indexOf(period);
    if (seasonGames === undefined || index < 0) return null;
    return Math.min(index === 3 ? 22 : 20, Math.max(0, seasonGames - index * 20));
}
export function quarterDisplay(value, games) {
    const production = numeric(value);
    if (production === null) return '—';
    return `${production.toFixed(2)}${numeric(games) === null ? '' : ` (${Number(games)})`}`;
}
export function satPerGame(rate, minutes) {
    const sat = numeric(rate);
    const toi = numeric(minutes);
    return sat === null || toi === null ? null : sat * toi / 60;
}
export function historical(row, window, metric) {
    if (metric === 'sat_per_game') return satPerGame(historical(row, window, 'sat_per_60'), historical(row, window, 'toi'));
    const value = row.inputs.context?.windows?.[window] ?? row.inputs.windows?.[window];
    if (metric !== 'toi') return numeric(value?.[metric]);
    return value?.games > 0 && numeric(value.toi_seconds) !== null && (value.usable_games > 0 || !(value.individual_missing_games > 0))
        ? Number(value.toi_seconds) / value.games / 60 : null;
}
export function prediction(row, metric) {
    if (metric === 'sat_per_game') return satPerGame(prediction(row, 'sat_per_60'), prediction(row, 'toi'));
    const value = numeric(metric === 'toi' ? row.toi_per_game_seconds : row.sat_per_60);
    return value === null ? null : value / (metric === 'toi' ? 60 : 1);
}
export function actual(row, metric) {
    if (metric === 'sat_per_game') return satPerGame(actual(row, 'sat_per_60'), actual(row, 'toi'));
    const value = numeric(metric === 'toi' ? row.comparison?.toi_seconds : row.comparison?.sat_per_60);
    return value === null ? null : value / (metric === 'toi' ? 60 : 1);
}
export function previous(row, metric) {
    const value = row.inputs.previous;
    const minutes = numeric(value?.toi_seconds) === null ? null : Number(value.toi_seconds) / 60;
    if (metric === 'sat_per_game') return satPerGame(value?.sat_per_60, minutes);
    return metric === 'toi' ? minutes : numeric(value?.sat_per_60);
}
export function quarter(row, metric) {
    const minutes = numeric(row.inputs.quarter_toi_seconds) === null ? null : Number(row.inputs.quarter_toi_seconds) / 60;
    if (metric === 'sat_per_game') return satPerGame(row.inputs.quarter_sat_per_60, minutes);
    return metric === 'toi' ? minutes : numeric(row.inputs.quarter_sat_per_60);
}
export function errorRate(predicted, measured) {
    return predicted == null || measured == null || predicted === 0 ? null : (measured - predicted) / predicted * 100;
}
export function quarterDifference(projected, current) {
    return numeric(projected) === null || numeric(current) === null ? null : Number(projected) - Number(current);
}
export function gameAdjusted(projected, current) {
    const difference = quarterDifference(projected, current);
    return difference === null ? null : Number(projected) + difference;
}
export function gameAdjustedError(projected, current, measured) {
    return errorRate(gameAdjusted(projected, current), numeric(measured));
}
export function projectedQuarter(row, metric) {
    if (metric === 'sat_per_game') return satPerGame(projectedQuarter(row, 'sat_per_60'), projectedQuarter(row, 'toi'));
    const season = historical(row, 'last_season', metric);
    const recent = historical(row, 'last_20', metric);
    return season === null || recent === null ? null : (season + recent) / 2;
}
export function total(rows, getter) {
    const mean = average(rows, getter);
    return mean === null ? null : mean * rows.length;
}
export function average(rows, getter) {
    const values = rows.map(getter).map(numeric).filter(value => value !== null);
    return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
}
export function aggregate(rows, metric) {
    const estimated = {};
    const sum = (key, getter) => {
        const value = total(rows, getter);
        estimated[key] = value === null ? 0 : rows.filter(row => numeric(getter(row)) === null).length;
        return value;
    };
    const predicted = sum('predicted', row => prediction(row, metric));
    const measured = sum('measured', row => actual(row, metric));
    const errors = rows.map(row => errorRate(prediction(row, metric), actual(row, metric))).filter(value => value !== null);
    const quarterValue = sum('quarter', row => projectedQuarter(row, metric));
    const quarterErrors = rows.map(row => errorRate(projectedQuarter(row, metric), actual(row, metric))).filter(value => value !== null);
    return {
        history: Object.fromEntries(windows.map(([key]) => [key, sum(key, row => historical(row, key, metric))])),
        quarter: quarterValue,
        quarterError: errorRate(quarterValue, measured),
        quarterMeasuredPlayers: quarterErrors.length,
        quarterUnder10: quarterErrors.length ? quarterErrors.filter(value => Math.abs(value) < 10).length / quarterErrors.length * 100 : null,
        quarterUnder20: quarterErrors.length ? quarterErrors.filter(value => Math.abs(value) < 20).length / quarterErrors.length * 100 : null,
        current: sum('current', row => historical(row, 'current_quarter', metric)),
        previous: sum('previous', row => previous(row, metric)),
        estimated,
        predicted, measured, error: errorRate(predicted, measured),
        under10: errors.length ? errors.filter(value => Math.abs(value) < 10).length / errors.length * 100 : null,
        under20: errors.length ? errors.filter(value => Math.abs(value) < 20).length / errors.length * 100 : null,
        measuredPlayers: errors.length,
    };
}

// Each lineup contributes one team total, independent of its player count.
export function leagueAggregate(lineups, metric) {
    const teams = lineups.filter(rows => rows.length).map(rows => aggregate(rows, metric));
    const coverage = {};
    const estimated = {};
    const mean = (key, getter) => {
        const available = teams.filter(team => numeric(getter(team)) !== null);
        coverage[key] = available.length;
        estimated[key] = available.filter(team => team.estimated[key] > 0).length;
        return average(available, getter);
    };
    const history = Object.fromEntries(windows.map(([key]) => [key, mean(key, team => team.history[key])]));
    const quarter = mean('quarter', team => team.quarter);
    const current = mean('current', team => team.current);
    const predicted = mean('predicted', team => team.predicted);
    const measured = mean('measured', team => team.measured);
    // Errors must compare the same teams, not two differently populated columns.
    const paired = teams.filter(team => team.predicted !== null && team.measured !== null);
    const sameCoverage = paired.length === coverage.predicted && paired.length === coverage.measured;
    const error = sameCoverage ? errorRate(predicted, measured) : null;
    coverage.error = paired.length;
    estimated.error = paired.filter(team => team.estimated.predicted || team.estimated.measured).length;
    const players = lineups.flat();
    const errors = players.map(row => errorRate(prediction(row, metric), actual(row, metric))).filter(value => value !== null);
    return { history, quarter, current, predicted, measured, error, coverage, estimated, teams: teams.length,
        measuredPlayers: errors.length,
        under10: errors.length ? errors.filter(value => Math.abs(value) < 10).length / errors.length * 100 : null,
        under20: errors.length ? errors.filter(value => Math.abs(value) < 20).length / errors.length * 100 : null };
}
