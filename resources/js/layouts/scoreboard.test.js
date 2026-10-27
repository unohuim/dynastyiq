import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick, ref } from 'vue';
import * as liveFeed from './live-game-feed';
import MobileScoreboard from './MobileScoreboard.vue';
import {
    defaultSlateDate, shiftSlateDate, slateRolloverDelay, slateDateLabels, slateNavigationLabel,
    scoreboardStatus, scoreboardTeamValue, mergeScoreboardGames, scoreboardDays,
} from './scoreboard';

const game = (id, date = '2026-10-22', extra = {}) => ({
    nhl_game_id: id, game_date: date, start_time_utc: `${date}T23:00:00Z`,
    game_state: 'FUT', href: `/games/${id}`,
    away: { team_abbrev: 'BOS', team_logo: '/bos.svg', score: null },
    home: { team_abbrev: 'TOR', team_logo: '/tor.svg', score: null }, ...extra,
});

describe('Eastern scoreboard date rules', () => {
    it('uses yesterday one millisecond before the summer rollover', () => {
        expect(defaultSlateDate(new Date('2026-10-22T14:59:59.999Z'))).toBe('2026-10-21');
    });
    it('uses today exactly at eleven Eastern', () => {
        expect(defaultSlateDate(new Date('2026-10-22T15:00:00Z'))).toBe('2026-10-22');
    });
    it('uses Eastern calendar dates around UTC midnight', () => {
        expect(defaultSlateDate(new Date('2026-10-23T00:30:00Z'))).toBe('2026-10-22');
    });
    it('handles the winter UTC offset', () => {
        expect(defaultSlateDate(new Date('2026-12-22T15:59:59Z'))).toBe('2026-12-21');
        expect(defaultSlateDate(new Date('2026-12-22T16:00:00Z'))).toBe('2026-12-22');
    });
    it('handles the spring daylight-saving transition', () => {
        expect(slateRolloverDelay(new Date('2026-03-07T16:00:00Z'))).toBe(23 * 3600000);
    });
    it('handles the autumn daylight-saving transition', () => {
        expect(slateRolloverDelay(new Date('2026-10-31T15:00:00Z'))).toBe(25 * 3600000);
    });
    it('schedules the exact remaining millisecond before eleven', () => {
        expect(slateRolloverDelay(new Date('2026-10-22T14:59:59.999Z'))).toBe(1);
    });
    it('moves dates across month and year boundaries', () => {
        expect(shiftSlateDate('2026-12-31', 1)).toBe('2027-01-01');
        expect(shiftSlateDate('2026-01-01', -1)).toBe('2025-12-31');
    });
    it('formats the pinned date without browser-timezone drift', () => {
        expect(slateDateLabels('2026-10-22')).toMatchObject({ weekday: 'THU', date: 'OCT 22' });
        const now = new Date('2026-10-23T00:30:00Z');
        expect(slateNavigationLabel('2026-10-19', now)).toBe('Mon Oct 19');
        expect(slateNavigationLabel('2026-10-21', now)).toBe('Yesterday Oct 21');
        expect(slateNavigationLabel('2026-10-22', now)).toBe('Today Oct 22');
        expect(slateNavigationLabel('2026-10-23', now)).toBe('Tomorrow Oct 23');
    });
});

