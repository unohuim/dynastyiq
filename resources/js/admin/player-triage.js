const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const text = (value, fallback = 'N/A') => {
    if (typeof value !== 'string') return fallback;

    const trimmed = value.trim();

    return trimmed === '' ? fallback : trimmed;
};

const identityMeta = (identity, includeProvider = false) => {
    const parts = [
        includeProvider ? text(identity.provider, '').replace(/^./, (char) => char.toUpperCase()) : null,
        text(identity.provider_player_id),
        text(identity.team, 'No team'),
        text(identity.position, 'No position'),
        text(identity.match_status),
    ].filter(Boolean);

    return parts.join(' - ');
};

const showToast = (message, type = 'success') => {
    if (window.toast?.show) {
        window.toast.show(message, { type });
        return;
    }

    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
};

const setLinkedState = (root, identity) => {
    const source = text(identity.provider, 'source');
    const label = `has ${source}`;

    root.querySelector('[data-player-triage-detail-badge]')?.classList.remove(
        'bg-red-50',
        'text-red-700',
        'ring-red-200'
    );
    root.querySelector('[data-player-triage-detail-badge]')?.classList.add(
        'bg-green-50',
        'text-green-700',
        'ring-green-200'
    );

    const detailBadge = root.querySelector('[data-player-triage-detail-badge]');
    if (detailBadge) detailBadge.textContent = label;

    const coverageLabel = root.querySelector('[data-player-triage-coverage-label]');
    if (coverageLabel) coverageLabel.textContent = label.replace(/^./, (char) => char.toUpperCase());

    const rowBadge = document.querySelector('[data-player-triage-selected-row-badge]');
    if (rowBadge) {
        rowBadge.classList.remove('bg-red-50', 'text-red-700', 'ring-red-200');
        rowBadge.classList.add('bg-green-50', 'text-green-700', 'ring-green-200');
        rowBadge.textContent = label;
    }

    root.querySelectorAll('[data-player-triage-unmatched-section]').forEach((section) => {
        section.classList.add('hidden');
    });

    const matchedSection = root.querySelector('[data-player-triage-matched-section]');
    matchedSection?.classList.remove('hidden');

    const matchedName = root.querySelector('[data-player-triage-matched-name]');
    if (matchedName) matchedName.textContent = text(identity.display_name, 'Matched identity');

    const matchedMeta = root.querySelector('[data-player-triage-matched-meta]');
    if (matchedMeta) matchedMeta.textContent = identityMeta(identity);

    const inboxCount = document.querySelector('[data-player-triage-inbox-count]');
    const currentCount = Number.parseInt(inboxCount?.textContent ?? '', 10);
    if (inboxCount && Number.isFinite(currentCount) && currentCount > 0) {
        inboxCount.textContent = String(currentCount - 1);
    }
};

const submitLinkForm = async (form, root) => {
    const button = form.querySelector('button[type="submit"]');
    const originalText = button?.textContent;

    if (button) {
        button.disabled = true;
        button.textContent = 'Linking...';
    }

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: new FormData(form),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message ?? 'Unable to link source identity');
        }

        setLinkedState(root, payload.matched_identity ?? {});
        showToast(payload.message ?? 'Matching source linked');
    } catch (error) {
        showToast(error.message ?? 'Unable to link source identity', 'error');

        if (button) {
            button.disabled = false;
            button.textContent = originalText;
        }
    }
};

const bootPlayerTriage = () => {
    const root = document.querySelector('[data-player-triage]');

    document.querySelectorAll('[data-prune-empty-get-fields]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
                if ('value' in field && String(field.value).trim() === '') {
                    field.disabled = true;
                }
            });
        });
    });

    if (!root) return;

    root.querySelectorAll('[data-player-triage-link-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitLinkForm(form, root);
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPlayerTriage, { once: true });
} else {
    bootPlayerTriage();
}
