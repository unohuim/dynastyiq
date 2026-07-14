const statusTone = {
    conflict: 'bg-red-50 text-red-700 ring-red-200',
    candidate: 'bg-yellow-50 text-yellow-800 ring-yellow-200',
    unmatched: 'bg-gray-100 text-gray-700 ring-gray-200',
    matched: 'bg-green-50 text-green-700 ring-green-200',
    ignored: 'bg-gray-50 text-gray-500 ring-gray-200',
};

const text = (value, fallback = 'N/A') => {
    if (typeof value !== 'string') return fallback;

    const trimmed = value.trim();

    return trimmed === '' ? fallback : trimmed;
};

const title = (value) => text(value, '').replace(/^./, (char) => char.toUpperCase());

const searchableText = (identity) => [
    identity.display_name,
    identity.provider,
    identity.provider_player_id,
    identity.provider_slug,
    identity.team,
    identity.position,
    identity.match_status,
    identity.unmatched_reason,
].filter(Boolean).join(' ').toLowerCase();

const appendMeta = (container, value) => {
    if (value === null || value === undefined || value === '') return;

    const span = document.createElement('span');
    span.textContent = value;
    container.append(span);
};

/**
 * Browser-owned renderer for the player triage inbox.
 */
export const createPlayerTriageInbox = (root, options = {}) => {
    const payloadScript = options.payloadScript ?? root.querySelector('[data-player-triage-inbox-payload]');
    const mount = options.mount ?? root.querySelector('[data-player-triage-inbox]');
    const count = options.count ?? root.querySelector('[data-player-triage-inbox-count]');
    let identities = [];
    let filtered = [];
    let selectedIdentityId = null;
    let loadedCount = 0;
    let totalCount = 0;
    let search = '';
    let isLoading = false;
    let errorMessage = null;

    const applyFilter = () => {
        filtered = search === ''
            ? identities
            : identities.filter((identity) => searchableText(identity).includes(search));
    };

    if (!mount) {
        return null;
    }

    const load = (payload) => {
        identities = Array.isArray(payload?.identities) ? payload.identities : [];
        loadedCount = Number(payload?.meta?.loaded_count ?? payload?.meta?.count ?? identities.length);
        totalCount = Number(payload?.meta?.total_count ?? loadedCount);
        selectedIdentityId = payload?.meta?.selected_identity_id ?? identities.find((identity) => identity.selected)?.id ?? null;
        isLoading = false;
        errorMessage = null;
        applyFilter();
        render();
    };

    const filter = (value = '') => {
        search = value.trim().toLowerCase();
        isLoading = false;
        errorMessage = null;
        applyFilter();
        render();
    };

    const countLabel = () => {
        if (search !== '') {
            return `${filtered.length} of ${loadedCount} loaded`;
        }

        if (totalCount > loadedCount) {
            return `${loadedCount} of ${totalCount}`;
        }

        return String(filtered.length);
    };

    const loading = () => {
        isLoading = true;
        errorMessage = null;
        render();
    };

    const error = (message = 'Unable to load player inbox.') => {
        isLoading = false;
        errorMessage = message;
        render();
    };

    const badge = (identity) => {
        const span = document.createElement('span');
        const usesCoverage = Boolean(identity.coverage?.active);
        const tone = usesCoverage
            ? (identity.coverage?.matched ? 'bg-green-50 text-green-700 ring-green-200' : 'bg-red-50 text-red-700 ring-red-200')
            : (statusTone[identity.match_status] ?? 'bg-gray-50 text-gray-600 ring-gray-200');
        span.className = `shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${tone}`;
        span.textContent = usesCoverage ? identity.coverage?.label : identity.match_status;

        return span;
    };

    const row = (identity) => {
        const anchor = document.createElement('a');
        const selected = selectedIdentityId === identity.id;
        anchor.href = identity.href;
        anchor.className = `block px-4 py-3 ${selected ? 'bg-indigo-50' : 'bg-white hover:bg-gray-50'}`;
        anchor.dataset.playerTriageRow = '';
        anchor.addEventListener('click', (event) => {
            event.preventDefault();
            selectedIdentityId = identity.id;
            render();
            root.dispatchEvent(new CustomEvent('player-triage:identity-selected', {
                bubbles: true,
                detail: {
                    identityId: identity.id,
                    detailUrl: identity.detail_url,
                    href: identity.href,
                    identity,
                },
            }));
        });

        const header = document.createElement('div');
        header.className = 'flex items-start justify-between gap-3';

        const body = document.createElement('div');
        body.className = 'min-w-0';

        const name = document.createElement('div');
        name.className = 'truncate text-sm font-semibold text-gray-900';
        name.textContent = text(identity.display_name, 'Unnamed identity');
        body.append(name);

        const meta = document.createElement('div');
        meta.className = 'mt-1 flex flex-wrap gap-2 text-xs text-gray-500';
        appendMeta(meta, title(identity.provider));
        appendMeta(meta, text(identity.position, 'No position'));
        appendMeta(meta, text(identity.team, 'No team'));
        body.append(meta);

        header.append(body, badge(identity));
        anchor.append(header);

        const detail = document.createElement('div');
        detail.className = 'mt-2 flex flex-wrap gap-2 text-xs text-gray-500';
        appendMeta(detail, `ID ${text(identity.provider_player_id)}`);

        if (identity.coverage?.active) {
            appendMeta(
                detail,
                `${title(identity.match_status)} ${identity.match_confidence !== null ? `at ${identity.match_confidence}%` : 'source identity'}`
            );
        } else {
            if (identity.recommendation?.confidence !== null && identity.recommendation?.confidence !== undefined) {
                appendMeta(detail, `${identity.recommendation.confidence}% recommendation`);
            }

            if (identity.recommendation?.status && identity.recommendation.status !== identity.match_status) {
                appendMeta(detail, `recommends ${identity.recommendation.status}`);
            }
        }

        if (identity.unmatched_reason) {
            appendMeta(detail, identity.unmatched_reason.replaceAll('_', ' '));
        }

        anchor.append(detail);

        return anchor;
    };

    const render = () => {
        mount.replaceChildren();

        if (count) {
            count.textContent = countLabel();
        }

        if (isLoading) {
            const wrapper = document.createElement('div');
            wrapper.className = 'divide-y divide-gray-100';

            const progress = document.createElement('div');
            progress.className = 'h-0.5 bg-gray-100';
            progress.dataset.playerTriageInboxProgress = '';

            const bar = document.createElement('div');
            bar.className = 'h-full w-0 bg-indigo-500 opacity-70 transition-[width] duration-[5000ms] ease-out';
            bar.dataset.playerTriageInboxProgressBar = '';
            progress.append(bar);
            window.requestAnimationFrame(() => {
                bar.classList.remove('w-0');
                bar.classList.add('w-11/12');
            });
            wrapper.append(progress);

            for (let index = 0; index < 8; index += 1) {
                const item = document.createElement('div');
                item.className = 'px-4 py-3';

                const pulse = document.createElement('div');
                pulse.className = 'animate-pulse';

                const header = document.createElement('div');
                header.className = 'flex items-start justify-between gap-3';

                const body = document.createElement('div');
                body.className = 'min-w-0 flex-1';

                const name = document.createElement('div');
                name.className = 'h-4 w-36 rounded bg-gray-200';
                const meta = document.createElement('div');
                meta.className = 'mt-2 h-3 w-48 max-w-full rounded bg-gray-100';
                body.append(name, meta);

                const badgeBlock = document.createElement('div');
                badgeBlock.className = 'h-5 w-16 rounded-full bg-gray-100';
                header.append(body, badgeBlock);

                const detailBlock = document.createElement('div');
                detailBlock.className = 'mt-3 h-3 w-56 max-w-full rounded bg-gray-100';

                pulse.append(header, detailBlock);
                item.append(pulse);
                wrapper.append(item);
            }

            mount.append(wrapper);

            return;
        }

        if (errorMessage) {
            const wrapper = document.createElement('div');
            wrapper.className = 'px-4 py-8';

            const message = document.createElement('div');
            message.className = 'border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700';
            message.textContent = errorMessage;

            wrapper.append(message);
            mount.append(wrapper);

            return;
        }

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'px-4 py-10 text-sm text-gray-600';
            empty.textContent = search === ''
                ? 'No identities match the current filters.'
                : 'No loaded identities match this search.';
            mount.append(empty);

            return;
        }

        const list = document.createElement('div');
        list.className = 'divide-y divide-gray-100';
        filtered.forEach((identity) => list.append(row(identity)));
        mount.append(list);
    };

    root.addEventListener('player-triage:inbox-loading', () => loading());
    root.addEventListener('player-triage:inbox-loaded', (event) => load(event.detail));
    root.addEventListener('player-triage:inbox-error', (event) => error(event.detail?.message));
    root.addEventListener('player-triage:player-searched', (event) => filter(event.detail?.value ?? ''));

    if (payloadScript?.textContent?.trim()) {
        load(JSON.parse(payloadScript.textContent));
    } else {
        render();
    }

    return {
        load,
        filter,
        loading,
        error,
        render,
        get identities() {
            return identities;
        },
        get filteredIdentities() {
            return filtered;
        },
    };
};

export default createPlayerTriageInbox;
