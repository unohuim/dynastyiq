/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import LeaderList from './Stats/LeaderList.vue';
import LoadingState from './Stats/LoadingState.vue';
import LeaderCardRow from './Stats/LeaderCardRow.vue';
import CategoryNav from './Stats/CategoryNav.vue';
import Teams from './Stats/Teams.vue';
import PlayerCardList from './Stats/Mobile/PlayerCardList.vue';
import { readFileSync } from 'node:fs';

let app;
let props;
const row = (id, name, points, gaa = 2) => ({ id, name, gp: id, url: `/players/${id}`,
    stats: { points, gaa, sv_pct: 92.5, toi: 1234, corsi: -3, cf_pct: 52.5 } });
const mount = () => { app = createApp(LeaderList, props); app.mount('#leaders'); };
const mountCards = (overrides = {}) => {
    app = createApp(LeaderCardRow, { title: 'All Skaters', href: '/stats?view=skaters', cards: [
        { key: 'goals', label: 'G', value: 12, entity: { name: 'Alex Leader', short_name: 'A. Leader',
            subtitle: 'TOR', image: '/leader.png', url: 'https://www.nhl.com/player/1', external: true } },
    ], ...overrides });
    app.mount('#leaders');
};
const names = () => [...document.querySelectorAll('tbody a')].map(node => node.textContent);
const button = label => [...document.querySelectorAll('button')].find(node => node.textContent === label);
beforeEach(() => {
    document.body.innerHTML = '<div id="leaders"></div>';
    props = { title: 'Leaders', rows: [row(1, 'Alpha', 3, 1), row(2, 'Beta', 8, 3)],
        tabs: [{ key: 'points', label: 'Points' }, { key: 'gaa', label: 'GAA', low: true, format: 'decimal' }], href: '/stats?view=skaters' };
});
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; });

it('announces loading statistics and keeps placeholder rows out of accessibility navigation', () => {
    app = createApp(LoadingState, { label: 'goalies' }); app.mount('#leaders');
    expect(document.querySelector('[role=status]').textContent).toContain('Loading goalies');
    expect(document.querySelector('[role=status]').getAttribute('aria-busy')).toBe('true');
    expect(document.querySelector('[aria-hidden=true]')).not.toBeNull();
});
it('offers a retry action after a statistics group fails', () => {
    let retried = false;
    app = createApp(LoadingState, { label: 'skaters', failed: true, onRetry: () => { retried = true; } }); app.mount('#leaders');
    expect(document.querySelector('[role=alert]').textContent).toContain('Unable to load skaters');
    document.querySelector('button').click();
    expect(retried).toBe(true);
});
it('prevents repeated retries while a failed statistics group is already reloading', () => {
    app = createApp(LoadingState, { label: 'skaters', failed: true, reloading: true }); app.mount('#leaders');
    expect(document.querySelector('button').disabled).toBe(true);
    expect(document.querySelector('button').textContent).toBe('Retrying…');
});