describe('Compact scoreboard facts', () => {
    it('formats pregame start times in Eastern', () => {
        expect(scoreboardStatus(game(1))).toBe('7:00 PM');
    });
    it('recognizes completed imported games as final', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'OFF' }))).toBe('Final');
    });
    it('recognizes final provider games', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'FINAL' }))).toBe('Final');
    });
    it('labels live games', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE' }))).toBe('Live');
    });
    it('formats provider time remaining and regulation period', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', time_remaining: '12:34', period_number: 1, period_type: 'REG' }))).toBe('12:34 | 1st');
        expect(scoreboardStatus(game(1, undefined, { game_state: 'CRIT', time_remaining: '00:00', period_number: 3, period_type: 'REG' }))).toBe('00:00 | 3rd');
    });
    it('labels intermission using the completed period instead of a game countdown', () => {
        for (const [period_number, label] of [[1, '1st Int.'], [2, '2nd Int.']]) {
            expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', in_intermission: true, time_remaining: '18:00', period_number, period_type: 'REG' }))).toBe(label);
        }
    });
    it('distinguishes overtime multiple overtimes and shootouts', () => {
        for (const [period_number, period_type, label] of [[4, 'OT', '04:12 | OT'], [5, 'OT', '04:12 | 2OT'], [5, 'SO', 'SO']]) {
            expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', time_remaining: '04:12', period_number, period_type }))).toBe(label);
        }
    });
    it('does not invent a clock from incomplete or malformed provider evidence', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', period_number: 1, time_remaining: 'bad' }))).toBe('Live');
        expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', time_remaining: '12:34' }))).toBe('Live');
    });
    it('does not present unavailable live data as a score', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'LIVE', live_data_unavailable: true }))).toBe('Unavailable');
    });
    it('labels postponed and cancelled games', () => {
        expect(scoreboardStatus(game(1, undefined, { game_state: 'PPD' }))).toBe('Postponed');
        expect(scoreboardStatus(game(1, undefined, { game_state: 'CNCL' }))).toBe('Cancelled');
    });
    it('leaves missing start times unavailable', () => {
        expect(scoreboardStatus(game(1, undefined, { start_time_utc: null }))).toBe('TBD');
    });
    it('replaces refreshed games without duplicate identities', () => {
        const updated = game(1, undefined, { game_state: 'FINAL' });
        expect(mergeScoreboardGames([game(1)], [updated])).toEqual([updated]);
    });
    it('keeps dates chronological when earlier games are prepended', () => {
        expect(mergeScoreboardGames([game(2)], [game(1, '2026-10-21')]).map(row => row.nhl_game_id)).toEqual([1, 2]);
    });
    it('preserves empty dates between populated slates', () => {
        const days = scoreboardDays('2026-10-21', '2026-10-23', [game(1, '2026-10-21'), game(2, '2026-10-23')]);
        expect(days.map(day => day.games.length)).toEqual([1, 0, 1]);
    });
    it('does not manufacture dates before a range is loaded', () => {
        expect(scoreboardDays(null, null, [])).toEqual([]);
    });
});

describe('Header score or probability values', () => {
    it('formats each upcoming teams own saved probability to one decimal', () => {
        const row = game(1);
        row.away.win_probability = .454; row.home.win_probability = .546;
        expect(scoreboardTeamValue(row, 'away')).toBe('45.4%');
        expect(scoreboardTeamValue(row, 'home')).toBe('54.6%');
    });
    it('also shows probabilities in starting-soon games', () => {
        const row = game(1, undefined, { game_state: 'PRE' }); row.home.win_probability = .54649;
        expect(scoreboardTeamValue(row, 'home')).toBe('54.6%');
    });
    it('leaves the slot blank when no probability is saved', () => {
        expect(scoreboardTeamValue(game(1), 'home')).toBe('');
    });
    it('does not substitute stored pregame zero scores', () => {
        const row = game(1); row.home.score = 0;
        expect(scoreboardTeamValue(row, 'home')).toBe('');
    });
    it('supports zero and full probability without treating zero as missing', () => {
        const row = game(1); row.home.win_probability = 0; row.away.win_probability = 1;
        expect(scoreboardTeamValue(row, 'home')).toBe('0.0%');
        expect(scoreboardTeamValue(row, 'away')).toBe('100.0%');
    });
    it('ignores nonnumeric or out-of-range probability values', () => {
        const row = game(1);
        for (const value of [null, '54.6', -1, 1.5, Infinity, NaN]) {
            row.home.win_probability = value; expect(scoreboardTeamValue(row, 'home')).toBe('');
        }
    });
    it('gives live and final scores priority including zero', () => {
        for (const state of ['LIVE', 'CRIT', 'FINAL', 'OFF']) {
            const row = game(1, undefined, { game_state: state }); row.home.score = 0; row.home.win_probability = .546;
            expect(scoreboardTeamValue(row, 'home')).toBe(0);
        }
    });
    it('does not show probabilities when live evidence is unavailable', () => {
        const row = game(1, undefined, { game_state: 'LIVE', live_data_unavailable: true }); row.home.win_probability = .546;
        expect(scoreboardTeamValue(row, 'home')).toBe('');
    });
    it('leaves cancelled and postponed games blank', () => {
        for (const state of ['CNCL', 'PPD']) {
            const row = game(1, undefined, { game_state: state }); row.home.win_probability = .546;
            expect(scoreboardTeamValue(row, 'home')).toBe('');
        }
    });
});

