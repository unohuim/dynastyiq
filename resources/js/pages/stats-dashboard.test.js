/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import LeaderList from './Stats/LeaderList.vue';

let app;
let props;
const row = (id, name, points, gaa = 2) => ({ id, name, gp: id, url: `/players/${id}`,
    stats: { points, gaa, sv_pct: 92.5, toi: 1234, corsi: -3, cf_pct: 52.5 } });
const mount = () => { app = createApp(LeaderList, props); app.mount('#leaders'); };
const names = () => [...document.querySelectorAll('tbody a')].map(node => node.textContent);
const button = label => [...document.querySelectorAll('button')].find(node => node.textContent === label);
beforeEach(() => {
    document.body.innerHTML = '<div id="leaders"></div>';
    props = { title: 'Leaders', rows: [row(1, 'Alpha', 3, 1), row(2, 'Beta', 8, 3)],
        tabs: [{ key: 'points', label: 'Points' }, { key: 'gaa', label: 'GAA', low: true, format: 'decimal' }], href: '/stats?view=skaters' };
});
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; });

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
