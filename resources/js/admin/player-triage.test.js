import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadModules = async () => {
    document.querySelectorAll('[data-player-triage-page]').forEach((root) => {
        root.dataset.playerTriageMounted = '1';
    });
    vi.resetModules();
    return import('./player-triage.js');
};

const detailPayload = (name = 'Initial') => ({
    selected_identity: {
        id: 7,
        display_name: name,
        normalized_name: name.toLowerCase(),
        birthdate: '1997-09-13',
        provider: 'fantrax',
        provider_player_id: 'fantrax-7',
        provider_slug: 'fantrax-7',
        team: 'TOR',
        position: 'C',
        match_status: 'candidate',
        match_confidence: 75,
        unmatched_reason: null,
        player_id: null,
        raw_payload: { name },
    },
    player: null,
    current_contract: null,
    recommendation: { status: 'candidate', confidence: 75 },
    coverage: { active: false, label: null, matched: false, source: null, matching_source: null },
    linked_sources: [],
    candidate_players: [{
        id: 9,
        full_name: 'Candidate Player',
        dob: '1997-09-13',
        position: 'C',
        team_abbrev: 'TOR',
        nhl_id: 99,
        status: 'active',
        is_prospect: false,
    }],
    player_search_results: [],
    suggested_external_matches: [],
    matching_source_identity: null,
    matching_source_candidates: [],
    matching_source_search_results: [],
    actions: {
        link_player: 'http://app.test/admin/player-triage/identities/7/link',
        link_matching_source: 'http://app.test/admin/player-triage/identities/7/link-matching-source',
        link_external_source: 'http://app.test/admin/player-triage/identities/7/link-external-source',
        create_canonical: 'http://app.test/admin/player-triage/identities/7/create-canonical',
    },
    queries: {
        current: { identity: '7' },
        player_search_action: 'http://app.test/admin/player-triage?identity=7',
        matching_source_search_action: 'http://app.test/admin/player-triage?identity=7',
    },
});

const rootHtml = (label = 'Initial') => `
    <div data-player-triage-page data-player-triage-url="http://app.test/admin/player-triage" data-player-triage-embedded="0">
        <form method="GET" action="http://app.test/admin/player-triage" data-player-triage-filter-form>
            <label data-search-field data-search-field-name="search" data-search-field-scope="triage-filter">
                <input name="search" value="" />
            </label>
            <label data-select-field data-select-field-name="source" data-select-field-scope="triage-filter">
                <select name="source"><option value="">All</option><option value="fantrax">Fantrax</option></select>
            </label>
            <label data-select-field data-select-field-name="matching_source" data-select-field-scope="triage-filter">
                <select name="matching_source"><option value="">No source match</option><option value="capwages">CapWages</option></select>
            </label>
            <label>
                <input type="radio" name="triage_state" value="unmatched" checked />
                <span>Unmatched</span>
            </label>
            <label>
                <input type="radio" name="triage_state" value="matched" />
                <span>Matched</span>
            </label>
            <label>
                <input type="radio" name="triage_state" value="all" />
                <span>All</span>
            </label>
        </form>
        <span data-player-triage-inbox-count>1</span>
        <script type="application/json" data-player-triage-inbox-payload>
            {
                "identities": [
                    {
                        "id": 7,
                        "display_name": "${label}",
                        "provider": "fantrax",
                        "provider_player_id": "fantrax-7",
                        "provider_slug": "fantrax-7",
                        "team": "TOR",
                        "position": "C",
                        "match_status": "candidate",
                        "match_confidence": 75,
                        "unmatched_reason": null,
                        "recommendation": { "status": "candidate", "confidence": 75 },
                        "coverage": { "active": false, "label": null, "matched": false },
                        "selected": true,
                        "detail_url": "http://app.test/admin/player-triage/identities/7/detail",
                        "href": "http://app.test/admin/player-triage?identity=7"
                    },
                    {
                        "id": 8,
                        "display_name": "Hidden Row",
                        "provider": "capwages",
                        "provider_player_id": "capwages-8",
                        "provider_slug": "capwages-8",
                        "team": "MTL",
                        "position": "D",
                        "match_status": "unmatched",
                        "match_confidence": null,
                        "unmatched_reason": "insufficient_identity_data",
                        "recommendation": { "status": "unmatched", "confidence": null },
                        "coverage": { "active": false, "label": null, "matched": false },
                        "selected": false,
                        "detail_url": "http://app.test/admin/player-triage/identities/8/detail",
                        "href": "http://app.test/admin/player-triage?identity=8"
                    }
                ],
                "meta": { "count": 2, "loaded_count": 2, "total_count": 2, "selected_identity_id": 7, "uses_source_coverage": false }
            }
        </script>
        <div data-player-triage-inbox></div>
        <section data-player-triage>
            <script type="application/json" data-player-triage-detail-payload>
                ${JSON.stringify(detailPayload(label))}
            </script>
            <div data-player-triage-detail></div>
        </section>
    </div>
`;

