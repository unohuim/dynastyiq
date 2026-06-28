const statusTone = {
    conflict: 'bg-red-50 text-red-700 ring-red-200',
    candidate: 'bg-yellow-50 text-yellow-800 ring-yellow-200',
    unmatched: 'bg-gray-100 text-gray-700 ring-gray-200',
    matched: 'bg-green-50 text-green-700 ring-green-200',
    ignored: 'bg-gray-50 text-gray-500 ring-gray-200',
};

const text = (value, fallback = 'N/A') => {
    if (value === null || value === undefined) return fallback;

    const trimmed = String(value).trim();

    return trimmed === '' ? fallback : trimmed;
};

const title = (value) => text(value, '').replace(/^./, (char) => char.toUpperCase());

const money = (value) => {
    if (value === null || value === undefined || value === '') return null;

    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 0,
        style: 'currency',
        currency: 'USD',
    }).format(Number(value));
};

const el = (tag, className = '', content = null) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (content !== null) node.textContent = content;

    return node;
};

const hidden = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;

    return input;
};

const actionButton = (label) => {
    const button = document.createElement('button');
    button.type = 'submit';
    button.className = 'inline-flex min-h-9 items-center rounded-md bg-gray-900 px-3 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-60';
    button.textContent = label;

    return button;
};

const disabledButton = (label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.disabled = true;
    button.className = 'inline-flex min-h-9 items-center rounded-md border border-gray-200 px-3 text-sm font-medium text-gray-400';
    button.textContent = label;

    return button;
};

const actionForm = (action, label, fields = {}) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    form.className = 'shrink-0';
    form.dataset.playerTriageActionForm = '';
    form.dataset.playerTriageActionLabel = `${label}...`;

    Object.entries(fields).forEach(([name, value]) => {
        form.append(hidden(name, value));
    });

    form.append(actionButton(label));

    return form;
};

const definitionList = (items, columns = 'md:grid-cols-3') => {
    const dl = el('dl', `mt-3 grid grid-cols-2 gap-3 text-sm ${columns}`);

    items.forEach(([label, value]) => {
        const item = el('div');
        item.append(
            el('dt', 'text-xs uppercase text-gray-500', label),
            el('dd', 'mt-1 text-gray-900', text(value)),
        );
        dl.append(item);
    });

    return dl;
};

const formParams = (query = {}, except = []) => {
    const fragment = document.createDocumentFragment();

    Object.entries(query).forEach(([key, value]) => {
        if (except.includes(key)) return;

        if (Array.isArray(value)) {
            value.forEach((item) => fragment.append(hidden(`${key}[]`, item)));
            return;
        }

        if (value !== null && value !== undefined && value !== '') {
            fragment.append(hidden(key, value));
        }
    });

    return fragment;
};

const searchForm = (detail, name, scope, placeholder, except) => {
    const form = el('form', 'mt-3 flex flex-col gap-3 sm:flex-row');
    form.method = 'GET';
    form.action = detail.queries?.[name === 'player_search' ? 'player_search_action' : 'matching_source_search_action'] ?? '';
    form.dataset.playerTriageFilterForm = '';
    form.append(formParams(detail.queries?.current, [...except, 'identity']));
    form.append(hidden('identity', detail.selected_identity.id));

    const wrapper = el('div', 'flex-1');
    wrapper.dataset.searchField = '';
    wrapper.dataset.searchFieldName = name;
    wrapper.dataset.searchFieldScope = scope;

    const input = document.createElement('input');
    input.name = name;
    input.value = detail.queries?.current?.[name] ?? '';
    input.className = 'block min-h-8 flex-1 text-sm';
    input.placeholder = placeholder;
    wrapper.append(input);

    const button = document.createElement('button');
    button.type = 'submit';
    button.className = 'inline-flex min-h-9 items-center rounded-md border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50';
    button.textContent = 'Search';

    form.append(wrapper, button);

    return form;
};

const section = (heading, children, description = null) => {
    const wrapper = el('div', 'border-t border-gray-200 px-6 py-5');

    if (heading) {
        wrapper.append(el('h4', 'text-sm font-semibold text-gray-900', heading));
    }

    if (description) {
        wrapper.append(el('p', 'mt-1 text-sm text-gray-600', description));
    }

    children.forEach((child) => wrapper.append(child));

    return wrapper;
};

const identityMeta = (identity, includeProvider = true) => [
    includeProvider ? title(identity.provider) : null,
    text(identity.provider_player_id),
    text(identity.team, 'No team'),
    text(identity.position, 'No position'),
    identity.match_status,
].filter(Boolean).join(' | ');

