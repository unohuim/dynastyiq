/* @vitest-environment jsdom */

import { beforeEach, describe, expect, it, vi } from 'vitest';
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
