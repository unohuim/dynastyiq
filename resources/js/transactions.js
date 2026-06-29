const TYPE_STYLES = {
    trade: 'bg-blue-50 text-blue-700 ring-blue-200',
    signed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    waivers: 'bg-amber-50 text-amber-800 ring-amber-200',
    draft: 'bg-gray-100 text-gray-700 ring-gray-200',
};

const TYPE_BANNER_STYLES = {
    trade: 'bg-blue-100 text-blue-900',
    signed: 'bg-emerald-100 text-emerald-900',
    waivers: 'bg-amber-100 text-amber-950',
    draft: 'bg-gray-200 text-gray-800',
};

export const DEFAULT_STATE = {
    sort: 'date_desc',
    type: '',
    q: '',
};

export function normalizePayload(payload) {
    return {
        transactions: Array.isArray(payload?.transactions) ? payload.transactions : [],
        filters: {
            types: Array.isArray(payload?.filters?.types) ? payload.filters.types : [],
            applied: payload?.filters?.applied ?? {},
        },
        meta: payload?.meta ?? { count: 0, limit: 500 },
    };
}

export function buildPayloadUrl(baseUrl, state) {
    const url = new URL(baseUrl, window.location.origin);

    if (state.type) {
        url.searchParams.set('type', state.type);
    }

    if (state.sort) {
        url.searchParams.set('sort', state.sort);
    }

    if (state.q) {
        url.searchParams.set('q', state.q);
    }

    return url.toString();
}

export function transactionTypeClass(type) {
    return TYPE_STYLES[type] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
}

export function transactionTypeBannerClass(type) {
    return TYPE_BANNER_STYLES[type] ?? 'bg-slate-100 text-slate-800';
}

export function teamPathLabel(transaction) {
    const fromTeam = transaction?.fromTeam;
    const toTeam = transaction?.toTeam;

    if (fromTeam && toTeam) {
        return `${fromTeam} -> ${toTeam}`;
    }

    if (toTeam) {
        return `To ${toTeam}`;
    }

    if (fromTeam) {
        return `From ${fromTeam}`;
    }

    return 'Team unavailable';
}

export function playerName(transaction) {
    return transaction?.player?.name || 'Unlinked player';
}

export function playerInitials(transaction) {
    const initials = transaction?.player?.initials;

    if (initials) {
        return initials;
    }

    return playerName(transaction)
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.slice(0, 1).toUpperCase())
        .join('') || 'DI';
}

export function statusText(payload, loading = false) {
    if (loading) {
        return 'Loading transactions...';
    }

    const count = payload?.meta?.count ?? payload?.transactions?.length ?? 0;

    if (count === 0) {
        return 'No transactions match the current filters.';
    }

    return `${count} transaction${count === 1 ? '' : 's'}`;
}

export function sortButtonClass(active) {
    return active
        ? 'bg-gray-950 text-white shadow-sm'
        : 'text-gray-700 hover:bg-gray-50';
}

export function createAvatar(transaction) {
    const avatar = document.createElement('div');
    avatar.className = 'flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-xs font-semibold text-gray-700 ring-1 ring-gray-200';

    if (transaction?.player?.avatarUrl) {
        const image = document.createElement('img');
        image.src = transaction.player.avatarUrl;
        image.alt = '';
        image.loading = 'lazy';
        image.className = 'h-full w-full object-cover';
        avatar.append(image);

        return avatar;
    }

    avatar.textContent = playerInitials(transaction);

    return avatar;
}

