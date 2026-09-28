export function shiftDate(date, days) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
    const value = new Date(`${date}T12:00:00Z`);
    if (!Number.isFinite(value.getTime()) || value.toISOString().slice(0, 10) !== date) return null;
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

export function createLineupsTest(payload, fetcher = (...args) => fetch(...args)) {
    let gamesVersion = 0;
    let lineupVersion = 0;
    let gamesRequest = null;
    let lineupRequest = null;

    return {
        date: payload.date,
        games: [],
        selectedGame: null,
        lineups: null,
        gamesLoading: false,
        lineupsLoading: false,
        gamesLoaded: false,
        gamesError: '',
        lineupsError: '',

        init() { return this.loadGames(); },

        async loadGames() {
            const version = ++gamesVersion;
            gamesRequest?.abort();
            this.clearSelection();
            this.games = [];
            this.gamesLoaded = false;
            this.gamesError = '';
            this.gamesLoading = false;
            if (shiftDate(this.date, 0) === null) {
                this.gamesError = 'Choose a valid game date.';
                return;
            }
            gamesRequest = new AbortController();
            this.gamesLoading = true;
            try {
                const query = new URLSearchParams({ date: this.date });
                const response = await fetcher(`${payload.gamesUrl}?${query}`, {
                    headers: { Accept: 'application/json' }, signal: gamesRequest.signal,
                });
                if (!response.ok) throw new Error('Unable to load games. Please try again.');
                const data = await response.json();
                if (version !== gamesVersion) return;
                if (!Array.isArray(data.games)) throw new Error('Unable to load games. Please try again.');
                this.games = data.games;
                this.gamesLoaded = true;
            } catch (error) {
                if (version === gamesVersion && error?.name !== 'AbortError') {
                    this.gamesError = 'Unable to load games. Please try again.';
                }
            } finally {
                if (version === gamesVersion) this.gamesLoading = false;
            }
        },

        changeDay(days) {
            const next = shiftDate(this.date, days);
            if (next === null) {
                this.gamesError = 'Choose a valid game date.';
                return;
            }
            this.date = next;
            return this.loadGames();
        },

        clearSelection() {
            lineupVersion++;
            lineupRequest?.abort();
            this.selectedGame = null;
            this.lineups = null;
            this.lineupsError = '';
            this.lineupsLoading = false;
        },

        async selectGame(game) {
            this.clearSelection();
            this.selectedGame = game;
            const version = lineupVersion;
            lineupRequest = new AbortController();
            this.lineupsLoading = true;
            try {
                const url = payload.lineupsUrl.replace('__MATCH_ID__', encodeURIComponent(game.id));
                const response = await fetcher(url, {
                    headers: { Accept: 'application/json' }, signal: lineupRequest.signal,
                });
                if (!response.ok) throw new Error('Unable to load lineups.');
                const data = await response.json();
                if (version !== lineupVersion) return;
                if (String(data.matchId) !== String(game.id)) throw new Error('Unexpected game.');
                this.lineups = data;
            } catch (error) {
                if (version === lineupVersion && error?.name !== 'AbortError') {
                    this.lineupsError = 'Unable to load lineups. Please try again.';
                }
            } finally {
                if (version === lineupVersion) this.lineupsLoading = false;
            }
        },

        players(side) { return this.lineups?.[side]?.lineup ?? []; },
        teamName(team) { return team?.displayName || team?.name || team?.abbreviation || 'Team unavailable'; },
        gameTime(game) {
            const date = new Date(game.date);
            if (!game.date || !Number.isFinite(date.getTime())) return 'Time unavailable';
            return new Intl.DateTimeFormat('en-CA', {
                timeZone: 'America/Toronto', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
            }).format(date);
        },
        destroy() {
            gamesVersion++;
            lineupVersion++;
            gamesRequest?.abort();
            lineupRequest?.abort();
        },
    };
}

export function mount(rootEl, payload, Alpine) {
    if (!rootEl) return;
    Alpine.data('highlightlyLineupsTest', () => createLineupsTest(payload));
}
