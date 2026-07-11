import { bootPlayerTriage } from './player-triage.js';

let importListenersRegistered = false;
const validationDetailTransitionMs = 300;
const seasonSyncDismissedStorageKey = 'dynastyiq:admin:nhl-game-imports:season-sync-dismissed';
const completedGameDismissedStorageKey = 'dynastyiq:admin:nhl-game-imports:completed-games-dismissed';
const completedGameFadeClasses = [
    'bg-lime-500',
    'bg-lime-300',
    'bg-green-100',
    'bg-gray-100',
    'bg-gray-200',
];

function readDismissedSeasonSyncRunIds() {
    try {
        const stored = globalThis.localStorage?.getItem(seasonSyncDismissedStorageKey);
        const parsed = stored ? JSON.parse(stored) : [];

        return Array.isArray(parsed) ? parsed.map((id) => String(id)) : [];
    } catch {
        return [];
    }
}

function writeDismissedSeasonSyncRunIds(ids) {
    try {
        globalThis.localStorage?.setItem(seasonSyncDismissedStorageKey, JSON.stringify(ids));
    } catch {
        // Browser storage is a convenience for UI dismissal, not required for operation.
    }
}

function readDismissedCompletedGameIds() {
    try {
        const stored = globalThis.localStorage?.getItem(completedGameDismissedStorageKey);
        const parsed = stored ? JSON.parse(stored) : [];

        return Array.isArray(parsed) ? parsed.map((id) => String(id)) : [];
    } catch {
        return [];
    }
}

function writeDismissedCompletedGameIds(ids) {
    try {
        globalThis.localStorage?.setItem(completedGameDismissedStorageKey, JSON.stringify(ids));
    } catch {
        // Browser storage is a convenience for UI dismissal, not required for operation.
    }
}

