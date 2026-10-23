/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import LeaderList from './Stats/LeaderList.vue';
import LoadingState from './Stats/LoadingState.vue';
import LeaderCardRow from './Stats/LeaderCardRow.vue';
import CategoryNav from './Stats/CategoryNav.vue';
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
    expect(document.querySelector('nav').classList.contains('overflow-x-auto')).toBe(true);
});

it('retains full player reports and separates mobile team rows from the desktop table', () => {
    const source = readFileSync(new URL('./Stats/Index.vue', import.meta.url), 'utf8');
    expect(source).toContain('<StatsTable :key="view + selectedPerspective"');
    expect(source).toContain('aria-label="Mobile team statistics"');
    expect(source).toContain('<li v-for="team in sortedTeams"');
    expect(source).toContain('<div class="hidden overflow-x-auto sm:block"><table');
    expect(source).toContain("['Leaders', 'Skaters', 'Goalies', 'Prospects', 'Teams']");
});


it('displays the exact rewind message while missing stats are calculated', () => {
    const message = "the previous user didn't rewind, so that's what we're doing now. Stand by.";
    app = createApp(LoadingState, { label: 'season leaders', message }); app.mount('#leaders');
    expect(document.querySelector('[role="status"] p').textContent).toBe(message);
    expect(document.querySelector('[role="status"]').getAttribute('aria-busy')).toBe('true');
});