const playerMeta = (player) => [
    text(player.team_abbrev, 'No team'),
    text(player.position, 'No position'),
    text(player.dob, 'No birthdate'),
    `NHL ${text(player.nhl_id)}`,
].join(' | ');

const list = (items, emptyMessage, row) => {
    if (!items.length) {
        return el('div', 'mt-4 border border-gray-200 bg-gray-50 px-4 py-5 text-sm text-gray-600', emptyMessage);
    }

    const wrapper = el('div', 'mt-4 divide-y divide-gray-100 border border-gray-200');
    items.forEach((item) => wrapper.append(row(item)));

    return wrapper;
};

const identityRow = (identity, control = null) => {
    const row = el('div', 'flex flex-wrap items-center justify-between gap-3 px-4 py-3');
    const body = el('div', 'min-w-0');
    body.append(
        el('div', 'truncate text-sm font-semibold text-gray-900', text(identity.display_name, 'Unnamed identity')),
        el('div', 'mt-1 text-xs text-gray-500', identityMeta(identity)),
    );
    row.append(body);
    if (control) row.append(control);

    return row;
};

const playerRow = (player, control = null) => {
    const row = el('div', 'flex flex-wrap items-center justify-between gap-3 px-4 py-3');
    const body = el('div', 'min-w-0');
    body.append(
        el('div', 'truncate text-sm font-semibold text-gray-900', text(player.full_name, 'Unnamed player')),
        el('div', 'mt-1 text-xs text-gray-500', playerMeta(player)),
    );
    row.append(body);
    if (control) row.append(control);

    return row;
};

const badge = (detail) => {
    const span = el('span');
    const hasPlayer = Boolean(detail.player);
    const coverage = detail.coverage;
    const tone = hasPlayer
        ? 'bg-green-50 text-green-700 ring-green-200'
        : (coverage?.active
            ? (coverage.matched ? 'bg-green-50 text-green-700 ring-green-200' : 'bg-red-50 text-red-700 ring-red-200')
            : (statusTone[detail.selected_identity.match_status] ?? 'bg-gray-50 text-gray-600 ring-gray-200'));
    span.className = `rounded-full px-3 py-1 text-sm font-medium ring-1 ${tone}`;
    span.textContent = hasPlayer ? 'linked' : (coverage?.active ? coverage.label : detail.selected_identity.match_status);

    return span;
};

const header = (detail) => {
    const identity = detail.selected_identity;
    const player = detail.player;
    const wrapper = el('div', 'border-b border-gray-200 px-6 py-5');
    const row = el('div', 'flex flex-wrap items-start justify-between gap-4');
    const body = el('div');
    const label = player ? 'Player Record' : `${title(identity.provider)} identity`;
    const name = player?.full_name ?? text(identity.display_name, 'Unnamed identity');
    body.append(el('div', 'text-xs font-medium uppercase text-gray-500', label), el('h3', 'mt-1 text-2xl font-semibold text-gray-900', name));

    if (player && detail.current_contract) {
        const contract = detail.current_contract;
        const parts = [
            contract.contract_type,
            contract.contract_length,
            money(contract.contract_value),
            contract.last_season_label,
        ].filter(Boolean);
        body.append(el('div', 'mt-3 text-sm text-gray-700', `Last Contract: ${parts.join(' | ')}`));
    } else if (!player) {
        body.append(el('div', 'mt-2 text-sm text-gray-600', [identity.provider_player_id, identity.provider_slug].filter(Boolean).join(' | ')));
    }

    row.append(body, badge(detail));
    wrapper.append(row);

    return wrapper;
};

const linkedPlayerView = (detail) => {
    const nodes = [
        header(detail),
        section('Player Record', [
            definitionList([
                ['DOB', detail.player.dob],
                ['Position', detail.player.position],
                ['Team', detail.player.team_abbrev],
                ['NHL ID', detail.player.nhl_id],
                ['Status', detail.player.status],
                ['Prospect', detail.player.is_prospect ? 'Yes' : 'No'],
            ]),
        ]),
    ];

    if (detail.linked_sources?.length) {
        nodes.push(section('Linked External Sources', [
            list(detail.linked_sources, 'No linked external sources.', (identity) => identityRow(
                identity,
                el('span', 'shrink-0 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 ring-1 ring-green-200', 'Linked'),
            )),
        ]));
    }

    if (detail.suggested_external_matches?.length) {
        nodes.push(section('Suggested External Records', [
            list(detail.suggested_external_matches, 'No suggested external records.', (identity) => identityRow(
                identity,
                actionForm(detail.actions.link_external_source, 'Link to this player', { external_identity_id: identity.id }),
            )),
        ], `These records look like the same player and can be linked to ${detail.player.full_name}.`));
    }

    appendMatchingSourceSections(nodes, detail);
    appendRawPayload(nodes, detail);

    return nodes;
};

