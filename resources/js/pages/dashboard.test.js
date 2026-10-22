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
            line_combinations: [{ unit_id: 1, game_id: 1, team: 'TOR', players: ['Alpha', 'Beta', 'Gamma'],
                gf: 2, ga: 0, goal_diff: 2, cf: 13, ca: 7, corsi: 6, cf_pct: 65, shifts: 8, toi_seconds: 425, url: '/stats/units?nhl_game_id=1' }], performance_date: '2026-10-21',
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
            links: { news: 'https://www.nhl.com/news', line_combinations: '/stats/units', stats: '/stats', transactions: '/transactions', injuries: '/injuries', games: '/games' },
        };
    });
    afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = ''; });

    it('renders all fetched stories while reserving only the first three for desktop', () => {
        mount();
        const cards = [...document.querySelectorAll('[aria-label="Featured NHL stories"] article')];
        expect(cards).toHaveLength(9);
        expect(cards.filter(card => !card.classList.contains('md:hidden'))).toHaveLength(3);
    });
    it('keeps every story in source order without duplicating featured headlines', () => {
        data.headlines.unshift(data.featured[1]); mount();
        expect([...document.querySelectorAll('#featured-story-cards h2')].map(node => node.textContent))
            .toEqual(Array.from({ length: 9 }, (_, index) => `Story ${index + 1}`));
    });
    it('tracks the story scrollbar thumb and supports end and home navigation', async () => {
        mount(); const strip = document.querySelector('#featured-story-cards');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 3600 });
        strip.scrollLeft = 800; strip.dispatchEvent(new Event('scroll')); await nextTick();
        const bar = document.querySelector('[aria-label="Browse NHL stories"]');
        expect(Number(bar.querySelectorAll('rect')[1].getAttribute('width'))).toBeCloseTo(100 / 9);
        expect(bar.getAttribute('aria-valuenow')).toBe('800');
        bar.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true })); await nextTick();
        expect(strip.scrollLeft).toBe(3200);
        bar.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true })); await nextTick();
        expect(strip.scrollLeft).toBe(0);
    });
    it('hides the story scrollbar when all story content fits', async () => {
        mount(); const strip = document.querySelector('#featured-story-cards');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 400 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[aria-label="Browse NHL stories"]')).toBeNull();
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
        mount(); for (const title of ["Last Night's Top Performers", 'Notable Signings & Moves', 'Injury Watch', 'Team Trends', 'Player Trends', 'Last Night’s Top Lines', 'Around the NHL']) expect(section(title)).toBeTruthy();
    });
    it('shows four top performer cards without introducing a fixed goalie slot', () => {
        mount(); expect(section("Last Night's Top Performers").querySelectorAll('a[href^="/players/"]')).toHaveLength(4);
    });
    it('shows four goalie cards when that is the supplied best-performing group', () => {
        data.performers.forEach(row => Object.assign(row, { goalie: true, position: 'G', saves: 38, goals_against: 1, save_percentage: 38 / 39, badge: '38 saves' }));
        mount(); expect(section("Last Night's Top Performers").textContent.match(/38 SV/g)).toHaveLength(4);
    });
    it('formats goalie save percentage as a ratio with three decimals', () => {
        Object.assign(data.performers[0], { goalie: true, saves: 38, goals_against: 1, save_percentage: 38 / 39 }); mount();
        expect(section("Last Night's Top Performers").textContent).toContain('.974');
    });
    it('shows skater goals assists and points distinctly', () => {
        mount(); const panel = section("Last Night's Top Performers").textContent;
        expect(panel).toContain('1 G'); expect(panel).toContain('2 A'); expect(panel).toContain('3 PTS');
    });
    it('uses initials when a performer has no headshot', () => {
        data.performers[0].avatar = null; mount(); expect(section("Last Night's Top Performers").textContent).toContain('P1');
    });
    it('labels the completed performance date', () => {
        mount(); expect(section("Last Night's Top Performers").textContent).toContain('Wed Oct 21');
    });
    it('shows an honest empty state if no imported player performances exist', () => {
        data.performers = []; mount(); expect(section("Last Night's Top Performers").textContent).toContain('completed boxscores');
    });
    it('shows actual transaction descriptions', () => {
        mount(); expect(section('Notable Signings & Moves').textContent).toContain('A real signing');
    });
    it('renders injury availability separately from the injury details', () => {
        mount(); const panel = section('Injury Watch'); expect(panel.textContent).toContain('Out'); expect(panel.textContent).toContain('Upper body');
    });
    it('shows a known anticipated return date for an injured player', () => {
        data.injuries[0].anticipated_return_date = '2026-10-29'; mount();
        const panel = section('Injury Watch');
        expect(panel.querySelector('time').getAttribute('datetime')).toBe('2026-10-29');
        expect(panel.textContent).toContain('Return Oct 29');
        expect(panel.textContent).not.toContain('Return unknown');
    });
    it('shows return unknown without inventing a date when unavailable', () => {
        data.injuries[0].anticipated_return_date = null; mount();
        expect(section('Injury Watch').textContent).toContain('Return unknown');
        expect(section('Injury Watch').querySelector('time')).toBeNull();
    });
    it('shows every available team including teams with missing selected metrics', async () => {
        data.team_trends = Array.from({ length: 32 }, (_, index) => ({ ...data.team_trends[0], team: `TEAM${index}`,
            offense: index, defense: 32 - index, special_teams: index === 0 ? null : index / 10 }));
        mount();
        expect(document.querySelectorAll('#team-trend-results a')).toHaveLength(32);
        document.querySelector('#team-tab-special_teams').click(); await nextTick();
        expect(document.querySelectorAll('#team-trend-results a')).toHaveLength(32);
        expect([...document.querySelectorAll('#team-trend-results a')].at(-1).textContent).toContain('TEAM0');
    });
    it('shows the leading 25 players separately for up down and breakout tabs', async () => {
        const template = data.player_trends[0];
        data.player_trends = Array.from({ length: 60 }, (_, index) => ({ ...template, nhl_player_id: index + 1,
            name: `Trend Player ${index + 1}`, delta: index < 30 ? index + 1 : -(index - 29), breakout: index < 30 }));
        mount();
        for (const [tab, name] of [['up', 'Trend Player 30'], ['down', 'Trend Player 60'], ['breakout', 'Trend Player 30']]) {
            document.querySelector(`#player-tab-${tab}`).click(); await nextTick();
            expect(document.querySelectorAll('#player-trend-results a')).toHaveLength(25);
            expect(document.querySelector('#player-trend-results h3').textContent).toBe(name);
            expect(document.querySelector('#player-trend-results').tabIndex).toBe(0);
        }
    });
    it('places the story source attribution before the featured story carousel', () => {
        mount(); const source = [...document.querySelectorAll('main p')].find(node => node.textContent.startsWith('Stories: NHL.com'));
        expect(source.compareDocumentPosition(document.querySelector('#featured-story-cards')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
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
        mount(); expect(section('Player Trends').textContent).toContain('+1.0 P/GP');
        expect(section('Player Trends').textContent).not.toContain('Infinity');
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
        mount(); expect(document.querySelector('main').textContent).toContain('Stories: NHL.com');
    });
    it('replaces videos with factual five-on-five line results and internal links', async () => {
        mount(); const panel = section('Last Night’s Top Lines');
        expect(panel.textContent).toContain('Alpha · Beta · Gamma');
        expect(panel.querySelector('[aria-label="2 G"]').textContent).toBe('2');
        document.querySelector('#line-tab-cf_pct').click(); await nextTick();
        expect(panel.querySelector('[aria-label="65.0% CF%"]').textContent).toBe('65.0');
        expect(panel.textContent).toContain('7:05');
        expect(panel.querySelector('a').getAttribute('href')).toBe('/stats/units');
        expect(panel.querySelector('a').hasAttribute('target')).toBe(false);
        expect(document.querySelector('main').textContent).not.toContain('Must-Watch');
    });
    it('shows a truthful empty state when last nights shift data is missing', () => {
        data.line_combinations = []; mount();
        expect(section('Last Night’s Top Lines').textContent).toContain('completed shift and play-by-play data');
    });
    it('does not label the performer date as completed games', () => {
        mount(); expect(section("Last Night's Top Performers").textContent).not.toContain('Completed games');
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
    it('opens every external dashboard destination safely in a new tab', async () => {
        data.performers[0].url = 'https://www.nhl.com/player/1';
        data.player_trends[0].url = 'https://www.nhl.com/player/2';
        data.query = 'Player';
        data.search = { players: [data.performers[0]], stories: [story(1)] };
        mount();
        section('Around the NHL').querySelector('button').click(); await nextTick();
        const links = [...document.querySelectorAll('main a')].filter(link => link.origin !== window.location.origin);
        expect(links.length).toBeGreaterThan(10);
        for (const link of links) {
            expect(link.target).toBe('_blank');
            expect(link.rel).toBe('noopener noreferrer');
        }
    });
    it('keeps internal relative and absolute links in the current tab', () => {
        data.performers[0].url = `${window.location.origin}/players/1`;
        mount();
        const links = [...document.querySelectorAll('main a')].filter(link => link.origin === window.location.origin);
        expect(links.length).toBeGreaterThan(5);
        for (const link of links) expect(link.hasAttribute('target')).toBe(false);
    });
    it('opens official fallback links in a new tab when feeds are unavailable', () => {
        data.featured = []; mount();
        const links = [...document.querySelectorAll('main a')].filter(link => link.hostname === 'www.nhl.com');
        expect(links.length).toBeGreaterThan(0);
        for (const link of links) expect(link.target).toBe('_blank');
    });
    it('shows each teams available comparison counts instead of claiming ten games', () => {
        Object.assign(data.team_trends[0], { games: 5, previous_games: 2, comparison_games: 5 });
        Object.assign(data.team_trends[1], { games: 10, previous_games: 10, comparison_games: 10 });
        mount();
        expect(document.querySelector('#team-trend-results').textContent).toContain('5 GP vs 2 GP');
        expect(document.querySelector('#team-trend-results').textContent).toContain('10 GP vs 10 GP');
    });

    it('renders every supplied performer in an accessible horizontal carousel', () => {
        data.performers = Array.from({ length: 8 }, (_, index) => ({ ...data.performers[0], nhl_player_id: index + 1, name: `Player ${index + 1}`, url: `/players/${index + 1}` }));
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        expect(strip.getAttribute('aria-label')).toContain('scroll horizontally');
        expect(strip.tabIndex).toBe(0);
        expect(strip.querySelectorAll('a')).toHaveLength(8);
        expect(strip.textContent).toContain('Player 8');
    });
    it('moves forward and backward by the visible carousel width', async () => {
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        strip.scrollBy = vi.fn(); strip.dispatchEvent(new Event('scroll')); await nextTick();
        document.querySelector('[aria-label="Next performers"]').click();
        expect(strip.scrollBy).toHaveBeenCalledWith({ left: 400, behavior: 'smooth' });
        strip.scrollLeft = 400; strip.dispatchEvent(new Event('scroll')); await nextTick();
        document.querySelector('[aria-label="Previous performers"]').click();
        expect(strip.scrollBy).toHaveBeenCalledWith({ left: -400, behavior: 'smooth' });
    });
    it('disables carousel arrows at the corresponding edges', async () => {
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[aria-label="Previous performers"]').disabled).toBe(true);
        expect(document.querySelector('[aria-label="Next performers"]').disabled).toBe(false);
        strip.scrollLeft = 800; strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[aria-label="Previous performers"]').disabled).toBe(false);
        expect(document.querySelector('[aria-label="Next performers"]').disabled).toBe(true);
    });

    it('shows the performer scrollbar only while cards overflow the visible area', async () => {
        mount(); const strip = document.querySelector('#top-performer-cards');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 400 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[role="scrollbar"]')).toBeNull();
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 800 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[role="scrollbar"]')).not.toBeNull();
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 400 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        expect(document.querySelector('[role="scrollbar"]')).toBeNull();
    });
    it('sizes and positions the visible scrollbar thumb from the actual content', async () => {
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        strip.scrollLeft = 400; strip.dispatchEvent(new Event('scroll')); await nextTick();
        const bar = document.querySelector('[role="scrollbar"]');
        expect(Number(bar.querySelectorAll('rect')[1].getAttribute('width'))).toBeCloseTo(100 / 3);
        expect(Number(bar.querySelectorAll('rect')[1].getAttribute('x'))).toBeCloseTo(100 / 3);
        expect(bar.getAttribute('aria-valuemax')).toBe('800');
        expect(bar.getAttribute('aria-valuenow')).toBe('400');
    });
    it('lets users navigate the scrollbar with keyboard controls', async () => {
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        const bar = document.querySelector('[role="scrollbar"]');
        bar.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true })); await nextTick();
        expect(strip.scrollLeft).toBe(800);
        bar.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true })); await nextTick();
        expect(strip.scrollLeft).toBe(0);
        bar.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); await nextTick();
        expect(strip.scrollLeft).toBe(100);
    });
    it('drags the scrollbar thumb to move the performer list proportionally', async () => {
        mount(); const strip = section("Last Night's Top Performers").querySelector('[role="region"]');
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 400 });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        strip.dispatchEvent(new Event('scroll')); await nextTick();
        const bar = document.querySelector('[role="scrollbar"]');
        bar.getBoundingClientRect = () => ({ left: 0, width: 400 });
        const pointer = (type, clientX) => Object.assign(new Event(type, { bubbles: true, cancelable: true }), { pointerId: 1, button: 0, clientX });
        bar.dispatchEvent(pointer('pointerdown', 10));
        bar.dispatchEvent(pointer('pointermove', 210));
        bar.dispatchEvent(pointer('pointerup', 210)); await nextTick();
        expect(strip.scrollLeft).toBeCloseTo(600);
        bar.dispatchEvent(pointer('pointermove', 310));
        expect(strip.scrollLeft).toBeCloseTo(600);
    });

    it('shows all five line metric tabs with goals for selected initially', () => {
        mount(); const tabs = [...document.querySelectorAll('[aria-label="5v5 line metric"] [role="tab"]')];
        expect(tabs.map(tab => tab.textContent)).toEqual(['G', 'Corsi', 'CF%', 'Shifts', 'TOI']);
        expect(document.querySelector('#line-tab-gf').getAttribute('aria-selected')).toBe('true');
    });
    it('ranks every available line for each selected metric before selecting five', async () => {
        data.line_combinations = Array.from({ length: 5 }, (_, index) => ({ ...data.line_combinations[0], unit_id: index + 1,
            players: [`Line ${index + 1}`], gf: 5 - index, corsi: index === 1 ? 20 : index,
            cf_pct: index === 2 ? 90 : 50, shifts: index === 3 ? 15 : 3, toi_seconds: index === 4 ? 900 : 400 }));
        mount(); const first = () => document.querySelector('#line-trend-results h3').textContent;
        expect(first()).toBe('Line 1');
        for (const [tab, expected] of [['corsi', 'Line 2'], ['cf_pct', 'Line 3'], ['shifts', 'Line 4'], ['toi_seconds', 'Line 5']]) {
            document.querySelector(`#line-tab-${tab}`).click(); await nextTick();
            expect(first()).toBe(expected);
            expect(document.querySelectorAll('#line-trend-results a')).toHaveLength(5);
        }
    });
    it('displays Corsi as a signed differential and CF percentage as attempt share', async () => {
        mount(); document.querySelector('#line-tab-corsi').click(); await nextTick();
        expect(document.querySelector('#line-trend-results [aria-label="+6 Corsi"]').textContent).toBe('+6');
        document.querySelector('#line-tab-cf_pct').click(); await nextTick();
        expect(document.querySelector('#line-trend-results [aria-label="65.0% CF%"]').textContent).toBe('65.0');
    });
    it('shows shared shift counts and formatted shared time for their tabs', async () => {
        mount(); document.querySelector('#line-tab-shifts').click(); await nextTick();
        expect(document.querySelector('#line-trend-results [aria-label="8 Shifts"]').textContent).toBe('8');
        document.querySelector('#line-tab-toi_seconds').click(); await nextTick();
        expect(document.querySelector('#line-trend-results [aria-label="7:05 TOI"]').textContent).toBe('7:05');
    });
    it('does not treat unavailable CF percentage as zero or remove it from other metrics', async () => {
        data.line_combinations[0].cf_pct = null; mount();
        expect(document.querySelectorAll('#line-trend-results a')).toHaveLength(1);
        document.querySelector('#line-tab-cf_pct').click(); await nextTick();
        expect(document.querySelectorAll('#line-trend-results a')).toHaveLength(0);
        expect(document.querySelector('#line-trend-results').textContent).toContain('No lines have data');
    });
    it('excludes brief trios from CF percentage ranking using total shared game time', async () => {
        data.line_combinations = [
            { ...data.line_combinations[0], unit_id: 1, players: ['Eleven seconds'], cf_pct: 100, toi_seconds: 11 },
            { ...data.line_combinations[0], unit_id: 2, players: ['Below minimum'], cf_pct: 95, toi_seconds: 299 },
            { ...data.line_combinations[0], unit_id: 3, players: ['Five minutes'], cf_pct: 70, toi_seconds: 300 },
            { ...data.line_combinations[0], unit_id: 4, players: ['Established line'], cf_pct: 80, toi_seconds: 600 },
        ];
        mount();
        expect(document.querySelectorAll('#line-trend-results a')).toHaveLength(4);
        document.querySelector('#line-tab-cf_pct').click(); await nextTick();
        expect([...document.querySelectorAll('#line-trend-results h3')].map(node => node.textContent))
            .toEqual(['Established line', 'Five minutes']);
        expect(section('Last Night’s Top Lines').textContent).toContain('Min. 5:00 TOI');
        document.querySelector('#line-tab-gf').click(); await nextTick();
        expect(document.querySelectorAll('#line-trend-results a')).toHaveLength(4);
    });
    it('colours negative secondary Corsi red while keeping primary badges blue', async () => {
        data.line_combinations[0].corsi = -6; mount();
        const secondary = () => [...document.querySelectorAll('#line-trend-results b')].find(node => node.textContent === '-6');
        expect(secondary().classList.contains('text-red-600')).toBe(true);
        document.querySelector('#line-tab-corsi').click(); await nextTick();
        expect(secondary()).toBeUndefined();
        expect(document.querySelector('#line-trend-results [aria-label="-6 Corsi"]').classList.contains('text-blue-600')).toBe(true);
        document.querySelector('#line-tab-gf').click(); await nextTick();
        expect(secondary().classList.contains('text-red-600')).toBe(true);
    });
    it('supports keyboard selection and focus for all line metric tabs', async () => {
        mount(); const first = document.querySelector('#line-tab-gf'); first.focus();
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true })); await nextTick();
        expect(document.activeElement).toBe(document.querySelector('#line-tab-toi_seconds'));
        expect(document.querySelector('#line-tab-toi_seconds').getAttribute('aria-selected')).toBe('true');
    });
    it('preserves source line order when switching ranking tabs', async () => {
        const before = JSON.stringify(data.line_combinations); mount();
        document.querySelector('#line-tab-corsi').click(); await nextTick();
        expect(JSON.stringify(data.line_combinations)).toBe(before);
    });

});
