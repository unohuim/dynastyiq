const teamFields = {
    matchup: ['form', 'starting_goalie'],
    lineups: ['lineup', 'display_lineup', 'is_projected', 'predictions', 'injuries'],
    summary: ['pp_goals', 'pp_opportunities'],
    boxscore: ['boxscore'],
};
const pageFields = { meetings: ['head_to_head'], summary: ['recap', 'comparison'], goals: ['events', 'events_available'] };

/** Deferred sections may fill their own fields, never replace the live overview. */
export function applyGamePanel(game, section, data) {
    const next = { ...game };
    for (const side of ['away', 'home']) {
        next[side] = { ...game[side] };
        for (const key of teamFields[section] ?? []) {
            if (Object.hasOwn(data[side] ?? {}, key)) next[side][key] = data[side][key];
        }
    }
    for (const key of pageFields[section] ?? []) {
        if (Object.hasOwn(data, key)) next[key] = data[key];
    }
    return next;
}
