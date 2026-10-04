export const baseUrl = '/admin/nhl-sat-engines';

export function discoveryDefaults() {
    return {
        kind: 'discovery', name: '', engine_id: null, model_run_id: '',
        desired_win_pct: 60, min_coverage_pct: 40,
        scope: { mode: 'season', selection: 'first', start_date: '', end_date: '', count: 5, teams: [], game_ids: [] },
    };
}

export function runPayload(data, gameIdsText = '') {
    const input = { ...data };
    delete input.search;
    return {
        ...input,
        scope: {
            ...data.scope,
            selection: ['games', 'days'].includes(data.scope.mode) ? data.scope.selection : 'first',
            start_date: data.scope.start_date || null,
            end_date: data.scope.end_date || null,
            game_ids: data.scope.mode === 'selected'
                ? gameIdsText.split(/[\s,]+/).filter(Boolean).map(Number) : [],
        },
    };
}

export function qualified(result, settings) {
    return result.status === 'complete' && result.correct !== null
        && (!settings.team_abbrev || result.game?.[settings.venue] === settings.team_abbrev)
        && Number(result.confidence) >= Number(settings.confidence_min)
        && Number(result.confidence) <= Number(settings.confidence_max)
        && Number(result.gap) > Number(settings.gap);
}

export function number(value, decimals = 0) {
    return value === null || value === undefined ? '—'
        : Number(value).toLocaleString('en-US', { maximumFractionDigits: decimals });
}

export function active(status) {
    return ['queued', 'running', 'ranking'].includes(status);
}

export function coverageShortfall(coverage, target) {
    return Math.max(0, Number(target) - Number(coverage));
}