it('sorts the default production metric highest first', () => { mount(); expect(names()).toEqual(['Beta', 'Alpha']); });
it('limits each leaderboard to five eligible rows', () => {
    props.rows = Array.from({ length: 9 }, (_, i) => row(i, `Player ${i}`, i)); mount(); expect(names()).toHaveLength(5);
});
it('excludes missing metrics rather than treating them as zero', () => {
    props.rows[0].stats.points = null; mount(); expect(names()).toEqual(['Beta']);
});
it('retains genuine zero-valued metrics', () => {
    props.rows[0].stats.points = 0; mount(); expect(names()).toContain('Alpha');
});
it('displays an explicit empty state without fabricated players', () => {
    props.rows = []; props.empty = 'No eligible results.'; mount(); expect(document.querySelector('[role=tabpanel]').textContent).toContain('No eligible results.');
    expect(document.querySelector('table')).toBeNull();
});
it('sorts goals-against averages lowest first', async () => {
    mount(); button('GAA').click(); await nextTick(); expect(names()).toEqual(['Alpha', 'Beta']);
});
it('formats goals-against averages to two decimal places', async () => {
    mount(); button('GAA').click(); await nextTick(); expect(document.querySelector('tbody').textContent).toContain('1.00');
});
it('formats save percentages as three-decimal ratios', () => {
    props.tabs = [{ key: 'sv_pct', label: 'SV%', format: 'save' }]; mount(); expect(document.querySelector('tbody').textContent).toContain('.925');
});
it('formats percentage metrics with a percent sign', () => {
    props.tabs = [{ key: 'cf_pct', label: 'CF%', format: 'percent' }]; mount(); expect(document.querySelector('tbody').textContent).toContain('52.5%');
});
it('formats ice time as minutes and seconds', () => {
    props.tabs = [{ key: 'toi', label: 'TOI', format: 'time' }]; mount(); expect(document.querySelector('tbody').textContent).toContain('20:34');
});
it('allows production order to be reversed through its column heading', async () => {
    mount(); document.querySelector('th[aria-sort=descending] button').click(); await nextTick(); expect(names()).toEqual(['Alpha', 'Beta']);
});
it('allows player names to be sorted alphabetically', async () => {
    mount(); button('Player').click(); await nextTick(); expect(names()).toEqual(['Alpha', 'Beta']);
});
it('allows games played to determine row order', async () => {
    props.rows[0].gp = 10; mount(); button('GP').click(); await nextTick(); expect(names()[0]).toBe('Alpha');
});
it('resets the column sort when changing metrics', async () => {
    mount(); button('GP').click(); await nextTick(); button('GAA').click(); await nextTick(); expect(names()[0]).toBe('Alpha');
});
it('supports arrow-key tab selection and focus', async () => {
    mount(); button('Points').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); await nextTick();
    expect(button('GAA').getAttribute('aria-selected')).toBe('true'); expect(document.activeElement).toBe(button('GAA'));
});
it('wraps keyboard navigation back to the first metric', async () => {
    mount(); button('Points').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true })); await nextTick();
    expect(button('GAA').getAttribute('aria-selected')).toBe('true');
});
it('links the active tab to its accessible panel', () => {
    mount(); const tab = document.querySelector('[role=tab][aria-selected=true]');
    expect(document.getElementById(tab.getAttribute('aria-controls')).getAttribute('aria-labelledby')).toBe(tab.id);
});
it('omits the games-played column for lines and trends', () => {
    props.hideGp = true; mount(); expect(button('GP')).toBeUndefined();
});
it('opens external player destinations in a separate tab', () => {
    props.rows.forEach(item => { item.external = true; }); mount(); const link = document.querySelector('tbody a');
    expect(link.target).toBe('_blank'); expect(link.rel).toBe('noopener noreferrer');
});
it('keeps internal destinations in the current tab', () => {
    mount(); expect(document.querySelector('tbody a').hasAttribute('target')).toBe(false);
});
it('preserves the category-specific view-all destination', () => {
    mount(); expect(document.querySelector('a').getAttribute('href')).toBe('/stats?view=skaters');
});
it('shows secondary metrics without duplicating the selected metric', () => {
    props.columns = [{ key: 'points', label: 'Points' }, { key: 'corsi', label: 'Corsi' }]; mount();
    expect(document.querySelectorAll('thead th')).toHaveLength(5); expect(document.querySelector('tbody').textContent).toContain('-3');
});

it('places the All heading above a mobile card row and links to its full report', () => {
    mountCards();
    expect(document.querySelector('h2').textContent).toContain('All Skaters');
    expect(document.querySelector('h2 a').getAttribute('href')).toBe('/stats?view=skaters');
    expect(document.querySelector('section').getAttribute('aria-label')).toBe('All Skaters');
});

it('provides a horizontal card row sized for three visible cards', () => {
    mountCards();
    const list = document.querySelector('ul');
    expect(list.classList.contains('grid-flow-col')).toBe(true);
    expect(list.classList.contains('auto-cols-[calc((100%_-_1rem)/3)]')).toBe(true);
    expect(list.classList.contains('overflow-x-auto')).toBe(true);
    expect(list.getAttribute('tabindex')).toBe('0');
});

it('renders the individual leader and metric value in each mobile card', () => {
    mountCards();
    expect(document.querySelector('li').textContent).toContain('12');
    expect(document.querySelector('li').textContent).toContain('A. Leader');
    expect(document.querySelector('li a').getAttribute('aria-label')).toBe('G: 12, Alex Leader');
    expect(document.querySelector('li img').getAttribute('loading')).toBe('lazy');
    expect(document.querySelector('li img').classList.contains('h-16')).toBe(true);
    expect(document.querySelector('li a').classList.contains('border-l-amber-400')).toBe(true);
    expect(document.querySelector('li a').classList.contains('h-[144px]')).toBe(true);
});

