/** Civil-date arithmetic independent of the browser's local timezone. */
export function shiftSlateDate(date, days) {
    const value = new Date(`${date}T12:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

const easternClock = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', hourCycle: 'h23',
});

/** Yesterday until 10:59:59 Eastern; today from 11:00, including daylight saving. */
export function defaultSlateDate(now = new Date()) {
    const parts = Object.fromEntries(easternClock.formatToParts(now).map(part => [part.type, part.value]));
    const date = `${parts.year}-${parts.month}-${parts.day}`;
    return Number(parts.hour) < 11 ? shiftSlateDate(date, -1) : date;
}

/** Find the next exact rollover without assuming Eastern is always UTC-5. */
export function slateRolloverDelay(now = new Date()) {
    const current = defaultSlateDate(now);
    const start = now.getTime();
    let low = start;
    let high = start + 36 * 60 * 60 * 1000;
    while (high - low > 1) {
        const midpoint = Math.floor((low + high) / 2);
        if (defaultSlateDate(new Date(midpoint)) === current) low = midpoint;
        else high = midpoint;
    }
    return Math.max(1, high - start);
}

export function slateDateLabels(date) {
    const value = new Date(`${date}T12:00:00Z`);
    return {
        weekday: new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'UTC' }).format(value).toUpperCase(),
        date: new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(value).toUpperCase(),
        full: new Intl.DateTimeFormat('en-US', { dateStyle: 'full', timeZone: 'UTC' }).format(value),
    };
}

/** Relative labels use the actual Eastern calendar day, independently of the slate rollover. */
export function slateNavigationLabel(date, now = new Date()) {
    const parts = Object.fromEntries(easternClock.formatToParts(now).map(part => [part.type, part.value]));
    const today = `${parts.year}-${parts.month}-${parts.day}`;
    const value = new Date(`${date}T12:00:00Z`);
    const relative = date === today ? 'Today'
        : date === shiftSlateDate(today, -1) ? 'Yesterday'
            : date === shiftSlateDate(today, 1) ? 'Tomorrow'
                : new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'UTC' }).format(value);
    const monthDay = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(value);
    return `${relative} ${monthDay}`;
}

export function scoreboardStatus(game) {
    if (game.live_data_unavailable) return 'Unavailable';
    const state = (game.game_state ?? '').toUpperCase();
    if (['FINAL', 'OFF'].includes(state)) return 'Final';
    if (state === 'PRE') return 'Starting soon';
    if (state === 'PPD') return 'Postponed';
    if (state === 'CNCL') return 'Cancelled';
    if (state && state !== 'FUT') return 'Live';
    const time = game.start_time_utc ? new Date(game.start_time_utc) : null;
    return time && Number.isFinite(time.getTime())
        ? new Intl.DateTimeFormat('en-US', { timeZone: 'America/Toronto', hour: 'numeric', minute: '2-digit' }).format(time)
        : 'TBD';
}

/** Updating a range replaces matching games without duplicates or stale scores. */
export function mergeScoreboardGames(existing, incoming) {
    const games = new Map(existing.map(game => [game.nhl_game_id, game]));
    incoming.forEach(game => games.set(game.nhl_game_id, game));
    return [...games.values()].sort((a, b) => a.game_date.localeCompare(b.game_date)
        || (a.start_time_utc ?? 'z').localeCompare(b.start_time_utc ?? 'z')
        || a.nhl_game_id - b.nhl_game_id);
}

export function scoreboardDays(start, end, games) {
    if (!start || !end) return [];
    const byDate = new Map();
    games.forEach(game => {
        if (!byDate.has(game.game_date)) byDate.set(game.game_date, []);
        byDate.get(game.game_date).push(game);
    });
    const days = [];
    for (let date = start; date <= end; date = shiftSlateDate(date, 1)) {
        days.push({ date, labels: slateDateLabels(date), games: byDate.get(date) ?? [] });
    }
    return days;
}