const jsonResponse = (payload, ok = true) => Promise.resolve({
    ok,
    json: () => Promise.resolve(payload),
});

describe('SearchField component', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
    });

    it('mounts on a data-search-field wrapper', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';

        const field = new SearchField(document.querySelector('[data-search-field]')).mount();

        expect(field.input.parentElement.className).toContain('grid grid-cols-1');
        expect(field.input.className).toContain('col-start-1');
        expect(field.input.className).toContain('rounded-md');
        expect(field.input.className).toContain('min-h-8');
        expect(field.input.className).toContain('pl-8');
        expect(field.input.className).not.toContain('focus:outline-indigo');
        expect(field.input.className).not.toContain('focus:ring-indigo');
        expect(field.input.className).toContain('focus:outline-gray-300');
        expect(field.input.className).toContain('focus:ring-0');
    });

    it('renders a leading Heroicons magnifying-glass icon', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';

        new SearchField(document.querySelector('[data-search-field]')).mount();

        const icon = document.querySelector('svg');
        expect(icon.previousElementSibling).toBe(document.querySelector('input'));
        expect(icon.className.baseVal).toContain('self-center');
        expect(icon.parentElement.className).toContain('col-start-1 row-start-1');
        expect(icon.querySelector('path').getAttribute('d')).toContain('m21 21-5.197-5.197');
    });

    it('renders an icon-only clear button with an x mark', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" value="mcdavid" /></label>';

        new SearchField(document.querySelector('[data-search-field]')).mount();

        const button = document.querySelector('button[aria-label^="Clear"]');
        expect(button.parentElement.className).toContain('justify-self-end');
        expect(button.textContent).toBe('');
        expect(button.querySelector('svg path').getAttribute('d')).toBe('M6 18 18 6M6 6l12 12');
        expect(button.className).not.toContain('focus:outline-indigo');
        expect(button.className).not.toContain('focus:ring-indigo');
        expect(button.className).toContain('focus:outline-gray-300');
        expect(button.className).toContain('focus:ring-0');
    });

    it('hides the clear button when the input is empty', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';

        new SearchField(document.querySelector('[data-search-field]')).mount();

        expect(document.querySelector('button[aria-label^="Clear"]').classList.contains('hidden')).toBe(true);
    });

    it('shows the clear button when the input has a value', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" value="mcdavid" /></label>';

        new SearchField(document.querySelector('[data-search-field]')).mount();

        expect(document.querySelector('button[aria-label^="Clear"]').classList.contains('hidden')).toBe(false);
    });

    it('toggles the clear button as text is entered and removed', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';

        new SearchField(document.querySelector('[data-search-field]')).mount();

        const input = document.querySelector('input');
        const button = document.querySelector('button[aria-label^="Clear"]');

        expect(button.classList.contains('hidden')).toBe(true);

        input.value = 'm';
        input.dispatchEvent(new Event('input'));
        expect(button.classList.contains('hidden')).toBe(false);

        input.value = '';
        input.dispatchEvent(new Event('input'));
        expect(button.classList.contains('hidden')).toBe(true);
    });

    it('emits a debounced change event with name and value', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field data-search-field-name="player"><input name="search" /></label>';
        const wrapper = document.querySelector('[data-search-field]');
        const listener = vi.fn();
        wrapper.addEventListener('search-field:change', listener);
        new SearchField(wrapper).mount();

        wrapper.querySelector('input').value = 'mackinnon';
        wrapper.querySelector('input').dispatchEvent(new Event('input'));
        vi.advanceTimersByTime(299);
        expect(listener).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: expect.objectContaining({ name: 'player', value: 'mackinnon' }),
        }));
    });

    it('preserves focus and cursor position when debounced events emit', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<button id="focus-target" type="button">Target</button><label data-search-field data-search-field-name="search"><input name="search" value="abcdef" /></label>';
        const wrapper = document.querySelector('[data-search-field]');
        const input = wrapper.querySelector('input');
        wrapper.addEventListener('search-field:change', () => {
            document.querySelector('#focus-target').focus();
        });
        new SearchField(wrapper).mount();

        input.focus();
        input.setSelectionRange(3, 3);
        input.dispatchEvent(new Event('input'));
        vi.advanceTimersByTime(300);

        expect(document.activeElement).toBe(input);
        expect(input.selectionStart).toBe(3);
        expect(input.selectionEnd).toBe(3);
    });

    it('emits immediately for native change events', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field data-search-field-name="search"><input name="search" /></label>';
        const wrapper = document.querySelector('[data-search-field]');
        const listener = vi.fn();
        wrapper.addEventListener('search-field:change', listener);
        new SearchField(wrapper).mount();

        wrapper.querySelector('input').value = 'quick';
        wrapper.querySelector('input').dispatchEvent(new Event('change'));

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: expect.objectContaining({ immediate: true, value: 'quick' }),
        }));
    });

    it('clears the input and emits an empty value', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field data-search-field-name="search"><input name="search" value="clear me" /></label>';
        const wrapper = document.querySelector('[data-search-field]');
        const listener = vi.fn();
        wrapper.addEventListener('search-field:change', listener);
        new SearchField(wrapper).mount();

        wrapper.querySelector('button').click();

        expect(wrapper.querySelector('input').value).toBe('');
        expect(wrapper.querySelector('button').classList.contains('hidden')).toBe(true);
        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: expect.objectContaining({ value: '', immediate: true }),
        }));
    });

    it('does not clear while disabled', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" value="locked" disabled /></label>';
        const wrapper = document.querySelector('[data-search-field]');
        new SearchField(wrapper).mount();

        wrapper.querySelector('button').click();

        expect(wrapper.querySelector('input').value).toBe('locked');
    });

    it('does not render loading text because loading feedback is page-owned', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';
        const field = new SearchField(document.querySelector('[data-search-field]')).mount();

        field.setLoading(true);

        expect(document.body.textContent).not.toContain('Loading');
    });

    it('shows inline error state on demand', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';
        const field = new SearchField(document.querySelector('[data-search-field]')).mount();

        field.setError('Search failed');

        expect(document.querySelector('.text-red-600').textContent).toBe('Search failed');
    });

    it('sets disabled state on the input', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<label data-search-field><input name="search" /></label>';
        const field = new SearchField(document.querySelector('[data-search-field]')).mount();

        field.setDisabled(true);

        expect(document.querySelector('input').disabled).toBe(true);
    });

    it('distinguishes multiple instances by emitted payload fields', async () => {
        const { mountSearchFields } = await loadModules();
        document.body.innerHTML = `
            <label data-search-field data-search-field-name="player_search" data-search-field-scope="canonical" data-search-field-id="a"><input name="player_search" /></label>
            <label data-search-field data-search-field-name="matching_identity_search" data-search-field-scope="source" data-search-field-id="b"><input name="matching_identity_search" /></label>
        `;
        const listener = vi.fn();
        document.body.addEventListener('search-field:change', listener);
        mountSearchFields(document);

        document.querySelectorAll('input')[1].value = 'source player';
        document.querySelectorAll('input')[1].dispatchEvent(new Event('change', { bubbles: true }));

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: expect.objectContaining({ name: 'matching_identity_search', scope: 'source', id: 'b' }),
        }));
    });

    it('throws when no input exists', async () => {
        const { SearchField } = await loadModules();
        document.body.innerHTML = '<div data-search-field></div>';

        expect(() => new SearchField(document.querySelector('[data-search-field]'))).toThrow('SearchField requires an input or textarea');
    });
});

