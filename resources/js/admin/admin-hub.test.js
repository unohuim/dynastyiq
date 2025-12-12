import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadAdminHub = async () => {
    vi.resetModules();
    return (await import('./admin-hub.js')).default;
};

describe('admin-hub import listeners', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        global.window = {};
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

    it('tracks importsBusy around started and finished events', async () => {
        let outputHandler;

        const listener = {
            listen: vi.fn((event, handler) => {
                if (event === '.admin.import.output') {
                    outputHandler = handler;
                }
                return listener;
            }),
        };

        window.Echo = { private: () => listener };

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.registerImportListeners();

        const started = {
            source: 'nhl',
            message: 'Import started',
            status: 'started',
            timestamp: '2025-10-01T12:00:00Z',
        };

        const finished = { ...started, status: 'finished', message: 'Done' };

        outputHandler?.call(instance, started);
        expect(instance.importsBusy).toBe(true);
        expect(instance.streams.nhl.running).toBe(true);

        outputHandler?.call(instance, finished);
        expect(instance.importsBusy).toBe(false);
        expect(instance.streams.nhl.running).toBe(false);
    });
});