export function createTransactionRow(transaction) {
    const row = document.createElement('article');
    row.className = 'relative grid gap-3 overflow-hidden p-4 pt-9 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:pt-8';

    const typeMarker = document.createElement('div');
    typeMarker.className = `absolute left-0 top-0 flex h-5 w-full items-center justify-start px-2 text-[10px] font-semibold uppercase tracking-normal ${transactionTypeBannerClass(transaction?.type)}`;
    typeMarker.textContent = transaction?.typeLabel || 'Unknown';
    row.append(typeMarker);

    row.append(createAvatar(transaction));

    const body = document.createElement('div');
    body.className = 'min-w-0';

    const topLine = document.createElement('div');
    topLine.className = 'flex flex-wrap items-center gap-2';

    const name = document.createElement('h2');
    name.className = 'text-sm font-semibold text-gray-950';
    name.textContent = playerName(transaction);
    topLine.append(name);

    const playerMeta = document.createElement('span');
    playerMeta.className = 'text-xs text-gray-500';
    playerMeta.textContent = [transaction?.player?.team, transaction?.player?.position].filter(Boolean).join(' · ');
    if (playerMeta.textContent) {
        topLine.append(playerMeta);
    }

    if (transaction?.player?.contractSummary) {
        const contract = document.createElement('span');
        contract.className = 'text-xs font-semibold text-gray-700';
        contract.textContent = transaction.player.contractSummary;
        topLine.append(contract);
    }

    const description = document.createElement('p');
    description.className = 'mt-1 text-sm leading-6 text-gray-700';
    description.textContent = transaction?.description || 'No transaction detail available.';

    const teams = document.createElement('p');
    teams.className = 'mt-1 text-xs font-medium text-gray-500';
    teams.textContent = teamPathLabel(transaction);

    body.append(topLine, description, teams);
    row.append(body);

    const date = document.createElement('div');
    date.className = 'text-left text-xs font-medium text-gray-500 sm:text-right';
    date.textContent = transaction?.dateLabel || 'Unknown date';
    row.append(date);

    return row;
}

export function renderTypeOptions(select, types, selectedType) {
    select.replaceChildren();

    const all = document.createElement('option');
    all.value = '';
    all.textContent = 'All types';
    select.append(all);

    types.forEach((type) => {
        const option = document.createElement('option');
        option.value = type.value;
        option.textContent = `${type.label} (${type.count})`;
        option.selected = type.value === selectedType;
        select.append(option);
    });
}

export function renderTransactions(list, transactions) {
    list.replaceChildren();
    list.classList.remove('space-y-2');

    if (transactions.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'p-8 text-center text-sm text-gray-600';
        empty.textContent = 'No transactions to display.';
        list.append(empty);
        return;
    }

    list.classList.add('space-y-2');

    transactions.forEach((transaction) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'overflow-hidden rounded-md bg-white';
        const row = createTransactionRow(transaction);
        wrapper.append(row);
        list.append(wrapper);
    });
}

export function mountTransactionsPage(root) {
    if (!root || root.dataset.transactionsMounted === 'true') {
        return null;
    }

    root.dataset.transactionsMounted = 'true';

    const payloadUrl = root.dataset.payloadUrl;
    const list = root.querySelector('[data-transactions-list]');
    const status = root.querySelector('[data-transactions-status]');
    const typeSelect = root.querySelector('[data-transactions-type]');
    const search = root.querySelector('[data-transactions-search]');
    const sortButtons = Array.from(root.querySelectorAll('[data-transactions-sort]'));
    const state = { ...DEFAULT_STATE };
    let currentTransactions = [];
    let searchTimer;

    const setSortButtonState = () => {
        sortButtons.forEach((button) => {
            button.className = `rounded px-3 text-sm font-medium ${sortButtonClass(button.dataset.transactionsSort === state.sort)}`;
        });
    };

    const load = async () => {
        status.textContent = statusText(null, true);
        setSortButtonState();

        const response = await fetch(buildPayloadUrl(payloadUrl, state), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load transactions.');
        }

        const payload = normalizePayload(await response.json());
        currentTransactions = payload.transactions;

        renderTypeOptions(typeSelect, payload.filters.types, state.type);
        renderCurrentTransactions();
        status.textContent = statusText(payload);
    };

    const renderCurrentTransactions = () => {
        renderTransactions(list, currentTransactions);
    };

    typeSelect.addEventListener('change', () => {
        state.type = typeSelect.value;
        load().catch(() => {
            status.textContent = 'Unable to load transactions.';
        });
    });

    search.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            state.q = search.value.trim();
            load().catch(() => {
                status.textContent = 'Unable to load transactions.';
            });
        }, 250);
    });

    sortButtons.forEach((button) => {
        button.addEventListener('click', () => {
            state.sort = button.dataset.transactionsSort || DEFAULT_STATE.sort;
            load().catch(() => {
                status.textContent = 'Unable to load transactions.';
            });
        });
    });

    load().catch(() => {
        status.textContent = 'Unable to load transactions.';
    });

    return { load, state };
}

export function mountAllTransactionsPages(documentRef = document) {
    return Array.from(documentRef.querySelectorAll('[data-transactions-page]'))
        .map((root) => mountTransactionsPage(root))
        .filter(Boolean);
}

document.addEventListener('DOMContentLoaded', () => {
    mountAllTransactionsPages();
});
