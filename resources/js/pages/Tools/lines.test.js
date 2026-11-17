/* @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import Lines from './Lines.vue';

describe('latest team lines tool', () => {
    let app;
    let state;
    const toggle = id => document.querySelector(`#lines-toggle-${id}`);
    const panel = id => document.querySelector(`#lines-panel-${id}`);
    const mount = () => {
        app = createApp({ render: () => h(Lines, { lines: state.lines }) });
        app.mount('#lines-test');
    };
    beforeEach(() => {
        document.body.innerHTML = '<div id="lines-test"></div>';
        vi.stubGlobal('fetch', vi.fn());
        const line = (id, names, toi = '10:23', slots = ['LW', 'C', 'RW', 'LD', 'RD']) => ({
            unit_id: id, toi, toi_seconds: 623,
            players: names.map((name, index) => ({ id: id * 10 + index, name, short_name: `${name[0]} ${name.split(' ').slice(1).join(' ')}`, slot: slots[index], nhl_id: 9000000 + id * 10 + index })),
        });
        state = reactive({ lines: { season_id: '20262027', teams: [
            { id: 10, abbrev: 'TOR', name: 'Toronto Maple Leafs', logo: '/TOR.svg',
                game: { id: 100, date: '2026-11-17', date_label: 'Tue Nov 17', opponent: 'MTL', venue: 'home', href: '/games/100' },
                strengths: {
                    EV: { F: [line(1, ['Forward One', 'Forward Two', 'Forward Three']), line(2, ['Forward Four', 'Forward Five', 'Forward Six'], '8:01')], D: [line(3, ['Defense One', 'Defense Two'], '10:23', ['LD', 'RD'])] },
                    PP: { units: [line(4, ['Forward One', 'Forward Two', 'Forward Three', 'Forward Four', 'Defense One'])] },
                    PK: { units: [line(6, ['Forward Five', 'Forward Six', 'Defense Two', 'Defense Three'], '2:00', ['C', 'RW', 'LD', 'RD'])] },
                },
            },
            { id: 8, abbrev: 'MTL', name: 'Montreal Canadiens', logo: '/MTL.svg',
                game: { id: 100, date: '2026-11-17', date_label: 'Tue Nov 17', opponent: 'TOR', venue: 'away', href: '/games/100' },
                strengths: { EV: { F: [], D: [] }, PP: { units: [] }, PK: { units: [] } },
            },
        ] } });
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('shows the Lines heading and every supplied team header', () => {
        mount();
        expect(document.querySelector('h1').textContent).toBe('Lines');
        expect(document.querySelectorAll('h2 button')).toHaveLength(2);
        expect(toggle(10).textContent).toContain('Toronto Maple Leafs');
        expect(toggle(8).textContent).toContain('Montreal Canadiens');
    });

    it('starts every team collapsed', () => {
        mount();
        [10, 8].forEach(id => {
            expect(toggle(id).getAttribute('aria-expanded')).toBe('false');
            expect(panel(id).getAttribute('aria-hidden')).toBe('true');
            expect(panel(id).hasAttribute('inert')).toBe(true);
        });
    });

    it('opens the chosen team with an accessible disclosure relationship', async () => {
        mount();
        toggle(10).click();
        await nextTick();
        expect(toggle(10).getAttribute('aria-expanded')).toBe('true');
        expect(toggle(10).getAttribute('aria-controls')).toBe(panel(10).id);
        expect(panel(10).getAttribute('aria-labelledby')).toBe(toggle(10).id);
        expect(panel(10).hasAttribute('inert')).toBe(false);
        expect(panel(10).getAttribute('aria-hidden')).toBe('false');
    });

    it('closes the same team on a second press', async () => {
        mount();
        toggle(10).click();
        await nextTick();
        toggle(10).click();
        await nextTick();
        expect(toggle(10).getAttribute('aria-expanded')).toBe('false');
        expect(panel(10).classList.contains('grid-rows-[0fr]')).toBe(true);
    });

    it('allows independent teams to remain expanded together', async () => {
        mount();
        toggle(10).click();
        toggle(8).click();
        await nextTick();
        expect(toggle(10).getAttribute('aria-expanded')).toBe('true');
        expect(toggle(8).getAttribute('aria-expanded')).toBe('true');
    });

    it('keeps another team expanded when closing a team', async () => {
        mount();
        toggle(10).click();
        toggle(8).click();
        await nextTick();
        toggle(10).click();
        await nextTick();
        expect(toggle(8).getAttribute('aria-expanded')).toBe('true');
    });

    it('animates the caret and panel with reduced-motion support', async () => {
        mount();
        expect(toggle(10).querySelector('svg').classList.contains('motion-reduce:transition-none')).toBe(true);
        expect(panel(10).classList.contains('transition-[grid-template-rows,opacity]')).toBe(true);
        expect(panel(10).classList.contains('motion-reduce:transition-none')).toBe(true);
        toggle(10).click();
        await nextTick();
        expect(toggle(10).querySelector('svg').classList.contains('rotate-180')).toBe(true);
        expect(panel(10).classList.contains('grid-rows-[1fr]')).toBe(true);
    });

    it('uses native non-submit buttons and exposes keyboard focus styling', () => {
        mount();
        expect(toggle(10).tagName).toBe('BUTTON');
        expect(toggle(10).type).toBe('button');
        expect(toggle(10).classList.contains('focus-visible:ring-2')).toBe(true);
    });

    it('shows the latest game date and home opponent in the header', () => {
        mount();
        expect(toggle(10).textContent).toContain('Tue Nov 17 · vs MTL');
    });

    it('uses the away marker for visiting teams', () => {
        mount();
        expect(toggle(8).textContent).toContain('Tue Nov 17 · @ TOR');
    });

    it('renders EV PP and PK as separately labelled sections', () => {
        mount();
        expect([...panel(10).querySelectorAll('h3')].map(node => node.textContent)).toEqual(['Even Strength EV', 'Power Play PP', 'Penalty Kill PK']);
        expect(panel(10).querySelector('[aria-label="TOR Power Play"]')).not.toBeNull();
    });

    it('separates EV positions but keeps PP and PK as complete units', () => {
        mount();
        expect([...panel(10).querySelectorAll('h4')].map(node => node.textContent)).toEqual(['Forwards', 'Defense', 'Units', 'Units']);
    });

    it('preserves the supplied TOI order and sequential line ranks', () => {
        mount();
        const rows = panel(10).querySelector('[aria-label="TOR Even Strength"] ol').querySelectorAll('li');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('F1');
        expect(rows[0].textContent).toContain('F One');
        expect(rows[0].textContent).toContain('10:23');
        expect(rows[1].textContent).toContain('F2');
        expect(rows[1].textContent).toContain('8:01');
    });

    it('shows defense ranks independently of the forward ranks', () => {
        mount();
        const row = panel(10).querySelector('[aria-label="TOR Even Strength"]').querySelectorAll('ol')[1].querySelector('li');
        expect(row.textContent).toContain('D1');
        expect(row.textContent).toContain('D One');
    });

    it('displays the complete five-player PP unit in one group', () => {
        mount();
        const text = panel(10).querySelector('[aria-label="TOR Power Play"] ol').textContent;
        ['F One', 'F Two', 'F Three', 'F Four', 'D One'].forEach(name => expect(text).toContain(name));
    });

    it('places the extra forward beside the defenseman in PP back spots', () => {
        mount();
        const section = panel(10).querySelector('[aria-label="TOR Power Play"]');
        expect(section.querySelectorAll('ol')).toHaveLength(1);
        expect(section.querySelector('[data-slot="LD"]').textContent).toContain('F Four');
        expect(section.querySelector('[data-slot="RD"]').textContent).toContain('D One');
    });

    it('shows shared TOI with a descriptive accessible label', () => {
        mount();
        expect(panel(10).querySelector('[aria-label="10:23 shared time on ice"]').textContent).toBe('10:23');
        expect(panel(10).textContent).toContain('Players appear once per strength');
    });

    it('links to the correct completed game from each team', () => {
        mount();
        expect(panel(10).querySelector('a').getAttribute('href')).toBe('/games/100');
        expect(panel(8).querySelector('a').getAttribute('href')).toBe('/games/100');
    });

    it('shows honest empty group messages without filling from another strength', () => {
        mount();
        expect(panel(8).querySelectorAll('ol')).toHaveLength(0);
        expect(panel(8).textContent).toContain('No forward combinations available.');
        expect(panel(8).textContent).toContain('No defensive combinations available.');
        expect(panel(8).textContent).toContain('No unit combinations available.');
        expect(panel(8).textContent).not.toContain('Forward One');
    });

    it('handles a team with no completed game without a broken game link', () => {
        state.lines.teams[1].game = null;
        mount();
        expect(toggle(8).textContent).toContain('No completed game this season');
        expect(panel(8).textContent).toContain('No completed game available');
        expect(panel(8).querySelector('a')).toBeNull();
    });

    it('shows a season-level empty state when no teams are available', () => {
        state.lines.teams = [];
        mount();
        expect(document.body.textContent).toContain('No teams available for this season.');
        expect(document.querySelectorAll('h2 button')).toHaveLength(0);
    });

    it('does not fetch or mutate supplied lines when teams are expanded', async () => {
        const before = JSON.stringify(state.lines);
        mount();
        toggle(10).click();
        toggle(8).click();
        await nextTick();
        expect(fetch).not.toHaveBeenCalled();
        expect(JSON.stringify(state.lines)).toBe(before);
    });

    it('escapes player and team names rather than interpreting markup', () => {
        state.lines.teams[0].name = '<script>team()</script>';
        state.lines.teams[0].strengths.EV.F[0].players[0].name = '<img src=x onerror=bad()> Forward';
        mount();
        expect(toggle(10).textContent).toContain('<script>team()</script>');
        expect(panel(10).querySelector('[title="<img src=x onerror=bad()> Forward"]')).not.toBeNull();
        expect(document.querySelector('script, img[onerror]')).toBeNull();
    });

    it('keeps header logos decorative and omits missing images', () => {
        state.lines.teams[1].logo = null;
        mount();
        expect(toggle(10).querySelector('img').getAttribute('alt')).toBe('');
        expect(toggle(8).querySelector('img')).toBeNull();
    });

    it('shows abbreviated player names with the full name on hover', () => {
        const player = state.lines.teams[0].strengths.EV.F[0].players[0];
        player.name = 'Macklin Celebrini';
        player.short_name = 'M Celebrini';
        mount();
        const spot = panel(10).querySelector('[title="Macklin Celebrini"]');
        expect(spot.textContent).toContain('M Celebrini');
        expect(panel(10).textContent).not.toContain('Macklin Celebrini');
    });

    it('renders supplied center and wing spots in distinct grid columns', () => {
        mount();
        const section = panel(10).querySelector('[aria-label="TOR Even Strength"]');
        expect(section.querySelector('[data-slot="LW"]').classList.contains('col-start-1')).toBe(true);
        expect(section.querySelector('[data-slot="C"]').classList.contains('col-start-3')).toBe(true);
        expect(section.querySelector('[data-slot="RW"]').classList.contains('col-start-5')).toBe(true);
        expect(section.querySelector('[data-slot="C"]').textContent).toContain('F Two');
    });

    it('uses the second row for special-team back spots and the first for EV defensive pairs', () => {
        mount();
        expect(panel(10).querySelector('[aria-label="TOR Power Play"] [data-slot="LD"]').classList.contains('row-start-2')).toBe(true);
        expect(panel(10).querySelector('[aria-label="TOR Even Strength"] [data-slot="LD"]').classList.contains('row-start-1')).toBe(true);
    });

    it('keeps a five-forward power-play unit intact in all five display spots', () => {
        const back = state.lines.teams[0].strengths.PP.units[0].players[4];
        back.name = 'Forward Seven';
        back.short_name = 'F Seven';
        mount();
        const section = panel(10).querySelector('[aria-label="TOR Power Play"]');
        expect(section.querySelectorAll('[data-slot]')).toHaveLength(5);
        expect(section.querySelector('[data-slot="RD"]').textContent).toContain('F Seven');
        expect(section.textContent).toContain('PP1');
        expect([...section.querySelectorAll('h4')].map(node => node.textContent)).toEqual(['Units']);
    });

    it('shows four-player PK units without creating a fifth player', () => {
        mount();
        const section = panel(10).querySelector('[aria-label="TOR Penalty Kill"]');
        expect(section.querySelectorAll('[data-slot]')).toHaveLength(4);
        expect(section.textContent).toContain('PK1');
        expect(section.querySelector('[data-slot="LW"]')).toBeNull();
    });

    it('shows three-player PK units with a center and two back spots', () => {
        const players = state.lines.teams[0].strengths.PK.units[0].players;
        state.lines.teams[0].strengths.PK.units[0].players = [players[0], players[2], players[3]];
        mount();
        const section = panel(10).querySelector('[aria-label="TOR Penalty Kill"]');
        expect([...section.querySelectorAll('[data-slot]')].map(node => node.dataset.slot)).toEqual(['C', 'LD', 'RD']);
    });
});