const unlinkedView = (detail) => {
    const identity = detail.selected_identity;
    const nodes = [
        header(detail),
        section('Source Record', [
            definitionList([
                ['Normalized', identity.normalized_name],
                ['Birthdate', identity.birthdate],
                ['Position', identity.position],
                ['Team', identity.team],
                ['Recommendation', detail.recommendation?.confidence !== null && detail.recommendation?.confidence !== undefined
                    ? `${detail.recommendation.confidence}% ${detail.recommendation.status}`
                    : detail.recommendation?.status],
                ['Reason', identity.unmatched_reason?.replaceAll('_', ' ')],
            ], 'md:grid-cols-2'),
        ]),
    ];

    if (detail.coverage?.active) {
        appendMatchingSourceSections(nodes, detail);
    } else {
        nodes.push(section('Suggested Player Matches', [
            list(detail.candidate_players ?? [], 'No suggested player matches.', (player) => playerRow(
                player,
                actionForm(detail.actions.link_player, 'Link', { player_id: player.id }),
            )),
        ], 'Suggestions use normalized names and birthdate context where available.'));
    }

    if (detail.suggested_external_matches?.length) {
        nodes.push(section('Suggested External Records', [
            list(detail.suggested_external_matches, 'No suggested external records.', (identityMatch) => identityRow(
                identityMatch,
                disabledButton('Link after player record'),
            )),
        ], 'These records look like the same player. Link the selected identity to a player record before attaching them.'));
    }

    if (!detail.player && !(detail.candidate_players ?? []).length) {
        nodes.push(createCanonicalSection(detail));
    }

    nodes.push(manualPlayerSearchSection(detail));
    appendRawPayload(nodes, detail);

    return nodes;
};

const appendMatchingSourceSections = (nodes, detail) => {
    if (!detail.coverage?.active) return;

    if (detail.matching_source_identity) {
        nodes.push(section(`Matched ${title(detail.coverage.matching_source)} Identity`, [
            el('div', 'mt-4 border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-950', text(detail.matching_source_identity.display_name, 'Matched identity')),
        ]));
        return;
    }

    nodes.push(section('Matching Source Search', [
        searchForm(detail, 'matching_identity_search', 'matching-source', `search ${detail.coverage.matching_source} identities`, ['matching_identity_search']),
    ]));

    nodes.push(section(`Suggested ${title(detail.coverage.matching_source)} Identities`, [
        list(detail.matching_source_candidates ?? [], 'No suggested matching-source identities.', (identity) => identityRow(
            identity,
            actionForm(detail.actions.link_matching_source, 'Link source', { matching_identity_id: identity.id }),
        )),
    ]));

    if (detail.matching_source_search_results?.length) {
        nodes.push(section('Search Results', [
            list(detail.matching_source_search_results, 'No search results.', (identity) => identityRow(
                identity,
                actionForm(detail.actions.link_matching_source, 'Link source', { matching_identity_id: identity.id }),
            )),
        ]));
    }
};

const createCanonicalSection = (detail) => {
    const form = el('form', 'mt-4 border border-gray-200 px-4 py-4');
    form.method = 'POST';
    form.action = detail.actions.create_canonical;
    form.dataset.playerTriageActionForm = '';
    form.dataset.playerTriageActionLabel = 'Creating...';

    if (detail.suggested_external_matches?.length) {
        const listNode = el('div', 'mt-3 divide-y divide-gray-100 border border-gray-200');
        detail.suggested_external_matches.forEach((identity) => {
            const label = el('label', 'flex cursor-pointer items-start gap-3 px-4 py-3');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'external_identity_ids[]';
            input.value = identity.id;
            input.className = 'mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500';
            const body = el('span');
            body.append(el('span', 'block text-sm font-semibold text-gray-900', text(identity.display_name, 'Unnamed identity')));
            body.append(el('span', 'mt-1 block text-xs text-gray-500', identityMeta(identity)));
            label.append(input, body);
            listNode.append(label);
        });
        form.append(el('div', 'text-sm font-semibold text-gray-900', 'Suggested External Matches'), listNode);
    } else {
        form.append(el('div', 'text-sm text-gray-600', 'No suggested external matches found.'));
    }

    const footer = el('div', 'mt-4 flex flex-wrap items-center gap-3');
    footer.append(actionButton('Create player record'), el('span', 'text-xs text-gray-500', 'Creates a prospect player with no NHL ID and links selected external identities.'));
    form.append(footer);

    return section('Create Player Record', [form], 'Use this when the player is real but absent from NHL API records.');
};

