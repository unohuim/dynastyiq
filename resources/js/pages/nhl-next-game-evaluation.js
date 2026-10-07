/** Page-local progress polling and mutations, without a full-page refresh. */
export function mount(root) {
    const content = root.querySelector('[data-evaluation-content]');
    const error = root.querySelector('[data-evaluation-error]');
    let url = new URL(window.location.href);
    let timer;
    let controller;
    let stopped = false;
    let mutating = false;
    const makeSortable = () => {
        content.querySelectorAll('table').forEach((table) => {
            const headers = [...table.querySelectorAll('thead th')];
            const isPlayers = headers[0]?.textContent === 'Player';
            headers.forEach((header, column) => {
                const label = header.textContent;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'text-left font-semibold underline decoration-dotted underline-offset-4';
                button.textContent = `${label} ↕`;
                header.replaceChildren(button);
                button.addEventListener('click', () => {
                    const ascending = header.getAttribute('aria-sort') !== 'ascending';
                    headers.forEach((item) => item.removeAttribute('aria-sort'));
                    header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                    if (isPlayers) {
                        const target = new URL(url);
                        const key = ['player_name', 'games', 'actual_sat', 'error_pct'][column];
                        const direction = target.searchParams.get('sort') === key && target.searchParams.get('direction') !== 'desc' ? 'desc' : 'asc';
                        target.searchParams.set('sort', key);
                        target.searchParams.set('direction', direction);
                        target.searchParams.delete('page');
                        refresh(target);
                        return;
                    }
                    const body = table.tBodies[0];
                    [...body.rows].sort((a, b) => {
                        const left = a.cells[column]?.textContent.trim() ?? '';
                        const right = b.cells[column]?.textContent.trim() ?? '';
                        const numericLeft = Number.parseFloat(left.replaceAll(',', ''));
                        const numericRight = Number.parseFloat(right.replaceAll(',', ''));
                        const order = Number.isFinite(numericLeft) && Number.isFinite(numericRight)
                            ? numericLeft - numericRight : left.localeCompare(right);
                        return ascending ? order : -order;
                    }).forEach((row) => body.appendChild(row));
                });
            });
        });
    };
    const schedule = () => {
        clearTimeout(timer);
        if (!stopped && !mutating && !document.hidden && content.querySelector('[data-evaluation-active="1"]')) {
            timer = setTimeout(pollProgress, 5000);
        }
    };
    const request = async (target, options = {}) => {
        const response = await fetch(target, { ...options, headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || (!data.html && !data.url && !data.progress_html)) throw new Error(data.message || `Request failed (${response.status}).`);
        return data;
    };
    const showError = (exception) => {
        error.textContent = exception.message;
        error.hidden = false;
    };
    const pollProgress = async () => {
        const target = new URL(url);
        target.searchParams.set('progress', '1');
        const pending = new AbortController();
        controller?.abort();
        controller = pending;
        try {
            const data = await request(target, { signal: pending.signal });
            if (stopped || controller !== pending) return;
            const previousStatus = content.querySelector('[data-progress-status]')?.dataset.progressStatus;
            const progress = content.querySelector('[data-evaluation-progress]');
            if (progress) progress.innerHTML = data.progress_html;
            const state = content.querySelector('[data-evaluation-active]');
            if (state) state.dataset.evaluationActive = data.active ? '1' : '0';
            error.hidden = true;
            // Expensive result aggregation is explicit, plus one refresh on completion.
            if (data.status === 'completed' && previousStatus !== 'completed') await refresh(url, false);
        } catch (exception) {
            if (exception.name !== 'AbortError') showError(exception);
        } finally {
            if (controller === pending) schedule();
        }
    };
    const refresh = async (target, navigate = true) => {
        target = new URL(target);
        target.searchParams.delete('progress');
        clearTimeout(timer);
        controller?.abort();
        const pending = new AbortController();
        controller = pending;
        try {
            const data = await request(target, { signal: pending.signal });
            if (stopped || controller !== pending) return;
            content.innerHTML = data.html;
            url = new URL(target);
            makeSortable();
            error.hidden = true;
            if (navigate) window.history.replaceState({}, '', url);
        } catch (exception) {
            if (exception.name !== 'AbortError') showError(exception);
        } finally {
            if (controller === pending) schedule();
        }
    };
    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('[data-evaluation-action], [data-evaluation-filters]')) return;
        event.preventDefault();
        if (mutating) return;
        if (form.matches('[data-evaluation-filters]')) {
            const target = new URL(url);
            target.search = new URLSearchParams([...new FormData(form)].filter(([, value]) => value !== '')).toString();
            await refresh(target);
            return;
        }
        mutating = true;
        clearTimeout(timer);
        controller?.abort();
        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        try {
            const data = await request(form.action, { method: 'POST', body: new FormData(form) });
            const target = new URL(data.url);
            const select = root.querySelector('[data-evaluation-select]');
            const id = target.searchParams.get('evaluation');
            if (![...select.options].some((option) => option.value === id)) select.add(new Option(`#${id}`, id));
            select.value = id;
            const currentFilters = new FormData(root.querySelector('[data-evaluation-filters]'));
            target.search = new URLSearchParams([...currentFilters].filter(([, value]) => value !== '')).toString();
            await refresh(target);
        } catch (exception) {
            showError(exception);
        } finally {
            mutating = false;
            buttons.forEach((button) => { button.disabled = false; });
            form.removeAttribute('aria-busy');
            schedule();
        }
    });
    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-evaluation-links] a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!mutating) refresh(new URL(link.href));
    });
    document.addEventListener('visibilitychange', () => document.hidden ? clearTimeout(timer) : schedule());
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); controller?.abort(); });
    window.addEventListener('pageshow', () => { stopped = false; schedule(); });
    makeSortable();
    schedule();
}
