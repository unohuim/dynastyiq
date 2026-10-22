/* @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import Dashboard from './Dashboard/Index.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

describe('NHL dashboard', () => {
    let app;
    let data;
    const story = id => ({ id: String(id), title: `Story ${id}`, summary: `Summary ${id}`, category: 'NHL News',
        url: `https://www.nhl.com/news/story-${id}`, image_url: `/story-${id}.jpg`, published_at: '2026-10-21T12:00:00Z' });
    const section = title => [...document.querySelectorAll('section')].find(node => node.querySelector('h2')?.textContent === title);
    const mount = () => { app = createApp(Dashboard, { dashboard: data }); app.mount('#dashboard'); };
    beforeEach(() => {
        document.body.innerHTML = '<div id="dashboard"></div>';
        data = {
            featured: [story(1), story(2), story(3)], headlines: [story(4), story(5), story(6), story(7), story(8), story(9)],
            videos: [story(10), story(11), story(12), story(13)], performance_date: '2026-10-21',
            performers: [1, 2, 3, 4].map(id => ({ nhl_player_id: id, name: `Player ${id}`, avatar: `/player-${id}.png`,
                team: 'TOR', team_logo: '/tor.svg', position: 'C', url: `/players/${id}`, goalie: false,
                goals: 1, assists: 2, points: 3, badge: '3-point night' })),
            transactions: [{ id: 1, name: 'Player Move', description: 'A real signing', type: 'Signing', team: 'TOR', date: '2026-10-21', url: '/transactions' }],
            injuries: [{ id: 1, name: 'Injured Player', availability: 'out', position: 'C', team: 'TOR', body_part: 'Upper body', url: '/injuries' }],
            team_trends: [
                { team: 'TOR', offense: 4, defense: 3, special_teams: .8, offense_delta: .5, defense_delta: .1, special_teams_delta: .2, url: '/games' },
                { team: 'BOS', offense: 3, defense: 2, special_teams: .9, offense_delta: -.1, defense_delta: -.3, special_teams_delta: .1, url: '/games' },
            ],
            player_trends: [
                { nhl_player_id: 1, name: 'Rising Player', position: 'C', team: 'TOR', current: 1.4, previous: 1, delta: .4, delta_pct: 40, breakout: false, url: '/players/1' },
                { nhl_player_id: 2, name: 'Falling Player', position: 'D', team: 'BOS', current: .5, previous: 1, delta: -.5, delta_pct: -50, breakout: false, url: '/players/2' },
                { nhl_player_id: 3, name: 'Breakout Player', position: 'RW', team: 'TOR', current: 1, previous: 0, delta: 1, delta_pct: null, breakout: true, url: '/players/3' },
            ],
            news_unavailable: false, news_updated_at: '2026-10-22T12:00:00Z', query: '', search: null,
            links: { news: 'https://www.nhl.com/news', video: 'https://www.nhl.com/video', stats: '/stats', transactions: '/transactions', injuries: '/injuries', games: '/games' },
        };
    });
    afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; });

    it('renders the three featured story cards', () => {
        mount(); expect(document.querySelectorAll('[aria-label="Featured NHL stories"] article')).toHaveLength(3);
    });
    it('uses actual NHL headlines rather than mock editorial titles', () => {
        data.featured[0].title = 'Provider headline'; mount();
        expect(document.querySelector('[aria-label="Featured NHL stories"] h2').textContent).toBe('Provider headline');
    });
    it('links featured stories to their supplied official article URLs', () => {
        mount(); expect(document.querySelector('[aria-label="Featured NHL stories"] h2 a').getAttribute('href')).toBe(data.featured[0].url);
    });
    it('shows provider imagery on the featured cards', () => {
        mount(); expect(document.querySelector('[aria-label="Featured NHL stories"] img').getAttribute('src')).toBe('/story-1.jpg');
    });
    it('keeps the supporting panels from the reference design', () => {
        mount(); for (const title of ['Top Performers', 'Notable Signings & Moves', 'Injury Watch', 'Team Trends', 'Player Heat Check', 'Must-Watch Video & Analysis', 'Around the NHL']) expect(section(title)).toBeTruthy();
    });
    it('shows four top performer cards without introducing a fixed goalie slot', () => {
        mount(); expect(section('Top Performers').querySelectorAll('a[href^="/players/"]')).toHaveLength(4);
    });
    it('shows four goalie cards when that is the supplied best-performing group', () => {
        data.performers.forEach(row => Object.assign(row, { goalie: true, position: 'G', saves: 38, goals_against: 1, save_percentage: 38 / 39, badge: '38 saves' }));
        mount(); expect(section('Top Performers').textContent.match(/38 SV/g)).toHaveLength(4);
    });
    it('formats goalie save percentage as a ratio with three decimals', () => {
        Object.assign(data.performers[0], { goalie: true, saves: 38, goals_against: 1, save_percentage: 38 / 39 }); mount();
        expect(section('Top Performers').textContent).toContain('.974');
    });
    it('shows skater goals assists and points distinctly', () => {
        mount(); const panel = section('Top Performers').textContent;
        expect(panel).toContain('1 G'); expect(panel).toContain('2 A'); expect(panel).toContain('3 PTS');
    });
    it('uses initials when a performer has no headshot', () => {
        data.performers[0].avatar = null; mount(); expect(section('Top Performers').textContent).toContain('P1');
    });
    it('labels the completed performance date', () => {
        mount(); expect(section('Top Performers').textContent).toContain('Completed games · Oct 21');
    });
    it('shows an honest empty state if no imported player performances exist', () => {
        data.performers = []; mount(); expect(section('Top Performers').textContent).toContain('completed boxscores');
    });
    it('shows actual transaction descriptions', () => {
        mount(); expect(section('Notable Signings & Moves').textContent).toContain('A real signing');
    });
    it('renders injury availability separately from the injury details', () => {
        mount(); const panel = section('Injury Watch'); expect(panel.textContent).toContain('Out'); expect(panel.textContent).toContain('Upper body');
    });
    it('orders offense by highest goals per game initially', () => {
        mount(); expect(document.querySelector('#team-trend-results a').textContent).toContain('TOR');
    });
    it('reorders defense by lowest goals against after selecting its tab', async () => {
        mount(); document.querySelector('#team-tab-defense').click(); await nextTick();
        expect(document.querySelector('#team-trend-results a').textContent).toContain('BOS');
        expect(document.querySelector('#team-tab-defense').getAttribute('aria-selected')).toBe('true');
    });
    it('supports arrow-key navigation and focus between metric tabs', async () => {
        mount(); const first = document.querySelector('#team-tab-offense'); first.focus();
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); await nextTick();
        const next = document.querySelector('#team-tab-defense');
        expect(document.activeElement).toBe(next);
        expect(next.getAttribute('aria-selected')).toBe('true');
        expect(next.getAttribute('tabindex')).toBe('0');
        expect(first.getAttribute('tabindex')).toBe('-1');
    });
    it('labels special teams as PP goals per game and excludes unknown values', async () => {
        data.team_trends[0].special_teams = null; mount(); document.querySelector('#team-tab-special_teams').click(); await nextTick();
        expect(section('Team Trends').textContent).toContain('PP goals / game');
        expect(document.querySelectorAll('#team-trend-results a')).toHaveLength(1);
    });
    it('shows falling players only when Trending Down is selected', async () => {
        mount(); document.querySelector('#player-tab-down').click(); await nextTick();
        expect(document.querySelector('#player-trend-results').textContent).toContain('Falling Player');
        expect(document.querySelector('#player-trend-results').textContent).not.toContain('Rising Player');
    });
    it('shows only the supplied breakout group when Breakout is selected', async () => {
        mount(); document.querySelector('#player-tab-breakout').click(); await nextTick();
        expect(document.querySelectorAll('#player-trend-results a')).toHaveLength(1);
        expect(document.querySelector('#player-trend-results').textContent).toContain('Breakout Player');
    });
    it('uses an absolute scoring change when the prior rate was zero', () => {
        mount(); expect(section('Player Heat Check').textContent).toContain('+1.0 P/GP');
        expect(section('Player Heat Check').textContent).not.toContain('Infinity');
    });
    it('does not mutate server-owned trend arrays during tab sorting', async () => {
        const before = JSON.stringify(data.team_trends); mount(); document.querySelector('#team-tab-defense').click(); await nextTick();
        expect(JSON.stringify(data.team_trends)).toBe(before);
    });
    it('puts only the remaining headlines under Around the NHL', () => {
        mount(); const panel = section('Around the NHL'); expect(panel.textContent).toContain('Story 4'); expect(panel.textContent).not.toContain('Story 1');
    });
    it('makes headlines beyond the first five available through disclosure', async () => {
        mount(); const toggle = section('Around the NHL').querySelector('button');
        expect(toggle.textContent).toContain('More headlines (1)');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        toggle.click(); await nextTick();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(document.querySelector('#dashboard-more-headlines').getAttribute('aria-hidden')).toBe('false');
        expect(document.querySelector('#dashboard-more-headlines a').textContent).toContain('Story 9');
    });
    it('shows the NHL source attribution', () => {
        mount(); expect(document.querySelector('main').textContent).toContain('Stories & video: NHL.com');
    });
    it('shows four supplied videos with the lead video emphasized', () => {
        mount(); const panel = section('Must-Watch Video & Analysis');
        expect(panel.querySelectorAll('a').length).toBe(5);
        expect(panel.textContent).toContain('Story 10');
    });
    it('renders unavailable news without blank fake story cards', () => {
        data.featured = []; data.headlines = []; mount();
        expect(document.querySelector('[aria-label="Featured NHL stories"]')).toBeNull();
        expect(document.querySelector('main').textContent).toContain('NHL stories are currently unavailable');
    });
    it('keeps injury and transaction empty states calm and actionable', () => {
        data.injuries = []; data.transactions = []; mount();
        expect(section('Injury Watch').textContent).toContain('No current injury reports');
        expect(section('Injury Watch').querySelector('a').getAttribute('href')).toBe('/injuries');
    });
    it('renders matching player and news search results', () => {
        data.query = 'Rising'; data.search = { players: [{ name: 'Rising Player', url: '/players/1', position: 'C', team: 'TOR' }], stories: [story(1)] }; mount();
        const results = section('Search results for “Rising”');
        expect(results.textContent).toContain('Rising Player'); expect(results.textContent).toContain('Story 1');
    });
    it('escapes provider headlines as text', () => {
        data.featured[0].title = '<script>alert(1)</script>'; mount();
        expect(document.querySelector('[aria-label="Featured NHL stories"] script')).toBeNull();
        expect(document.querySelector('[aria-label="Featured NHL stories"] h2').textContent).toBe('<script>alert(1)</script>');
    });
    it('identifies a stale feed without claiming it was newly published', () => {
        data.news_unavailable = true; mount(); expect(document.querySelector('main').textContent).toContain('Showing the last available feed');
    });
});