it('opens an external leader destination in a new tab', () => {
    mountCards();
    const link = document.querySelector('li a');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
});

it('keeps internal prospect report destinations in the same tab', () => {
    mountCards({ cards: [{ key: 'goals', label: 'G', value: 2,
        entity: { name: 'Prospect', url: '/stats?perspective=prospects', external: false } }] });
    expect(document.querySelector('li a').getAttribute('href')).toBe('/stats?perspective=prospects');
    expect(document.querySelector('li a').hasAttribute('target')).toBe(false);
});

it('shows missing card metrics as unavailable rather than zero', () => {
    mountCards({ cards: [{ key: 'gsax', label: 'GSAx', value: null, entity: null }] });
    expect(document.querySelector('li').textContent).toContain('—');
    expect(document.querySelector('li').textContent).toContain('Unavailable');
    expect(document.querySelector('li img')).toBeNull();
});

it('formats mobile percentage and rate metrics with their proper units', () => {
    mountCards({ cards: [
        { key: 'ev_sv_pct', label: 'EVSV%', value: 94.25 }, { key: 'win_pct', label: 'W%', value: 66.666 },
        { key: 'pts_per_gp', label: 'PTS/GP', value: 1.234 },
    ] });
    const values = [...document.querySelectorAll('li')].map(node => node.textContent);
    expect(values[0]).toContain('94.25%');
    expect(values[1]).toContain('66.7%');
    expect(values[2]).toContain('1.23');
});

it('preserves the sign of goals scored and saved above expected', () => {
    mountCards({ cards: [{ key: 'gax', label: 'GAx', value: 2.5 }, { key: 'gsax', label: 'GSAx', value: -1.25 }] });
    expect(document.querySelector('ul').textContent).toContain('+2.50');
    expect(document.querySelector('ul').textContent).toContain('-1.25');
});

it('keeps mobile category navigation while replacing the desktop hero and leader panels', () => {
    const source = readFileSync(new URL('./Stats/Index.vue', import.meta.url), 'utf8');
    expect(source).toContain('bg-[#03131f] sm:hidden');
    expect(source).toContain('sm:grid lg:grid-cols-[1.6fr_1fr]');
    expect(source).toContain('aria-label="Mobile season leaders"');
    expect(source).toContain("statsCacheMissing ? rewindMessage : ''");
    expect(source).toContain("the previous user didn't rewind, so that's what we're doing now. Stand by.");
    expect(source).not.toContain('Season statistics are not prepared yet.');
    expect(source).toContain('v-if="!hasLeaderCards" label="season leaders"');
    expect(source).toContain('class="hidden space-y-3 sm:block"');
    expect(source).toContain('<LeaderCardRow v-for="row in leaders.mobile_leaders || []"');
});


it('links the Prospects category to the existing full report and marks it active', () => {
    app = createApp(CategoryNav, { view: 'prospects', links: [
        { label: 'Skaters', view: 'skaters', href: '/stats?view=skaters&season_id=20252026' },
        { label: 'Prospects', view: 'prospects', href: '/stats?view=skaters&perspective=prospects&season_id=20252026' },
    ] });
    app.mount('#leaders');
    const active = document.querySelector('[aria-current="page"]');
    expect(active.textContent).toBe('Prospects');
    expect(active.getAttribute('href')).toBe('/stats?view=skaters&perspective=prospects&season_id=20252026');
    expect(document.querySelector('button[aria-expanded]').textContent).toContain('Prospects');
});

it('retains full player reports and uses a responsive compact team table', () => {
    const source = readFileSync(new URL('./Stats/Index.vue', import.meta.url), 'utf8');
    expect(source).toContain('<StatsTable :key="view + selectedPerspective"');
    const teams = readFileSync(new URL('./Stats/Teams.vue', import.meta.url), 'utf8');
    expect(source).toContain('<Teams v-else-if=');
    expect(teams).toContain('data-compact-team-row');
    expect(teams).toContain('data-compact-team-table');
    expect(source).toContain("['Leaders', 'Skaters', 'Goalies', 'Prospects', 'Teams']");
});


