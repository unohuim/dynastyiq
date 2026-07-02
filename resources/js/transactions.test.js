import { describe, expect, it, vi } from 'vitest';
import {
    buildPayloadUrl,
    createAvatar,
    createTransactionRow,
    DEFAULT_STATE,
    mountTransactionsPage,
    normalizePayload,
    playerInitials,
    playerName,
    renderTransactions,
    renderTypeOptions,
    sortButtonClass,
    statusText,
    teamPathLabel,
    transactionTypeBannerClass,
    transactionTypeClass,
} from './transactions';

const transaction = (overrides = {}) => ({
    id: 1,
    date: '2024-07-01',
    dateLabel: 'Jul 1, 2024',
    type: 'signed',
    typeLabel: 'Signed',
    description: 'Signed as a free agent on July 1, 2024',
    fromTeam: null,
    toTeam: 'FLA',
    player: {
        name: 'Test Player',
        team: 'FLA',
        position: 'LW',
        avatarUrl: null,
        initials: 'TP',
    },
    ...overrides,
});

const pageRoot = () => {
    const root = document.createElement('div');
    root.dataset.transactionsPage = '';
    root.dataset.payloadUrl = '/transactions/payload';
    root.innerHTML = `
        <input data-transactions-search>
        <select data-transactions-type></select>
        <button data-transactions-sort="date_desc"></button>
        <button data-transactions-sort="date_asc"></button>
        <div data-transactions-status></div>
        <div data-transactions-list></div>
    `;

    return root;
};

describe('transactions page helpers', () => {
    it('normalizes missing payloads to empty collections', () => {
        expect(normalizePayload(null).transactions).toEqual([]);
    });

    it('normalizes missing filter types to an empty array', () => {
        expect(normalizePayload({ filters: {} }).filters.types).toEqual([]);
    });

    it('preserves provided transaction rows during payload normalization', () => {
        expect(normalizePayload({ transactions: [transaction()] }).transactions).toHaveLength(1);
    });

    it('builds payload URLs with default sort state', () => {
        const url = new URL(buildPayloadUrl('/transactions/payload', DEFAULT_STATE));

        expect(url.pathname).toBe('/transactions/payload');
        expect(url.searchParams.get('sort')).toBe('date_desc');
    });

    it('builds payload URLs with type and search filters', () => {
        const url = new URL(buildPayloadUrl('/transactions/payload', { sort: 'date_asc', type: 'trade', q: 'Horvat' }));

        expect(url.searchParams.get('type')).toBe('trade');
        expect(url.searchParams.get('sort')).toBe('date_asc');
        expect(url.searchParams.get('q')).toBe('Horvat');
    });

    it('returns semantic classes for known transaction types', () => {
        expect(transactionTypeClass('trade')).toContain('bg-blue-50');
    });

    it('returns fallback classes for unknown transaction types', () => {
        expect(transactionTypeClass('unknown')).toContain('bg-slate-100');
    });

    it('returns gradient banner classes for known transaction types', () => {
        expect(transactionTypeBannerClass('signed')).toContain('bg-emerald-100');
    });

    it('formats team paths with origin and destination teams', () => {
        expect(teamPathLabel(transaction({ fromTeam: 'NYI', toTeam: 'VAN' }))).toBe('NYI -> VAN');
    });

    it('formats team paths with only a destination team', () => {
        expect(teamPathLabel(transaction({ fromTeam: null, toTeam: 'FLA' }))).toBe('To FLA');
    });

    it('formats unavailable team paths', () => {
        expect(teamPathLabel(transaction({ fromTeam: null, toTeam: null }))).toBe('Team unavailable');
    });

    it('returns player names from payloads', () => {
        expect(playerName(transaction())).toBe('Test Player');
    });

    it('returns unlinked player name fallback', () => {
        expect(playerName({ player: null })).toBe('Unlinked player');
    });

    it('uses provided player initials when present', () => {
        expect(playerInitials(transaction())).toBe('TP');
    });

    it('derives initials when payload initials are missing', () => {
        expect(playerInitials(transaction({ player: { name: 'Aatu Raty' } }))).toBe('AR');
    });

    it('reports loading status text', () => {
        expect(statusText(null, true)).toBe('Loading transactions...');
    });

    it('reports empty status text', () => {
        expect(statusText({ transactions: [], meta: { count: 0 } })).toBe('No transactions match the current filters.');
    });

    it('reports pluralized transaction count status text', () => {
        expect(statusText({ transactions: [transaction(), transaction({ id: 2 })], meta: { count: 2 } })).toBe('2 transactions');
    });

    it('returns active sort button classes', () => {
        expect(sortButtonClass(true)).toContain('bg-gray-950');
    });

    it('creates image avatars when avatar URLs are present', () => {
        const avatar = createAvatar(transaction({ player: { name: 'Test Player', avatarUrl: 'https://example.test/a.png' } }));

        expect(avatar.querySelector('img')?.src).toBe('https://example.test/a.png');
    });

    it('creates fallback avatar initials when avatar URLs are absent', () => {
        expect(createAvatar(transaction()).textContent).toBe('TP');
    });

    it('creates transaction rows with player date and description text', () => {
        const row = createTransactionRow(transaction());

        expect(row.firstElementChild.textContent).toBe('Signed');
        expect(row.firstElementChild.className).toContain('absolute left-0 top-0');
        expect(row.firstElementChild.className).toContain('w-full');
        expect(row.firstElementChild.className).toContain('bg-emerald-100');
        expect(row.children[1].textContent).toBe('TP');
        expect(row.textContent).toContain('Test Player');
        expect(row.textContent).toContain('Jul 1, 2024');
        expect(row.textContent).toContain('Signed as a free agent');
    });

    it('creates transaction rows with player contract summaries', () => {
        const row = createTransactionRow(transaction({
            player: {
                name: 'Test Player',
                team: 'FLA',
                position: 'LW',
                contractSummary: '$5.45M x 2 yrs (2031)',
            },
        }));

        expect(row.textContent).toContain('$5.45M x 2 yrs (2031)');
    });

    it('renders type options with counts', () => {
        const select = document.createElement('select');

        renderTypeOptions(select, [{ value: 'trade', label: 'Trade', count: 3 }], 'trade');

        expect(select.options).toHaveLength(2);
        expect(select.options[1].textContent).toBe('Trade (3)');
        expect(select.options[1].selected).toBe(true);
    });

    it('renders an empty transaction list state', () => {
        const list = document.createElement('div');

        renderTransactions(list, []);

        expect(list.textContent).toContain('No transactions to display.');
    });

    it('renders transaction rows into a list', () => {
        const list = document.createElement('div');

        renderTransactions(list, [transaction()]);

        expect(list.querySelectorAll('article')).toHaveLength(1);
    });

    it('mounts the page once and fetches the payload', async () => {
        const root = pageRoot();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                transactions: [transaction()],
                filters: { types: [{ value: 'signed', label: 'Signed', count: 1 }] },
                meta: { count: 1 },
            }),
        }));

        const mounted = mountTransactionsPage(root);
        await mounted.load();

        expect(root.dataset.transactionsMounted).toBe('true');
        expect(root.querySelector('[data-transactions-list]').textContent).toContain('Test Player');
    });
});
