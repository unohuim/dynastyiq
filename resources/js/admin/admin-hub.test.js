import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadAdminHub = async () => {
    vi.resetModules();
    return (await import('./admin-hub.js')).default;
};

describe('admin-hub import listeners', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        globalThis.localStorage?.clear();
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
        expect(listen).toHaveBeenCalledTimes(3);
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

    it('refreshes game imports when a game import status event arrives while active', async () => {
        let gameImportHandler;

        const listen = vi.fn((event, handler) => {
            if (event === '.admin.nhl-game-imports.updated') {
                gameImportHandler = handler;
            }
            return listener;
        });

        const listener = { listen };
        const privateChannel = vi.fn(() => listener);
        window.Echo = { private: privateChannel };

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.activeTab = 'game-imports';
        instance.loadGameImports = vi.fn();

        instance.registerImportListeners();
        gameImportHandler?.call(instance, {
            reason: 'stage-completed',
        });

        expect(instance.loadGameImports).toHaveBeenCalledTimes(1);
        expect(instance.loadGameImports).toHaveBeenCalledWith({ background: true });
    });

    it('refreshes validations when a game import status event arrives on the validations tab', async () => {
        let gameImportHandler;

        const listen = vi.fn((event, handler) => {
            if (event === '.admin.nhl-game-imports.updated') {
                gameImportHandler = handler;
            }
            return listener;
        });

        const listener = { listen };
        const privateChannel = vi.fn(() => listener);
        window.Echo = { private: privateChannel };

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.activeTab = 'validations';
        instance.loadGameImports = vi.fn();
        instance.loadValidations = vi.fn();
        instance.loadShiftMismatches = vi.fn();

        instance.registerImportListeners();
        gameImportHandler?.call(instance, {
            reason: 'stage-completed',
        });

        expect(instance.loadGameImports).not.toHaveBeenCalled();
        expect(instance.loadValidations).toHaveBeenCalledWith({ force: true, background: true });
        expect(instance.loadShiftMismatches).not.toHaveBeenCalled();
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

    it('opens the admin validations tab from the tab query parameter', async () => {
        global.window = {
            location: {
                search: '?tab=validations',
                href: 'http://localhost/admin?tab=validations',
            },
            history: {
                replaceState: vi.fn(),
                state: null,
            },
        };

        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: true, hasFantrax: true });

        expect(instance.activeTab).toBe('validations');
    });

    it('opens the admin shifts mismatch tab from the tab query parameter', async () => {
        global.window = {
            location: {
                search: '?tab=shift-mismatches',
                href: 'http://localhost/admin?tab=shift-mismatches',
            },
            history: {
                replaceState: vi.fn(),
                state: null,
            },
        };

        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: true, hasFantrax: true });

        expect(instance.activeTab).toBe('shift-mismatches');
    });

    it('opens the admin game imports tab from the tab query parameter', async () => {
        global.window = {
            location: {
                search: '?tab=game-imports',
                href: 'http://localhost/admin?tab=game-imports',
            },
            history: {
                replaceState: vi.fn(),
                state: null,
            },
        };

        const adminHub = await loadAdminHub();
        const instance = adminHub({ hasPlayers: true, hasFantrax: true });

        expect(instance.activeTab).toBe('game-imports');
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

    it('lazy loads game validations when the validations tab is selected', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<div data-admin-validations-mount></div>';
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-triage-page>Game Validation Triage</div>',
                }),
            })
        );

        const instance = adminHub({ validationsUrl: '/admin/nhl-validations?admin_panel=1' });

        await instance.setTab('validations');

        expect(instance.activeTab).toBe('validations');
        expect(fetch).toHaveBeenCalledWith('/admin/nhl-validations?admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-validation-triage-page]')).not.toBeNull();
        expect(instance.validationsLoaded).toBe(true);

        await instance.setTab('imports');
        await instance.setTab('validations');

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('lazy loads shift mismatches when the shift mismatches tab is selected', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<div data-admin-shift-mismatches-mount></div>';
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-triage-page>Shift Mismatch Triage</div>',
                }),
            })
        );

        const instance = adminHub({
            shiftMismatchesUrl: '/admin/nhl-validations?admin_panel=1&status=shiftchart-mismatch',
        });

        await instance.setTab('shift-mismatches');

        expect(instance.activeTab).toBe('shift-mismatches');
        expect(fetch).toHaveBeenCalledWith('/admin/nhl-validations?admin_panel=1&status=shiftchart-mismatch', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-validation-triage-page]')).not.toBeNull();
        expect(instance.shiftMismatchesLoaded).toBe(true);

        await instance.setTab('imports');
        await instance.setTab('shift-mismatches');

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('submits embedded validation action forms over AJAX', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = `
            <meta name="csrf-token" content="csrf-token-value">
            <div data-validation-detail-content="77">
                <form
                    action="/admin/nhl-validations/77/accept-api-pbp"
                    method="POST"
                    data-validation-action
                    data-validation-id="77"
                    data-validation-url="/admin/nhl-validations/77?admin_panel=1"
                >
                    <button type="submit"><span data-validation-action-label>Accept API</span></button>
                </form>
            </div>
            <div data-admin-validations-mount></div>
            <div data-admin-shift-mismatches-mount></div>
        `;
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ status: 'accepted_exception' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-detail-reloaded>Reloaded detail</div>',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validations-reloaded>Validations</div>',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-shift-reloaded>Shifts</div>',
                }),
            });

        const instance = adminHub({
            validationsUrl: '/admin/nhl-validations?admin_panel=1',
        });
        const target = document.querySelector('[data-validation-detail-content="77"]');
        const form = document.querySelector('[data-validation-action]');

        instance.bindValidationActionForms(target);
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(fetch).toHaveBeenNthCalledWith(1, 'http://localhost/admin/nhl-validations/77/accept-api-pbp', expect.objectContaining({
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
        }));
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-validations/77?admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-validations-reloaded]')).not.toBeNull();
    });

    it('loads embedded validation filter links as fragments', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = `
            <div data-admin-validations-mount>
                <a href="/admin/nhl-validations?status=approved">Approved</a>
            </div>
        `;
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validations-filtered>Approved validations</div>',
                }),
            })
        );

        const instance = adminHub();
        const mount = document.querySelector('[data-admin-validations-mount]');
        const link = document.querySelector('a');

        instance.bindValidationDetailToggles(mount);
        link.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/admin/nhl-validations?status=approved&admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-validations-filtered]')).not.toBeNull();
    });

    it('toggles embedded game validation details from a caret row action', async () => {
        vi.useFakeTimers();
        const adminHub = await loadAdminHub();
        document.body.innerHTML = `
            <div data-admin-validations-mount>
                <button
                    data-validation-toggle
                    data-validation-id="7"
                    data-validation-url="/admin/nhl-validations/7?admin_panel=1"
                    aria-expanded="false"
                >
                    <svg data-validation-caret></svg>
                </button>
                <table>
                    <tbody>
                        <tr data-validation-detail-row="7" class="hidden">
                            <td>
                                <div
                                    data-validation-detail-shell="7"
                                    class="grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-300 ease-out"
                                >
                                    <div class="min-h-0 overflow-hidden">
                                        <div data-validation-detail-content="7"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-detail>Delta rows</div>',
                }),
            })
        );

        const instance = adminHub();
        const mount = document.querySelector('[data-admin-validations-mount]');
        const trigger = document.querySelector('[data-validation-toggle]');
        const row = document.querySelector('[data-validation-detail-row="7"]');
        const shell = document.querySelector('[data-validation-detail-shell="7"]');
        const caret = document.querySelector('[data-validation-caret]');

        instance.bindValidationDetailToggles(mount);
        trigger.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/admin/nhl-validations/7?admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(trigger.getAttribute('aria-expanded')).toBe('true');
        expect(row.classList.contains('hidden')).toBe(false);
        expect(shell.classList.contains('grid-rows-[1fr]')).toBe(true);
        expect(shell.classList.contains('opacity-100')).toBe(true);
        expect(caret.classList.contains('rotate-180')).toBe(true);
        expect(document.querySelector('[data-validation-detail]')).not.toBeNull();

        trigger.click();

        expect(trigger.getAttribute('aria-expanded')).toBe('false');
        expect(shell.classList.contains('grid-rows-[0fr]')).toBe(true);
        expect(shell.classList.contains('opacity-0')).toBe(true);
        expect(caret.classList.contains('rotate-180')).toBe(false);
        expect(row.classList.contains('hidden')).toBe(false);

        vi.advanceTimersByTime(300);

        expect(row.classList.contains('hidden')).toBe(true);
    });

    it('queues a full game rebuild from an embedded validation row action', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = `
            <meta name="csrf-token" content="csrf-token-value">
            <div data-admin-validations-mount>
                <button
                    data-validation-rebuild
                    data-validation-id="7"
                    data-validation-rebuild-url="/admin/nhl-validations/7/rebuild-game"
                >
                    <span data-validation-rebuild-label>Re Run</span>
                </button>
            </div>
        `;
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ status: 'game_rebuild_queued' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-triage-page>Reloaded validations</div>',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    html: '<div data-validation-triage-page>Reloaded shift mismatches</div>',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 28, action: 'process' }] }),
            });

        const instance = adminHub({
            validationsUrl: '/admin/nhl-validations?admin_panel=1',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        const mount = document.querySelector('[data-admin-validations-mount]');
        const trigger = document.querySelector('[data-validation-rebuild]');

        instance.bindValidationDetailToggles(mount);
        trigger.click();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-validations/7/rebuild-game', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({}),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-validations?admin_panel=1', {
            headers: { Accept: 'application/json' },
        });
        expect(fetch).toHaveBeenNthCalledWith(3, '/admin/nhl-validations?admin_panel=1&status=shiftchart-mismatch', {
            headers: { Accept: 'application/json' },
        });
        expect(fetch).toHaveBeenNthCalledWith(4, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(document.querySelector('[data-validation-triage-page]')).not.toBeNull();
        expect(instance.gameImports.runs[0].id).toBe(28);
        expect(instance.validationRebuilds[7]).toBeUndefined();
    });

    it('loads game imports when the game imports tab is selected', async () => {
        const adminHub = await loadAdminHub();
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    processable: { date_count: 2 },
                    seasons: [
                        { season: '20252026', label: '2025-26' },
                    ],
                    runs: [
                        {
                            id: 12,
                            action: 'process',
                            status: 'completed',
                            start_date: '2026-01-17',
                            end_date: '2026-01-15',
                            progress: { percentage: 33 },
                            facts: {
                                discovered_game_count: 2,
                                discovered_game_date_count: 2,
                                scheduled_stage_rows: 8,
                            },
                        },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    gaps: [
                        {
                            game_id: 2026020001,
                            sources: [{ source: 'shifts', status: 'empty' }],
                        },
                    ],
                }),
            });

        const instance = adminHub({
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
            gameImportSourceGapsUrl: '/admin/nhl-game-imports/source-gaps',
        });

        await instance.setTab('game-imports');

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/source-gaps', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.runs).toHaveLength(1);
        expect(instance.gameImports.runs[0].id).toBe(12);
        expect(instance.gameImports.processableDateCount).toBe(2);
        expect(instance.gameImports.seasons[0].label).toBe('2025-26');
        expect(instance.gameImports.sourceGaps.items).toHaveLength(1);

        instance.gameImports.loading = false;
        await instance.loadGameImports({ background: true });

        expect(instance.gameImports.loading).toBe(false);
    });

    it('disables discovery row processing when there are no scheduled stage rows', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const run = {
            id: 12,
            action: 'discover',
            facts: {
                scheduled_stage_rows: 0,
            },
        };

        expect(instance.canProcessGameImportRun(run)).toBe(false);

        run.facts.scheduled_stage_rows = 8;
        expect(instance.canProcessGameImportRun(run)).toBe(true);

        instance.gameImports.processingRuns = { [run.id]: true };
        expect(instance.canProcessGameImportRun(run)).toBe(false);
    });

    it('enables discovery row processing as a replay when games exist with no scheduled stage rows', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const run = {
            id: 12,
            action: 'discover',
            processing_started: false,
            facts: {
                discovered_game_count: 13,
                scheduled_stage_rows: 0,
            },
        };

        expect(instance.shouldReprocessGameImportRun(run)).toBe(true);
        expect(instance.canProcessGameImportRun(run)).toBe(true);

        run.facts.scheduled_stage_rows = 8;
        expect(instance.shouldReprocessGameImportRun(run)).toBe(false);
        expect(instance.canProcessGameImportRun(run)).toBe(true);

        run.payload = { rerun_from_run_id: 40 };
        expect(instance.shouldReprocessGameImportRun(run)).toBe(true);

        run.facts.scheduled_stage_rows = 0;
        run.payload = {};
        instance.gameImports.processingRuns = { [run.id]: true };
        expect(instance.shouldReprocessGameImportRun(run)).toBe(true);
        expect(instance.canProcessGameImportRun(run)).toBe(false);
    });

    it('enables rerun for all import row types when the stored payload can be replayed', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const run = {
            id: 41,
            action: 'process',
            start_date: '2026-01-17',
            end_date: '2026-01-15',
        };

        expect(instance.canRerunGameImportRun(run)).toBe(true);

        instance.gameImports.rerunningRuns[41] = true;
        expect(instance.canRerunGameImportRun(run)).toBe(true);

        instance.gameImports.rerunningRuns = {};
        run.end_date = '';
        expect(instance.canRerunGameImportRun(run)).toBe(false);

        run.end_date = '2026-01-15';
        run.action = 'season-sync';
        run.payload = { season: '20252026' };
        expect(instance.canRerunGameImportRun(run)).toBe(true);

        run.payload = {};
        expect(instance.canRerunGameImportRun(run)).toBe(false);
    });

    it('formats discovery facts for pending and discovered ranges', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();

        expect(instance.gameImportTitle({
            action: 'discover',
            start_date: '2026-01-15',
            end_date: '2026-01-15',
        })).toBe('Jan 15, 2026');
        expect(instance.gameImportTitle({
            action: 'discover',
            start_date: '2026-01-17',
            end_date: '2026-01-15',
        })).toBe('Jan 15, 2026 - Jan 17, 2026');
        expect(instance.gameImportBadgeText({
            action: 'discover',
            status: 'queued',
            facts: { total_stage_rows: 0 },
        })).toBe('DISCOVERING');
        expect(instance.discoveryStatusText({
            date_count: 3,
            facts: { total_stage_rows: 0 },
        })).toBe('DISCOVERING');
        expect(instance.discoveryFactsText({
            date_count: 3,
            facts: { selected_date_count: 3 },
        })).toBe('Checking 3 selected dates');
        expect(instance.gameImportBadgeText({
            action: 'discover',
            facts: { total_stage_rows: 16, scheduled_stage_rows: 16 },
        })).toBe('READY');
        expect(instance.gameImportTitle({
            action: 'discover',
            processing_started: true,
            start_date: '2026-01-17',
            end_date: '2026-01-15',
        })).toBe('Jan 15, 2026 - Jan 17, 2026');
        expect(instance.gameImportBadgeText({
            action: 'discover',
            processing_started: true,
            status: 'running',
        })).toBe('RUNNING');
        expect(instance.discoveryStatusText({
            facts: { total_stage_rows: 16, scheduled_stage_rows: 16 },
        })).toBe('READY');
        expect(instance.discoveryFactsText({
            facts: {
                discovered_game_count: 2,
                discovered_game_date_count: 2,
                scheduled_stage_rows: 16,
            },
        })).toBe('2 games · 16 stages scheduled');
    });

    it('formats game import accordion rows and per-game progress', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const run = {
            id: 42,
            action: 'discover',
            facts: {
                discovered_game_count: 3,
                scheduled_stage_rows: 24,
            },
            games: [
                {
                    game_id: '2026020001',
                    game_date: '2026-01-15',
                    away_team_abbrev: 'MTL',
                    home_team_abbrev: 'TOR',
                    total_stage_rows: 8,
                    completed_stage_rows: 4,
                    running_stage_rows: 1,
                    skipped_stage_rows: 0,
                    failed_stage_rows: 0,
                    percentage: 50,
                },
            ],
        };

        expect(instance.gameImportSummaryText(run)).toBe('3 games · 24 stages scheduled');
        expect(instance.gameImportAccordionId(run)).toBe('game-import-run-42-games');
        expect(instance.isGameImportRunExpanded(run)).toBe(false);

        instance.toggleGameImportRun(run);

        expect(instance.isGameImportRunExpanded(run)).toBe(true);
        expect(instance.gameImportGames(run)).toHaveLength(1);
        expect(instance.gameImportGameLabel(run.games[0])).toBe('MTL vs TOR');
        expect(instance.gameImportGameMeta(run.games[0])).toBe('2026020001 · Jan 15, 2026');
        expect(instance.gameImportGameLabel({ game_id: '2026020953', game_date: '2026-03-03' })).toBe('2026020953');
        expect(instance.gameImportGameMeta({ game_id: '2026020953', game_date: '2026-03-03' })).toBe('Mar 3, 2026');
        expect(instance.gameImportGameProgressPercentage(run.games[0])).toBe(50);
        expect(instance.gameImportGameProgressClass(run.games[0])).toBe('bg-indigo-600');
        expect(instance.gameImportGameProgressClass({
            total_stage_rows: 8,
            completed_stage_rows: 0,
            running_stage_rows: 0,
            failed_stage_rows: 0,
            percentage: 0,
        })).toBe('bg-yellow-400');
        expect(instance.gameImportGameProgressClass({
            total_stage_rows: 8,
            completed_stage_rows: 8,
            running_stage_rows: 0,
            failed_stage_rows: 0,
            percentage: 100,
        })).toBe('bg-lime-500');
        expect(instance.gameImportGameProgressClass({
            total_stage_rows: 8,
            completed_stage_rows: 4,
            running_stage_rows: 0,
            skipped_stage_rows: 4,
            failed_stage_rows: 0,
            percentage: 100,
        })).toBe('bg-yellow-400');
        expect(instance.gameImportGameProgressText(run.games[0])).toBe('4 / 8 stages completed · 1 active · 0 failed');
        expect(instance.gameImportGameProgressText({
            total_stage_rows: 8,
            completed_stage_rows: 4,
            running_stage_rows: 0,
            skipped_stage_rows: 4,
            failed_stage_rows: 0,
        })).toBe('4 / 8 stages completed · 0 active · 4 skipped · 0 failed');
        expect(instance.gameImportBlockedSources({
            blocked_sources: [
                {
                    source: 'shifts',
                    status: 'empty',
                    reason: 'empty_shiftcharts',
                    url: 'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020959',
                },
            ],
        })).toHaveLength(1);
        expect(instance.gameImportSourceStatusLabel({
            source: 'shifts',
            status: 'empty',
            reason: 'empty_shiftcharts',
        })).toBe('shifts empty · empty_shiftcharts');

        const gap = {
            game_id: '2026020959',
            sources: [
                { source: 'shifts', status: 'empty', reason: 'empty_shiftcharts' },
                { source: 'boxscore', status: 'unavailable', reason: 'http_404' },
            ],
        };

        expect(instance.sourceGapsAccordionId()).toBe('game-import-source-gaps');
        expect(instance.isGameImportSourceGapsExpanded()).toBe(false);
        expect(instance.gameImportSourceGapSummaryText(gap)).toBe('2 sources missing');

        instance.toggleGameImportSourceGaps();

        expect(instance.isGameImportSourceGapsExpanded()).toBe(true);
        expect(instance.gameImportSourceGapSummaryText({
            game_id: '2026020960',
            sources: [{ source: 'shifts', status: 'empty' }],
        })).toBe('1 source missing');
    });

    it('selects NHL game import season sync options from the dropdown state', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.gameImports.seasons = [
            { season: '20252026', label: '2025-26' },
            { season: '20242025', label: '2024-25' },
        ];

        expect(instance.gameImportSeasonSyncButtonText()).toBe('Sync Season');
        expect(instance.gameImportSeasonOptions()).toHaveLength(2);

        instance.toggleGameImportSeasonDropdown();
        expect(instance.gameImports.seasonDropdownOpen).toBe(true);

        instance.selectGameImportSeason(instance.gameImports.seasons[0]);

        expect(instance.gameImports.selectedSeason).toBe('20252026');
        expect(instance.gameImports.seasonDropdownOpen).toBe(false);
        expect(instance.gameImportSeasonSyncButtonText()).toBe('Sync 2025-26');

        instance.closeGameImportSeasonDropdown();
        expect(instance.gameImports.seasonDropdownOpen).toBe(false);
    });

    it('submits a selected NHL season sync and refreshes game imports', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Season sync queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    runs: [
                        {
                            id: 29,
                            action: 'season-sync',
                            status: 'queued',
                            payload: { season: '20252026', season_label: '2025-26' },
                        },
                    ],
                }),
            });

        const instance = adminHub({
            gameImportSeasonSyncUrl: '/admin/nhl-game-imports/season-sync',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        instance.gameImports.seasons = [{ season: '20252026', label: '2025-26' }];
        instance.selectGameImportSeason(instance.gameImports.seasons[0]);

        await instance.submitGameImportSeasonSync();

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/season-sync', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ season: '20252026' }),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.runs[0].id).toBe(29);
        expect(instance.gameImports.syncingSeason).toBe(false);
        expect(instance.shouldShowGameImportSeasonSync()).toBe(true);
    });

    it('confirms and queues an NHL game import reset', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        globalThis.confirm = vi.fn(() => true);
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'NHL game import reset queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    runs: [
                        {
                            id: 32,
                            action: 'empty-games',
                            status: 'queued',
                        },
                    ],
                }),
            });

        const instance = adminHub({
            gameImportEmptyGamesUrl: '/admin/nhl-game-imports/empty-games',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });

        await instance.submitGameImportEmptyGames();

        expect(confirm).toHaveBeenCalledWith(
            'Empty all NHL game imports, summaries, season stats, validations, and progress?'
        );
        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/empty-games', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({}),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.runs[0].id).toBe(32);
        expect(instance.gameImports.emptyingGames).toBe(false);
    });

    it('does not queue an NHL game import reset when confirmation is cancelled', async () => {
        const adminHub = await loadAdminHub();
        globalThis.confirm = vi.fn(() => false);
        global.fetch = vi.fn();

        const instance = adminHub({
            gameImportEmptyGamesUrl: '/admin/nhl-game-imports/empty-games',
        });

        await instance.submitGameImportEmptyGames();

        expect(confirm).toHaveBeenCalledOnce();
        expect(fetch).not.toHaveBeenCalled();
        expect(instance.gameImports.emptyingGames).toBe(false);
    });

    it('formats and dismisses NHL season sync progress cards', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const run = {
            id: 30,
            action: 'season-sync',
            status: 'completed',
            payload: {
                season: '20252026',
                season_label: '2025-26',
                rows_upserted: 812,
            },
            progress: { percentage: 100 },
        };
        instance.gameImports.runs = [
            run,
            { id: 31, action: 'process', status: 'running' },
        ];

        expect(instance.gameImportVisibleRuns()).toHaveLength(1);
        expect(instance.gameImportLatestSeasonSyncRun().id).toBe(30);
        expect(instance.gameImportSeasonSyncProgressText(run)).toBe('812 season stat rows synced');
        expect(instance.gameImportSummaryText(run)).toBe('812 season stat rows synced');
        expect(instance.gameImportProgressPercentage(run)).toBe(100);

        instance.dismissGameImportSeasonSync();

        expect(instance.shouldShowGameImportSeasonSync()).toBe(false);
        expect(JSON.parse(globalThis.localStorage.getItem('dynastyiq:admin:nhl-game-imports:season-sync-dismissed'))).toEqual(['30']);
    });

    it('removes source gap games from recent orchestration runs', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.gameImports.sourceGaps.items = [
            { game_id: '2026020001' },
            { game_id: 2026020003 },
        ];
        instance.gameImports.runs = [
            {
                id: 41,
                action: 'process',
                games: [
                    { game_id: '2026020001' },
                    { game_id: '2026020002' },
                ],
            },
            {
                id: 42,
                action: 'process',
                games: [
                    { game_id: '2026020003' },
                ],
            },
            {
                id: 43,
                action: 'discover',
                processing_started: false,
                games: [],
            },
        ];

        expect(instance.gameImportGames(instance.gameImports.runs[0])).toEqual([
            { game_id: '2026020002' },
        ]);
        expect(instance.gameImportGames(instance.gameImports.runs[1])).toEqual([]);
        expect(instance.gameImportVisibleRuns().map((run) => run.id)).toEqual([41, 42, 43]);
    });

    it('fades completed game progress toward gray before permanently removing it', async () => {
        vi.useFakeTimers();

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const completedGame = {
            game_id: '2026020001',
            total_stage_rows: 8,
            completed_stage_rows: 8,
            running_stage_rows: 0,
            skipped_stage_rows: 0,
            failed_stage_rows: 0,
            percentage: 100,
        };
        instance.gameImports.runs = [
            {
                id: 41,
                action: 'process',
                games: [completedGame],
            },
        ];

        instance.syncCompletedGameFadeState();

        expect(instance.gameImportGames(instance.gameImports.runs[0])).toHaveLength(1);
        expect(instance.gameImportGameProgressClass(completedGame)).toBe('bg-lime-500');

        vi.advanceTimersByTime(1000);
        expect(instance.gameImportGameProgressClass(completedGame)).toBe('bg-lime-300');

        vi.advanceTimersByTime(1000);
        expect(instance.gameImportGameProgressClass(completedGame)).toBe('bg-green-100');

        vi.advanceTimersByTime(1000);
        expect(instance.gameImportGameProgressClass(completedGame)).toBe('bg-gray-100');

        vi.advanceTimersByTime(1000);
        expect(instance.gameImportGameProgressClass(completedGame)).toBe('bg-gray-200');

        vi.advanceTimersByTime(1000);

        expect(instance.gameImportGames(instance.gameImports.runs[0])).toHaveLength(0);
        expect(instance.gameImportVisibleRuns().map((run) => run.id)).toEqual([41]);
        expect(JSON.parse(globalThis.localStorage.getItem('dynastyiq:admin:nhl-game-imports:completed-games-dismissed'))).toEqual(['2026020001']);

        const reloaded = adminHub();
        reloaded.gameImports.runs = instance.gameImports.runs;

        expect(reloaded.gameImportGames(reloaded.gameImports.runs[0])).toHaveLength(0);
        expect(reloaded.gameImportVisibleRuns().map((run) => run.id)).toEqual([41]);
    });

    it('keeps stopped failed games visible instead of fading them out', async () => {
        vi.useFakeTimers();

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        const failedGame = {
            game_id: '2026020002',
            total_stage_rows: 8,
            completed_stage_rows: 7,
            running_stage_rows: 0,
            skipped_stage_rows: 0,
            failed_stage_rows: 1,
            percentage: 100,
        };
        instance.gameImports.runs = [
            {
                id: 42,
                action: 'process',
                games: [failedGame],
            },
        ];

        instance.syncCompletedGameFadeState();
        vi.advanceTimersByTime(6000);

        expect(instance.gameImportGames(instance.gameImports.runs[0])).toEqual([failedGame]);
        expect(instance.gameImportGameProgressClass(failedGame)).toBe('bg-red-500');
        expect(globalThis.localStorage.getItem('dynastyiq:admin:nhl-game-imports:completed-games-dismissed')).toBeNull();
    });

    it('keeps dismissed NHL season sync progress cards hidden after reload-style initialization', async () => {
        globalThis.localStorage.setItem(
            'dynastyiq:admin:nhl-game-imports:season-sync-dismissed',
            JSON.stringify(['30'])
        );

        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.gameImports.runs = [
            {
                id: 30,
                action: 'season-sync',
                status: 'completed',
                payload: {
                    season: '20252026',
                    season_label: '2025-26',
                    rows_upserted: 812,
                },
                progress: { percentage: 100 },
            },
        ];

        expect(instance.shouldShowGameImportSeasonSync()).toBe(false);

        instance.gameImports.runs = [
            {
                id: 31,
                action: 'season-sync',
                status: 'queued',
                payload: {
                    season: '20252026',
                    season_label: '2025-26',
                },
                progress: { percentage: 0 },
            },
        ];

        expect(instance.shouldShowGameImportSeasonSync()).toBe(true);
    });

    it('opens and closes the game import drawer without mutating the form', async () => {
        const adminHub = await loadAdminHub();
        const instance = adminHub();
        instance.gameImports.form.date = '2026-01-15';

        instance.openGameImportDrawer();
        expect(instance.gameImports.drawerOpen).toBe(true);

        instance.closeGameImportDrawer();
        expect(instance.gameImports.drawerOpen).toBe(false);
        expect(instance.gameImports.form.date).toBe('2026-01-15');
    });

    it('submits game discovery as JSON and refreshes runs', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Discovery queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 22, action: 'discover' }] }),
            });

        const instance = adminHub({
            gameImportDiscoverUrl: '/admin/nhl-game-imports/discover',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        instance.gameImports.drawerOpen = true;
        instance.gameImports.form.date = '2026-01-15';

        await instance.submitGameImportDiscover();

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/discover', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ date: '2026-01-15' }),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.drawerOpen).toBe(false);
        expect(instance.gameImports.runs[0].id).toBe(22);
    });

    it('submits process games using the selected discovery run range', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Processing queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 23, action: 'process' }] }),
            });

        const instance = adminHub({
            gameImportProcessUrl: '/admin/nhl-game-imports/process',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        const run = {
            id: 23,
            start_date: '2026-01-17',
            end_date: '2026-01-15',
        };

        await instance.processGameImports(run);

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/process', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ run_id: 23, start: '2026-01-17', end: '2026-01-15' }),
        });
        expect(instance.gameImports.runs[0].id).toBe(23);
        expect(instance.gameImports.processingRuns[23]).toBeUndefined();
    });

    it('submits game import rerun using the selected run range', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Discovery queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 25, action: 'discover' }] }),
            });

        const instance = adminHub({
            gameImportDiscoverUrl: '/admin/nhl-game-imports/discover',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        instance.refreshValidationContainers = vi.fn(() => Promise.resolve());
        const run = {
            id: 24,
            action: 'process',
            start_date: '2026-01-17',
            end_date: '2026-01-15',
        };

        await instance.rerunGameImportRun(run);

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/discover', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ run_id: 24, start: '2026-01-17', end: '2026-01-15' }),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.refreshValidationContainers).toHaveBeenCalledTimes(1);
        expect(instance.gameImports.rerunningRuns[24]).toBeUndefined();
    });

    it('submits season sync rerun using the stored season payload', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Season sync queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 31, action: 'season-sync' }] }),
            });

        const instance = adminHub({
            gameImportSeasonSyncUrl: '/admin/nhl-game-imports/season-sync',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        instance.refreshValidationContainers = vi.fn(() => Promise.resolve());
        const run = {
            id: 31,
            action: 'season-sync',
            payload: { season: '20252026' },
        };

        await instance.rerunGameImportRun(run);

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/season-sync', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ season: '20252026' }),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.refreshValidationContainers).toHaveBeenCalledTimes(1);
        expect(instance.gameImports.rerunningRuns[31]).toBeUndefined();
    });

    it('submits replay processing from a discovered range with no scheduled rows', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ message: 'Processing queued.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 24, action: 'discover' }] }),
            });

        const instance = adminHub({
            gameImportProcessUrl: '/admin/nhl-game-imports/process',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });
        const run = {
            id: 24,
            action: 'discover',
            processing_started: false,
            start_date: '2026-01-17',
            end_date: '2026-01-15',
            payload: {
                rerun_from_run_id: 12,
            },
            facts: {
                discovered_game_count: 3,
                scheduled_stage_rows: 21,
            },
        };

        await instance.processGameImports(run);

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/process', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({
                run_id: 24,
                start: '2026-01-17',
                end: '2026-01-15',
                reprocess_existing: true,
            }),
        });
    });

    it('reruns a source gap and refreshes source gaps plus game imports', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ status: 'shift_stages_queued' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ gaps: [] }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 24, action: 'process' }] }),
            });

        const instance = adminHub({
            gameImportSourceGapsUrl: '/admin/nhl-game-imports/source-gaps',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });

        await instance.rerunGameImportSourceGap({ game_id: 2026020001 });

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/source-gaps/2026020001/rerun', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({}),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/source-gaps', {
            headers: { Accept: 'application/json' },
        });
        expect(fetch).toHaveBeenNthCalledWith(3, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.sourceGaps.items).toHaveLength(0);
        expect(instance.gameImports.runs[0].id).toBe(24);
        expect(instance.gameImports.sourceGaps.rerunning[2026020001]).toBeUndefined();
    });

    it('reruns a stopped game and refreshes source gaps plus game imports', async () => {
        const adminHub = await loadAdminHub();
        document.body.innerHTML = '<meta name="csrf-token" content="csrf-token-value">';
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ status: 'game_rebuild_queued' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ gaps: [] }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ runs: [{ id: 25, action: 'process' }] }),
            });

        const instance = adminHub({
            gameImportGameRerunUrl: '/admin/nhl-game-imports/games',
            gameImportSourceGapsUrl: '/admin/nhl-game-imports/source-gaps',
            gameImportStatusUrl: '/admin/nhl-game-imports/status',
        });

        await instance.rerunStoppedGameImport({ game_id: 2026020001 }, { id: 25 });

        expect(fetch).toHaveBeenNthCalledWith(1, '/admin/nhl-game-imports/games/2026020001/rerun', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-token-value',
            },
            body: JSON.stringify({ run_id: 25 }),
        });
        expect(fetch).toHaveBeenNthCalledWith(2, '/admin/nhl-game-imports/source-gaps', {
            headers: { Accept: 'application/json' },
        });
        expect(fetch).toHaveBeenNthCalledWith(3, '/admin/nhl-game-imports/status', {
            headers: { Accept: 'application/json' },
        });
        expect(instance.gameImports.runs[0].id).toBe(25);
        expect(instance.gameImports.rerunningGames[2026020001]).toBeUndefined();
    });

    it('shows validation errors from game import JSON responses', async () => {
        const adminHub = await loadAdminHub();
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                json: () => Promise.resolve({
                    message: 'The given data was invalid.',
                    errors: {
                        date: ['Choose a date option before queuing discovery.'],
                    },
                }),
            })
        );

        const instance = adminHub({ gameImportDiscoverUrl: '/admin/nhl-game-imports/discover' });

        await instance.submitGameImportDiscover();

        expect(instance.gameImports.error).toBe('Choose a date option before queuing discovery.');
        expect(instance.gameImports.discovering).toBe(false);
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

    it('posts fantrax league refresh from the admin imports panel', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="token-123" />';
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ message: 'League refresh queued.' }),
            })
        );

        const adminHub = await loadAdminHub();
        const instance = adminHub({
            leagueRefreshUrl: '/leagues/resync',
        });

        await instance.refreshFantraxLeagues();

        expect(global.fetch).toHaveBeenCalledWith('/leagues/resync', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({
                'X-CSRF-TOKEN': 'token-123',
                'X-Requested-With': 'XMLHttpRequest',
            }),
            body: JSON.stringify({}),
        }));
        expect(instance.fantraxLeagueRefresh.running).toBe(false);
        expect(instance.streams.fantrax.open).toBe(true);
        expect(instance.streams.fantrax.messages[0].message).toBe('League refresh queued.');
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
