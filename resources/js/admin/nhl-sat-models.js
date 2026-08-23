const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

let satModelBroadcastsMounted = false;

const showToast = (message, type = 'success') => {
    if (!message) return;

    if (window.toast?.show) {
        window.toast.show(message, { type });
        return;
    }

    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
};

const firstError = (payload) => {
    const errors = payload?.errors ?? {};
    const first = Object.values(errors)[0];

    if (Array.isArray(first) && first[0]) {
        return first[0];
    }

    return payload?.message || 'The request could not be saved.';
};

const htmlToRow = (html) => {
    const template = document.createElement('template');
    template.innerHTML = String(html).trim();

    return template.content.firstElementChild;
};

const setDrawerOpen = (root, open) => {
    const data = window.Alpine?.$data?.(root);

    if (data && Object.prototype.hasOwnProperty.call(data, 'createOpen')) {
        data.createOpen = open;
    }
};

const setSubmitting = (form, submitting) => {
    form.querySelectorAll('button[type="submit"]').forEach((field) => {
        field.disabled = submitting;
    });
};

const actionMessage = (form) => {
    if (form.matches('[data-sat-model-profile-build-form]')) {
        return 'Building profiles...';
    }

    if (form.matches('[data-sat-model-rate-build-form]')) {
        return 'Building /60...';
    }

    if (form.matches('[data-sat-model-rate-compare-build-form]')) {
        return 'Comparing /60...';
    }

    const evaluation = form.querySelector('input[name="evaluation"]')?.value;

    if (evaluation === 'sat') {
        return 'Evaluating SAT...';
    }

    return 'Evaluating SOG...';
};

const setLoading = (root, visible, message = 'Working...') => {
    const overlay = root.querySelector('[data-sat-model-loading]');
    const title = root.querySelector('[data-sat-model-loading-title]');

    if (!overlay) return;

    if (title) {
        title.textContent = message;
    }

    overlay.classList.toggle('hidden', !visible);
    overlay.classList.toggle('flex', visible);
};

const setFormError = (form, message) => {
    const error = form.querySelector('[data-sat-model-form-errors]');

    if (!error) return;

    error.textContent = message || '';
    error.classList.toggle('hidden', !message);
};

const syncEmptyState = (root) => {
    const rows = root.querySelector('[data-sat-model-rows]');
    const hasRows = Boolean(rows?.querySelector('[data-sat-model-row]'));

    root.querySelector('[data-sat-model-empty]')?.classList.toggle('hidden', hasRows);
    root.querySelector('[data-sat-model-table]')?.classList.toggle('hidden', !hasRows);
};

const replaceRow = (root, rowHtml) => {
    const row = htmlToRow(rowHtml);

    if (!row) return;

    const modelId = row.getAttribute('data-sat-model-row');
    const currentRow = modelId
        ? root.querySelector(`[data-sat-model-row="${modelId}"]`)
        : null;

    if (currentRow) {
        currentRow.replaceWith(row);
        return;
    }

    root.querySelector('[data-sat-model-rows]')?.prepend(row);
    syncEmptyState(root);
};

const request = async (form) => {
    const response = await fetch(form.action, {
        method: form.method || 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: new FormData(form),
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(firstError(payload));
    }

    return payload;
};

const mountSatModels = (root) => {
    const createForm = root.querySelector('[data-sat-model-create-form]');
    const rows = root.querySelector('[data-sat-model-rows]');

    createForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        setFormError(createForm, '');
        setSubmitting(createForm, true);

        try {
            const payload = await request(createForm);
            const row = htmlToRow(payload.row_html);

            if (row && rows) {
                rows.prepend(row);
            }

            createForm.reset();
            setDrawerOpen(root, false);
            syncEmptyState(root);
            showToast(payload.message || 'Model created.');
        } catch (error) {
            setFormError(createForm, error.message);
            showToast(error.message, 'error');
        } finally {
            setSubmitting(createForm, false);
        }
    });

    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-sat-model-train-form], [data-sat-model-profile-build-form], [data-sat-model-rate-build-form], [data-sat-model-rate-compare-build-form]');

        if (!form) return;

        event.preventDefault();
        setSubmitting(form, true);
        setLoading(root, true, actionMessage(form));

        try {
            const payload = await request(form);
            replaceRow(root, payload.row_html);

            showToast(payload.message || 'Action complete.');

            if (form.matches('[data-sat-model-reload-on-success]')) {
                window.location.reload();
            }
        } catch (error) {
            showToast(error.message, 'error');
            setLoading(root, false);
        } finally {
            setSubmitting(form, false);
            if (!form.matches('[data-sat-model-reload-on-success]')) {
                setLoading(root, false);
            }
        }
    });
};

const mountSatModelBroadcasts = () => {
    if (!window.Echo || satModelBroadcastsMounted) {
        return;
    }

    satModelBroadcastsMounted = true;

    window.Echo
        .private('admin.sat-models')
        .listen('.admin.nhl-sat-models.updated', (event) => {
            document.querySelectorAll('[data-admin-sat-models]').forEach((root) => {
                if (event?.row_html) {
                    replaceRow(root, event.row_html);
                }
            });

            if (event?.reason === 'sat-eval-completed') {
                showToast('Eval SAT complete.');
            } else if (event?.reason === 'sat-eval-failed') {
                showToast('Eval SAT failed.', 'error');
            } else if (event?.reason === 'sog-eval-completed' || event?.reason === 'training-completed') {
                showToast('Eval SOG complete.');
            } else if (event?.reason === 'sog-eval-failed' || event?.reason === 'training-failed') {
                showToast('Eval SOG failed.', 'error');
            } else if (event?.reason === 'profiles-completed') {
                showToast('Profiles built.');
            } else if (event?.reason === 'profiles-queued') {
                showToast('Profiles queued.');
            } else if (event?.reason === 'profiles-failed') {
                showToast('Profiles failed.', 'error');
            } else if (event?.reason === 'rate-projections-completed') {
                showToast('Built /60.');
            } else if (event?.reason === 'rate-projections-queued') {
                showToast('Queued /60.');
            } else if (event?.reason === 'rate-projections-failed') {
                showToast('/60 failed.', 'error');
            } else if (event?.reason === 'rate-comparisons-completed') {
                showToast('Compared /60.');
            } else if (event?.reason === 'rate-comparisons-queued') {
                showToast('Queued Compare /60.');
            } else if (event?.reason === 'rate-comparisons-failed') {
                showToast('Compare /60 failed.', 'error');
            }
        });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-admin-sat-models]').forEach(mountSatModels);
    mountSatModelBroadcasts();
});