describe('SelectField component', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('mounts on a data-select-field wrapper', async () => {
        const { SelectField } = await loadModules();
        document.body.innerHTML = '<label data-select-field><select name="source"><option value="">All</option></select></label>';

        const field = new SelectField(document.querySelector('[data-select-field]')).mount();

        expect(field.select.parentElement.className).toContain('grid grid-cols-1');
        expect(field.select.className).toContain('appearance-none');
        expect(field.select.className).toContain('rounded-md');
        expect(field.select.className).toContain('min-h-8');
        expect(field.select.nextElementSibling.className).toContain('justify-self-end');
        expect(field.select.className).not.toContain('focus:outline-indigo');
        expect(field.select.className).not.toContain('focus:ring-indigo');
        expect(field.select.className).toContain('focus:outline-gray-300');
        expect(field.select.className).toContain('focus:ring-0');
    });

    it('emits a generic select-field change event with label and scope', async () => {
        const { SelectField } = await loadModules();
        document.body.innerHTML = `
            <label data-select-field data-select-field-name="source" data-select-field-scope="triage-filter" data-select-field-id="primary">
                <select name="source">
                    <option value="">All</option>
                    <option value="fantrax">Fantrax</option>
                </select>
            </label>
        `;
        const wrapper = document.querySelector('[data-select-field]');
        const listener = vi.fn();
        wrapper.addEventListener('select-field:change', listener);
        new SelectField(wrapper).mount();

        wrapper.querySelector('select').value = 'fantrax';
        wrapper.querySelector('select').dispatchEvent(new Event('change'));

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: {
                name: 'source',
                value: 'fantrax',
                label: 'Fantrax',
                scope: 'triage-filter',
                id: 'primary',
            },
        }));
    });

    it('distinguishes multiple select instances by emitted payload fields', async () => {
        const { mountSelectFields } = await loadModules();
        document.body.innerHTML = `
            <label data-select-field data-select-field-name="source" data-select-field-scope="triage-filter"><select name="source"><option value="">All</option><option value="fantrax">Fantrax</option></select></label>
            <label data-select-field data-select-field-name="matching_source" data-select-field-scope="triage-filter"><select name="matching_source"><option value="">No source match</option><option value="capwages">CapWages</option></select></label>
        `;
        const listener = vi.fn();
        document.body.addEventListener('select-field:change', listener);
        mountSelectFields(document);

        document.querySelectorAll('select')[1].value = 'capwages';
        document.querySelectorAll('select')[1].dispatchEvent(new Event('change', { bubbles: true }));

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({
            detail: expect.objectContaining({ name: 'matching_source', value: 'capwages', scope: 'triage-filter' }),
        }));
    });

    it('throws when no select exists', async () => {
        const { SelectField } = await loadModules();
        document.body.innerHTML = '<div data-select-field></div>';

        expect(() => new SelectField(document.querySelector('[data-select-field]'))).toThrow('SelectField requires a select');
    });
});