const manualPlayerSearchSection = (detail) => section('Manual Player Search', [
    searchForm(detail, 'player_search', 'canonical-player', 'search by player name or nhl id', ['player_search']),
    list(detail.player_search_results ?? [], '', (player) => playerRow(
        player,
        actionForm(detail.actions.link_player, 'Link', { player_id: player.id }),
    )),
].filter((node) => node.textContent !== ''), null);

const appendRawPayload = (nodes, detail) => {
    const details = document.createElement('details');
    const summary = el('summary', 'cursor-pointer text-sm font-semibold text-gray-900', 'Raw Provider Payload');
    const pre = el('pre', 'mt-3 max-h-80 overflow-auto bg-gray-950 p-4 text-xs text-gray-100', JSON.stringify(detail.selected_identity.raw_payload ?? {}, null, 2));
    details.append(summary, pre);
    nodes.push(section('', [details]));
};

const loading = () => {
    const wrapper = el('div', 'min-h-[72vh] px-6 py-5');
    const pulse = el('div', 'animate-pulse');
    pulse.append(el('div', 'h-4 w-28 rounded bg-gray-200'));
    pulse.append(el('div', 'mt-3 h-8 w-64 max-w-full rounded bg-gray-200'));
    pulse.append(el('div', 'mt-5 h-20 rounded bg-gray-100'));

    const grid = el('div', 'mt-6 grid grid-cols-2 gap-3 md:grid-cols-3');
    for (let index = 0; index < 6; index += 1) {
        const block = el('div');
        block.append(el('div', 'h-3 w-16 rounded bg-gray-200'), el('div', 'mt-2 h-5 rounded bg-gray-100'));
        grid.append(block);
    }

    pulse.append(grid);
    wrapper.append(pulse);

    return wrapper;
};

const error = (message) => {
    const wrapper = el('div', 'px-6 py-12');
    wrapper.append(el('div', 'border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700', message));

    return wrapper;
};

const previewPayload = (identity) => ({
    selected_identity: {
        id: identity.id,
        display_name: identity.display_name,
        normalized_name: identity.normalized_name,
        birthdate: identity.birthdate,
        provider: identity.provider,
        provider_player_id: identity.provider_player_id,
        provider_slug: identity.provider_slug,
        team: identity.team,
        position: identity.position,
        match_status: identity.match_status,
        match_confidence: identity.match_confidence,
        unmatched_reason: identity.unmatched_reason,
        player_id: identity.player_id ?? null,
        raw_payload: {},
    },
    player: null,
    current_contract: null,
    recommendation: identity.recommendation ?? { status: null, confidence: null },
    coverage: identity.coverage ?? { active: false, label: null, matched: false },
    preview: true,
});

/**
 * Browser-owned renderer for the player triage detail panel.
 */
export const createPlayerTriageDetail = (root, options = {}) => {
    const payloadScript = options.payloadScript ?? root.querySelector('[data-player-triage-detail-payload]');
    const mount = options.mount ?? root.querySelector('[data-player-triage-detail]');
    let detail = null;
    let isLoading = false;
    let errorMessage = null;

    if (!mount) {
        return null;
    }

    const load = (payload) => {
        detail = payload;
        isLoading = false;
        errorMessage = null;
        render();
    };

    const preview = (identity) => {
        detail = previewPayload(identity);
        isLoading = true;
        errorMessage = null;
        render();
    };

    const render = () => {
        mount.replaceChildren();

        if (isLoading) {
            if (detail?.preview && detail.selected_identity) {
                mount.append(header(detail), loading());
                return;
            }

            mount.append(loading());
            return;
        }

        if (errorMessage) {
            mount.append(error(errorMessage));
            return;
        }

        if (!detail?.selected_identity) {
            mount.append(el('div', 'px-6 py-12 text-sm text-gray-600', detail?.meta?.empty_message ?? 'Select an identity from the inbox to review match details.'));
            return;
        }

        const nodes = detail.player ? linkedPlayerView(detail) : unlinkedView(detail);
        mount.append(...nodes);
    };

    root.addEventListener('player-triage:detail-loading', () => {
        isLoading = true;
        errorMessage = null;
        render();
    });
    root.addEventListener('player-triage:detail-preview', (event) => {
        if (event.detail?.identity) {
            preview(event.detail.identity);
        }
    });
    root.addEventListener('player-triage:detail-loaded', (event) => load(event.detail));
    root.addEventListener('player-triage:detail-error', (event) => {
        isLoading = false;
        errorMessage = event.detail?.message ?? 'Unable to load player detail.';
        render();
    });

    if (payloadScript?.textContent?.trim()) {
        load(JSON.parse(payloadScript.textContent));
    } else {
        render();
    }

    return {
        load,
        preview,
        render,
        get detail() {
            return detail;
        },
    };
};

export default createPlayerTriageDetail;