describe('Mobile scoreboard lifecycle', () => {
    let app;
    let media;
    let requests;
    let pushLive;
    let liveWarning;
    let resyncLive;
    const settle = async () => { for (let index = 0; index < 8; index++) await Promise.resolve(); await nextTick(); };
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-22T14:59:00Z'));
        document.body.innerHTML = '<div id="scoreboard"></div>';
        media = { matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() };
        requests = [];
        liveWarning = ref('');
        resyncLive = vi.fn(async () => { liveWarning.value = ''; });
        vi.spyOn(liveFeed, 'useLiveGameFeed').mockImplementation(({ update }) => {
            pushLive = update;
            return { warning: liveWarning, resync: resyncLive };
        });
        vi.stubGlobal('matchMedia', vi.fn(() => media));
        vi.stubGlobal('fetch', vi.fn(async url => {
            requests.push(new URL(url));
            const parsed = new URL(url);
            const start = parsed.searchParams.get('start');
            const end = parsed.searchParams.get('end');
            return { ok: true, json: async () => ({
                games: [game(1, '2026-10-21'), game(2)].filter(row => row.game_date >= start && row.game_date <= end),
                meta: { start, end, min_date: '2026-10-01', max_date: '2026-11-30' },
            }) };
        }));
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });
    const mount = () => {
        app = createApp(MobileScoreboard, { endpoint: '/games/scoreboard', gamesUrl: '/games' });
        app.mount('#scoreboard');
    };
    const scrollToEdge = async side => {
        const strip = document.querySelector('[aria-label="Games by date, scroll horizontally"]');
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 2400 });
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 320 });
        strip.scrollLeft = side === 'previous' ? 0 : 2080;
        strip.dispatchEvent(new Event('scroll'));
        await vi.advanceTimersByTimeAsync(20); await settle();
        return strip;
    };
    const selectDate = async date => {
        const input = document.querySelector('input[type="date"]');
        input.value = date;
        input.dispatchEvent(new Event('change'));
        await settle();
    };
    it('loads a bounded fortnight around yesterday before eleven', async () => {
        mount(); await settle();
        expect(requests[0].searchParams.get('start')).toBe('2026-10-14');
        expect(requests[0].searchParams.get('end')).toBe('2026-10-28');
        expect(document.querySelector('input[type="date"]').value).toBe('2026-10-21');
    });
    it('loads the same visible scoreboard on desktop', async () => {
        media.matches = false; mount(); await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[aria-label="NHL scoreboard"]').classList.contains('md:hidden')).toBe(false);
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
    });
    it('places the selected-date Games link above the separate full-width game strip', async () => {
        mount(); await settle();
        const controls = document.querySelector('[aria-label="Scoreboard date navigation"]');
        const strip = document.querySelector('[aria-label="Games by date, scroll horizontally"]');
        expect(controls.nextElementSibling).toBe(strip);
        expect(strip.classList.contains('w-full')).toBe(true);
        expect(controls.querySelector('a').getAttribute('href')).toBe('/games?date=2026-10-21');
        expect(controls.querySelector('a').textContent).toBe('Games ›');
        expect(controls.querySelector('label span').textContent).toBe('Yesterday Oct 21');
        document.querySelector('[aria-label="Next scoreboard date"]').click(); await settle();
        expect(controls.querySelector('a').getAttribute('href')).toBe('/games?date=2026-10-22');
        expect(controls.querySelector('label span').textContent).toBe('Today Oct 22');
    });
    it('updates the date and Games link when scrolling into future and past days', async () => {
        mount(); await settle();
        const strip = document.querySelector('[aria-label="Games by date, scroll horizontally"]');
        const sections = [...strip.querySelectorAll('[data-slate-date]')];
        sections.forEach((section, index) => {
            Object.defineProperty(section, 'offsetLeft', { configurable: true, value: index * 160 });
            Object.defineProperty(section, 'offsetWidth', { configurable: true, value: 160 });
        });
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: sections.length * 160 });
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 320 });
        for (const date of ['2026-10-23', '2026-10-20']) {
            strip.scrollLeft = sections.find(section => section.dataset.slateDate === date).offsetLeft;
            strip.dispatchEvent(new Event('scroll'));
            await vi.advanceTimersByTimeAsync(20); await settle();
            expect(document.querySelector('input[type="date"]').value).toBe(date);
            expect(document.querySelector('[aria-label="Scoreboard date navigation"] a').getAttribute('href')).toBe(`/games?date=${date}`);
        }
    });
    it('switches the default date at eleven while the layout stays mounted', async () => {
        mount(); await settle();
        await vi.advanceTimersByTimeAsync(60000); await settle();
        expect(document.querySelector('input[type="date"]').value).toBe('2026-10-22');
    });
    it('keeps an explicitly browsed date across the default rollover', async () => {
        mount(); await settle();
        document.querySelector('[aria-label="Previous scoreboard date"]').click(); await settle();
        await vi.advanceTimersByTimeAsync(60000); await settle();
        expect(document.querySelector('input[type="date"]').value).toBe('2026-10-20');
    });
    it('loads the preceding week and preserves the visible horizontal position', async () => {
        mount(); await settle();
        const strip = document.querySelector('[aria-label="Games by date, scroll horizontally"]');
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, get: () => strip.querySelectorAll('section').length * 128 });
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 300 });
        strip.scrollLeft = 0;
        strip.dispatchEvent(new Event('scroll'));
        await vi.advanceTimersByTimeAsync(20); await settle();
        expect(requests.at(-1).searchParams.get('start')).toBe('2026-10-07');
        expect(requests.at(-1).searchParams.get('end')).toBe('2026-10-13');
        expect(strip.scrollLeft).toBe(7 * 128);
    });
    it('loads the following week when the right edge is reached', async () => {
        mount(); await settle();
        const strip = document.querySelector('[aria-label="Games by date, scroll horizontally"]');
        Object.defineProperty(strip, 'scrollWidth', { configurable: true, value: 1200 });
        Object.defineProperty(strip, 'clientWidth', { configurable: true, value: 300 });
        strip.scrollLeft = 900;
        strip.dispatchEvent(new Event('scroll'));
        await vi.advanceTimersByTimeAsync(20); await settle();
        expect(requests.at(-1).searchParams.get('start')).toBe('2026-10-29');
        expect(requests.at(-1).searchParams.get('end')).toBe('2026-11-04');
    });
    it('shows a previous-week loader while retaining existing game tiles', async () => {
        mount(); await settle();
        let finish;
        fetch.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        const strip = await scrollToEdge('previous');
        expect(document.querySelector('[role="status"]').textContent).toContain('Loading previous week');
        expect(strip.getAttribute('aria-busy')).toBe('true');
        expect(strip.querySelector('a[href="/games/1"]')).not.toBeNull();
        finish({ ok: true, json: async () => ({ games: [] }) });
        await settle();
        expect(strip.getAttribute('aria-busy')).toBe('false');
        expect(document.querySelector('[role="status"]')).toBeNull();
        expect(strip.querySelector('[data-slate-date="2026-10-07"]')).not.toBeNull();
    });
    it('shows a next-week loader and prevents duplicate requests during that load', async () => {
        mount(); await settle();
        let finish;
        fetch.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        await scrollToEdge('next');
        expect(document.querySelector('[role="status"]').textContent).toContain('Loading next week');
        const count = fetch.mock.calls.length;
        await scrollToEdge('next');
        expect(fetch).toHaveBeenCalledTimes(count);
        finish({ ok: true, json: async () => ({ games: [] }) });
        await settle();
        expect(document.querySelector('[data-slate-date="2026-11-04"]')).not.toBeNull();
    });
    it('keeps loaded historical and future weeks while revalidating a cached recent day', async () => {
        mount(); await settle();
        await scrollToEdge('previous');
        await scrollToEdge('next');
        const count = fetch.mock.calls.length;
        await selectDate('2026-10-07');
        await selectDate('2026-11-04');
        await selectDate('2026-10-21');
        expect(fetch).toHaveBeenCalledTimes(count);
        expect(resyncLive).toHaveBeenCalled();
        expect(document.querySelector('[data-slate-date="2026-10-07"]')).not.toBeNull();
        expect(document.querySelector('[data-slate-date="2026-11-04"]')).not.toBeNull();
    });
    it('retains cached weeks across a distant date jump and return', async () => {
        mount(); await settle();
        await selectDate('2026-12-15');
        const count = fetch.mock.calls.length;
        await selectDate('2026-10-21');
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
        await selectDate('2026-12-15');
        expect(fetch).toHaveBeenCalledTimes(count);
        expect(document.querySelector('input[type="date"]').value).toBe('2026-12-15');
    });
    it('continues loading complete weeks beyond stored schedule bounds with explicit empty dates', async () => {
        mount(); await settle();
        for (let week = 0; week < 3; week++) await scrollToEdge('previous');
        expect(requests.at(-1).searchParams.get('start')).toBe('2026-09-23');
        expect(document.querySelector('[data-slate-date="2026-09-23"]').textContent).toContain('No games scheduled');
        for (let week = 0; week < 5; week++) await scrollToEdge('next');
        expect(requests.at(-1).searchParams.get('end')).toBe('2026-12-02');
        expect(document.querySelector('[data-slate-date="2026-12-02"]')).not.toBeNull();
    });
    it('retries the failed week without replacing or refetching the existing slate', async () => {
        mount(); await settle();
        fetch.mockResolvedValueOnce({ ok: false });
        await scrollToEdge('previous');
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
        [...document.querySelectorAll('button')].find(button => button.textContent === 'Retry').click();
        await settle();
        expect(requests.at(-1).searchParams.get('start')).toBe('2026-10-07');
        expect(requests.at(-1).searchParams.get('end')).toBe('2026-10-13');
        expect(document.querySelector('[data-slate-date="2026-10-07"]')).not.toBeNull();
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
    });
    it('receives pushed live games while browsing cached historical dates', async () => {
        mount(); await settle(); await selectDate('2026-10-19');
        const count = fetch.mock.calls.length;
        pushLive({ game: game(2, '2026-10-22', { game_state: 'LIVE', time_remaining: '12:34', period_number: 1, period_type: 'REG' }) });
        await settle(); expect(fetch).toHaveBeenCalledTimes(count);
        expect(document.querySelector('a[href="/games/2"]').textContent).toContain('12:34 | 1st');
        expect(document.querySelector('input[type="date"]').value).toBe('2026-10-19');
    });
    it('updates starting-soon games immediately from the stream', async () => {
        mount(); await settle(); await selectDate('2026-10-23');
        pushLive({ game: game(2, '2026-10-22', { game_state: 'LIVE', time_remaining: '12:34', period_number: 1, period_type: 'REG' }) });
        await settle(); const tile = document.querySelector('a[href="/games/2"]');
        expect(tile.textContent).toContain('12:34 | 1st'); expect(tile.querySelector('.text-emerald-400')).not.toBeNull();
    });
    it('reports interrupted streams and permits shared-snapshot retry', async () => {
        mount(); await settle(); liveWarning.value = 'Live updates delayed. Reconnecting…'; await settle();
        expect(document.querySelector('[role="status"]').textContent).toContain('Live updates delayed');
        expect(document.querySelector('a[href="/games/2"]')).not.toBeNull();
        [...document.querySelectorAll('button')].find(button => button.textContent === 'Retry').click(); await settle();
        expect(resyncLive).toHaveBeenCalled(); expect(document.querySelector('[role="status"]')).toBeNull();
    });
    it('does not let a slower initial range response overwrite newer pushed facts', async () => {
        let finish; fetch.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        mount(); await settle();
        pushLive({ game: game(2, '2026-10-22', { game_state: 'LIVE', time_remaining: '12:34', period_number: 1, period_type: 'REG' }) });
        finish({ ok: true, json: async () => ({ games: [game(2, '2026-10-22', { game_state: 'PRE' })] }) });
        await settle(); expect(document.querySelector('a[href="/games/2"]').textContent).toContain('12:34 | 1st');
    });
    it('times out a stuck range request and lets the user retry', async () => {
        let signal;
        fetch.mockImplementationOnce((_url, options) => new Promise((_resolve, reject) => {
            signal = options.signal;
            signal.addEventListener('abort', () => reject(new DOMException('Timed out', 'AbortError')), { once: true });
        }));
        mount(); await settle();
        await vi.advanceTimersByTimeAsync(30001); await settle();
        expect(signal.aborted).toBe(true);
        expect(document.querySelector('[role="status"]').textContent).toContain('Unable to load games');
        [...document.querySelectorAll('button')].find(button => button.textContent === 'Retry').click(); await settle();
        expect(document.querySelector('a[href="/games/2"]')).not.toBeNull();
    });
    it('links individual game tiles to their existing detail routes', async () => {
        mount(); await settle();
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
        expect(document.querySelector('a[href="/games/2"]')).not.toBeNull();
    });
    it('disposes request and refresh work on unmount', async () => {
        mount(); await settle();
        const count = fetch.mock.calls.length;
        app.unmount(); app = null;
        await vi.advanceTimersByTimeAsync(120000);
        expect(fetch).toHaveBeenCalledTimes(count);
        expect(vi.getTimerCount()).toBe(0);
    });
    it('offers retry after a failed schedule request', async () => {
        fetch.mockResolvedValueOnce({ ok: false });
        mount(); await settle();
        const retry = [...document.querySelectorAll('button')].find(button => button.textContent === 'Retry');
        expect(retry).toBeTruthy();
        retry.click(); await settle();
        expect(document.querySelector('a[href="/games/1"]')).not.toBeNull();
    });
    it('renders both saved probabilities in the shared header tiles and accessible labels', async () => {
        const row = game(1, '2026-10-21'); row.away.win_probability = .454; row.home.win_probability = .546;
        fetch.mockImplementation(async url => {
            const params = new URL(url).searchParams;
            return { ok: true, json: async () => ({ games: [row], meta: { start: params.get('start'), end: params.get('end') } }) };
        });
        mount(); await settle(); const tile = document.querySelector('a[href="/games/1"]');
        expect(tile.textContent).toContain('45.4%'); expect(tile.textContent).toContain('54.6%');
        expect(tile.getAttribute('aria-label')).toContain('TOR win probability 54.6%');
    });

});