export default function adminHub(options = {}) {
    const nhlAvailable = Boolean(options.hasPlayers);
    const fantraxAvailable = Boolean(options.hasFantrax);
    const requestedTab = new URLSearchParams(
        typeof window !== 'undefined' && window.location?.search ? window.location.search : ''
    ).get('tab');
    const validInitialTabs = ['imports', 'platform-imports', 'users', 'activity', 'game-imports', 'triage', 'validations'];

    const initialTab = validInitialTabs.includes(requestedTab) ? requestedTab : 'imports';
    const initialSource = nhlAvailable ? 'nhl' : fantraxAvailable ? 'fantrax' : 'nhl';

    return {
        activeTab: initialTab,
        imports: options.imports ?? [],
        users: options.users ?? [],
        activity: options.activity ?? {},
        activeSource: initialSource,
        hasPlayers: nhlAvailable,
        hasFantrax: fantraxAvailable,
        triageUrl: options.triageUrl ?? '/admin/player-triage?admin_panel=1',
        validationsUrl: options.validationsUrl ?? '/admin/nhl-validations?admin_panel=1',
        gameImportStatusUrl: options.gameImportStatusUrl ?? '/admin/nhl-game-imports/status',
        gameImportSourceGapsUrl: options.gameImportSourceGapsUrl ?? '/admin/nhl-game-imports/source-gaps',
        gameImportGameRerunUrl: options.gameImportGameRerunUrl ?? '/admin/nhl-game-imports/games',
        gameImportDiscoverUrl: options.gameImportDiscoverUrl ?? '/admin/nhl-game-imports/discover',
        gameImportProcessUrl: options.gameImportProcessUrl ?? '/admin/nhl-game-imports/process',
        gameImportSeasonSyncUrl: options.gameImportSeasonSyncUrl ?? '/admin/nhl-game-imports/season-sync',
        triageLoaded: false,
        triageLoading: false,
        triageError: '',
        validationsLoaded: false,
        validationsLoading: false,
        validationsError: '',
        validationRebuilds: {},
        validationDetails: {},
        gameImports: {
            drawerOpen: false,
            loading: false,
            discovering: false,
            processing: false,
            syncingSeason: false,
            error: '',
            runs: [],
            seasons: [],
            expandedRuns: {},
            rerunningGames: {},
            completedGameFadeSteps: {},
            completedGameFadeTimers: {},
            dismissedCompletedGameIds: readDismissedCompletedGameIds(),
            sourceGapsExpanded: false,
            processableDateCount: 0,
            seasonDropdownOpen: false,
            selectedSeason: '',
            seasonSyncDismissedRunIds: readDismissedSeasonSyncRunIds(),
            sourceGaps: {
                loading: false,
                items: [],
                rerunning: {},
            },
            form: {
                date: '',
                start: '',
                end: '',
                days: '',
                newdays: '',
                season: '',
            },
        },

        streams: {},
        progressPollers: {},
        gameImportPoller: null,

        roster: {
            nhl: {
                items: [],
                page: 1,
                perPage: 25,
                total: 0,
                lastPage: 1,
                filter: '',
                toggle: false,
                loading: false,
            },
            fantrax: {
                items: [],
                page: 1,
                perPage: 25,
                total: 0,
                lastPage: 1,
                filter: '',
                toggle: true,
                loading: false,
            },
        },

        init() {
            this.initializeImportStreams();
            this.registerImportListeners();
            void this.setTab(this.activeTab, { history: false });
        },

        /* -----------------------------
         * Tabs
         * --------------------------- */

        async setTab(tab, options = {}) {
            if (tab === 'nhl' && !this.hasPlayers) {
                return;
            }

            if (tab === 'fantrax' && !this.hasFantrax) {
                return;
            }

            this.activeTab = tab;
            this.syncTabUrl(tab, options);

            if (tab !== 'game-imports') {
                this.stopGameImportPoll();
            }

            if (tab === 'nhl' || tab === 'fantrax') {
                this.activeSource = tab;
            }

            if (
                (tab === 'nhl' && this.hasPlayers) ||
                (tab === 'fantrax' && this.hasFantrax)
            ) {
                this.loadPlayers();
            }

            if (tab === 'triage') {
                await this.loadTriage();
            }

            if (tab === 'validations') {
                await this.loadValidations();
            }

            if (tab === 'game-imports') {
                await this.loadGameImports();
                await this.loadGameImportSourceGaps();
            }
        },

        syncTabUrl(tab, options = {}) {
            if (
                options.history === false ||
                typeof window === 'undefined' ||
                !window.location?.href ||
                typeof window.history?.replaceState !== 'function'
            ) {
                return;
            }

            const url = new URL(window.location.href);

            if (tab === 'imports') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tab);
            }

            window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
        },

        async loadTriage() {
            if (this.triageLoaded || this.triageLoading) {
                return;
            }

            this.triageLoading = true;
            this.triageError = '';

            try {
                const response = await fetch(this.triageUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Unable to load triage data');
                }

                if (!payload.html) {
                    throw new Error('Triage response did not include a page fragment');
                }

                const mount = this.$refs?.triageMount ?? document.querySelector('[data-admin-triage-mount]');
                if (!mount) {
                    throw new Error('Triage mount was not found');
                }

                mount.innerHTML = payload.html;
                this.triageLoaded = true;
                bootPlayerTriage();
            } catch (error) {
                this.triageError = error.message ?? 'Unable to load triage data';
            } finally {
                this.triageLoading = false;
            }
        },

        async loadValidations(options = {}) {
            const force = Boolean(options.force);
            const background = Boolean(options.background);

            if ((!force && this.validationsLoaded) || this.validationsLoading) {
                return;
            }

            if (!background) {
                this.validationsLoading = true;
            }
            this.validationsError = '';

            try {
                const response = await fetch(this.validationsUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Unable to load validation triage');
                }

                if (!payload.html) {
                    throw new Error('Validation response did not include a page fragment');
                }

                const mount = this.$refs?.validationsMount ?? document.querySelector('[data-admin-validations-mount]');
                if (!mount) {
                    throw new Error('Validation mount was not found');
                }

                mount.innerHTML = payload.html;
                this.bindValidationDetailToggles(mount);
                this.validationsLoaded = true;
                this.validationDetails = {};
            } catch (error) {
                this.validationsError = error.message ?? 'Unable to load validation triage';
            } finally {
                if (!background) {
                    this.validationsLoading = false;
                }
            }
        },

        bindValidationDetailToggles(mount) {
            if (!mount || mount.dataset.validationTogglesBound === 'true') {
                return;
            }

            mount.dataset.validationTogglesBound = 'true';
            mount.addEventListener('click', (event) => {
                const rebuildTrigger = event.target?.closest?.('[data-validation-rebuild]');

                if (rebuildTrigger) {
                    event.preventDefault();
                    void this.rebuildValidationGame(rebuildTrigger);
                    return;
                }

                const trigger = event.target?.closest?.('[data-validation-toggle]');

                if (!trigger) {
                    return;
                }

                event.preventDefault();
                void this.toggleValidationDetail(trigger);
            });
        },

        async rebuildValidationGame(trigger) {
            const validationId = trigger.dataset.validationId;
            const url = trigger.dataset.validationRebuildUrl;

            if (!validationId || !url || this.validationRebuilds[validationId] === true) {
                return;
            }

            this.validationsError = '';
            this.validationRebuilds = {
                ...this.validationRebuilds,
                [validationId]: true,
            };
            trigger.disabled = true;
            const label = trigger.querySelector('[data-validation-rebuild-label]');
            const previousLabel = label?.textContent ?? '';
            if (label) {
                label.textContent = 'Queuing...';
            }

            try {
                await this.sendGameImportRequest(url, {});
                await this.loadValidations({ force: true, background: true });
                await this.loadGameImports({ background: true });
            } catch (error) {
                this.validationsError = error.message ?? 'Unable to queue game rebuild';
            } finally {
                const next = { ...this.validationRebuilds };
                delete next[validationId];
                this.validationRebuilds = next;
                trigger.disabled = false;
                if (label) {
                    label.textContent = previousLabel || 'Re Run';
                }
            }
        },

        async toggleValidationDetail(trigger) {
            const validationId = trigger.dataset.validationId;
            const url = trigger.dataset.validationUrl;

            if (!validationId || !url) {
                return;
            }

            const row = document.querySelector(`[data-validation-detail-row="${validationId}"]`);
            const shell = document.querySelector(`[data-validation-detail-shell="${validationId}"]`);
            const target = document.querySelector(`[data-validation-detail-content="${validationId}"]`);

            if (!row || !shell || !target) {
                return;
            }

            const isOpen = trigger.getAttribute('aria-expanded') === 'true';

            if (isOpen) {
                this.setValidationDetailOpen(trigger, row, shell, false);
                return;
            }

            this.setValidationDetailOpen(trigger, row, shell, true);

            if (this.validationDetails[validationId]?.loaded) {
                return;
            }

            target.innerHTML = '<div class="px-4 py-6 text-sm text-gray-500">Loading validation details...</div>';

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Unable to load validation details');
                }

                if (!payload.html) {
                    throw new Error('Validation detail response did not include a page fragment');
                }

                target.innerHTML = payload.html;
                this.validationDetails[validationId] = { loaded: true };
            } catch (error) {
                target.innerHTML = `<div class="px-4 py-6 text-sm text-red-600">${this.escapeHtml(error.message ?? 'Unable to load validation details')}</div>`;
            }
        },

        setValidationDetailOpen(trigger, row, shell, open) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                row.dataset.validationClosing = 'false';
                row.classList.remove('hidden');
            }

            shell.classList.toggle('grid-rows-[0fr]', !open);
            shell.classList.toggle('grid-rows-[1fr]', open);
            shell.classList.toggle('opacity-0', !open);
            shell.classList.toggle('opacity-100', open);
            trigger.querySelector('[data-validation-caret]')?.classList.toggle('rotate-180', open);

            if (!open) {
                row.dataset.validationClosing = 'true';
                globalThis.setTimeout(() => {
                    if (
                        row.dataset.validationClosing === 'true' &&
                        trigger.getAttribute('aria-expanded') === 'false'
                    ) {
                        row.classList.add('hidden');
                    }
                }, validationDetailTransitionMs);
            }
        },

        escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        /* -----------------------------
         * Game Imports
         * --------------------------- */

        openGameImportDrawer() {
            this.gameImports.drawerOpen = true;
            this.gameImports.error = '';
        },

        closeGameImportDrawer() {
            this.gameImports.drawerOpen = false;
        },

        gameImportSeasonOptions() {
            return Array.isArray(this.gameImports.seasons) ? this.gameImports.seasons : [];
        },

        gameImportSelectedSeason() {
            return this.gameImportSeasonOptions()
                .find((season) => String(season.season) === String(this.gameImports.selectedSeason));
        },

        gameImportSeasonSyncButtonText() {
            const selected = this.gameImportSelectedSeason();

            return selected ? `Sync ${selected.label}` : 'Sync Season';
        },

        toggleGameImportSeasonDropdown() {
            this.gameImports.seasonDropdownOpen = !this.gameImports.seasonDropdownOpen;
        },

        closeGameImportSeasonDropdown() {
            this.gameImports.seasonDropdownOpen = false;
        },

        selectGameImportSeason(season) {
            this.gameImports.selectedSeason = season?.season ? String(season.season) : '';
            this.closeGameImportSeasonDropdown();
        },

        gameImportVisibleRuns() {
            return this.gameImports.runs.filter((run) => run.action !== 'season-sync');
        },

        gameImportLatestSeasonSyncRun() {
            const run = this.gameImports.runs.find((item) => item.action === 'season-sync') ?? null;

            if (!run || this.isGameImportSeasonSyncDismissed(run.id)) {
                return null;
            }

            return run;
        },

        isGameImportSeasonSyncDismissed(runId) {
            return this.gameImports.seasonSyncDismissedRunIds.includes(String(runId));
        },

        shouldShowGameImportSeasonSync() {
            return this.gameImportLatestSeasonSyncRun() !== null;
        },

        dismissGameImportSeasonSync() {
            const run = this.gameImportLatestSeasonSyncRun();

            if (run) {
                const dismissed = Array.from(new Set([
                    ...this.gameImports.seasonSyncDismissedRunIds,
                    String(run.id),
                ]));

                this.gameImports.seasonSyncDismissedRunIds = dismissed;
                writeDismissedSeasonSyncRunIds(dismissed);
            }
        },

        gameImportPayload() {
            return Object.entries(this.gameImports.form).reduce((payload, [key, value]) => {
                const normalized = typeof value === 'string' ? value.trim() : value;

                if (normalized !== '' && normalized !== null && normalized !== undefined) {
                    payload[key] = normalized;
                }

                return payload;
            }, {});
        },

        async loadGameImports(options = {}) {
            const background = Boolean(options.background);

            if (!background) {
                this.gameImports.loading = true;
            }

            this.gameImports.error = '';

            try {
                const response = await fetch(this.gameImportStatusUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Unable to load game imports');
                }

                this.gameImports.runs = payload.runs ?? [];
                this.syncCompletedGameFadeState();
                this.gameImports.seasons = payload.seasons ?? [];
                this.gameImports.processableDateCount = Number(payload.processable?.date_count) || 0;
                this.scheduleGameImportPollIfNeeded();
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to load game imports';
            } finally {
                if (!background) {
                    this.gameImports.loading = false;
                }
            }
        },

        async loadGameImportSourceGaps(options = {}) {
            const background = Boolean(options.background);

            if (!background) {
                this.gameImports.sourceGaps.loading = true;
            }

            try {
                const response = await fetch(this.gameImportSourceGapsUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Unable to load source gaps');
                }

                this.gameImports.sourceGaps.items = payload.gaps ?? [];
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to load source gaps';
            } finally {
                if (!background) {
                    this.gameImports.sourceGaps.loading = false;
                }
            }
        },

        async rerunGameImportSourceGap(gap) {
            const gameId = gap?.game_id;

            if (!gameId) {
                return;
            }

            this.gameImports.error = '';
            this.gameImports.sourceGaps.rerunning = {
                ...this.gameImports.sourceGaps.rerunning,
                [gameId]: true,
            };

            try {
                await this.sendGameImportRequest(`${this.gameImportSourceGapsUrl}/${gameId}/rerun`, {});
                await this.loadGameImportSourceGaps({ background: true });
                await this.loadGameImports({ background: true });
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to rerun source check';
            } finally {
                const next = { ...this.gameImports.sourceGaps.rerunning };
                delete next[gameId];
                this.gameImports.sourceGaps.rerunning = next;
            }
        },

        async rerunStoppedGameImport(game) {
            const gameId = game?.game_id;

            if (!gameId) {
                return;
            }

            this.gameImports.error = '';
            this.gameImports.rerunningGames = {
                ...this.gameImports.rerunningGames,
                [gameId]: true,
            };

            try {
                await this.sendGameImportRequest(`${this.gameImportGameRerunUrl}/${gameId}/rerun`, {});
                await this.loadGameImportSourceGaps({ background: true });
                await this.loadGameImports({ background: true });
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to rerun game import';
            } finally {
                const next = { ...this.gameImports.rerunningGames };
                delete next[gameId];
                this.gameImports.rerunningGames = next;
            }
        },

        async submitGameImportDiscover() {
            this.gameImports.discovering = true;
            this.gameImports.error = '';

            try {
                await this.sendGameImportRequest(
                    this.gameImportDiscoverUrl,
                    this.gameImportPayload()
                );

                this.gameImports.drawerOpen = false;
                await this.loadGameImports();
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to queue discovery';
            } finally {
                this.gameImports.discovering = false;
            }
        },

        async processGameImports(run = null) {
            this.gameImports.processing = true;
            this.gameImports.error = '';

            try {
                await this.sendGameImportRequest(
                    this.gameImportProcessUrl,
                    run ? this.gameImportRunPayload(run) : this.gameImportPayload()
                );

                await this.loadGameImports();
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to queue processing';
            } finally {
                this.gameImports.processing = false;
            }
        },

        async submitGameImportSeasonSync() {
            const selected = this.gameImportSelectedSeason();

            if (!selected) {
                this.gameImports.error = 'Choose a season before syncing.';
                return;
            }

            this.gameImports.syncingSeason = true;
            this.gameImports.error = '';

            try {
                await this.sendGameImportRequest(this.gameImportSeasonSyncUrl, {
                    season: selected.season,
                });

                await this.loadGameImports();
            } catch (error) {
                this.gameImports.error = error.message ?? 'Unable to queue season sync';
            } finally {
                this.gameImports.syncingSeason = false;
            }
        },

        gameImportRunPayload(run) {
            return {
                run_id: run.id,
                start: run.start_date,
                end: run.end_date,
            };
        },

        async sendGameImportRequest(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content'),
                },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(this.validationMessage(payload) ?? payload.message ?? 'Request failed');
            }

            return payload;
        },

        validationMessage(payload) {
            const errors = payload?.errors ?? {};
            const firstKey = Object.keys(errors)[0];

            if (!firstKey) {
                return null;
            }

            return errors[firstKey]?.[0] ?? null;
        },

        gameImportTitle(run) {
            return this.formatGameImportRange(run);
        },

        gameImportBadgeText(run) {
            if (run.processing_started) {
                return String(run.status ?? 'queued').toUpperCase();
            }

            if (run.action === 'discover') {
                return this.discoveryBadgeText(run);
            }

            return String(run.status ?? 'queued').toUpperCase();
        },

        gameImportBadgeClass(run) {
            if (run.processing_started) {
                return this.gameImportStatusClass(run.status);
            }

            if (run.action === 'discover') {
                return this.discoveryBadgeClass(run);
            }

            return this.gameImportStatusClass(run.status);
        },

        gameImportStatusClass(status) {
            if (status === 'completed') {
                return 'bg-green-100 text-green-700';
            }

            if (status === 'failed') {
                return 'bg-red-100 text-red-700';
            }

            if (status === 'running') {
                return 'bg-blue-100 text-blue-700';
            }

            return 'bg-gray-100 text-gray-700';
        },

        discoveryBadgeText(run) {
            const progress = run.progress ?? {};
            const facts = run.facts ?? {};
            const failed = Number(progress.failed_stage_rows) || 0;
            const totalRows = Number(facts.total_stage_rows) || Number(progress.total_stage_rows) || 0;

            if (failed > 0 || run.status === 'failed') {
                return 'FAILED';
            }

            return totalRows > 0 ? 'READY' : 'DISCOVERING';
        },

        discoveryBadgeClass(run) {
            const label = this.discoveryBadgeText(run);

            if (label === 'READY') {
                return 'bg-green-100 text-green-700';
            }

            if (label === 'FAILED') {
                return 'bg-red-100 text-red-700';
            }

            return 'bg-blue-100 text-blue-700';
        },

        formatGameImportRange(run) {
            if (run.start_date === run.end_date) {
                return this.formatGameImportDate(run.start_date);
            }

            return `${this.formatGameImportDate(run.end_date)} - ${this.formatGameImportDate(run.start_date)}`;
        },

        formatGameImportDate(value) {
            if (!value) {
                return 'No date';
            }

            const parts = String(value).split('-').map((part) => Number(part));
            const date = parts.length === 3
                ? new Date(parts[0], parts[1] - 1, parts[2])
                : this.parseDate(value);

            if (!date || Number.isNaN(date.getTime())) {
                return String(value);
            }

            return date.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
        },

        gameImportProgressPercentage(run) {
            const progress = run.progress ?? {};
            const percentage = Number(progress.percentage);

            if (Number.isFinite(percentage) && percentage > 0) {
                return Math.max(0, Math.min(100, percentage));
            }

            return run.status === 'queued' ? 0 : 8;
        },

        gameImportProgressText(run) {
            if (run.action === 'season-sync') {
                return this.gameImportSeasonSyncProgressText(run);
            }

            const progress = run.progress ?? {};
            const total = Number(progress.total_stage_rows) || 0;
            const completed = Number(progress.completed_stage_rows) || 0;
            const running = Number(progress.running_stage_rows) || 0;
            const skipped = Number(progress.skipped_stage_rows) || 0;
            const failed = Number(progress.failed_stage_rows) || 0;

            if (total === 0) {
                return 'Awaiting discovered pipeline rows';
            }

            return this.stageProgressText(completed, total, running, failed, skipped);
        },

        gameImportSeasonSyncProgressText(run) {
            const rows = Number(run.payload?.rows_upserted) || 0;

            if (run.status === 'completed') {
                return `${this.formatNumber(rows)} season stat rows synced`;
            }

            if (run.status === 'failed') {
                return 'Season sync failed';
            }

            if (run.status === 'running') {
                return 'Syncing season stats';
            }

            return 'Season sync queued';
        },

        gameImportSummaryText(run) {
            if (run.action === 'discover' && !run.processing_started) {
                return this.discoveryFactsText(run);
            }

            return this.gameImportProgressText(run);
        },

        isGameImportRunCompacted(run) {
            return run?.status === 'completed'
                && run?.processing_started === true
                && this.gameImportGames(run).length === 0;
        },

        gameImportCompactSummaryText(run) {
            const games = Array.isArray(run?.games) ? run.games : [];
            const facts = run?.facts ?? {};
            const total = Number(facts.discovered_game_count) || games.length || 0;
            const failed = games.filter((game) => Number(game?.failed_stage_rows) > 0).length;
            const skipped = games.filter((game) =>
                Number(game?.skipped_stage_rows) > 0 && Number(game?.failed_stage_rows) === 0
            ).length;
            const active = games.filter((game) =>
                this.gameImportGameProgressPercentage(game) < 100
                && Number(game?.failed_stage_rows) === 0
                && Number(game?.skipped_stage_rows) === 0
            ).length;

            return [
                `${this.formatNumber(total)} games`,
                `${this.formatNumber(active)} active`,
                `${this.formatNumber(skipped)} skipped`,
                `${this.formatNumber(failed)} failed`,
            ].join(' - ');
        },

        gameImportAccordionId(run) {
            return `game-import-run-${run.id}-games`;
        },

        isGameImportRunExpanded(run) {
            return Boolean(this.gameImports.expandedRuns?.[run.id]);
        },

        toggleGameImportRun(run) {
            this.gameImports.expandedRuns = {
                ...this.gameImports.expandedRuns,
                [run.id]: !this.isGameImportRunExpanded(run),
            };
        },

        sourceGapsAccordionId() {
            return 'game-import-source-gaps';
        },

        isGameImportSourceGapsExpanded() {
            return Boolean(this.gameImports.sourceGapsExpanded);
        },

        toggleGameImportSourceGaps() {
            this.gameImports.sourceGapsExpanded = !this.isGameImportSourceGapsExpanded();
        },

        gameImportSourceGapSummaryText(gap) {
            const count = Array.isArray(gap.sources) ? gap.sources.length : 0;

            return `${this.formatNumber(count)} ${count === 1 ? 'source' : 'sources'} missing`;
        },

        gameImportSourceGapGameIds() {
            const gaps = this.gameImports.sourceGaps?.items ?? [];

            return new Set(gaps.map((gap) => String(gap.game_id)));
        },

        isGameImportSourceGapGame(game) {
            return this.gameImportSourceGapGameIds().has(String(game?.game_id));
        },

        isDismissedCompletedGame(game) {
            return this.gameImports.dismissedCompletedGameIds.includes(String(game?.game_id));
        },

        gameImportGames(run) {
            const games = Array.isArray(run.games) ? run.games : [];

            return games.filter((game) => !this.isGameImportSourceGapGame(game) && !this.isDismissedCompletedGame(game));
        },

        syncCompletedGameFadeState() {
            const games = this.gameImports.runs.flatMap((run) => Array.isArray(run.games) ? run.games : []);
            const seenGameIds = new Set(games.map((game) => String(game?.game_id)).filter(Boolean));

            for (const [gameId, timer] of Object.entries(this.gameImports.completedGameFadeTimers)) {
                if (!seenGameIds.has(gameId)) {
                    clearInterval(timer);
                    this.removeCompletedGameFadeState(gameId);
                }
            }

            for (const game of games) {
                const gameId = String(game?.game_id ?? '');

                if (
                    gameId === ''
                    || this.isDismissedCompletedGame(game)
                    || this.gameImports.completedGameFadeTimers[gameId]
                    || !this.shouldFadeCompletedGame(game)
                ) {
                    continue;
                }

                this.startCompletedGameFade(gameId);
            }
        },

        shouldFadeCompletedGame(game) {
            return this.gameImportGameProgressPercentage(game) >= 100
                && Number(game.failed_stage_rows) === 0
                && Number(game.skipped_stage_rows) === 0
                && !this.isGameImportSourceGapGame(game);
        },

        startCompletedGameFade(gameId) {
            this.gameImports.completedGameFadeSteps = {
                ...this.gameImports.completedGameFadeSteps,
                [gameId]: 0,
            };

            const timer = setInterval(() => {
                this.advanceCompletedGameFade(gameId);
            }, 1000);

            this.gameImports.completedGameFadeTimers = {
                ...this.gameImports.completedGameFadeTimers,
                [gameId]: timer,
            };
        },

        advanceCompletedGameFade(gameId) {
            const currentStep = Number(this.gameImports.completedGameFadeSteps[gameId]) || 0;
            const nextStep = currentStep + 1;

            if (nextStep >= completedGameFadeClasses.length) {
                this.dismissCompletedGame(gameId);
                return;
            }

            this.gameImports.completedGameFadeSteps = {
                ...this.gameImports.completedGameFadeSteps,
                [gameId]: nextStep,
            };
        },

        dismissCompletedGame(gameId) {
            const normalizedGameId = String(gameId);
            const dismissed = new Set(this.gameImports.dismissedCompletedGameIds);
            dismissed.add(normalizedGameId);

            this.gameImports.dismissedCompletedGameIds = [...dismissed];
            writeDismissedCompletedGameIds(this.gameImports.dismissedCompletedGameIds);
            this.removeCompletedGameFadeState(normalizedGameId);
        },

        removeCompletedGameFadeState(gameId) {
            const normalizedGameId = String(gameId);
            const timer = this.gameImports.completedGameFadeTimers[normalizedGameId];

            if (timer) {
                clearInterval(timer);
            }

            const timers = { ...this.gameImports.completedGameFadeTimers };
            delete timers[normalizedGameId];
            this.gameImports.completedGameFadeTimers = timers;

            const steps = { ...this.gameImports.completedGameFadeSteps };
            delete steps[normalizedGameId];
            this.gameImports.completedGameFadeSteps = steps;
        },

        completedGameFadeClass(game) {
            const step = Number(this.gameImports.completedGameFadeSteps[String(game?.game_id)]) || 0;

            return completedGameFadeClasses[Math.min(step, completedGameFadeClasses.length - 1)];
        },

        gameImportGameLabel(game) {
            if (game.away_team_abbrev && game.home_team_abbrev) {
                return `${game.away_team_abbrev} vs ${game.home_team_abbrev}`;
            }

            return game.game_id ?? "";
        },

        gameImportGameMeta(game) {
            if (!game.away_team_abbrev || !game.home_team_abbrev) {
                return this.formatGameImportDate(game.game_date);
            }

            return [game.game_id, this.formatGameImportDate(game.game_date)].filter(Boolean).join(" · ");
        },

        gameImportGameProgressPercentage(game) {
            const percentage = Number(game.percentage);

            if (Number.isFinite(percentage)) {
                return Math.max(0, Math.min(100, percentage));
            }

            const total = Number(game.total_stage_rows) || 0;
            const completed = Number(game.completed_stage_rows) || 0;

            const skipped = Number(game.skipped_stage_rows) || 0;

            return total > 0 ? Math.floor(((completed + skipped) / total) * 100) : 0;
        },

        gameImportGameProgressClass(game) {
            if (Number(game.failed_stage_rows) > 0) {
                return 'bg-red-500';
            }

            if (Number(game.skipped_stage_rows) > 0) {
                return 'bg-yellow-400';
            }

            const percentage = this.gameImportGameProgressPercentage(game);

            if (percentage >= 100) {
                return this.completedGameFadeClass(game);
            }

            if (percentage > 0 || Number(game.running_stage_rows) > 0) {
                return 'bg-indigo-600';
            }

            return 'bg-yellow-400';
        },

        gameImportGameProgressText(game) {
            const total = Number(game.total_stage_rows) || 0;
            const completed = Number(game.completed_stage_rows) || 0;
            const running = Number(game.running_stage_rows) || 0;
            const skipped = Number(game.skipped_stage_rows) || 0;
            const failed = Number(game.failed_stage_rows) || 0;

            if (total === 0) {
                return 'Awaiting stages';
            }

            return this.stageProgressText(completed, total, running, failed, skipped);
        },

        canRerunStoppedGameImport(game) {
            return Number(game?.failed_stage_rows) > 0;
        },

        stageProgressText(completed, total, running, failed, skipped = 0) {
            const parts = [
                `${this.formatNumber(completed)} / ${this.formatNumber(total)} stages completed`,
                `${this.formatNumber(running)} active`,
            ];

            if (skipped > 0) {
                parts.push(`${this.formatNumber(skipped)} skipped`);
            }

            parts.push(`${this.formatNumber(failed)} failed`);

            return parts.join(' · ');
        },

        gameImportBlockedSources(game) {
            if (Array.isArray(game.blocked_sources)) {
                return game.blocked_sources;
            }

            if (!Array.isArray(game.source_statuses)) {
                return [];
            }

            return game.source_statuses.filter((source) => source.status && source.status !== 'available');
        },

        gameImportSourceStatusLabel(source) {
            const status = source?.status ?? 'unknown';
            const reason = source?.reason ? ` · ${source.reason}` : '';

            return `${source?.source ?? 'source'} ${status}${reason}`;
        },

        canProcessGameImportRun(run) {
            return run?.action === 'discover'
                && !run.processing_started
                && !this.gameImports.processing
                && Number(run?.facts?.scheduled_stage_rows || 0) > 0;
        },

        discoveryFactsText(run) {
            const facts = run.facts ?? {};
            const discoveredGames = Number(facts.discovered_game_count) || 0;
            const selectedDates = Number(facts.selected_date_count) || Number(run.date_count) || 0;
            const scheduledRows = Number(facts.scheduled_stage_rows) || 0;

            if (discoveredGames === 0) {
                return `Checking ${this.formatNumber(selectedDates)} selected dates`;
            }

            return `${this.formatNumber(discoveredGames)} games · ${this.formatNumber(scheduledRows)} stages scheduled`;
        },

        discoveryStatusText(run) {
            return this.discoveryBadgeText(run);
        },

        scheduleGameImportPollIfNeeded() {
            this.stopGameImportPoll();

            const hasActiveRun = this.gameImports.runs.some((run) =>
                ['queued', 'running'].includes(run.status)
            );

            if (!hasActiveRun || this.activeTab !== 'game-imports') {
                return;
            }

            this.gameImportPoller = setTimeout(() => {
                this.loadGameImports({ background: true });
            }, 5000);
        },

        stopGameImportPoll() {
            if (!this.gameImportPoller) {
                return;
            }

            clearTimeout(this.gameImportPoller);
            this.gameImportPoller = null;
        },

        /* -----------------------------
         * Players
         * --------------------------- */

        async loadPlayers() {
            const source = this.activeSource;
            const state = this.roster[source];

            if (
                (source === 'nhl' && !this.hasPlayers) ||
                (source === 'fantrax' && !this.hasFantrax)
            ) {
                state.items = [];
                state.total = 0;
                state.page = 1;
                state.lastPage = 1;

                return;
            }

            state.loading = true;

            try {
                const params = new URLSearchParams({
                    page: state.page,
                    per_page: state.perPage,
                    source,
                });

                const toggle = state.toggle;

                if (source === 'nhl') {
                    params.set('all_players', toggle ? '1' : '0');
                } else {
                    params.set('nhl_matched', toggle ? '1' : '0');
                }

                if (state.filter.trim()) {
                    params.set('search', state.filter.trim());
                }

                const response = await fetch(`/admin/api/players?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Failed to load players (${response.status})`);
                }

                const payload = await response.json();
                const meta = payload.meta ?? {};

                state.items = payload.data ?? [];
                state.total = meta.total ?? 0;
                state.page = meta.current_page ?? state.page;
                state.perPage = meta.per_page ?? state.perPage;
                state.lastPage =
                    meta.last_page ?? Math.max(1, Math.ceil(state.total / state.perPage));
            } catch (e) {
                console.error(e);
                state.items = [];
                state.total = 0;
                state.page = 1;
                state.lastPage = 1;
            } finally {
                state.loading = false;
            }
        },

        nextPage() {
            const state = this.roster[this.activeSource];

            if (state.page < state.lastPage) {
                state.page++;
                this.loadPlayers();
            }
        },

        previousPage() {
            const state = this.roster[this.activeSource];

            if (state.page > 1) {
                state.page--;
                this.loadPlayers();
            }
        },

        filterPlayers() {
            const state = this.roster[this.activeSource];
            state.page = 1;
            this.loadPlayers();
        },

        /* -----------------------------
         * Imports / Streams
         * --------------------------- */

        registerImportListeners() {
            if (!window.Echo || importListenersRegistered) {
                return;
            }

            importListenersRegistered = true;

            window.Echo
                .private('admin.imports')
                .listen('.admin.import.output', (payload) => {
                    const key = payload.source;
                    this.ensureStream(key);

                    const stream = this.streams[key];
                    stream.open = true;

                    if (payload.message?.trim()) {
                        stream.messages.unshift({
                            message: payload.message,
                            status: payload.status,
                            timestamp: payload.timestamp,
                        });
                    }

                    if (payload.status === 'started') {
                        stream.running = true;
                        this.scheduleImportProgressPoll(key);
                    }

                    if (payload.status === 'progress') {
                        stream.running = true;
                        this.refreshImportProgress(key);
                    }

                    if (payload.status === 'finished' || payload.status === 'failed') {
                        this.refreshImportProgress(key);
                    }
                })
                .listen('.players.available', (payload) => {
                    this.handlePlayersAvailable(payload?.source);
                })
                .listen('.admin.nhl-game-imports.updated', () => {
                    this.handleGameImportStatusUpdated();
                });
        },

        handleGameImportStatusUpdated() {
            if (this.activeTab !== 'game-imports') {
                return;
            }

            void this.loadGameImports({ background: true });
            void this.loadGameImportSourceGaps({ background: true });
        },

        ensureStream(key) {
            if (!this.streams[key]) {
                this.streams[key] = {
                    messages: [],
                    open: false,
                    running: false,
                    importRun: null,
                    progress: null,
                };
            }
        },

        initializeImportStreams() {
            this.imports.forEach((item) => {
                this.ensureStream(item.key);
                this.streams[item.key].progress = item.progress ?? null;
                this.streams[item.key].running = item.status === 'working';
                this.streams[item.key].importRun = {
                    status: item.status,
                    started_at: item.started_at,
                    finished_at: item.finished_at,
                    duration_seconds: item.duration_seconds,
                };

                if (this.streams[item.key].running) {
                    this.scheduleImportProgressPoll(item.key);
                }
            });
        },

        toggleStream(key) {
            this.ensureStream(key);
            this.streams[key].open = !this.streams[key].open;
        },

        async startImport(key) {
            const config = this.imports.find((i) => i.key === key);
            if (!config?.run_url) {
                return;
            }

            this.ensureStream(key);

            const stream = this.streams[key];
            stream.messages = [];
            stream.open = true;
            stream.running = true;
            stream.importRun = null;
            stream.progress = null;
            this.stopImportProgressPoll(key);

            try {
                const response = await fetch(config.run_url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content'),
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to start import');
                }

                const payload = await response.json();
                this.applyImportRun(key, payload.import_run);

                const imported = payload.import?.imported;
                if (imported !== undefined && imported !== null) {
                    stream.messages.unshift({
                        message: `${config.label ?? key} imported ${this.formatNumber(imported)} records`,
                        status: payload.import_run?.status ?? 'completed',
                        timestamp: new Date().toISOString(),
                    });
                }

                if (payload.import_run?.status === 'completed' || payload.import_run?.status === 'failed') {
                    stream.running = false;
                    this.stopImportProgressPoll(key);
                    return;
                }

                this.scheduleImportProgressPoll(key);
            } catch (e) {
                stream.messages.unshift({
                    message: e.message,
                    status: 'failed',
                    timestamp: new Date().toISOString(),
                });

                stream.running = false;
            }
        },

        async refreshImportProgress(key, shouldContinue = true) {
            const config = this.imports.find((i) => i.key === key);
            if (!config?.status_url) {
                return;
            }

            try {
                const response = await fetch(config.status_url, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Failed to refresh import progress');
                }

                const payload = await response.json();
                const importRun = payload.import_run ?? null;
                this.applyImportRun(key, importRun);

                if (importRun?.status === 'working') {
                    this.scheduleImportProgressPoll(key);
                    return;
                }

                if (importRun?.status === 'completed' || importRun?.status === 'failed') {
                    this.streams[key].running = false;
                    this.stopImportProgressPoll(key);
                    return;
                }

                if (shouldContinue && this.streams[key]?.running) {
                    this.scheduleImportProgressPoll(key);
                }
            } catch (e) {
                console.error(e);

                if (shouldContinue && this.streams[key]?.running) {
                    this.scheduleImportProgressPoll(key, 5000);
                }
            }
        },

        scheduleImportProgressPoll(key, delay = 2000) {
            this.stopImportProgressPoll(key);

            this.progressPollers[key] = setTimeout(() => {
                this.refreshImportProgress(key);
            }, delay);
        },

        stopImportProgressPoll(key) {
            if (!this.progressPollers[key]) {
                return;
            }

            clearTimeout(this.progressPollers[key]);
            delete this.progressPollers[key];
        },

        applyImportRun(key, importRun) {
            if (!importRun) {
                return;
            }

            this.ensureStream(key);

            const progress = importRun.progress ?? null;
            this.streams[key].importRun = importRun;
            this.streams[key].progress = progress;
            this.streams[key].running = importRun.status === 'working';

            this.imports = this.imports.map((item) =>
                item.key === key
                    ? {
                          ...item,
                          status: importRun.status,
                          duration_seconds: importRun.duration_seconds,
                          started_at: importRun.started_at,
                          finished_at: importRun.finished_at,
                          last_run:
                              importRun.finished_at ??
                              importRun.started_at ??
                              item.last_run,
                          progress,
                      }
                    : item
            );
        },

        importProgress(key) {
            return this.streams[key]?.progress ?? null;
        },

        shouldShowImportProgress(key) {
            const progress = this.importProgress(key);

            return Boolean(progress) || this.streams[key]?.running === true;
        },

        importProgressPercentage(key) {
            const progress = this.importProgress(key);

            if (progress?.dynamic_total) {
                const status = this.streams[key]?.importRun?.status
                    ?? this.imports.find((importConfig) => importConfig.key === key)?.status;
                const processed = Number(progress.processed_records) || 0;
                const estimate = this.importProgressEstimatedTotal(key);

                if (status === 'completed') {
                    return 100;
                }

                if (processed <= 0 || estimate <= 0) {
                    return this.streams[key]?.running ? 8 : 0;
                }

                return Math.min(92, Math.max(8, Math.floor((processed / estimate) * 100)));
            }

            if (progress?.percentage === null || progress?.percentage === undefined) {
                return this.streams[key]?.running ? 8 : 0;
            }

            return Math.max(0, Math.min(100, Number(progress.percentage) || 0));
        },

        importProgressText(key) {
            const progress = this.importProgress(key);
            const processed = progress?.processed_records ?? 0;
            const total = progress?.total_records;

            if (progress?.dynamic_total) {
                const status = this.streams[key]?.importRun?.status
                    ?? this.imports.find((importConfig) => importConfig.key === key)?.status;
                const estimate = this.importProgressEstimatedTotal(key);

                if (status === 'completed' && total) {
                    return `${this.formatNumber(processed)} / ${this.formatNumber(total)} records`;
                }

                if (estimate) {
                    return `${this.formatNumber(processed)} / ~${this.formatNumber(estimate)} records`;
                }

                return `${this.formatNumber(processed)} records processed`;
            }

            if (total) {
                return `${this.formatNumber(processed)} / ~${this.formatNumber(total)} records`;
            }

            return `${this.formatNumber(processed)} records processed`;
        },

        importProgressEstimatedTotal(key) {
            const progress = this.importProgress(key);
            const discovered = Number(progress?.total_records) || 0;
            const processed = Number(progress?.processed_records) || 0;
            const status = this.streams[key]?.importRun?.status
                ?? this.imports.find((importConfig) => importConfig.key === key)?.status;

            if (status === 'completed') {
                return Math.max(discovered, processed);
            }

            if (key === 'nhl') {
                return Math.max(processed, 2650);
            }

            return processed;
        },

        importProgressDetailText(key) {
            const progress = this.importProgress(key);

            if (!progress) {
                return 'Preparing import...';
            }

            const successful = progress.successful_records ?? 0;
            const failed = progress.failed_records ?? 0;
            const skipped = progress.skipped_records ?? 0;
            const elapsed = this.importElapsedText(key);
            const elapsedText = elapsed ? ` · elapsed ${elapsed}` : '';

            return `${this.formatNumber(successful)} imported, ${this.formatNumber(failed)} failed, ${this.formatNumber(skipped)} skipped${elapsedText}`;
        },

        formatNumber(value) {
            return new Intl.NumberFormat().format(Number(value) || 0);
        },

        activitySummaryItems() {
            const summary = this.activity?.summary ?? {};

            return [
                { key: 'events_24h', label: 'Events 24h', value: summary.events_24h },
                { key: 'events_7d', label: 'Events 7d', value: summary.events_7d },
                { key: 'visitors_24h', label: 'Visitors 24h', value: summary.visitors_24h },
                { key: 'sessions_24h', label: 'Sessions 24h', value: summary.sessions_24h },
                { key: 'anonymous_visitors', label: 'Anonymous', value: summary.anonymous_visitors },
                { key: 'linked_visitors', label: 'Linked', value: summary.linked_visitors },
            ];
        },

        activityEventMix() {
            return Object.entries(this.activity?.events_by_name ?? {})
                .map(([name, count]) => ({ name, count: Number(count) || 0 }));
        },

        activityActor(item) {
            if (item?.user?.name) {
                return item.user.name;
            }

            if (item?.user?.email) {
                return item.user.email;
            }

            return 'Anonymous visitor';
        },

        formatDuration(seconds) {
            const total = Number(seconds) || 0;
            const minutes = Math.floor(total / 60);
            const remainder = total % 60;

            if (minutes <= 0) {
                return `${remainder}s`;
            }

            return `${minutes}m ${remainder}s`;
        },

        formatDateTime(value) {
            const date = this.parseDate(value);

            if (!date) {
                return 'Never';
            }

            return date.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            });
        },

        userInitials(user) {
            const source = String(user?.name || user?.email || '').trim();

            if (!source) {
                return 'U';
            }

            const parts = source.split(/\s+/).filter(Boolean);
            const initials = parts.length > 1
                ? `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`
                : source.slice(0, 2);

            return initials.toUpperCase();
        },

        presenceClass(user) {
            return {
                online: 'bg-green-50 text-green-700 ring-1 ring-green-600/20',
                recent: 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20',
                offline: 'bg-gray-50 text-gray-600 ring-1 ring-gray-200',
            }[user?.presence?.state] ?? 'bg-gray-50 text-gray-600 ring-1 ring-gray-200';
        },

        presenceDotClass(user) {
            return {
                online: 'bg-green-500',
                recent: 'bg-amber-500',
                offline: 'bg-gray-300',
            }[user?.presence?.state] ?? 'bg-gray-300';
        },

        formatUserLastSeen(user) {
            const date = this.parseDate(user?.last_seen_at);

            if (!date) {
                return 'Last online never';
            }

            return `Last online ${date.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            })}`;
        },

        formatLastRun(key) {
            const item = this.imports.find((importConfig) => importConfig.key === key);
            const date = this.parseDate(item?.last_run);

            if (!date) {
                return 'N/A';
            }

            return this.formatSocialDate(date);
        },

        importElapsedText(key) {
            const importRun = this.streams[key]?.importRun
                ?? this.imports.find((importConfig) => importConfig.key === key);

            if (!importRun) {
                return null;
            }

            const explicitDuration = Number(importRun.duration_seconds);
            if (explicitDuration > 0) {
                return this.formatDuration(explicitDuration);
            }

            const startedAt = this.parseDate(importRun.started_at);
            if (!startedAt) {
                return null;
            }

            return this.formatDuration((Date.now() - startedAt.getTime()) / 1000);
        },

        parseDate(value) {
            if (!value) {
                return null;
            }

            const normalized = typeof value === 'string' && value.includes(' ') && !value.includes('T')
                ? value.replace(' ', 'T')
                : value;
            const date = new Date(normalized);

            return Number.isNaN(date.getTime()) ? null : date;
        },

        formatSocialDate(date) {
            const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));

            if (seconds < 60) {
                return 'just now';
            }

            if (seconds < 3600) {
                return `${Math.floor(seconds / 60)}m ago`;
            }

            if (seconds < 86400) {
                return `${Math.floor(seconds / 3600)}h ago`;
            }

            if (seconds < 172800) {
                return `yesterday at ${this.formatClockTime(date)}`;
            }

            return `${date.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            })} at ${this.formatClockTime(date)}`;
        },

        formatClockTime(date) {
            return date.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
            });
        },

        formatDuration(value) {
            const totalSeconds = Math.max(0, Math.floor(Number(value) || 0));
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            if (hours > 0) {
                return `${hours}h ${String(minutes).padStart(2, '0')}m`;
            }

            if (minutes > 0) {
                return `${minutes}m ${String(seconds).padStart(2, '0')}s`;
            }

            return `${seconds}s`;
        },

        refreshImportMeta(key) {
            const importRun = this.streams[key]?.importRun;
            const lastRun = importRun?.finished_at ?? importRun?.started_at ?? null;

            this.imports = this.imports.map((item) =>
                item.key === key && lastRun ? { ...item, last_run: lastRun } : item
            );
        },

        handlePlayersAvailable(source) {
            if (!source) {
                return;
            }

            const wasNhlAvailable = this.hasPlayers;
            const wasFantraxAvailable = this.hasFantrax;

            if (source === 'nhl') {
                this.hasPlayers = true;
            }

            if (source === 'fantrax') {
                this.hasFantrax = true;
            }

            const tabUnavailable =
                (this.activeTab === 'nhl' && !this.hasPlayers) ||
                (this.activeTab === 'fantrax' && !this.hasFantrax);

            if (tabUnavailable) {
                this.activeTab = 'triage';
                this.activeSource = source;
                this.roster[source].page = 1;
            }

            const isPlayersTab = this.activeTab === 'nhl' || this.activeTab === 'fantrax';

            if (
                isPlayersTab &&
                ((source === 'nhl' && !wasNhlAvailable) ||
                    (source === 'fantrax' && !wasFantraxAvailable) ||
                    tabUnavailable)
            ) {
                if (this.activeSource !== source) {
                    this.activeSource = source;
                    this.roster[source].page = 1;
                }

                this.loadPlayers();
            }
        },
    };
}