describe('player triage page module', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = rootHtml();
        window.history.pushState({}, '', '/admin/player-triage');
        document.head.innerHTML = '<meta name="csrf-token" content="token-123">';
        window.toast = { show: vi.fn() };
    });

    it('mounts SearchField controls during boot', async () => {
        const { createPlayerTriagePage } = await loadModules();

        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher: vi.fn() });

        expect(document.querySelector('[data-search-field]').dataset.searchFieldMounted).toBe('1');
    });

    it('mounts SelectField controls during boot', async () => {
        const { createPlayerTriagePage } = await loadModules();

        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher: vi.fn() });

        expect(document.querySelector('[data-select-field]').dataset.selectFieldMounted).toBe('1');
    });

    it('loads JSON when a filter select changes', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Filtered') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('select').value = 'fantrax';
        document.querySelector('select').dispatchEvent(new Event('change'));
        await Promise.resolve();

        expect(String(fetcher.mock.calls[0][0])).toContain('source=fantrax');
    });

    it('emits inbox-loaded after SelectField refreshes triage JSON', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const inboxPayload = {
            identities: [{
                id: 99,
                display_name: 'Select Loaded Player',
                provider: 'fantrax',
                provider_player_id: 'select-99',
                team: 'BOS',
                position: 'C',
                match_status: 'candidate',
                match_confidence: 75,
                recommendation: { status: 'candidate', confidence: 75 },
                coverage: { active: false, label: null, matched: false },
                detail_url: 'http://app.test/admin/player-triage/identities/99/detail',
                href: 'http://app.test/admin/player-triage?identity=99',
            }],
            meta: { loaded_count: 1, total_count: 1, selected_identity_id: 99 },
        };
        const fetcher = vi.fn(() => jsonResponse({
            inbox: inboxPayload,
            detail: detailPayload('Select Loaded Player'),
        }));
        const root = document.querySelector('[data-player-triage-page]');
        const listener = vi.fn();
        root.addEventListener('player-triage:inbox-loaded', listener);
        createPlayerTriagePage(root, { fetcher });

        document.querySelector('select').value = 'fantrax';
        document.querySelector('select').dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();

        expect(listener).toHaveBeenCalledWith(expect.objectContaining({ detail: inboxPayload }));
        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('Select Loaded Player');
    });

    it('loads JSON when the status segment changes', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Matched Segment') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('input[name="triage_state"][value="matched"]').checked = true;
        document.querySelector('input[name="triage_state"][value="matched"]').dispatchEvent(new Event('change'));
        await Promise.resolve();

        expect(String(fetcher.mock.calls[0][0])).toContain('triage_state=matched');
        expect(String(fetcher.mock.calls[0][0])).not.toContain('include_resolved');
    });

    it('includes the typed inbox filter when the status segment reloads from the server', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Matched Segment') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        const search = document.querySelector('input[name="search"]');
        search.value = 'Daws';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(300);

        document.querySelector('input[name="triage_state"][value="all"]').checked = true;
        document.querySelector('input[name="triage_state"][value="all"]').dispatchEvent(new Event('change'));
        await Promise.resolve();

        const url = String(fetcher.mock.calls[0][0]);
        expect(url).toContain('search=Daws');
        expect(url).toContain('triage_state=all');
    });

    it('reapplies the typed inbox filter after a status reload returns a new payload', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const inboxPayload = {
            identities: [
                {
                    id: 100,
                    display_name: 'Nicolas Daws',
                    provider: 'fantrax',
                    provider_player_id: '059mt',
                    provider_slug: '059mt',
                    team: 'NJD',
                    position: 'G',
                    match_status: 'unmatched',
                    match_confidence: null,
                    recommendation: { status: 'matched', confidence: 95 },
                    coverage: { active: false, label: null, matched: false },
                    detail_url: 'http://app.test/admin/player-triage/identities/100/detail',
                    href: 'http://app.test/admin/player-triage?identity=100',
                },
                {
                    id: 101,
                    display_name: 'Other Player',
                    provider: 'fantrax',
                    provider_player_id: 'other-101',
                    provider_slug: 'other-101',
                    team: 'NJD',
                    position: 'G',
                    match_status: 'unmatched',
                    match_confidence: null,
                    recommendation: { status: 'unmatched', confidence: null },
                    coverage: { active: false, label: null, matched: false },
                    detail_url: 'http://app.test/admin/player-triage/identities/101/detail',
                    href: 'http://app.test/admin/player-triage?identity=101',
                },
            ],
            meta: { loaded_count: 2, total_count: 2, selected_identity_id: 100 },
        };
        const fetcher = vi.fn(() => jsonResponse({
            inbox: inboxPayload,
            detail: detailPayload('Nicolas Daws'),
        }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        const search = document.querySelector('input[name="search"]');
        search.value = 'Daws';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(300);

        document.querySelector('input[name="triage_state"][value="all"]').checked = true;
        document.querySelector('input[name="triage_state"][value="all"]').dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();

        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('Nicolas Daws');
        expect(document.querySelector('[data-player-triage-inbox]').textContent).not.toContain('Other Player');
    });

    it('shows an inbox loading skeleton while SelectField refreshes triage JSON', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => new Promise(() => {}));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('select').value = 'fantrax';
        document.querySelector('select').dispatchEvent(new Event('change'));

        expect(document.querySelector('[data-player-triage-inbox] .animate-pulse')).not.toBeNull();
        expect(document.querySelector('[data-player-triage-inbox-progress]')).not.toBeNull();
        expect(document.querySelector('[data-player-triage-inbox-progress-bar]').className).toContain('duration-[5000ms]');
    });

    it('shows an inbox error when a triage refresh fails', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ message: 'Inbox failed' }, false));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('select').value = 'fantrax';
        document.querySelector('select').dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();

        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('Inbox failed');
    });

    it('does not show page or SearchField loading text while loading refreshed data', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Filtered') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('select').value = 'fantrax';
        document.querySelector('select').dispatchEvent(new Event('change'));

        expect(document.querySelector('[data-player-triage-loading]')).toBeNull();
        expect(document.body.textContent).not.toContain('Loading');
    });

    it('replaces the triage fragment after a successful load', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Loaded Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('select').dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();

        expect(document.body.textContent).toContain('Loaded Row');
    });

    it('pushes URL state after a successful load', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const history = { pushState: vi.fn() };
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('History Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher, history });

        document.querySelector('select').dispatchEvent(new Event('change'));
        await Promise.resolve();

        expect(history.pushState).toHaveBeenCalled();
    });

    it('does not push URL state when history is disabled', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const history = { pushState: vi.fn() };
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Pop Row') }));
        const page = createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher, history });

        await page.load(new URL('http://app.test/admin/player-triage?identity=1'), { history: false });

        expect(history.pushState).not.toHaveBeenCalled();
    });

    it('loads detail JSON when an inbox row is clicked', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ detail: detailPayload('Selected Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-row]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(String(fetcher.mock.calls[0][0])).toBe('http://app.test/admin/player-triage/identities/7/detail');
        expect(document.querySelector('[data-player-triage-detail]').textContent).toContain('Selected Row');
    });

    it('shows a detail loading skeleton while an inbox selection is loading', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => new Promise(() => {}));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-row]').click();

        expect(document.querySelector('[data-player-triage-detail] .animate-pulse')).not.toBeNull();
    });

    it('updates the detail header from the selected inbox row before detail JSON resolves', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => new Promise(() => {}));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelectorAll('[data-player-triage-row]')[1].click();

        expect(String(fetcher.mock.calls[0][0])).toBe('http://app.test/admin/player-triage/identities/8/detail');
        expect(document.querySelector('[data-player-triage-detail]').textContent).toContain('Hidden Row');
        expect(document.querySelector('[data-player-triage-detail] .animate-pulse')).not.toBeNull();
    });

    it('pushes the page URL instead of the detail endpoint after row selection', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const history = { pushState: vi.fn(), replaceState: vi.fn() };
        const fetcher = vi.fn(() => jsonResponse({ detail: detailPayload('Selected Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher, history });

        document.querySelector('[data-player-triage-row]').click();
        await Promise.resolve();

        expect(history.pushState).toHaveBeenCalledWith(
            { playerTriage: true },
            '',
            new URL('http://app.test/admin/player-triage?identity=7'),
        );
    });

    it('keeps embedded triage history on the admin panel after row selection', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const history = { pushState: vi.fn(), replaceState: vi.fn() };
        const fetcher = vi.fn(() => jsonResponse({ detail: detailPayload('Selected Row') }));
        const root = document.querySelector('[data-player-triage-page]');
        root.dataset.playerTriageEmbedded = '1';
        root.dataset.playerTriageHistoryUrl = 'http://app.test/admin';

        createPlayerTriagePage(root, { fetcher, history });

        document.querySelector('[data-player-triage-row]').click();
        await Promise.resolve();

        expect(String(fetcher.mock.calls[0][0])).toBe('http://app.test/admin/player-triage/identities/7/detail?admin_panel=1');
        expect(history.pushState).toHaveBeenCalledWith(
            { playerTriage: true },
            '',
            new URL('http://app.test/admin?identity=7'),
        );
    });

    it('renders detail errors without replacing the page', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ message: 'Detail failed' }, false));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-row]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(document.querySelector('[data-player-triage-detail]').textContent).toContain('Detail failed');
        expect(document.querySelector('[data-player-triage-inbox]')).not.toBeNull();
    });

    it('renders detail payloads sent through the detail-loaded event', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const root = document.querySelector('[data-player-triage-page]');
        createPlayerTriagePage(root, { fetcher: vi.fn() });

        root.dispatchEvent(new CustomEvent('player-triage:detail-loaded', {
            bubbles: true,
            detail: detailPayload('Event Detail Player'),
        }));

        expect(document.querySelector('[data-player-triage-detail]').textContent).toContain('Event Detail Player');
    });

    it('ignores stale detail responses', async () => {
        const { createPlayerTriagePage } = await loadModules();
        let resolveFirst;
        const first = new Promise((resolve) => {
            resolveFirst = () => resolve({
                ok: true,
                json: () => Promise.resolve({ detail: detailPayload('Stale Detail') }),
            });
        });
        const fetcher = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(jsonResponse({ detail: detailPayload('Fresh Detail') }));
        const page = createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        const firstLoad = page.loadDetail(new URL('http://app.test/admin/player-triage?identity=1'));
        await page.loadDetail(new URL('http://app.test/admin/player-triage?identity=2'));
        resolveFirst();
        await firstLoad;

        expect(document.querySelector('[data-player-triage-detail]').textContent).toContain('Fresh Detail');
        expect(document.querySelector('[data-player-triage-detail]').textContent).not.toContain('Stale Detail');
    });

    it('uses SearchField events to filter the owned inbox JSON without fetching', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Search Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('input[name="search"]').value = 'Hidden';
        document.querySelector('input[name="search"]').dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(300);
        await Promise.resolve();

        expect(fetcher).not.toHaveBeenCalled();
        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('Hidden Row');
        expect(document.querySelector('[data-player-triage-inbox]').textContent).not.toContain('Initial');
        expect(document.querySelector('[data-player-triage-inbox] .animate-pulse')).toBeNull();
    });

    it('accepts a new inbox JSON list through the inbox-loaded event', async () => {
        const { createPlayerTriagePage } = await loadModules();
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher: vi.fn() });

        document.querySelector('[data-player-triage-page]').dispatchEvent(new CustomEvent('player-triage:inbox-loaded', {
            bubbles: true,
            detail: {
                identities: [{
                    id: 99,
                    display_name: 'Loaded Event Player',
                    provider: 'fantrax',
                    provider_player_id: 'loaded-99',
                    team: 'BOS',
                    position: 'C',
                    match_status: 'candidate',
                    match_confidence: 75,
                    recommendation: { status: 'candidate', confidence: 75 },
                    coverage: { active: false, label: null, matched: false },
                    href: 'http://app.test/admin/player-triage?identity=99',
                }],
                meta: { loaded_count: 1, total_count: 1, selected_identity_id: 99 },
            },
        }));

        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('Loaded Event Player');
        expect(document.querySelector('[data-player-triage-inbox-count]').textContent).toBe('1');
    });

    it('shows loaded and total counts when the server caps the inbox payload', async () => {
        const { createPlayerTriagePage } = await loadModules();
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher: vi.fn() });

        document.querySelector('[data-player-triage-page]').dispatchEvent(new CustomEvent('player-triage:inbox-loaded', {
            bubbles: true,
            detail: {
                identities: [{
                    id: 99,
                    display_name: 'Loaded Event Player',
                    provider: 'fantrax',
                    provider_player_id: 'loaded-99',
                    team: 'BOS',
                    position: 'C',
                    match_status: 'candidate',
                    match_confidence: 75,
                    recommendation: { status: 'candidate', confidence: 75 },
                    coverage: { active: false, label: null, matched: false },
                    href: 'http://app.test/admin/player-triage?identity=99',
                }],
                meta: { loaded_count: 75, total_count: 312, selected_identity_id: 99 },
            },
        }));

        expect(document.querySelector('[data-player-triage-inbox-count]').textContent).toBe('75 of 312');
    });

    it('shows an empty state when local inbox search has no match', async () => {
        const { createPlayerTriagePage } = await loadModules();
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher: vi.fn() });

        document.querySelector('[data-player-triage-page]').dispatchEvent(new CustomEvent('player-triage:player-searched', {
            bubbles: true,
            detail: { value: 'not-loaded' },
        }));

        expect(document.querySelector('[data-player-triage-inbox]').textContent).toContain('No loaded identities match this search.');
        expect(document.querySelector('[data-player-triage-inbox-count]').textContent).toBe('0 of 2 loaded');
    });

    it('keeps search focus and cursor after locally filtering the inbox', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Focused Row') }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });
        const input = document.querySelector('input[name="search"]');

        input.value = 'marner';
        input.focus();
        input.setSelectionRange(3, 3);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(300);
        await Promise.resolve();

        const nextInput = document.querySelector('input[name="search"]');
        expect(fetcher).not.toHaveBeenCalled();
        expect(nextInput).toBe(input);
        expect(document.activeElement).toBe(nextInput);
        expect(nextInput.selectionStart).toBe(3);
        expect(nextInput.selectionEnd).toBe(3);
    });

    it('submits action forms as JSON POST requests', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Linked Row'), message: 'Linked' }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-action-form]').dispatchEvent(new Event('submit', { bubbles: true }));
        await Promise.resolve();

        expect(fetcher.mock.calls[0][1]).toMatchObject({
            method: 'POST',
            headers: expect.objectContaining({ Accept: 'application/json', 'X-CSRF-TOKEN': 'token-123' }),
        });
    });

    it('shows a toast after a successful action', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ html: rootHtml('Linked Row'), message: 'Linked' }));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-action-form]').dispatchEvent(new Event('submit', { bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        expect(window.toast.show).toHaveBeenCalledWith('Linked', { type: 'success' });
    });

    it('shows an error toast when an action fails', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ message: 'No player' }, false));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-action-form]').dispatchEvent(new Event('submit', { bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        expect(window.toast.show).toHaveBeenCalledWith('No player', { type: 'error' });
    });

    it('restores action button text when an action fails', async () => {
        const { createPlayerTriagePage } = await loadModules();
        const fetcher = vi.fn(() => jsonResponse({ message: 'No player' }, false));
        createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        document.querySelector('[data-player-triage-action-form]').dispatchEvent(new Event('submit', { bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        expect(document.querySelector('button[type="submit"]').textContent).toBe('Link');
    });

    it('ignores stale load responses', async () => {
        const { createPlayerTriagePage } = await loadModules();
        let resolveFirst;
        const first = new Promise((resolve) => {
            resolveFirst = () => resolve({
                ok: true,
                json: () => Promise.resolve({ html: rootHtml('Stale Row') }),
            });
        });
        const fetcher = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(jsonResponse({ html: rootHtml('Fresh Row') }));
        const page = createPlayerTriagePage(document.querySelector('[data-player-triage-page]'), { fetcher });

        const firstLoad = page.load(new URL('http://app.test/admin/player-triage?identity=1'));
        await page.load(new URL('http://app.test/admin/player-triage?identity=2'));
        resolveFirst();
        await firstLoad;

        expect(document.body.textContent).toContain('Fresh Row');
        expect(document.body.textContent).not.toContain('Stale Row');
    });
});