it('displays the exact rewind message while missing stats are calculated', () => {
    const message = "the previous user didn't rewind, so that's what we're doing now. Stand by.";
    app = createApp(LoadingState, { label: 'season leaders', message }); app.mount('#leaders');
    expect(document.querySelector('[role="status"] p').textContent).toBe(message);
    expect(document.querySelector('[role="status"]').getAttribute('aria-busy')).toBe('true');
});


const mountTeams = (overrides = {}) => {
    app = createApp(Teams, { season: '20262027', seasons: ['20262027'], rows: [
        { team: 'TOR', name: 'Toronto Maple Leafs', logo: '/tor.svg', gp: 2, points: 4, wins: 2, gf: 8, gaa: 2.5, win_pct: 100, cf_pct: 52, toi_total_seconds: 7440 },
        { team: 'MTL', name: 'Montreal Canadiens', logo: '/mtl.svg', gp: 2, points: 1, wins: 0, gf: 3, gaa: null, win_pct: 0, cf_pct: null, toi_total_seconds: null },
    ], ...overrides });
    app.mount('#leaders');
};
it('renders every requested Teams column without duplicate hits or blocks', () => {
    mountTeams();
    expect([...document.querySelectorAll('thead th')].map(node => node.textContent.trim().replace(/^#\s*/, '').replace(/ [↓↑]$/, '')))
        .toEqual(['Team', 'GP', 'W', 'PTS', 'GF', 'GA', 'W%', 'GAA', 'Corsi%', 'Hits', 'Blocks', 'SAT', 'HDSAT', 'Saves', 'SOG', 'PIM', 'PP%', 'PK%']);
});
it('filters prepared Teams rows immediately by name or abbreviation', async () => {
    mountTeams();
    const input = document.querySelector('#team-search');
    input.value = 'mtl'; input.dispatchEvent(new Event('input')); await nextTick();
    expect(document.querySelectorAll('tbody tr')).toHaveLength(1);
    expect(document.querySelector('tbody').textContent).toContain('Montreal Canadiens');
});
it('shows Teams unavailable rates as dashes and sorts them after numeric GAA', async () => {
    mountTeams();
    [...document.querySelectorAll('thead button')].find(node => node.textContent.trim() === 'GAA').click(); await nextTick();
    expect(document.querySelector('tbody tr').textContent).toContain('Toronto');
    expect(document.querySelectorAll('tbody tr')[1].textContent).toContain('—');
});
it('slices Teams counting stats per game while retaining GP, standings and percentages', () => {
    mountTeams({ filters: { slice: 'pgp' } });
    const values = [...document.querySelector('tbody tr').querySelectorAll('td')].map(node => node.textContent.trim());
    expect(values.slice(1, 8)).toEqual(['2', '2', '4', '4.00', '—', '100.0%', '2.50']);
    expect(document.querySelector('thead').textContent).toContain('GF/GP');
});
it('uses actual Teams elapsed time for per-sixty counting-stat presentation', () => {
    mountTeams({ filters: { slice: 'p60' } });
    expect(document.querySelector('tbody tr').querySelectorAll('td')[4].textContent).toBe((8 * 3600 / 7440).toFixed(2));
    expect(document.querySelector('thead').textContent).toContain('GF/60');
});
it('opens Teams filters with one toggle and no player-position or goalie report controls', async () => {
    mountTeams();
    const toggle = [...document.querySelectorAll('button')].find(node => node.textContent.includes('Filters'));
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    toggle.click(); await nextTick();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    const source = readFileSync(new URL('./Stats/Teams.vue', import.meta.url), 'utf8');
    expect(source).toContain('aria-label="Game type"');
    expect(source).not.toContain('aria-label="Position"');
});


it('shows visible compact loading feedback without replacing a retained report with skeleton rows', () => {
    app = createApp(LoadingState, { label: 'goalies', compact: true }); app.mount('#leaders');
    const status = document.querySelector('[role="status"]');
    expect(status.textContent).toContain('Loading goalies');
    expect(status.classList.contains('sr-only')).toBe(false);
    expect(status.getAttribute('aria-busy')).toBe('true');
    expect(status.querySelector('svg')).not.toBeNull();
    expect(status.querySelector('[aria-hidden="true"].space-y-3')).toBeNull();
});

it('keeps explicit loading boundaries for preserved player tabs and deferred team data', () => {
    const source = readFileSync(new URL('./Stats/Index.vue', import.meta.url), 'utf8');
    expect(source).toContain('<LoadingState v-if="seamlessTableSwitch" :label="pendingView" compact />');
    expect(source).toContain('<LoadingState v-if="pendingView && !seamlessTableSwitch"');
    expect(source).toContain(':aria-busy="Boolean(pendingView)"');
    expect(source).toContain("['skaters', 'goalies', 'prospects'].includes(pendingView.value)");
    expect(source).not.toContain('v-if="seamlessTableSwitch" role="status" class="sr-only"');
});

const mountCompact = (overrides = {}) => {
    app = createApp(PlayerCardList, {
        rows: [
            { player_id: 1, name: 'Connor McDavid', team: 'EDM', pos: 'C', age: 29, gp: 10, pts: 20, sog: 30, avatar_url: '/connor.png' },
            { player_id: 2, name: 'Leon Draisaitl', team: 'EDM', pos: 'C/L', age: 30, gp: 12, pts: 15, sog: 25 },
        ],
        headings: [{ key: 'name', label: 'Player' }, { key: 'gp', label: 'GP' }, { key: 'pts', label: 'PTS' }, { key: 'sog', label: 'SOG' }],
        settings: { dashboardTable: true, sortKey: 'pts', sortDirection: 'desc' },
        ...overrides,
    });
    app.mount('#leaders');
};

it('renders compact dashboard players as ranked table rows while retaining every report metric', () => {
    mountCompact();
    expect(document.querySelectorAll('[data-compact-player-row]')).toHaveLength(2);
    expect([...document.querySelectorAll('thead th')].map(node => node.textContent.trim())).toEqual(['#', 'Player', 'PTS↓', 'GP', 'SOG']);
    expect(document.querySelector('tbody tr').textContent).toContain('C. McDavid');
    expect(document.querySelector('tbody tr td').textContent).toBe('1');
});

it('keeps full player names available alongside compact team and position identity', () => {
    mountCompact();
    expect(document.querySelector('[title="Connor McDavid"]').textContent).toBe('C. McDavid');
    expect(document.querySelector('tbody tr th').textContent).toContain('EDM · C');
});

it('retains genuine zero counting stats in compact rows', () => {
    mountCompact({ rows: [{ player_id: 1, name: 'Zero Goals', gp: 1, pts: 0, sog: 0 }] });
    expect([...document.querySelectorAll('tbody td')].map(node => node.textContent.trim())).toEqual(['1', '0', '1', '0']);
});

it('shows missing compact metrics as unavailable rather than blank or zero', () => {
    mountCompact({ rows: [{ player_id: 1, name: 'Missing Data', gp: 1, pts: null, sog: undefined }] });
    expect([...document.querySelectorAll('tbody td')].map(node => node.textContent.trim())).toEqual(['1', '—', '1', '—']);
});

it('formats compact goalie columns as GAA save percentage and wins', () => {
    mountCompact({ settings: { dashboardTable: true, dashboardGoalies: true, sortKey: 'wins', sortDirection: 'desc' },
        rows: [{ player_id: 1, name: 'Ilya Sorokin', team: 'NYI', pos: 'G', gaa: 2, sv_pct: 94.3, wins: 7 }],
        headings: [{ key: 'wins', label: 'W' }, { key: 'sv_pct', label: 'SV%' }, { key: 'gaa', label: 'GAA' }],
    });
    expect([...document.querySelectorAll('thead th')].map(node => node.textContent.trim())).toEqual(['#', 'Player', 'GAA', 'SV%', 'W↓']);
    expect([...document.querySelectorAll('tbody td')].map(node => node.textContent.trim())).toEqual(['1', '2.00', '.943', '7']);
});

it('preserves ratio-based goalie save percentages and perfect saves in compact rows', () => {
    mountCompact({ settings: { dashboardTable: true, dashboardGoalies: true, sortKey: 'sv_pct', sortDirection: 'desc' },
        rows: [{ player_id: 1, name: 'Perfect Saves', sv_pct: 1 }, { player_id: 2, name: 'Ratio Saves', sv_pct: .925 }],
        headings: [{ key: 'sv_pct', label: 'SV%' }],
    });
    expect(document.querySelector('tbody').textContent).toContain('1.000');
    expect(document.querySelector('tbody').textContent).toContain('.925');
});

it('keeps compact points per game readable without changing its numeric source', () => {
    const rows = [{ player_id: 1, name: 'Rate Skater', pts_pg: 2.6666666667 }];
    mountCompact({ rows, headings: [{ key: 'pts_pg', label: 'PTS/GP' }] });
    expect(document.querySelector('tbody').textContent).toContain('2.67');
    expect(rows[0].pts_pg).toBe(2.6666666667);
});

it('exposes available prospect age team and points as compact columns', () => {
    mountCompact({ settings: { dashboardTable: true, dashboardProspects: true, sortKey: 'pts', sortDirection: 'desc' },
        rows: [{ player_id: 1, name: 'Future Skater', age: 19, team: 'TOR', league: 'OHL', pos: 'C', pts: 20 }],
        headings: [{ key: 'pts', label: 'PTS' }, { key: 'age', label: 'Age' }, { key: 'team', label: 'Team' }],
    });
    expect([...document.querySelectorAll('thead th')].map(node => node.textContent.trim())).toEqual(['#', 'Player', 'Age', 'Team', 'PTS↓']);
    expect(document.querySelector('tbody th').textContent).toContain('C · OHL');
});

it('delegates compact header sorting through the existing sort callback', async () => {
    const changes = [];
    mountCompact({ onSortChange: change => changes.push(change) });
    [...document.querySelectorAll('thead button')].find(node => node.textContent.includes('PTS')).click();
    await nextTick();
    expect(changes).toEqual([{ sortKey: 'pts', sortDirection: 'asc' }]);
});

it('preserves nested stat values and existing local player search in compact tables', () => {
    mountCompact({ rows: [{ player_id: 1, name: 'Nested Skater', stats: { gp: 8, pts: 12, sog: 0 } },
        { player_id: 2, name: 'Other Player', gp: 2, pts: 1 }], searchTerm: 'nested' });
    expect(document.querySelectorAll('tbody tr')).toHaveLength(1);
    expect(document.querySelector('tbody').textContent).toContain('12');
    expect(document.querySelector('tbody').textContent).not.toContain('Other Player');
});

it('uses initials when a compact player portrait fails', async () => {
    mountCompact();
    document.querySelector('tbody img').dispatchEvent(new Event('error'));
    await nextTick();
    expect(document.querySelector('tbody tr img')).toBeNull();
    expect(document.querySelector('tbody tr th').textContent).toContain('CM');
});

it('retains legacy player cards outside the dashboard compact-table opt-in', () => {
    mountCompact({ settings: { sortKey: 'pts', sortDirection: 'desc' } });
    expect(document.querySelector('[data-compact-player-table]')).toBeNull();
    expect(document.querySelectorAll('[data-vue-stats-mobile-card]')).toHaveLength(2);
});

it('opens and dismisses compact category navigation with Escape and restores focus', async () => {
    app = createApp(CategoryNav, { view: 'goalies', links: [
        { view: 'skaters', label: 'Skaters', href: '/stats?view=skaters' },
        { view: 'goalies', label: 'Goalies', href: '/stats?view=goalies' },
    ] });
    app.mount('#leaders');
    const toggle = document.querySelector('button[aria-expanded]');
    toggle.click(); await nextTick();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(document.getElementById(toggle.getAttribute('aria-controls')).textContent).toContain('Skaters');
    toggle.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await nextTick();
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(toggle);
});

it('leaves Leaders category navigation in its existing tab format', () => {
    app = createApp(CategoryNav, { view: 'leaders', links: [{ view: 'leaders', label: 'Leaders', href: '/stats' }] });
    app.mount('#leaders');
    expect(document.querySelector('button[aria-expanded]')).toBeNull();
    expect(document.querySelector('nav').classList.contains('overflow-x-auto')).toBe(true);
});

it('keeps team identity pinned while all existing team metrics remain accessible', () => {
    mountTeams();
    expect(document.querySelector('[data-compact-team-table]').getAttribute('tabindex')).toBe('0');
    expect(document.querySelectorAll('table')).toHaveLength(1);
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2);
    expect(document.querySelector('tbody tr td').classList.contains('sticky')).toBe(true);
    expect(document.querySelector('thead').textContent).toContain('PK%');
});
