import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadAdminHub = async () => {
    vi.resetModules();
    return (await import('./admin-hub.js')).default;
};

describe('admin-hub import listeners', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        global.window = {};
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ data: [], meta: { total: 0 } }),
            })
        );
    });

    it('registers Echo listeners only once even if adminHub is initialized multiple times', async () => {
        const listen = vi.fn().mockReturnThis();
        const privateChannel = vi.fn(() => ({ listen }));
        window.Echo = { private: privateChannel };

        const adminHub = await loadAdminHub();

        const first = adminHub();
        const second = adminHub();

        first.registerImportListeners();
        second.registerImportListeners();

        expect(privateChannel).toHaveBeenCalledTimes(1);
        expect(listen).toHaveBeenCalledTimes(2); // one for stream output, one for players availability
    });

    it('appends stream output once per event even with repeated adminHub boot', async () => {
        let outputHandler;

        const listen = vi.fn((event, handler) => {
            if (event === '.admin.import.output') {
                outputHandler = handler;
            }
            return listener;
        });

        const listener = { listen };
        const privateChannel = vi.fn(() => listener);
        window.Echo = { private: privateChannel };

        const adminHub = await loadAdminHub();
        const instanceA = adminHub();
        const instanceB = adminHub();

        instanceA.registerImportListeners();
        instanceB.registerImportListeners();

        const payload = {
            source: 'nhl',
            message: 'Importing Wayne Gretzky - ANA, C',
            status: 'started',
            timestamp: '2025-10-01T12:00:00Z',
        };

        outputHandler?.call(instanceA, payload);
        outputHandler?.call(instanceA, payload);

        expect(instanceA.streams.nhl.messages).toHaveLength(2);
    });

    it('updates availability and switches to newly available tab when current tab is invalid', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: false, hasFantrax: false });
        instance.activeTab = 'nhl';
        instance.activeSource = 'nhl';
        instance.players.page = 3;

        instance.handlePlayersAvailable('fantrax');

        expect(instance.hasFantrax).toBe(true);
        expect(instance.activeTab).toBe('fantrax');
        expect(instance.activeSource).toBe('fantrax');
        expect(instance.players.page).toBe(1);
    });

    it('loads players immediately when availability arrives for the active tab', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: false });
        instance.loadPlayers = vi.fn();
        instance.activeTab = 'nhl';
        instance.activeSource = 'nhl';

        instance.handlePlayersAvailable('nhl');

        expect(instance.hasPlayers).toBe(true);
        expect(instance.loadPlayers).toHaveBeenCalledTimes(1);
    });
});
