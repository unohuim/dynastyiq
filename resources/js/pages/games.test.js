/* @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import * as inertia from '@inertiajs/vue3';
import * as shellRealtime from '../layouts/shell-realtime';
import AppLayout from '../layouts/AppLayout.vue';
import ManualLineupModal from './Games/ManualLineupModal.vue';
import ToggleSwitch from '../components/ToggleSwitch.vue';
import GameCard from './Games/GameCard.vue';
import GamesIndex from './Games/Index.vue';
import LineupTeam from './Games/LineupTeam.vue';
import GameShow from './Games/Show.vue';
import { applyGamePanel } from './Games/game-panels';
import {
    createGameCard,
    formatGameDate,
    initializeGamesPage,
    gamePageUrl,
    gamePayloadUrl,
    renderGames,
    shiftDate,
} from './games.js';

const game = {
    nhl_game_id: 2026010001,
    game_date: '2026-09-20',
    start_time_utc: '2026-09-20T23:00:00Z',
    away: {
        team_abbrev: 'MTL',
        team_logo: 'https://assets.nhle.com/logos/nhl/svg/MTL_light.svg',
        score: 2,
        lineup: null,
    },
    home: {
        team_abbrev: 'TOR',
        team_logo: null,
        score: 4,
        lineup: {
            evidence_status: 'corroborated',
            source_count: 2,
            last_observed_at: '2026-09-20T15:00:00Z',
        },
    },
};

describe('single team lineup refresh', () => {
    let app;
    let originalShowModal;
    const settle = async () => {
        for (let index = 0; index < 8; index += 1) await Promise.resolve();
        await nextTick();
    };
    const mount = (props = {}) => {
        document.body.innerHTML = '<div id="refresh-card"></div>';
        app = createApp(GameCard, { game, canManageLineups: true, ...props });
        app.mount('#refresh-card');
    };
    beforeEach(() => {
        originalShowModal = HTMLDialogElement.prototype.showModal;
        HTMLDialogElement.prototype.showModal = vi.fn();
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn());
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        if (originalShowModal) HTMLDialogElement.prototype.showModal = originalShowModal;
        else delete HTMLDialogElement.prototype.showModal;
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('shows a green refresh only for a missing lineup and a super admin', () => {
        mount({ canManageLineups: false });
        expect(document.querySelector('[aria-label="Refresh MTL lineup"]')).toBeNull();
        app.unmount();
        mount();
        expect(document.querySelector('[aria-label="Refresh MTL lineup"]').classList.contains('text-green-400')).toBe(true);
        expect(document.querySelector('[aria-label="Refresh TOR lineup"]')).toBeNull();
    });

    it('queues only that game and team and blocks repeat clicks while polling', async () => {
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ status_url: '/attempt/1' }) })
            .mockResolvedValue({ ok: true, json: async () => ({ status: 'working' }) });
        mount();
        const button = document.querySelector('[aria-label="Refresh MTL lineup"]');
        button.click();
        button.click();
        await settle();
        expect(fetch.mock.calls[0][0]).toBe('/games/2026010001/lineup/refresh');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ team_abbrev: 'MTL' });
        expect(fetch.mock.calls.filter(([, options]) => options.method === 'POST')).toHaveLength(1);
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        app.unmount();
        app = null;
        await vi.advanceTimersByTimeAsync(6000);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('emits the new lineup from the completed attempt without navigation', async () => {
        const lineup = { nhl_game_id: 2026010001, team_abbrev: 'MTL', evidence_status: 'reported' };
        const submitted = vi.fn();
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ status_url: '/attempt/1' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'working' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'completed', lineup }) });
        mount({ onLineupSubmitted: submitted });
        document.querySelector('[aria-label="Refresh MTL lineup"]').click();
        await settle();
        await vi.advanceTimersByTimeAsync(3000);
        await settle();
        expect(submitted).toHaveBeenCalledWith(lineup);
        expect(document.body.textContent).toContain('Lineup updated.');
        expect(document.querySelector('[aria-label="Refresh MTL lineup"]').disabled).toBe(false);
    });

    it.each(['failed', 'completed'])('shows the %s no-lineup outcome and allows retry', async (status) => {
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ status_url: '/attempt/1' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status, lineup: null, message: 'Search failed.' }) });
        mount();
        document.querySelector('[aria-label="Refresh MTL lineup"]').click();
        await settle();
        expect(document.body.textContent).toContain(status === 'failed' ? 'Search failed.' : 'No reported lineup found.');
        expect(document.querySelector('[aria-label="Refresh MTL lineup"]').disabled).toBe(false);
    });

    it('shows an HTTP failure without leaving the button pending', async () => {
        fetch.mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Forbidden' }) });
        mount();
        document.querySelector('[aria-label="Refresh MTL lineup"]').click();
        await settle();
        expect(document.body.textContent).toContain('Forbidden');
        expect(document.querySelector('[aria-label="Refresh MTL lineup"]').disabled).toBe(false);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('adds expandable post reviews live and keeps polling when the modal is closed', async () => {
        const post = {
            id: '123', author: 'Reporter', handle: 'reporter', published_at: '2026-09-20T14:00:00Z',
            decision: 'declined', reason: 'Generic practice group.', context: 'posts 1-5',
            text: '<script>bad()</script> Practice lines', url: 'javascript:bad()',
            media: [{ url: 'https://untrusted.test/photo.png' }], ocr: [{ status: 'ok', text: 'Extracted player names' }],
            players: [{ name: 'Player One', line_key: 'F1', nhl_player_id: 1 }], matched_players: [],
        };
        fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ status_url: '/attempt/1' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'working', review: { activity: 'MTL | @reporter | posts 1-5', posts: [post] } }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'completed', lineup: null, review: { posts: [post, { ...post, id: '124' }] } }) });
        mount();
        document.querySelector('[aria-label="Refresh MTL lineup"]').click();
        await settle();
        expect(document.querySelector('dialog[aria-label="MTL lineup search"]')).not.toBeNull();
        expect(document.body.textContent).toContain('MTL | @reporter | posts 1-5');
        const header = document.querySelector('[aria-controls="lineup-post-MTL-123"]');
        expect(header.getAttribute('aria-expanded')).toBe('false');
        header.click();
        await nextTick();
        expect(header.getAttribute('aria-expanded')).toBe('true');
        expect(document.body.textContent).toContain('Extracted player names');
        expect(document.querySelector('dialog script')).toBeNull();
        expect(document.querySelector('a[href^="javascript:"]')).toBeNull();
        expect(document.querySelector('img[src*="untrusted.test"]')).toBeNull();
        document.querySelector('[aria-label="Close lineup search"]').click();
        await nextTick();
        expect(document.querySelector('dialog')).toBeNull();
        await vi.advanceTimersByTimeAsync(3000);
        await settle();
        expect(fetch).toHaveBeenCalledTimes(3);
        [...document.querySelectorAll('button')].find((button) => button.textContent === 'View search details').click();
        await nextTick();
        expect(document.querySelectorAll('dialog button[aria-expanded]')).toHaveLength(2);
        expect(document.body.textContent).toContain('No reported lineup found.');
    });
});

describe('live game card scores and shots', () => {
    let app;
    afterEach(() => {
        app?.unmount();
        app = null;
        document.body.innerHTML = '';
    });

    it.each(['LIVE', 'CRIT', 'INTERMISSION'])('replaces lineup evidence with scores and shots for %s', (state) => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: {
            ...game, game_state: state,
            away: { ...game.away, sog: 0 }, home: { ...game.home, sog: 17 },
        }, canManageLineups: true });
        app.mount('#live-card');
        expect(document.querySelector('[aria-label="MTL score"]').textContent).toBe('2');
        expect(document.querySelector('[aria-label="TOR score"]').textContent).toBe('4');
        expect(document.body.textContent).toContain('SOG: 0');
        expect(document.body.textContent).toContain('SOG: 17');
        expect(document.body.textContent).not.toContain('Updated');
        expect(document.body.textContent).not.toContain('Not Reported');
        expect(document.body.textContent).not.toContain('Corroborated');
        expect(document.querySelector('button[aria-label="Add MTL lineup"]')).toBeNull();
    });

    it('shows unavailable live shots as a dash rather than zero', () => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: { ...game, game_state: 'LIVE' } });
        app.mount('#live-card');
        expect(document.body.textContent).toContain('SOG: —');
    });

    it('renders all participating NHL goalies instead of a projected starter', () => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: {
            ...game, live_mode: true, game_state: 'LIVE', away: { ...game.away,
                starting_goalie: { name: 'Wrong projected goalie' },
                goalies: [
                    { nhl_player_id: 1, name: 'NHL starter', goals_against: 0, saves: 13 },
                    { nhl_player_id: 2, name: 'NHL relief', goals_against: 1, saves: 4 },
                ],
            },
        } });
        app.mount('#live-card');
        expect(document.body.textContent).toContain('NHL starter');
        expect(document.body.textContent).toContain('NHL relief');
        expect(document.body.textContent).not.toContain('Wrong projected goalie');
        expect(document.body.textContent).not.toContain('View current lineups');
    });

    it('does not display stored game information when live NHL data is unavailable', () => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: { ...game, live_mode: true, live_data_unavailable: true } });
        app.mount('#live-card');
        expect(document.body.textContent).toContain('Live NHL boxscore temporarily unavailable.');
        expect(document.body.textContent).not.toContain('MTL');
        expect(document.body.textContent).not.toContain('Corroborated');
        expect(document.querySelectorAll('img')).toHaveLength(0);
    });

    it.each([[0, 0, 'GA: 0 · Saves: 0'], [2, 18, 'GA: 2 · Saves: 18'], [null, null, 'GA: — · Saves: —']])('keeps the live goalie identity and shows individual stats (%s, %s)', (goalsAgainst, saves, expected) => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: {
            ...game, game_state: 'LIVE', away: { ...game.away, goalies: [{
                nhl_player_id: 8489991,
                name: 'Live Goalie', avatar_url: 'https://example.test/goalie.png', status: 'confirmed',
                goals_against: goalsAgainst, saves,
            }] },
        } });
        app.mount('#live-card');
        expect(document.querySelector('img[alt="Live Goalie"]').getAttribute('src')).toBe('https://example.test/goalie.png');
        expect(document.body.textContent).toContain('Live Goalie');
        expect(document.body.textContent).toContain(expected);
        expect(document.body.textContent).not.toContain('Confirmed');
    });

    it.each(['FUT', 'PRE', 'FINAL'])('preserves existing lineup display for %s', (state) => {
        document.body.innerHTML = '<div id="live-card"></div>';
        app = createApp(GameCard, { game: { ...game, game_state: state } });
        app.mount('#live-card');
        expect(document.body.textContent).toContain('Corroborated');
        expect(document.body.textContent).toContain('Updated');
        expect(document.body.textContent).not.toContain('SOG:');
    });
});

describe('manual game lineup entry', () => {
    let app;
    let originalShowModal;
    beforeEach(() => {
        document.body.innerHTML = '<div id="manual-test"></div>';
        originalShowModal = HTMLDialogElement.prototype.showModal;
        HTMLDialogElement.prototype.showModal = vi.fn();
        vi.stubGlobal('URL', class extends URL {
            static createObjectURL = vi.fn(() => 'blob:lineup-preview');
            static revokeObjectURL = vi.fn();
        });
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        if (originalShowModal) HTMLDialogElement.prototype.showModal = originalShowModal;
        else delete HTMLDialogElement.prototype.showModal;
        document.body.innerHTML = '';
    });

    it('does not expose manual entry to public visitors', () => {
        app = createApp(GameCard, { game });
        app.mount('#manual-test');
        expect(document.querySelector('button[aria-label="Add MTL lineup"]')).toBeNull();
    });

    it('lets super admins open manual entry for reported and unreported teams', async () => {
        app = createApp(GameCard, { game, canManageLineups: true });
        app.mount('#manual-test');
        expect(document.querySelector('button[aria-label="Add MTL lineup"]')).not.toBeNull();
        const update = document.querySelector('button[aria-label="Update TOR lineup"]');
        expect(update).not.toBeNull();
        update.click();
        await nextTick();
        expect(document.querySelector('textarea').getAttribute('placeholder')).toContain('TOR');
    });

    it('posts the selected game team and text and emits the returned lineup', async () => {
        HTMLDialogElement.prototype.showModal = vi.fn();
        const lineup = { nhl_game_id: 2026010001, team_abbrev: 'MTL', evidence_status: 'reported' };
        const submitted = vi.fn();
        const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ lineup }) });
        vi.stubGlobal('fetch', fetcher);
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL', onSubmitted: submitted });
        app.mount('#manual-test');
        const textarea = document.querySelector('textarea');
        textarea.value = 'Pasted lineup';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();
        document.querySelector('dialog form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await settle();
        expect(fetcher.mock.calls[0][0]).toBe('/games/2026010001/lineup');
        expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({ team_abbrev: 'MTL', text: 'Pasted lineup' });
        expect(submitted).toHaveBeenCalledWith(lineup);
    });

    it('previews an image then submits the reviewed text with its image evidence', async () => {
        const lineup = { team_abbrev: 'MTL', evidence_status: 'reported' };
        const submitted = vi.fn();
        const fetcher = vi.fn()
            .mockResolvedValueOnce({ ok: true, json: async () => ({ text: 'Name - Name - Name' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ lineup }) });
        vi.stubGlobal('fetch', fetcher);
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL', onSubmitted: submitted });
        app.mount('#manual-test');
        const file = new File(['image'], 'lineup.png', { type: 'image/png' });
        const input = document.querySelector('input[type="file"]');
        Object.defineProperty(input, 'files', { value: [file], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await settle();
        await nextTick();
        expect(document.querySelector('img').src).toBe('blob:lineup-preview');
        expect(document.querySelector('textarea').value).toBe('Name - Name - Name');
        expect(fetcher.mock.calls[0][0]).toBe('/games/2026010001/lineup/preview');
        expect(submitted).not.toHaveBeenCalled();
        document.querySelector('textarea').value = 'Corrected - Names - Here';
        document.querySelector('textarea').dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();
        document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await settle();
        const request = fetcher.mock.calls[1][1];
        expect(request.body).toBeInstanceOf(FormData);
        expect(request.body.get('image').name).toBe('lineup.png');
        expect(request.body.get('team_abbrev')).toBe('MTL');
        expect(request.body.get('text')).toBe('Corrected - Names - Here');
        expect(request.body.get('image_reviewed')).toBe('1');
        expect(request.headers['Content-Type']).toBeUndefined();
        expect(submitted).toHaveBeenCalledWith(lineup);
    });

    it('accepts clipboard images in the text field and allows removing them', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL' });
        app.mount('#manual-test');
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', { value: { files: [new File(['image'], 'paste.png', { type: 'image/png' })] } });
        document.querySelector('textarea').dispatchEvent(event);
        await nextTick();
        expect(event.defaultPrevented).toBe(true);
        expect(document.querySelector('img')).not.toBeNull();
        document.querySelector('button[type="button"]').click();
        await nextTick();
        expect(document.querySelector('img')).toBeNull();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:lineup-preview');
        expect(document.querySelector('button[type="submit"]').disabled).toBe(true);
    });

    it('leaves ordinary text paste to the browser', () => {
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL' });
        app.mount('#manual-test');
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', { value: { files: [] } });
        document.querySelector('textarea').dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it('rejects unsupported image uploads in the modal', async () => {
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL' });
        app.mount('#manual-test');
        const input = document.querySelector('input[type="file"]');
        Object.defineProperty(input, 'files', { value: [new File(['svg'], 'lineup.svg', { type: 'image/svg+xml' })] });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();
        expect(document.querySelector('[role="alert"]').textContent).toContain('JPEG or PNG');
        expect(document.querySelector('img')).toBeNull();
    });

    it('retains invalid text and shows the server explanation', async () => {
        HTMLDialogElement.prototype.showModal = vi.fn();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({ errors: { text: ['Unresolved core player.'] } }) }));
        app = createApp(ManualLineupModal, { gameId: 2026010001, team: 'MTL' });
        app.mount('#manual-test');
        const textarea = document.querySelector('textarea');
        textarea.value = 'Incomplete lineup';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();
        document.querySelector('dialog form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await settle();
        await nextTick();
        expect(document.querySelector('[role="alert"]').textContent).toBe('Unresolved core player.');
        expect(textarea.value).toBe('Incomplete lineup');
    });
});

function pageMarkup() {
    return `
        <main data-games-index-page data-payload-url="/games/payload">
            <p data-games-description>Initial date</p>
            <button data-games-previous></button>
            <input data-games-date type="date" value="2026-09-20">
            <button data-games-next></button>
            <p data-games-status></p>
            <div data-games-list></div>
        </main>
    `;
}

function response(payload = { games: [game], meta: { date: '2026-09-21', count: 1 } }) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(payload) });
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

describe('game date navigation', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.history.replaceState({}, '', '/games?date=2026-09-20');
    });

    it('moves a date forward one day', () => {
        expect(shiftDate('2026-09-20', 1)).toBe('2026-09-21');
    });

    it('moves a date backward one day', () => {
        expect(shiftDate('2026-09-20', -1)).toBe('2026-09-19');
    });

    it('moves across a month boundary', () => {
        expect(shiftDate('2026-09-30', 1)).toBe('2026-10-01');
    });

    it('moves across a year boundary', () => {
        expect(shiftDate('2026-12-31', 1)).toBe('2027-01-01');
    });

    it('returns blank for an invalid date', () => {
        expect(shiftDate('September 20', 1)).toBe('');
    });

    it('formats a game date for people', () => {
        expect(formatGameDate('2026-09-20', 'en-US')).toBe('Sunday, September 20, 2026');
    });

    it('returns blank when formatting an invalid date', () => {
        expect(formatGameDate('bad-date', 'en-US')).toBe('');
    });

    it('adds the date to the payload URL', () => {
        expect(gamePayloadUrl('/games/payload', '2026-09-21')).toBe('http://localhost:3000/games/payload?date=2026-09-21');
    });

    it('builds the visible lineup page URL', () => {
        expect(gamePageUrl('2026-09-21')).toBe('/games?date=2026-09-21');
    });

    it('renders the game matchup', () => {
        expect(createGameCard(game).textContent).toContain('MTL at TOR');
    });

    it('renders the NHL game identifier', () => {
        expect(createGameCard(game).textContent).toContain('#2026010001');
    });

    it('renders a localizable game start time', () => {
        expect(createGameCard(game).querySelector(`time[datetime="${game.start_time_utc}"]`)).not.toBeNull();
    });

    it('renders unavailable when a start time is missing', () => {
        expect(createGameCard({ ...game, start_time_utc: null }).textContent).toContain('Start time unavailable');
    });

    it('renders an independent status for each team', () => {
        expect(createGameCard(game).textContent).toContain('Not Reported');
        expect(createGameCard(game).textContent).toContain('Corroborated');
    });

    it('renders the lineup source count', () => {
        expect(createGameCard(game).textContent).toContain('2 sources');
    });

    it('renders a returned score beside each team', () => {
        const card = createGameCard(game);
        expect([...card.querySelectorAll('.tabular-nums')].map((score) => score.textContent)).toEqual(['2', '4']);
    });

    it('does not render score elements when scores are unavailable', () => {
        const card = createGameCard({
            ...game,
            away: { ...game.away, score: null },
            home: { ...game.home, score: null },
        });
        expect(card.querySelectorAll('.tabular-nums')).toHaveLength(0);
    });

    it('renders the game state label', () => {
        expect(createGameCard({ ...game, game_state: 'PRE', game_state_label: 'Starting soon' }).textContent)
            .toContain('Starting soon');
    });

    it('renders a reported starting goalie on a pregame team', () => {
        const card = createGameCard({
            ...game,
            away: {
                ...game.away,
                starting_goalie: {
                    name: 'Jeremy Swayman',
                    status: 'confirmed',
                    provider: 'rotowire',
                    avatar_url: 'https://example.test/swayman.png',
                },
            },
        });
        expect(card.textContent).toContain('Jeremy Swayman');
        expect(card.textContent).toContain('Confirmed');
        expect(card.textContent).not.toContain('Starting goalie:');
        expect(card.querySelector('img[src="https://example.test/swayman.png"]')).not.toBeNull();
    });

    it('labels a fallback starting goalie as projected', () => {
        const card = createGameCard({
            ...game,
            home: {
                ...game.home,
                starting_goalie: { name: 'Igor Shesterkin', status: 'projected', selection_source: 'goalie_projection' },
            },
        });
        expect(card.textContent).toContain('Igor Shesterkin');
        expect(card.textContent).toContain('Projected');
    });

    it('renders a team logo when one is available', () => {
        expect(createGameCard(game).querySelector('img').alt).toBe('MTL logo');
    });

    it('renders the team abbreviation when a logo is unavailable', () => {
        expect(createGameCard(game).textContent).toContain('TOR');
    });

    it('renders an empty schedule state', () => {
        const container = document.createElement('div');
        renderGames(container, []);
        expect(container.textContent).toContain('No NHL games are scheduled for this date.');
    });

    it('renders every returned game', () => {
        const container = document.createElement('div');
        renderGames(container, [game, { ...game, nhl_game_id: 2026010002 }]);
        expect(container.querySelectorAll('article')).toHaveLength(2);
    });

    it('does nothing when the lineup page root is absent', () => {
        expect(initializeGamesPage(null, vi.fn())).toBeNull();
    });

    it('loads the next date without refreshing the page', async () => {
        document.body.innerHTML = pageMarkup();
        const fetcher = vi.fn(() => response());
        initializeGamesPage(document.querySelector('main'), fetcher);
        document.querySelector('[data-games-next]').click();
        await settle();
        expect(fetcher).toHaveBeenCalledWith('http://localhost:3000/games/payload?date=2026-09-21', expect.any(Object));
        expect(document.querySelector('[data-games-date]').value).toBe('2026-09-21');
        expect(window.location.search).toBe('?date=2026-09-21');
    });

    it('loads the previous date without refreshing the page', async () => {
        document.body.innerHTML = pageMarkup();
        const fetcher = vi.fn(() => response({ games: [], meta: { date: '2026-09-19', count: 0 } }));
        initializeGamesPage(document.querySelector('main'), fetcher);
        document.querySelector('[data-games-previous]').click();
        await settle();
        expect(fetcher).toHaveBeenCalledWith('http://localhost:3000/games/payload?date=2026-09-19', expect.any(Object));
    });

    it('loads a manually selected date automatically', async () => {
        document.body.innerHTML = pageMarkup();
        const fetcher = vi.fn(() => response({ games: [], meta: { date: '2026-09-25', count: 0 } }));
        initializeGamesPage(document.querySelector('main'), fetcher);
        const input = document.querySelector('[data-games-date]');
        input.value = '2026-09-25';
        input.dispatchEvent(new Event('change'));
        await settle();
        expect(fetcher).toHaveBeenCalledWith('http://localhost:3000/games/payload?date=2026-09-25', expect.any(Object));
    });

    it('updates the human-readable heading after loading', async () => {
        document.body.innerHTML = pageMarkup();
        initializeGamesPage(document.querySelector('main'), vi.fn(() => response()));
        document.querySelector('[data-games-next]').click();
        await settle();
        expect(document.querySelector('[data-games-description]').textContent).toContain('Monday, September 21, 2026');
    });

    it('restores the prior date and displays an error when loading fails', async () => {
        document.body.innerHTML = pageMarkup();
        initializeGamesPage(document.querySelector('main'), vi.fn(() => Promise.resolve({ ok: false })));
        document.querySelector('[data-games-next]').click();
        await settle();
        expect(document.querySelector('[data-games-date]').value).toBe('2026-09-20');
        expect(document.querySelector('[data-games-status]').textContent).toContain('could not be loaded');
    });
});

describe('Vue game ordering', () => {
    let app;
    const matchup = (id, state, hour) => ({
        ...game, nhl_game_id: id, game_state: state,
        start_time_utc: `2026-09-20T${hour}:00:00Z`,
        away: { ...game.away, team_abbrev: `TEAM${id}` },
    });
    const order = () => [...document.querySelectorAll('article h2')].map((node) => node.textContent);
    beforeEach(() => {
        document.body.innerHTML = '<div id="sorted-games"></div>';
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('places live games first and final games last with puck-drop order inside groups', () => {
        const games = [matchup(1, 'FINAL', '18'), matchup(2, 'FUT', '23'), matchup(3, 'LIVE', '22'), matchup(4, 'CRIT', '21'), matchup(5, 'PRE', '20')];
        app = createApp(GamesIndex, { initialPayload: { games, meta: { date: '2026-09-20' } }, payloadUrl: '/games/payload' });
        app.mount('#sorted-games');
        expect(order()).toEqual(['TEAM4 at TOR', 'TEAM3 at TOR', 'TEAM5 at TOR', 'TEAM2 at TOR', 'TEAM1 at TOR']);
        expect(games.map((item) => item.nhl_game_id)).toEqual([1, 2, 3, 4, 5]);
    });

    it('reorders updated game states after an AJAX date change', async () => {
        app = createApp(GamesIndex, { initialPayload: { games: [matchup(1, 'LIVE', '18'), matchup(2, 'FUT', '20')], meta: { date: '2026-09-20' } }, payloadUrl: '/games/payload' });
        app.mount('#sorted-games');
        vi.stubGlobal('fetch', vi.fn(() => response({ games: [matchup(1, 'FINAL', '18'), matchup(2, 'LIVE', '20')], meta: { date: '2026-09-21' } })));
        document.querySelector('[aria-label="Next game date"]').click();
        await settle();
        await nextTick();
        expect(order()).toEqual(['TEAM2 at TOR', 'TEAM1 at TOR']);
    });
});

describe('Vue game presentation', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="vue-game"></div>';
    });

    it('renders the matchup through the Vue game card', () => {
        createApp(GameCard, { game }).mount('#vue-game');
        expect(document.body.textContent).toContain('MTL at TOR');
    });

    it('renders both team scores through the Vue game card', () => {
        createApp(GameCard, { game }).mount('#vue-game');
        expect([...document.querySelectorAll('.tabular-nums')].map((node) => node.textContent)).toEqual(['2', '4']);
    });

    it('uses the final-state visual treatment', () => {
        createApp(GameCard, { game: { ...game, game_state: 'FINAL', game_state_label: 'Final' } }).mount('#vue-game');
        expect(document.body.querySelector('.bg-emerald-50')?.textContent).toBe('Final');
    });

    it('renders reported goalies and scratches in Vue lineup details', () => {
        const team = {
            team_abbrev: 'TOR',
            lineup: {
                evidence_status: 'reported', source_count: 1, last_observed_at: '2026-09-20T15:00:00Z',
                sources: [],
                players: [
                    { player_name: 'Starting Goalie', line_key: 'G', slot_index: 1, resolution_status: 'resolved' },
                    { player_name: 'Healthy Scratch', line_key: 'SCR', slot_index: 1, resolution_status: 'resolved' },
                ],
            },
        };
        createApp(LineupTeam, { team, side: 'Home' }).mount('#vue-game');
        expect(document.body.textContent).toContain('Starting Goalie');
        expect(document.body.textContent).toContain('Healthy Scratch');
    });

    it('renders lineup source links in Vue lineup details', () => {
        const team = {
            team_abbrev: 'TOR',
            lineup: {
                evidence_status: 'reported', source_count: 1, last_observed_at: '2026-09-20T15:00:00Z',
                players: [],
                sources: [{ source_id: 1, name: 'Team Reporter', handle: 'reporter', post_url: 'https://x.com/reporter/status/1' }],
            },
        };
        createApp(LineupTeam, { team, side: 'Home' }).mount('#vue-game');
        expect(document.querySelector('a[href="https://x.com/reporter/status/1"]')?.textContent).toContain('@reporter');
    });
});

describe('game detail lineup and prediction tabs', () => {
    let app;
    const team = {
        team_abbrev: 'TOR', is_projected: true, lineup: null,
        display_lineup: { evidence_status: 'projected', sources: [], players: [
            { nhl_player_id: 1, player_name: 'Projected Skater', line_key: 'F1', slot_index: 1 },
        ] },
        injuries: [{ nhl_player_id: 2, player_name: 'Injured Skater', availability: 'out', body_part: 'Hip' }],
        predictions: [{ nhl_player_id: 1, player_name: 'Projected Skater', line_key: 'F1',
            projection_source: 'sat_model', model_run_id: 7, game_projected_toi_seconds: 1200,
            projected_sat: 4, projected_sog: 2, projected_goals: 0.2,
            projected_sat_per_60: 12, projected_sog_per_60: 6, projected_goals_per_60: 0.6 }],
    };
    beforeEach(() => { document.body.innerHTML = '<div id="detail-tabs"></div>'; });
    afterEach(() => { app?.unmount(); document.body.innerHTML = ''; });

    it('defaults to the projected lineup and lists injuries below it', () => {
        app = createApp(LineupTeam, { team, side: 'Home' });
        app.mount('#detail-tabs');
        expect(document.querySelector('#team-home-lineup').getAttribute('aria-selected')).toBe('true');
        expect(document.querySelector('#team-home-lineup-panel').style.display).not.toBe('none');
        expect(document.querySelector('#team-home-prediction-panel').style.display).toBe('none');
        expect(document.querySelector('#team-home-lineup-panel').textContent).toContain('Projected Skater');
        expect(document.querySelector('[aria-label="Injured players"]').textContent).toContain('Injured Skater');
        expect(document.querySelector('header').textContent).toContain('Projected');
    });

    it('switches in place and displays all six model rates', async () => {
        app = createApp(LineupTeam, { team, side: 'Home' });
        app.mount('#detail-tabs');
        document.querySelector('#team-home-prediction').click();
        await nextTick();
        expect(document.querySelector('#team-home-prediction-panel').style.display).not.toBe('none');
        expect([...document.querySelectorAll('tbody td')].slice(1).map((cell) => cell.textContent))
            .toEqual(['12.00', '4.00', '6.00', '2.00', '0.60', '0.20']);
        expect(document.querySelector('tbody').textContent).not.toContain('Injured Skater');
    });

    it('keeps the other team on its selected tab', async () => {
        app = createApp({ render: () => [h(LineupTeam, { team, side: 'Away' }), h(LineupTeam, { team, side: 'Home' })] });
        app.mount('#detail-tabs');
        document.querySelector('#team-home-prediction').click();
        await nextTick();
        expect(document.querySelector('#team-home-prediction').getAttribute('aria-selected')).toBe('true');
        expect(document.querySelector('#team-away-lineup').getAttribute('aria-selected')).toBe('true');
    });

    it('preserves zero while rendering missing rates as unavailable', () => {
        app = createApp(LineupTeam, { team: { ...team, predictions: [{ player_name: 'Zero Skater',
            projected_goals: 0, game_projected_toi_seconds: 900 }] }, side: 'Home' });
        app.mount('#detail-tabs');
        expect([...document.querySelectorAll('tbody td')].slice(1).map((cell) => cell.textContent))
            .toEqual(['—', '—', '—', '—', '0.00', '0.00']);
    });

    it('sorts numeric rates both directions without replacing the lineup', async () => {
        app = createApp(LineupTeam, { team: { ...team, predictions: [...team.predictions,
            { player_name: 'Lower Rate', projected_sat: 1 }] }, side: 'Home' });
        app.mount('#detail-tabs');
        const button = [...document.querySelectorAll('th button')].find((node) => node.textContent === 'SAT/GP');
        button.click();
        await nextTick();
        expect(document.querySelector('tbody tr').textContent).toContain('Lower Rate');
        button.click();
        await nextTick();
        expect(document.querySelector('tbody tr').textContent).toContain('Projected Skater');
        expect(document.querySelector('#team-home-lineup-panel').textContent).toContain('Projected Skater');
    });

    it('supports keyboard tab selection and focus', async () => {
        app = createApp(LineupTeam, { team, side: 'Home' });
        app.mount('#detail-tabs');
        document.querySelector('#team-home-lineup').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        await nextTick();
        expect(document.activeElement.id).toBe('team-home-prediction');
        expect(document.querySelector('#team-home-prediction').getAttribute('aria-selected')).toBe('true');
    });

    it('shows an empty prediction state without inventing players', () => {
        app = createApp(LineupTeam, { team: { team_abbrev: 'TOR' }, side: 'Home' });
        app.mount('#detail-tabs');
        expect(document.querySelector('#team-home-prediction-panel').textContent).toContain('No player projections');
        expect(document.querySelector('tbody')).toBeNull();
    });
});

describe('Vue toggle switch', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="vue-toggle"></div>';
    });

    it('exposes accessible switch state', () => {
        createApp(ToggleSwitch, { modelValue: true, label: 'Enable sync' }).mount('#vue-toggle');
        const toggle = document.querySelector('[role="switch"]');
        expect(toggle.getAttribute('aria-label')).toBe('Enable sync');
        expect(toggle.getAttribute('aria-checked')).toBe('true');
    });

    it('emits the inverse value when selected', () => {
        const changed = vi.fn();
        createApp(ToggleSwitch, {
            modelValue: false,
            label: 'Enable sync',
            'onUpdate:modelValue': changed,
        }).mount('#vue-toggle');
        document.querySelector('[role="switch"]').click();
        expect(changed).toHaveBeenCalledWith(true);
    });

    it('does not emit while disabled', () => {
        const changed = vi.fn();
        createApp(ToggleSwitch, {
            modelValue: false,
            disabled: true,
            label: 'Enable sync',
            'onUpdate:modelValue': changed,
        }).mount('#vue-toggle');
        document.querySelector('[role="switch"]').click();
        expect(changed).not.toHaveBeenCalled();
    });

    it('moves the switch thumb for the enabled state', () => {
        createApp(ToggleSwitch, { modelValue: true, label: 'Enable sync' }).mount('#vue-toggle');
        expect(document.querySelector('[role="switch"] span').classList.contains('translate-x-5')).toBe(true);
    });
});

describe('Vue game sync settings', () => {
    const props = {
        initialPayload: { games: [], meta: { date: '2026-09-21', count: 0 } },
        payloadUrl: '/games/payload',
        canManageGameSync: true,
        gameSyncSchedule: { enabled: false, lanes: { today: { interval_seconds: 60 } } },
        gameSyncScheduleUrl: '/admin/imports/nhl-game-boxscores/schedule',
    };

    beforeEach(() => {
        document.body.innerHTML = '<div id="vue-games"></div>';
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function openSettings() {
        createApp(GamesIndex, props).mount('#vue-games');
        document.querySelector('[aria-label="Game sync settings"]').click();
    }

    it('shows only the Sync label beside the toggle', () => {
        openSettings();
        expect(document.querySelector('[role="dialog"]').textContent).toContain('Sync');
        expect(document.querySelector('[role="dialog"]').textContent).not.toContain('Sync today’s games');
    });

    it('does not render frequency submit or cancel actions', () => {
        openSettings();
        expect(document.querySelector('[role="dialog"]').textContent).not.toContain('Save frequency');
        expect(document.querySelector('[role="dialog"]').textContent).not.toContain('Cancel');
    });

    it('persists the toggle immediately through AJAX', async () => {
        openSettings();
        document.querySelector('[role="switch"]').click();
        await settle();
        expect(fetch).toHaveBeenCalledWith(
            '/admin/imports/nhl-game-boxscores/schedule',
            expect.objectContaining({ method: 'PUT' }),
        );
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ enabled: true, timing: { start_before_minutes: 30, pregame_seconds: 900, live_seconds: 300 } });
    });

    it('debounces frequency persistence through AJAX', async () => {
        vi.useFakeTimers();
        openSettings();
        const hoursInput = document.querySelector('input[type="number"]');
        hoursInput.value = '1';
        hoursInput.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        expect(fetch).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(500);
        await settle();
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ enabled: false, timing: { start_before_minutes: 30, pregame_seconds: 4500, live_seconds: 300 } });
    });
});


describe('Inertia mobile navigation', () => {
    let app;
    let page;
    const mobileNav = () => document.querySelector('nav[aria-label="Mobile navigation"]');

    beforeEach(() => {
        document.body.innerHTML = '<div id="mobile-shell"></div>';
        page = { url: '/dashboard', props: {
            auth: { user: { id: 1, name: 'Rob' } },
            shell: {
                primary: [
                    { label: 'Home', href: '/', active: true },
                    { label: 'Stats', href: '/stats', active: false },
                    { label: 'Leagues', href: '/leagues', active: false },
                ],
                news: [
                    { label: 'Transactions', href: '/transactions', active: false },
                    { label: 'Starting Goalies', href: '/starting-goalies', active: false },
                    { label: 'Injuries', href: '/injuries', active: false },
                ],
                organization: { id: null }, csrf: 'test-token', flashes: [],
                avatar: '/avatar.png', login_url: '/auth/discord',
            },
        } };
        vi.spyOn(inertia, 'usePage').mockImplementation(() => page);
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ snapshots: [], games: [], meta: {} }) })));
        vi.spyOn(shellRealtime, 'connectShellRealtime').mockImplementation(options => { options.connectionState?.(true); return () => {}; });
    });

    afterEach(() => {
        app?.unmount();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    function mountShell() {
        app = createApp(AppLayout);
        app.mount('#mobile-shell');
    }

    it('places the desktop home logo before the menu and account avatar after it', () => {
        mountShell();
        const menu = document.querySelector('[aria-label="Primary navigation"]');
        const row = menu.parentElement;
        const home = row.firstElementChild;
        expect(home.getAttribute('href')).toBe('/');
        expect(home.getAttribute('aria-label')).toBe('Home');
        expect(home.querySelector('image').getAttribute('href')).toBe('/images/logo.png');
        expect(home.querySelector('image').getAttribute('filter')).toBe('url(#desktop-brand-transparent)');
        expect(home.querySelector('filter').id).toBe('desktop-brand-transparent');
        expect(home.querySelector('svg').getAttribute('viewBox')).toBe('0 0 928 804');
        expect(menu.querySelector('a[href="/"]')).toBeNull();
        expect(menu.querySelector('a[href="/stats"]')).not.toBeNull();
        expect(menu.querySelector('a[href="/leagues"]')).not.toBeNull();
        expect(row.lastElementChild.getAttribute('aria-label')).toBe('Open account');
        expect(row.lastElementChild.querySelector('img').getAttribute('src')).toBe('/avatar.png');
    });

    it('matches the original dark mobile bar and evenly spaced icon layout', () => {
        mountShell();
        expect(mobileNav().classList.contains('bg-gray-900')).toBe(true);
        expect(mobileNav().classList.contains('md:hidden')).toBe(true);
        expect(mobileNav().querySelector('ul').children).toHaveLength(5);
        expect(mobileNav().querySelectorAll('svg')).toHaveLength(4);
        expect(mobileNav().querySelector('a[href="/"] image').getAttribute('href')).toBe('/images/logo.png');
        expect(mobileNav().querySelector('a[href="/"] image').getAttribute('filter')).toBe('url(#mobile-brand-transparent)');
        const home = mobileNav().querySelector('[aria-current="page"]');
        expect(home.getAttribute('aria-label')).toBe('Home');
        expect(home.textContent.trim()).toBe('');
        const logo = home.querySelector('svg');
        const avatar = mobileNav().querySelector('[aria-label="Open account"] img');
        expect(logo.classList.contains('h-11')).toBe(true);
        expect(logo.classList.contains('w-12')).toBe(true);
        expect(avatar.classList.contains('h-9')).toBe(true);
        expect(avatar.classList.contains('w-9')).toBe(true);
        expect(logo.getAttribute('viewBox')).toBe('0 0 928 804');
        expect(logo.getAttribute('preserveAspectRatio')).toBe('xMidYMid meet');
        expect(mobileNav().querySelector('a[href="/stats"]')).not.toBeNull();
        expect(mobileNav().querySelector('a[href="/leagues"]')).toBeNull();
    });

    it('shows the account avatar in the original circular style', () => {
        mountShell();
        const account = mobileNav().querySelector('[aria-label="Open account"]');
        expect(account.querySelector('img').getAttribute('src')).toBe('/avatar.png');
        expect(account.querySelector('img').classList.contains('rounded-full')).toBe(true);
        expect(account.querySelector('.sr-only').textContent).toBe('Account');
    });

    it('shows the guest Discord sign-in item without member controls', () => {
        page.props.auth.user = null;
        mountShell();
        expect(mobileNav().querySelector('ul').children).toHaveLength(4);
        const signIn = mobileNav().querySelector('a[href="/auth/discord"]');
        expect(signIn.textContent).toContain('Sign In');
        expect(signIn.querySelector('img').getAttribute('src')).toBe('/images/Discord-Symbol-White.svg');
        expect(mobileNav().querySelector('[aria-label="Open account"]')).toBeNull();
        expect(mobileNav().textContent).not.toContain('Menu');
        expect(shellRealtime.connectShellRealtime).toHaveBeenCalledWith(expect.objectContaining({ userId: undefined }));
    });

    it('opens the mobile News menu independently of desktop navigation', async () => {
        mountShell();
        const trigger = mobileNav().querySelector('[aria-label="Open news menu"]');
        trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await nextTick();
        await nextTick();
        expect(trigger.getAttribute('aria-expanded')).toBe('true');
        const menus = document.querySelectorAll('[role="menu"]');
        expect(menus).toHaveLength(1);
        expect([...menus[0].querySelectorAll('a')].map(link => link.getAttribute('href')))
            .toEqual(['/transactions', '/starting-goalies', '/injuries']);
        menus[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        await nextTick();
        expect(trigger.getAttribute('aria-expanded')).toBe('false');
    });
});


describe('public game preview and recap', () => {
    let app;
    let state;
    const mount = (extra = {}, pageProps = {}) => {
        state = reactive({ game: {
            nhl_game_id: 2026020001, game_date: '2026-10-26', start_time_utc: '2026-10-26T23:00:00Z',
            mode: 'recap', status: 'Final', game_state: 'OFF', events: [], head_to_head: [], comparison: [],
            away: { team_abbrev: 'BOS', score: 0, sog: 22, boxscore: [] },
            home: { team_abbrev: 'TOR', score: 3, sog: 30, boxscore: [] }, ...extra,
        }, ...pageProps });
        app = createApp({ render: () => h(GameShow, state) });
        app.config.globalProperties.$headManager = { createProvider: () => ({ update: vi.fn(), disconnect: vi.fn() }) };
        app.mount('#game-show');
    };
    const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); await nextTick(); };
    beforeEach(() => {
        document.body.innerHTML = '<div id="game-show"></div>';
        vi.useFakeTimers();
        vi.spyOn(inertia.router, 'reload').mockImplementation(() => {});
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    });
    afterEach(() => {
        app?.unmount();
        app = null;
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });
    it('presents completed games as recaps and preserves zero scores', () => {
        mount();
        expect(document.querySelector('h1').textContent).toContain('Game recap');
        expect(document.querySelector('#summary')).not.toBeNull();
        expect(document.querySelector('header').textContent).toContain('0');
    });
    it('shows saved preview probabilities with one decimal', () => {
        mount({ mode: 'preview', prediction: { probabilities: { away: .454, home: .546 } } });
        expect(document.querySelector('header').textContent).toContain('45.4%');
        expect(document.querySelector('header').textContent).toContain('54.6%');
        expect(document.querySelector('#lineups')).not.toBeNull();
    });
    it('shows saved normalized totals and updates them with a revised prediction', async () => {
        mount({ mode: 'preview', prediction: { score: { away: 2.4, home: 3.1 }, total_goals: 5.5 } });
        expect(document.querySelector('header').textContent).toContain('Total goals 5.50');
        state.game.prediction.total_goals = 6.2;
        await nextTick();
        expect(document.querySelector('header').textContent).toContain('Total goals 6.20');
    });
    it('does not invent a total for older stored predictions', () => {
        mount({ mode: 'preview', prediction: { score: { away: 2.4, home: 3.1 } } });
        expect(document.querySelector('header').textContent).not.toContain('Total goals');
    });
    it('labels missing predictions without inventing percentages', () => {
        mount({ mode: 'preview', prediction: null });
        expect(document.querySelector('header').textContent).toContain('Prediction not yet available');
        expect(document.querySelector('header').textContent).not.toContain('50.0%');
    });
    it('links previous meetings to the existing game route', () => {
        mount({ mode: 'preview', head_to_head: [{ id: 2025020001, date: '2025-10-01', away: 'BOS', home: 'TOR', away_score: 2, home_score: 3 }] });
        expect(document.querySelector('#meetings a').getAttribute('href')).toBe('/games/2025020001');
    });
    it('shows imported scoring names assists and period', () => {
        mount({ events: [{ id: 1, period: 2, time: '04:12', scorer: 'Scorer', assists: ['Helper'], away_score: 0, home_score: 1 }] });
        expect(document.querySelector('#goals').textContent).toContain('Scorer');
        expect(document.querySelector('#goals').textContent).toContain('Helper');
        expect(document.querySelector('#goals').textContent).toContain('P2');
    });
    it('keeps missing boxscores explicit', () => {
        mount();
        expect(document.querySelector('#boxscore').textContent).toContain('Boxscore details have not been imported yet.');
    });
    it('renders the live clock and period', () => {
        mount({ mode: 'live', game_state: 'LIVE', time_remaining: '12:34', period_number: 2, period_type: 'REG' });
        expect(document.querySelector('header').textContent).toContain('12:34 | 2nd');
    });
    it('does not poll Inertia for live game updates', async () => {
        mount({ mode: 'live', game_state: 'LIVE' });
        await vi.advanceTimersByTimeAsync(60000);
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('refreshes previews after one minute', async () => {
        mount({ mode: 'preview', game_state: 'FUT' });
        await vi.advanceTimersByTimeAsync(60000);
        expect(inertia.router.reload).toHaveBeenCalledTimes(1);
    });
    it('never polls a completed recap', async () => {
        mount();
        await vi.advanceTimersByTimeAsync(120000);
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('skips live refreshes while the document is hidden', async () => {
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        mount({ mode: 'live', game_state: 'LIVE' });
        await vi.advanceTimersByTimeAsync(15000);
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('does not leave page reload timers after live detail unmounts', async () => {
        mount({ mode: 'live', game_state: 'LIVE' });
        app.unmount();
        app = null;
        await vi.advanceTimersByTimeAsync(60000);
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('shows an unavailable live state without exposing the stored teams', () => {
        mount({ mode: 'live', live_data_unavailable: true });
        expect(document.querySelector('[role="status"]').textContent).toBeTruthy();
        expect(document.querySelector('header')).toBeNull();
    });
    it('renders the overview while every deferred request is still pending', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        mount({}, { detailsUrl: '/games/2026020001/details' });
        await settle();
        expect(document.querySelector('header').textContent).toContain('BOS');
        expect(document.querySelector('header').textContent).toContain('TOR');
        expect(document.querySelector('#summary [role="status"]').textContent).toContain('Loading');
        expect(fetch).toHaveBeenCalledTimes(4);
    });
    it('requests each deferred section independently', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        mount({ mode: 'preview' }, { detailsUrl: '/games/2026020001/details' });
        await settle();
        expect(fetch.mock.calls.map(([url]) => url.searchParams.get('section'))).toEqual(['matchup', 'lineups', 'meetings']);
    });
    it('shows a local error without removing the overview or successful sections', async () => {
        vi.stubGlobal('fetch', vi.fn(async url => ({ ok: url.searchParams.get('section') !== 'goals', status: 200,
            json: async () => ({ nhl_game_id: 2026020001, mode: 'recap', section: url.searchParams.get('section'), data: { comparison: [], away: { boxscore: [] }, home: { boxscore: [] } } }) })));
        mount({}, { detailsUrl: '/games/2026020001/details' });
        await settle();
        expect(document.querySelector('header').textContent).toContain('TOR');
        expect(document.querySelector('#goals [role="alert"]')).not.toBeNull();
        expect(document.querySelector('#boxscore table')).toBeNull();
        expect(document.querySelector('#summary [aria-busy="true"]')).toBeNull();
    });
    it('retries only the failed section', async () => {
        let failed = true;
        vi.stubGlobal('fetch', vi.fn(async url => ({ ok: !(failed && url.searchParams.get('section') === 'goals'), status: 200,
            json: async () => ({ nhl_game_id: 2026020001, mode: 'recap', section: url.searchParams.get('section'), data: { events: [] } }) })));
        mount({}, { detailsUrl: '/games/2026020001/details' }); await settle();
        failed = false;
        document.querySelector('[aria-label="Retry Scoring summary"]').click(); await settle();
        expect(fetch).toHaveBeenCalledTimes(5);
        expect(document.querySelector('#goals [role="alert"]')).toBeNull();
    });
    it('keeps preparation placeholders visible while the background worker builds details', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 202 })));
        mount({}, { detailsUrl: '/games/2026020001/details' }); await settle();
        expect(fetch).toHaveBeenCalledTimes(4);
        await vi.advanceTimersByTimeAsync(2000); await settle();
        expect(fetch).toHaveBeenCalledTimes(8);
        expect(document.querySelector('header')).not.toBeNull();
    });
    it('aborts all deferred requests when navigating away', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        mount({}, { detailsUrl: '/games/2026020001/details' }); await settle();
        const signals = fetch.mock.calls.map(([, options]) => options.signal);
        app.unmount(); app = null;
        expect(signals.every(signal => signal.aborted)).toBe(true);
    });
    it('prevents deferred player panels from overwriting scores clock or identity', () => {
        const core = { nhl_game_id: 123, mode: 'live', game_state: 'LIVE', time_remaining: '12:34',
            home: { team_abbrev: 'TOR', score: 3 }, away: { team_abbrev: 'BOS', score: 1 } };
        const merged = applyGamePanel(core, 'lineups', { mode: 'preview', time_remaining: '00:00',
            home: { team_abbrev: 'CHI', score: 99, predictions: [{ player_name: 'Player' }] } });
        expect(merged.mode).toBe('live'); expect(merged.time_remaining).toBe('12:34');
        expect(merged.home.team_abbrev).toBe('TOR'); expect(merged.home.score).toBe(3);
        expect(merged.home.predictions).toHaveLength(1);
    });
    it('keeps matchup fields separate from unrelated player and scoring sections', () => {
        const merged = applyGamePanel({ home: {}, away: {} }, 'matchup', { home: { form: { gp: 10 }, predictions: [1] }, events: [1] });
        expect(merged.home.form.gp).toBe(10); expect(merged.home.predictions).toBeUndefined(); expect(merged.events).toBeUndefined();
    });

    it('switches accessible live tabs without navigating or changing the selected game', async () => {
        mount({ mode: 'live', game_state: 'LIVE' });
        const tabs = document.querySelectorAll('[role=tab]');
        expect(tabs[0].getAttribute('aria-selected')).toBe('true');
        tabs[1].click(); await nextTick();
        expect(tabs[1].getAttribute('aria-selected')).toBe('true');
        expect(document.querySelector('#panel-summary').style.display).toBe('none');
        expect(document.querySelector('#panel-boxscore').style.display).not.toBe('none');
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('supports keyboard navigation between live tabs', async () => {
        mount();
        document.querySelector('[role=tab]').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        await nextTick(); await nextTick();
        expect(document.querySelector('#tab-boxscore').getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement.id).toBe('tab-boxscore');
    });
    it('filters shot locations by period and team without fetching new data', async () => {
        mount({ plays_available: true, shots: [
            { id: 1, type: 'shot-on-goal', label: 'Shot on goal', player: 'Away shot', side: 'away', period: 1, x: 0, y: 0 },
            { id: 2, type: 'goal', label: 'Goal', player: 'Home goal', side: 'home', period: 2, x: 75, y: -10 },
        ] });
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(2);
        const period = document.querySelector('[aria-label="Shot map period"]');
        period.value = '2'; period.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(1);
        const team = document.querySelector('[aria-label="Shot map team"]');
        team.value = 'away'; team.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(0);
        expect(document.body.textContent).toContain('No recorded shots match these filters.');
    });
    it('shows selected shot details for zero rink coordinates using keyboard input', async () => {
        mount({ plays_available: true, shots: [{ id: 1, type: 'goal', label: 'Goal', player: 'Scorer', side: 'away', period: 1, time: '01:00', x: 0, y: 0 }] });
        const marker = document.querySelector('svg g[role=button]');
        expect(marker.querySelector('circle').getAttribute('cx')).toBe('100');
        marker.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); await nextTick();
        expect(document.querySelector('[aria-live=polite]').textContent).toContain('Scorer');
    });
    it('filters the live event timeline by event type', async () => {
        mount({ plays_available: true, plays: [
            { id: 1, type: 'goal', label: 'Goal', player: 'Goal scorer', period: 1 },
            { id: 2, type: 'penalty', label: 'Penalty', player: 'Penalty taker', period: 1, duration: 2 },
        ] });
        document.querySelector('#tab-play_by_play').click(); await nextTick();
        const filter = document.querySelector('[aria-label="Play-by-play event type"]');
        filter.value = 'penalty'; filter.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
        expect(document.querySelectorAll('#play_by_play li')).toHaveLength(1);
        expect(document.querySelector('#play_by_play').textContent).toContain('Penalty taker');
        expect(document.querySelector('#play_by_play').textContent).not.toContain('Goal scorer');
    });
    it('keeps live shot filters as newer scores and markers arrive', async () => {
        mount({ mode: 'live', game_state: 'LIVE', plays_available: true, shots: [{ id: 1, side: 'away', type: 'goal', period: 1, x: 20, y: 10 }] });
        const filter = document.querySelector('[aria-label="Shot map team"]');
        filter.value = 'away'; filter.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
        state.game.away.score = 2;
        state.game.shots.push({ id: 2, side: 'home', type: 'goal', period: 1, x: 20, y: 10 });
        await nextTick();
        expect(filter.value).toBe('away');
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(1);
    });
    it('accepts navigation from a live game to a different upcoming game', async () => {
        mount({ mode: 'live', game_state: 'LIVE' });
        state.game = { ...state.game, nhl_game_id: 2026020002, mode: 'preview', game_state: 'FUT' };
        await nextTick();
        expect(document.querySelector('h1').textContent).toBe('Game preview');
        expect(document.querySelector('[role=tablist]')).toBeNull();
    });
    it('clears the previous games shot locations when navigating to another live game', async () => {
        mount({ mode: 'live', game_state: 'LIVE', shots: [{ id: 1, side: 'away', type: 'goal', period: 1, x: 20, y: 10 }] });
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(1);
        state.game = { nhl_game_id: 2026020002, mode: 'live', game_state: 'LIVE', away: { team_abbrev: 'CBJ' }, home: { team_abbrev: 'PHI' } };
        await nextTick();
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(0);
        expect(document.querySelector('header').textContent).toContain('CBJ');
    });
    it('limits deferred timeline fields to the timeline without replacing the authoritative score', () => {
        const original = { away: { score: 2 }, home: { score: 1 }, time_remaining: '01:00', mode: 'live' };
        const merged = applyGamePanel(original, 'play_by_play', { plays: [{ id: 1 }], shots: [], plays_available: true,
            away: { score: 99 }, time_remaining: '00:00', mode: 'recap' });
        expect(merged.plays).toHaveLength(1); expect(merged.away.score).toBe(2);
        expect(merged.time_remaining).toBe('01:00'); expect(merged.mode).toBe('live');
    });

    it('opens the dedicated shot map using the existing markers and retained filters', async () => {
        mount({ plays_available: true, shots: [{ id: 1, type: 'goal', side: 'away', period: 1, x: 20, y: 10 }] });
        const filter = document.querySelector('[aria-label="Shot map team"]');
        filter.value = 'away'; filter.dispatchEvent(new Event('change', { bubbles: true }));
        document.querySelector('#tab-shot_map').click(); await nextTick();
        expect(document.querySelector('#panel-summary').getAttribute('aria-labelledby')).toBe('tab-shot_map');
        expect(document.querySelector('#panel-summary').style.display).not.toBe('none');
        expect(document.querySelectorAll('svg g[role=button]')).toHaveLength(1);
        expect(filter.value).toBe('away');
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });
    it('opens all plays from the summary recent plays card', async () => {
        mount({ plays_available: true, plays: [{ id: 1, type: 'hit', label: 'Hit', player: 'Hitter', period: 1, time: '01:00' }] });
        expect(document.querySelector('[aria-label="Recent plays"]').textContent).toContain('Hitter');
        document.querySelector('[aria-label="Recent plays"] button').click(); await nextTick();
        expect(document.querySelector('#tab-play_by_play').getAttribute('aria-selected')).toBe('true');
        expect(document.querySelector('#panel-play_by_play').style.display).not.toBe('none');
    });
    it('limits recent plays to four newest meaningful events', () => {
        mount({ plays: Array.from({ length: 6 }, (_, i) => ({ id: i, type: i === 5 ? 'stoppage' : 'hit', label: 'Hit', player: `Player ${i}`, period: 1 })) });
        const rows = document.querySelectorAll('[aria-label="Recent plays"] li');
        expect(rows).toHaveLength(4);
        expect(rows[0].textContent).toContain('Player 4');
        expect(rows[3].textContent).toContain('Player 1');
    });
    it('shows individual goalie saves shots against and save rate without treating zero shots as a rate', () => {
        mount({ away: { team_abbrev: 'BOS', boxscore: [{ id: 1, name: 'Starter', position: 'G', toi: '20:00', saves: 19, goals_against: 1 }] },
            home: { team_abbrev: 'TOR', boxscore: [{ id: 2, name: 'Relief', position: 'G', toi: '00:30', saves: 0, shots_against: 0, goals_against: 0 }] } });
        const card = document.querySelector('[aria-label="Game goalies"]');
        expect(card.textContent).toContain('19'); expect(card.textContent).toContain('20');
        expect(card.textContent).toContain('.950'); expect(card.textContent).toContain('Relief');
        expect(card.textContent).toContain('—'); expect(card.textContent).not.toContain('NaN');
    });
    it('builds game flow from all timed attempts including unlocated events but excluding shootouts', async () => {
        mount({ plays_available: true, shots: [], plays: [
            { id: 1, type: 'blocked-shot', side: 'away', period: 1, time: '01:00' },
            { id: 2, type: 'missed-shot', side: 'away', period: 2, time: '01:00' },
            { id: 3, type: 'goal', side: 'home', period: 5, period_type: 'SO', time: '00:00' },
        ] });
        document.querySelector('#tab-game_flow').click(); await nextTick();
        const panel = document.querySelector('#panel-game_flow');
        expect(panel.style.display).not.toBe('none');
        expect(panel.textContent).toContain('BOS 2'); expect(panel.textContent).toContain('TOR 0');
        expect(panel.querySelectorAll('polyline')).toHaveLength(2);
        expect(panel.querySelector('polyline').getAttribute('points')).not.toContain('NaN');
    });
    it('shows unavailable game flow instead of invented zeros when play evidence is missing', async () => {
        mount({ plays_available: false });
        document.querySelector('#tab-game_flow').click(); await nextTick();
        expect(document.querySelector('#panel-game_flow').textContent).toContain('temporarily unavailable');
        expect(document.querySelector('#panel-game_flow polyline')).toBeNull();
    });
    it('displays the live clock separately from the period and handles intermission', async () => {
        mount({ mode: 'live', game_state: 'LIVE', period_number: 2, time_remaining: '00:35' });
        const header = document.querySelector('header');
        expect(header.textContent).toContain('2nd period'); expect(header.textContent).toContain('00:35');
        state.game.in_intermission = true; await nextTick();
        expect(header.textContent).toContain('2nd Int.');
    });

});
