import { describe, expect, it, vi } from 'vitest';
import { createLineupsTest, mount, shiftDate } from './lineups-test';

const payload = { date: '2026-09-29', gamesUrl: '/lineups-test/games', lineupsUrl: '/lineups-test/__MATCH_ID__/lineups' };
const game = { id: 91, date: '2026-09-29T23:00:00Z', awayTeam: { displayName: 'Away' }, homeTeam: { displayName: 'Home' } };
const ok = (data) => ({ ok: true, json: async () => data });
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
};

describe('Highlightly lineups test page', () => {
    it('starts with the server supplied date and no selected game', () => {
        const state = createLineupsTest(payload, vi.fn());
        expect(state.date).toBe('2026-09-29');
        expect(state.selectedGame).toBeNull();
    });

    it('loads the default date on initialization', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ games: [game] }));
        const state = createLineupsTest(payload, fetcher);
        await state.init();
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/games?date=2026-09-29');
        expect(state.games).toEqual([game]);
    });

    it('moves to the previous date and requests its games', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ games: [] }));
        const state = createLineupsTest(payload, fetcher);
        await state.changeDay(-1);
        expect(state.date).toBe('2026-09-28');
        expect(fetcher.mock.calls[0][0]).toContain('date=2026-09-28');
    });

    it('moves to the next date and requests its games', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ games: [] })));
        await state.changeDay(1);
        expect(state.date).toBe('2026-09-30');
    });

    it('handles month year and leap day boundaries without local timezone drift', () => {
        expect(shiftDate('2026-12-31', 1)).toBe('2027-01-01');
        expect(shiftDate('2024-03-01', -1)).toBe('2024-02-29');
        expect(shiftDate('2026-09-30', 1)).toBe('2026-10-01');
    });

    it('rejects an empty date without fetching', async () => {
        const fetcher = vi.fn();
        const state = createLineupsTest(payload, fetcher);
        state.date = '';
        await state.loadGames();
        expect(fetcher).not.toHaveBeenCalled();
        expect(state.gamesError).toContain('valid game date');
    });

    it('rejects impossible dates', () => {
        expect(shiftDate('2026-02-30', 0)).toBeNull();
        expect(shiftDate('invalid', 1)).toBeNull();
    });

    it('shows loading state while games are pending', async () => {
        const pending = deferred();
        const state = createLineupsTest(payload, () => pending.promise);
        const task = state.loadGames();
        expect(state.gamesLoading).toBe(true);
        pending.resolve(ok({ games: [] }));
        await task;
        expect(state.gamesLoading).toBe(false);
        expect(state.gamesLoaded).toBe(true);
    });

    it('shows an empty state after a successful empty response', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ games: [] })));
        await state.loadGames();
        expect(state.games).toEqual([]);
        expect(state.gamesLoaded).toBe(true);
        expect(state.gamesError).toBe('');
    });

    it('shows a retryable error after a failed games request', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue({ ok: false }));
        await state.loadGames();
        expect(state.gamesError).toContain('try again');
        expect(state.gamesLoaded).toBe(false);
    });

    it('clears a games error after a successful retry', async () => {
        const fetcher = vi.fn().mockResolvedValueOnce({ ok: false }).mockResolvedValueOnce(ok({ games: [game] }));
        const state = createLineupsTest(payload, fetcher);
        await state.loadGames();
        await state.loadGames();
        expect(state.gamesError).toBe('');
        expect(state.games).toEqual([game]);
    });

    it('fetches both lineups only after a game is selected', async () => {
        const data = { matchId: 91, away: { lineup: [{ player: 'A' }] }, home: { lineup: [{ player: 'B' }] } };
        const fetcher = vi.fn().mockResolvedValue(ok(data));
        const state = createLineupsTest(payload, fetcher);
        expect(fetcher).not.toHaveBeenCalled();
        await state.selectGame(game);
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/91/lineups');
        expect(state.players('away')).toEqual([{ player: 'A' }]);
        expect(state.players('home')).toEqual([{ player: 'B' }]);
    });

    it('shows lineup loading immediately when selecting a game', async () => {
        const pending = deferred();
        const state = createLineupsTest(payload, () => pending.promise);
        const task = state.selectGame(game);
        expect(state.selectedGame).toEqual(game);
        expect(state.lineupsLoading).toBe(true);
        pending.resolve(ok({ matchId: 91 }));
        await task;
        expect(state.lineupsLoading).toBe(false);
    });

    it('represents missing team lineups as empty player lists', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ matchId: 91, away: null, home: null })));
        await state.selectGame(game);
        expect(state.players('away')).toEqual([]);
        expect(state.players('home')).toEqual([]);
        expect(state.lineupsError).toBe('');
    });

    it('preserves positions jerseys and scratch flags', async () => {
        const player = { player: 'A', positionAbbreviation: 'D', jersey: 4, isScratched: true };
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ matchId: 91, home: { lineup: [player] } })));
        await state.selectGame(game);
        expect(state.players('home')[0]).toEqual(player);
    });

    it('shows a retryable lineup error without retaining old players', async () => {
        const state = createLineupsTest(payload, vi.fn().mockRejectedValue(new Error('network')));
        state.lineups = { home: { lineup: [{ player: 'Old' }] } };
        await state.selectGame(game);
        expect(state.lineupsError).toContain('try again');
        expect(state.players('home')).toEqual([]);
    });

    it('rejects lineup results for a different match', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ matchId: 92 })));
        await state.selectGame(game);
        expect(state.lineupsError).not.toBe('');
        expect(state.lineups).toBeNull();
    });

    it('clears selected lineups when the date changes', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ games: [] })));
        state.selectedGame = game;
        state.lineups = { home: { lineup: [{ player: 'Old' }] } };
        await state.changeDay(1);
        expect(state.selectedGame).toBeNull();
        expect(state.lineups).toBeNull();
    });

    it('ignores stale games after a later date finishes loading', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValueOnce(ok({ games: [{ id: 92 }] }));
        const state = createLineupsTest(payload, fetcher);
        const first = state.loadGames();
        await state.changeDay(1);
        pending.resolve(ok({ games: [game] }));
        await first;
        expect(state.games).toEqual([{ id: 92 }]);
        expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    });

    it('ignores stale lineups after switching games', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValueOnce(ok({ matchId: 92 }));
        const state = createLineupsTest(payload, fetcher);
        const first = state.selectGame(game);
        await state.selectGame({ id: 92 });
        pending.resolve(ok({ matchId: 91 }));
        await first;
        expect(state.lineups.matchId).toBe(92);
    });

    it('ignores pending lineups after a date change', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValueOnce(ok({ games: [] }));
        const state = createLineupsTest(payload, fetcher);
        const first = state.selectGame(game);
        await state.changeDay(1);
        pending.resolve(ok({ matchId: 91 }));
        await first;
        expect(state.lineups).toBeNull();
    });

    it('aborts requests on teardown and ignores their results', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValue(pending.promise);
        const state = createLineupsTest(payload, fetcher);
        const task = state.loadGames();
        state.destroy();
        expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
        pending.resolve(ok({ games: [game] }));
        await task;
        expect(state.games).toEqual([]);
    });

    it('provides safe labels for missing team and time values', () => {
        const state = createLineupsTest(payload, vi.fn());
        expect(state.teamName(null)).toBe('Team unavailable');
        expect(state.teamName({ abbreviation: 'TOR' })).toBe('TOR');
        expect(state.gameTime({})).toBe('Time unavailable');
    });

    it('registers page state through the supplied Alpine instance', () => {
        const Alpine = { data: vi.fn() };
        mount({}, payload, Alpine);
        expect(Alpine.data.mock.calls[0][0]).toBe('highlightlyLineupsTest');
        expect(Alpine.data.mock.calls[0][1]().date).toBe(payload.date);
    });

    it('defaults to Highlightly and preserves its request URLs', async () => {
        const fetcher = vi.fn().mockResolvedValueOnce(ok({ games: [] })).mockResolvedValueOnce(ok({ matchId: 91 }));
        const state = createLineupsTest(payload, fetcher);
        expect(state.source).toBe('highlightly');
        await state.loadGames();
        await state.selectGame(game);
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/games?date=2026-09-29');
        expect(fetcher.mock.calls[1][0]).toBe('/lineups-test/91/lineups');
    });

    it('switches to Cap Wages without changing the selected date', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ games: [] }));
        const state = createLineupsTest(payload, fetcher);
        await state.selectSource('capwages');
        expect(state.source).toBe('capwages');
        expect(state.date).toBe('2026-09-29');
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/games?date=2026-09-29&source=capwages');
    });

    it('clears a selected game when switching providers', async () => {
        const state = createLineupsTest(payload, vi.fn().mockResolvedValue(ok({ games: [] })));
        state.selectedGame = game;
        state.lineups = { matchId: 91 };
        await state.selectSource('capwages');
        expect(state.selectedGame).toBeNull();
        expect(state.lineups).toBeNull();
    });

    it('includes the Cap Wages source when viewing a stored game', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ matchId: 2026010001 }));
        const state = createLineupsTest({ ...payload, source: 'capwages' }, fetcher);
        await state.selectGame({ id: 2026010001 });
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/2026010001/lineups?source=capwages');
    });

    it('keeps the Cap Wages source when navigating dates', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ games: [] }));
        const state = createLineupsTest({ ...payload, source: 'capwages' }, fetcher);
        await state.changeDay(1);
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/games?date=2026-09-30&source=capwages');
    });

    it('returns to Highlightly requests when switching back', async () => {
        const fetcher = vi.fn().mockResolvedValue(ok({ games: [] }));
        const state = createLineupsTest({ ...payload, source: 'capwages' }, fetcher);
        await state.selectSource('highlightly');
        expect(fetcher.mock.calls[0][0]).toBe('/lineups-test/games?date=2026-09-29');
    });

    it('does not refetch an already selected source', async () => {
        const fetcher = vi.fn();
        const state = createLineupsTest(payload, fetcher);
        await state.selectSource('highlightly');
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('ignores an unsupported source selection', async () => {
        const fetcher = vi.fn();
        const state = createLineupsTest(payload, fetcher);
        await state.selectSource('invalid');
        expect(state.source).toBe('highlightly');
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('ignores stale games from the previous provider', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValueOnce(ok({ games: [{ id: 2026010001 }] }));
        const state = createLineupsTest(payload, fetcher);
        const oldRequest = state.loadGames();
        await state.selectSource('capwages');
        pending.resolve(ok({ games: [game] }));
        await oldRequest;
        expect(state.games).toEqual([{ id: 2026010001 }]);
        expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    });

    it('ignores stale lineups from the previous provider even when ids overlap', async () => {
        const pending = deferred();
        const fetcher = vi.fn().mockReturnValueOnce(pending.promise)
            .mockResolvedValueOnce(ok({ games: [game] }))
            .mockResolvedValueOnce(ok({ matchId: 91, home: { lineup: [{ player: 'Cap Player' }] } }));
        const state = createLineupsTest(payload, fetcher);
        const oldRequest = state.selectGame(game);
        await state.selectSource('capwages');
        await state.selectGame(game);
        pending.resolve(ok({ matchId: 91, home: { lineup: [{ player: 'Highlightly Player' }] } }));
        await oldRequest;
        expect(state.players('home')).toEqual([{ player: 'Cap Player' }]);
    });

    it('exposes one team error while retaining the other team lineup', async () => {
        const state = createLineupsTest({ ...payload, source: 'capwages' }, vi.fn().mockResolvedValue(ok({
            matchId: 91, away: { lineup: [], error: 'Team unavailable' },
            home: { lineup: [{ player: 'Home Player' }], error: null },
        })));
        await state.selectGame(game);
        expect(state.teamError('away')).toBe('Team unavailable');
        expect(state.teamError('home')).toBe('');
        expect(state.players('home')).toEqual([{ player: 'Home Player' }]);
        expect(state.lineupsError).toBe('');
    });

    it('formats provider freshness in Toronto and handles missing timestamps', () => {
        const state = createLineupsTest(payload, vi.fn());
        state.lineups = { away: { lastUpdated: '2026-09-28T16:00:00Z' } };
        expect(state.updatedAt('away')).toBe(new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/Toronto', dateStyle: 'medium', timeStyle: 'short',
        }).format(new Date('2026-09-28T16:00:00Z')));
        expect(state.updatedAt('home')).toBe('');
    });
});
