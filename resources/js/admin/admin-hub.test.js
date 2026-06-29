import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadAdminHub = async () => {
    vi.resetModules();
    return (await import('./admin-hub.js')).default;
};

describe('admin-hub import listeners', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
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

    it('refreshes import progress when progress events arrive over Echo', async () => {
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
        const instance = adminHub();
        instance.refreshImportProgress = vi.fn();

        instance.registerImportListeners();
        outputHandler?.call(instance, {
            source: 'fantrax',
            message: 'Processed Fantrax players 100 / 3000',
            status: 'progress',
        });

        expect(instance.streams.fantrax.running).toBe(true);
        expect(instance.refreshImportProgress).toHaveBeenCalledWith('fantrax');
    });

    it('checks server status instead of stopping immediately when a finished event arrives', async () => {
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
        const instance = adminHub();
        instance.streams.fantrax = {
            messages: [],
            open: false,
            running: true,
            importRun: { status: 'working' },
            progress: null,
        };
        instance.refreshImportProgress = vi.fn();
        instance.stopImportProgressPoll = vi.fn();

        instance.registerImportListeners();
        outputHandler?.call(instance, {
            source: 'fantrax',
            message: '',
            status: 'finished',
        });

        expect(instance.stopImportProgressPoll).not.toHaveBeenCalled();
        expect(instance.refreshImportProgress).toHaveBeenCalledWith('fantrax');
    });

    it('opens the admin hub on data imports by default', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: true, hasFantrax: true });

        expect(instance.activeTab).toBe('imports');
    });

    it('opens the admin hub from the tab query parameter', async () => {
        global.window = {
            location: {
                search: '?tab=triage',
                href: 'http://localhost/admin?tab=triage',
            },
            history: {
                replaceState: vi.fn(),
                state: null,
            },
        };

        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: true, hasFantrax: true });

        expect(instance.activeTab).toBe('triage');
    });

    it('lazy loads triage when the triage tab is selected', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<div data-admin-triage-mount></div>';
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-player-triage-page data-player-triage-url="/admin/player-triage"></div>',
                }),
            })
        );

        const instance = adminHub({ triageUrl: '/admin/player-triage?admin_panel=1' });

        await instance.setTab('triage');

        expect(instance.activeTab).toBe('triage');
        expect(fetch).toHaveBeenCalledWith('/admin/player-triage?admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-player-triage-page]')).not.toBeNull();
        expect(instance.triageLoaded).toBe(true);

        await instance.setTab('imports');
        await instance.setTab('triage');

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('updates availability without switching back to removed player tabs', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: false, hasFantrax: false });
        instance.activeTab = 'nhl';
        instance.activeSource = 'nhl';
        instance.roster.fantrax.page = 3;

        instance.handlePlayersAvailable('fantrax');

        expect(instance.hasFantrax).toBe(true);
        expect(instance.activeTab).toBe('triage');
        expect(instance.activeSource).toBe('fantrax');
        expect(instance.roster.fantrax.page).toBe(1);
    });

    it('keeps triage active when player availability arrives', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: false });
        instance.loadPlayers = vi.fn();
        instance.activeTab = 'triage';
        instance.activeSource = 'nhl';

        instance.handlePlayersAvailable('nhl');

        expect(instance.hasPlayers).toBe(true);
        expect(instance.activeTab).toBe('triage');
        expect(instance.loadPlayers).not.toHaveBeenCalled();
    });

    it('formats import last run timestamps for social display', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-27T15:00:00'));

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'contracts',
                    last_run: '2026-06-27 14:55:25',
                },
            ],
        });

        expect(instance.formatLastRun('contracts')).toBe('4m ago');
    });

    it('formats ISO import timestamps older than an hour without collapsing to just now', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-27T15:00:00-04:00'));

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'contracts',
                    last_run: '2026-06-27T17:45:00+00:00',
                },
            ],
        });

        expect(instance.formatLastRun('contracts')).toBe('1h ago');
    });

    it('does not replace last run with browser now when stream metadata lacks run timestamps', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'contracts',
                    last_run: '2026-06-27T17:45:00+00:00',
                },
            ],
        });
        instance.streams.contracts = { importRun: {} };

        instance.refreshImportMeta('contracts');

        expect(instance.imports[0].last_run).toBe('2026-06-27T17:45:00+00:00');
    });

    it('keeps polling when a command finishes but the import run is still working', async () => {
        vi.useFakeTimers();
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    import_run: {
                        status: 'working',
                        progress: {
                            processed_records: 100,
                            total_records: 3000,
                        },
                    },
                }),
            })
        );

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'fantrax',
                    status_url: '/admin/imports/fantrax/status',
                },
            ],
        });

        await instance.refreshImportProgress('fantrax', false);

        expect(instance.streams.fantrax.running).toBe(true);
        expect(instance.progressPollers.fantrax).toBeTruthy();
    });

    it('resets import card state before starting a new run', async () => {
        vi.useFakeTimers();
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    import: { imported: 100 },
                    import_run: {
                        status: 'completed',
                        started_at: '2026-06-28T12:00:00+00:00',
                        finished_at: '2026-06-28T12:00:01+00:00',
                        duration_seconds: 1,
                        progress: {
                            processed_records: 100,
                            successful_records: 100,
                            failed_records: 0,
                            skipped_records: 0,
                            total_records: 100,
                            percentage: 100,
                        },
                    },
                }),
            })
        );

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'yahoo',
                    label: 'Yahoo Players',
                    run_url: '/admin/yahoo/players/import',
                },
            ],
        });
        instance.streams.yahoo = {
            messages: [{ message: 'old output', status: 'completed' }],
            open: false,
            running: false,
            importRun: { status: 'completed' },
            progress: { processed_records: 12 },
        };
        instance.progressPollers.yahoo = setTimeout(() => {}, 1000);

        await instance.startImport('yahoo');

        expect(instance.streams.yahoo.open).toBe(true);
        expect(instance.streams.yahoo.running).toBe(false);
        expect(instance.streams.yahoo.progress.processed_records).toBe(100);
        expect(instance.streams.yahoo.messages).toHaveLength(1);
        expect(instance.streams.yahoo.messages[0].message).toBe('Yahoo Players imported 100 records');
        expect(instance.progressPollers.yahoo).toBeUndefined();
    });

    it('updates repeated immediate import runs with the newest response', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    import: { imported: 100 },
                    import_run: {
                        status: 'completed',
                        started_at: '2026-06-28T12:00:00+00:00',
                        finished_at: '2026-06-28T12:00:01+00:00',
                        progress: {
                            processed_records: 100,
                            successful_records: 100,
                            failed_records: 0,
                            skipped_records: 0,
                            total_records: 100,
                            percentage: 100,
                        },
                    },
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    import: { imported: 75 },
                    import_run: {
                        status: 'completed',
                        started_at: '2026-06-28T12:05:00+00:00',
                        finished_at: '2026-06-28T12:05:01+00:00',
                        progress: {
                            processed_records: 75,
                            successful_records: 75,
                            failed_records: 0,
                            skipped_records: 0,
                            total_records: 75,
                            percentage: 100,
                        },
                    },
                }),
            });

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'yahoo',
                    label: 'Yahoo Players',
                    run_url: '/admin/yahoo/players/import',
                },
            ],
        });

        await instance.startImport('yahoo');
        await instance.startImport('yahoo');

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(instance.streams.yahoo.running).toBe(false);
        expect(instance.streams.yahoo.progress.processed_records).toBe(75);
        expect(instance.imports[0].last_run).toBe('2026-06-28T12:05:01+00:00');
        expect(instance.streams.yahoo.messages).toHaveLength(1);
        expect(instance.streams.yahoo.messages[0].message).toBe('Yahoo Players imported 75 records');
    });

    it('includes human elapsed time in import progress detail text', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.streams.contracts = {
            importRun: {
                duration_seconds: 312,
            },
            progress: {
                successful_records: 789,
                failed_records: 2,
                skipped_records: 40,
            },
        };

        expect(instance.importProgressDetailText('contracts')).toBe(
            '789 imported, 2 failed, 40 skipped · elapsed 5m 12s'
        );
    });

    it('includes elapsed time from initial import card data', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub({
            imports: [
                {
                    key: 'contracts',
                    status: 'completed',
                    duration_seconds: 312,
                    progress: {
                        successful_records: 789,
                        failed_records: 2,
                        skipped_records: 40,
                    },
                },
            ],
        });

        instance.initializeImportStreams();

        expect(instance.importProgressDetailText('contracts')).toBe(
            '789 imported, 2 failed, 40 skipped · elapsed 5m 12s'
        );
    });
});
