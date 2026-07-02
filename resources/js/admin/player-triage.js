import { mountSearchFields } from '../components/SearchField/search-field.js';
import { mountSelectFields } from '../components/SelectField/select-field.js';
import { createPlayerTriageDetail } from './player-triage-detail.js';
import { createPlayerTriageInbox } from './player-triage-inbox.js';

export { SearchField, mountSearchFields } from '../components/SearchField/search-field.js';
export { SelectField, mountSelectFields } from '../components/SelectField/select-field.js';

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const showToast = (message, type = 'success') => {
    if (!message) return;

    if (window.toast?.show) {
        window.toast.show(message, { type });
        return;
    }

    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
};

const formParams = (form) => {
    const params = new URLSearchParams();

    new FormData(form).forEach((value, key) => {
        const text = String(value).trim();
        if (text !== '') params.append(key, text);
    });

    return params;
};

const mergeParams = (url, updates) => {
    const next = new URL(url.toString());

    updates.forEach((value, key) => {
        next.searchParams.delete(key);

        if (String(value).trim() !== '') {
            next.searchParams.append(key, value);
        }
    });

    return next;
};

/**
 * Page-local controller for the admin player triage surface.
 */
export const createPlayerTriagePage = (initialRoot, dependencies = {}) => {
    let root = initialRoot;
    let requestId = 0;
    let detailRequestId = 0;
    let controller = null;
    let detailController = null;
    const fetcher = dependencies.fetcher ?? window.fetch.bind(window);
    const historyApi = dependencies.history ?? window.history;
    const locationRef = dependencies.location ?? window.location;

    const currentUrl = () => new URL(root.dataset.playerTriageUrl || locationRef.href, locationRef.origin);
    const visibleBaseUrl = () => new URL(root.dataset.playerTriageHistoryUrl || locationRef.href, locationRef.origin);

    const historyUrlFor = (url) => {
        if (root.dataset.playerTriageEmbedded !== '1') {
            return url;
        }

        const visibleUrl = visibleBaseUrl();
        visibleUrl.search = '';

        url.searchParams.forEach((value, key) => {
            if (key !== 'admin_panel') {
                visibleUrl.searchParams.append(key, value);
            }
        });

        return visibleUrl;
    };

    const focusSnapshot = () => {
        const input = root.contains(document.activeElement) && document.activeElement.matches('input, textarea')
            ? document.activeElement
            : null;

        if (!input) return null;

        const searchField = input.closest('[data-search-field]');

        if (!searchField) return null;

        return {
            name: input.name,
            scope: searchField.getAttribute('data-search-field-scope') || null,
            id: searchField.getAttribute('data-search-field-id') || input.id || null,
            start: input.selectionStart,
            end: input.selectionEnd,
            direction: input.selectionDirection,
        };
    };

    const restoreFocus = (snapshot) => {
        if (!snapshot) return;

        const fields = Array.from(root.querySelectorAll('[data-search-field]'));
        const field = fields.find((candidate) => {
            const input = candidate.querySelector('input, textarea');

            return input?.name === snapshot.name
                && (candidate.getAttribute('data-search-field-scope') || null) === snapshot.scope
                && (candidate.getAttribute('data-search-field-id') || input?.id || null) === snapshot.id;
        });
        const input = field?.querySelector('input, textarea');

        if (!input) return;

        input.focus({ preventScroll: true });

        if (
            snapshot.start !== null
            && snapshot.end !== null
            && typeof input.setSelectionRange === 'function'
        ) {
            input.setSelectionRange(snapshot.start, snapshot.end, snapshot.direction ?? 'none');
        }
    };

    const restoreSearchFieldFocus = (searchField, snapshot) => {
        if (!snapshot) return;

        const input = searchField?.querySelector('input, textarea');

        if (!input) return;

        input.focus({ preventScroll: true });

        if (
            snapshot.start !== null
            && snapshot.end !== null
            && typeof input.setSelectionRange === 'function'
        ) {
            input.setSelectionRange(snapshot.start, snapshot.end, snapshot.direction ?? 'none');
        }
    };

    const replaceRoot = (html, restore = null) => {
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const nextRoot = template.content.querySelector('[data-player-triage-page]');

        if (!nextRoot) {
            throw new Error('Triage response did not include a triage page fragment');
        }

        root.replaceWith(nextRoot);
        root = nextRoot;
        bind();
        restoreFocus(restore);
    };

    const load = async (url, options = {}) => {
        requestId += 1;
        const id = requestId;
        const restore = focusSnapshot();

        if (controller) controller.abort();
        controller = new AbortController();

        try {
            const requestUrl = new URL(url.toString());
            if (root.dataset.playerTriageEmbedded === '1') {
                requestUrl.searchParams.set('admin_panel', '1');
            }

            root.dispatchEvent(new CustomEvent('player-triage:inbox-loading', {
                bubbles: true,
                detail: { url: requestUrl.toString() },
            }));

            const response = await fetcher(requestUrl, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (id !== requestId) return null;

            if (!response.ok) {
                throw new Error(payload.message ?? 'Unable to load triage data');
            }

            if (payload.inbox) {
                root.dispatchEvent(new CustomEvent('player-triage:inbox-loaded', {
                    bubbles: true,
                    detail: payload.inbox,
                }));
            }

            if (payload.detail) {
                root.dispatchEvent(new CustomEvent('player-triage:detail-loaded', {
                    bubbles: true,
                    detail: payload.detail,
                }));
                mountSearchFields(root);
                mountSelectFields(root);
            } else if (payload.html) {
                replaceRoot(payload.html, restore);
            }

            if (options.history !== false) {
                historyApi.pushState({ playerTriage: true }, '', options.historyUrl ?? historyUrlFor(requestUrl));
            }

            return payload;
        } catch (error) {
            if (error.name !== 'AbortError') {
                root.dispatchEvent(new CustomEvent('player-triage:inbox-error', {
                    bubbles: true,
                    detail: { message: error.message ?? 'Unable to load player inbox.' },
                }));
                showToast(error.message ?? 'Unable to load triage data', 'error');
            }

            return null;
        } finally {
            if (id === requestId) controller = null;
        }
    };

    const loadDetail = async (url, options = {}) => {
        detailRequestId += 1;
        const id = detailRequestId;

        if (detailController) detailController.abort();
        detailController = new AbortController();

        const requestUrl = new URL(url.toString());
        if (root.dataset.playerTriageEmbedded === '1') {
            requestUrl.searchParams.set('admin_panel', '1');
        }

        root.dispatchEvent(new CustomEvent('player-triage:detail-loading', {
            bubbles: true,
            detail: { url: requestUrl.toString() },
        }));
        try {
            const response = await fetcher(requestUrl, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: detailController.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (id !== detailRequestId) return null;

            if (!response.ok) {
                throw new Error(payload.message ?? 'Unable to load player detail');
            }

            if (payload.detail) {
                root.dispatchEvent(new CustomEvent('player-triage:detail-loaded', {
                    bubbles: true,
                    detail: payload.detail,
                }));
                mountSearchFields(root);
                mountSelectFields(root);
            }

            if (options.history !== false) {
                historyApi.pushState({ playerTriage: true }, '', options.historyUrl ?? historyUrlFor(requestUrl));
            }

            return payload;
        } catch (error) {
            if (error.name !== 'AbortError') {
                root.dispatchEvent(new CustomEvent('player-triage:detail-error', {
                    bubbles: true,
                    detail: { message: error.message ?? 'Unable to load player detail' },
                }));
            }

            return null;
        } finally {
            if (id === detailRequestId) detailController = null;
        }
    };

    const submitAction = async (form) => {
        const button = form.querySelector('button[type="submit"]');
        const originalText = button?.textContent;
        const actionUrl = new URL(form.action);

        new URL(locationRef.href).searchParams.forEach((value, key) => {
            if (!actionUrl.searchParams.has(key)) {
                actionUrl.searchParams.append(key, value);
            }
        });

        if (root.dataset.playerTriageEmbedded === '1') {
            actionUrl.searchParams.set('admin_panel', '1');
        }

        if (button) {
            button.disabled = true;
            button.textContent = form.dataset.playerTriageActionLabel || 'Working...';
        }

        try {
            const response = await fetcher(actionUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: new FormData(form),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message ?? 'Unable to update identity');
            }

            if (payload.inbox) {
                root.dispatchEvent(new CustomEvent('player-triage:inbox-loaded', {
                    bubbles: true,
                    detail: payload.inbox,
                }));
            }

            if (payload.detail) {
                root.dispatchEvent(new CustomEvent('player-triage:detail-loaded', {
                    bubbles: true,
                    detail: payload.detail,
                }));
                mountSearchFields(root);
                mountSelectFields(root);
            } else if (payload.html) {
                replaceRoot(payload.html, focusSnapshot());
            }

            showToast(payload.message ?? 'Identity updated');

            return payload;
        } catch (error) {
            showToast(error.message ?? 'Unable to update identity', 'error');

            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }

            return null;
        }
    };

    const bind = () => {
        mountSearchFields(root);
        mountSelectFields(root);
        createPlayerTriageInbox(root);
        createPlayerTriageDetail(root);
        mountSearchFields(root);
        mountSelectFields(root);

        root.querySelectorAll('[data-player-triage-filter-form]').forEach((form) => {
            form.querySelectorAll('select, input[type="checkbox"], input[type="radio"]').forEach((field) => {
                if (field.matches('select') && field.closest('[data-select-field]')) return;

                field.addEventListener('change', () => load(mergeParams(currentUrl(), formParams(form))));
            });
        });

        root.addEventListener('submit', (event) => {
            const actionForm = event.target.closest('[data-player-triage-action-form]');
            if (actionForm && root.contains(actionForm)) {
                event.preventDefault();
                submitAction(actionForm);

                return;
            }

            const filterForm = event.target.closest('[data-player-triage-filter-form]');
            if (filterForm && root.contains(filterForm)) {
                event.preventDefault();
                load(mergeParams(currentUrl(), formParams(filterForm)));
            }
        });

        root.addEventListener('player-triage:identity-selected', (event) => {
            if (!event.detail?.href) return;

            if (event.detail.identity) {
                root.dispatchEvent(new CustomEvent('player-triage:detail-preview', {
                    bubbles: true,
                    detail: { identity: event.detail.identity },
                }));
            }

            loadDetail(new URL(event.detail.detailUrl ?? event.detail.href), {
                historyUrl: historyUrlFor(new URL(event.detail.href)),
            });
        });

        root.addEventListener('search-field:change', (event) => {
            if (event.detail.name === 'search') {
                root.dispatchEvent(new CustomEvent('player-triage:player-searched', {
                    bubbles: true,
                    detail: { value: event.detail.value },
                }));
                restoreSearchFieldFocus(event.target, event.detail.focus);

                const url = mergeParams(visibleBaseUrl(), new URLSearchParams({ search: event.detail.value }));
                url.searchParams.delete('identity');
                historyApi.replaceState?.({ playerTriage: true }, '', url);

                return;
            }

            const form = event.target.closest('form');
            const params = form ? formParams(form) : new URLSearchParams();
            params.set(event.detail.name, event.detail.value);

            const selected = root.querySelector('[name="identity"]')?.value;
            if (selected) params.set('identity', selected);

            load(mergeParams(currentUrl(), params));
        });

        root.addEventListener('select-field:change', (event) => {
            const form = event.target.closest('form');
            const params = form ? formParams(form) : new URLSearchParams();
            params.set(event.detail.name, event.detail.value);

            load(mergeParams(currentUrl(), params));
        });
    };

    const handlePopState = () => {
        const visibleUrl = new URL(locationRef.href);
        const url = mergeParams(currentUrl(), visibleUrl.searchParams);

        load(url, { history: false });
    };

    bind();
    window.addEventListener('popstate', handlePopState);

    return {
        load,
        loadDetail,
        submitAction,
        destroy() {
            if (controller) controller.abort();
            if (detailController) detailController.abort();
            window.removeEventListener('popstate', handlePopState);
        },
        get root() {
            return root;
        },
    };
};

export const bootPlayerTriage = () => {
    document.querySelectorAll('[data-player-triage-page]').forEach((root) => {
        if (root.dataset.playerTriageMounted === '1') return;

        root.dataset.playerTriageMounted = '1';
        createPlayerTriagePage(root);
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPlayerTriage, { once: true });
} else {
    bootPlayerTriage();
}
