import { formatLocalDateTime, localizeDateTimes } from './starting-goalies.js';

const statusClasses = {
    not_reported: 'border-gray-200 bg-gray-50 text-gray-600',
    reported: 'border-green-200 bg-green-50 text-green-700',
    corroborated: 'border-sky-200 bg-sky-50 text-sky-700',
    official: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    strongly_corroborated: 'border-emerald-200 bg-emerald-50 text-emerald-700',
};

export function shiftDate(value, days) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) {
        return '';
    }

    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

export function formatGameDate(value, locale = undefined) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) {
        return '';
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return new Intl.DateTimeFormat(locale, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }).format(date);
}

export function gamePayloadUrl(baseUrl, date) {
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('date', date);

    return url.toString();
}

export function gamePageUrl(date) {
    const url = new URL('/games', window.location.origin);
    url.searchParams.set('date', date);

    return `${url.pathname}${url.search}`;
}

function statusLabel(status) {
    return String(status ?? 'not_reported')
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function createStatusBadge(status) {
    const badge = document.createElement('span');
    badge.className = `inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses[status] ?? statusClasses.not_reported}`;
    badge.textContent = statusLabel(status);

    return badge;
}

function createTeamSection(team, sideLabel) {
    const lineup = team?.lineup ?? null;
    const status = lineup?.evidence_status ?? 'not_reported';
    const section = document.createElement('section');
    section.className = 'flex items-center gap-4 px-5 py-4';

    const logo = document.createElement('div');
    logo.className = 'flex size-12 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50';
    if (team?.team_logo) {
        const image = document.createElement('img');
        image.src = team.team_logo;
        image.alt = `${team.team_abbrev ?? 'Team'} logo`;
        image.className = 'size-9 object-contain';
        logo.append(image);
    } else {
        const abbreviation = document.createElement('span');
        abbreviation.className = 'text-xs font-semibold text-gray-600';
        abbreviation.textContent = team?.team_abbrev ?? 'TBD';
        logo.append(abbreviation);
    }
    section.append(logo);

    const body = document.createElement('div');
    body.className = 'min-w-0 flex-1';
    const teamLabel = document.createElement('p');
    teamLabel.className = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
    teamLabel.textContent = `${sideLabel} · ${team?.team_abbrev ?? 'TBD'}`;
    body.append(teamLabel);

    const statusRow = document.createElement('div');
    statusRow.className = 'mt-1 flex flex-wrap items-center gap-2';
    statusRow.append(createStatusBadge(status));
    if (lineup) {
        const sourceCount = document.createElement('span');
        sourceCount.className = 'text-xs text-gray-500';
        sourceCount.textContent = `${lineup.source_count} ${Number(lineup.source_count) === 1 ? 'source' : 'sources'}`;
        statusRow.append(sourceCount);
    }
    body.append(statusRow);

    if (lineup?.last_observed_at) {
        const updated = document.createElement('p');
        updated.className = 'mt-2 text-xs text-gray-500';
        updated.append('Updated ');
        const time = document.createElement('time');
        time.dateTime = lineup.last_observed_at;
        time.dataset.localDatetime = '';
        time.textContent = formatLocalDateTime(lineup.last_observed_at);
        updated.append(time);
        body.append(updated);
    }

    section.append(body);

    if (team?.starting_goalie) {
        const goalie = document.createElement('div');
        goalie.className = 'ml-auto flex shrink-0 items-center gap-3';
        const identity = document.createElement('div');
        identity.className = 'text-right';
        const name = document.createElement('p');
        name.className = 'max-w-36 truncate text-sm font-semibold text-gray-950';
        name.textContent = String(team.starting_goalie.name ?? team.starting_goalie.nhl_player_id);
        identity.append(name);
        const goalieStatus = document.createElement('span');
        const status = team.starting_goalie.status ?? 'projected';
        const badgeClass = status === 'confirmed'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : status === 'expected'
                ? 'border-sky-200 bg-sky-50 text-sky-700'
                : 'border-gray-200 bg-gray-50 text-gray-600';
        goalieStatus.className = `mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${badgeClass}`;
        goalieStatus.textContent = statusLabel(status);
        identity.append(goalieStatus);
        goalie.append(identity);

        const avatar = document.createElement('div');
        avatar.className = 'flex size-12 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100';
        if (team.starting_goalie.avatar_url) {
            const image = document.createElement('img');
            image.src = team.starting_goalie.avatar_url;
            image.alt = name.textContent;
            image.loading = 'lazy';
            image.className = 'size-full object-cover';
            avatar.append(image);
        } else {
            const fallback = document.createElement('span');
            fallback.className = 'text-xs font-semibold text-gray-500';
            fallback.textContent = 'G';
            avatar.append(fallback);
        }
        goalie.append(avatar);
        section.append(goalie);
    }

    if (team?.score !== null && team?.score !== undefined) {
        const score = document.createElement('span');
        score.className = 'shrink-0 text-2xl font-semibold tabular-nums text-gray-950';
        score.textContent = String(team.score);
        section.append(score);
    }

    return section;
}

export function createGameCard(game) {
    const article = document.createElement('article');
    article.className = 'overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm';

    const header = document.createElement('header');
    header.className = 'border-b border-gray-100 px-5 py-4';
    const headerRow = document.createElement('div');
    headerRow.className = 'flex items-start justify-between gap-4';
    const headingBody = document.createElement('div');
    const heading = document.createElement('h2');
    heading.className = 'text-lg font-semibold text-gray-950';
    heading.textContent = `${game?.away?.team_abbrev ?? 'TBD'} at ${game?.home?.team_abbrev ?? 'TBD'}`;
    headingBody.append(heading);
    const start = document.createElement('p');
    start.className = 'mt-1 text-sm text-gray-600';
    if (game?.start_time_utc) {
        const time = document.createElement('time');
        time.dateTime = game.start_time_utc;
        time.dataset.localDatetime = '';
        time.textContent = formatLocalDateTime(game.start_time_utc);
        start.append(time);
    } else {
        start.textContent = 'Start time unavailable';
    }
    headingBody.append(start);
    headerRow.append(headingBody);
    const meta = document.createElement('div');
    meta.className = 'flex shrink-0 flex-col items-end gap-2';
    if (game?.game_state_label) {
        const state = document.createElement('span');
        const stateClass = game.game_state === 'FINAL'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : ['FUT', 'PRE'].includes(game.game_state)
                ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                : 'border-red-200 bg-red-50 text-red-700';
        state.className = `inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${stateClass}`;
        state.textContent = game.game_state_label;
        meta.append(state);
    }
    const gameId = document.createElement('span');
    gameId.className = 'text-xs font-medium text-gray-400';
    gameId.textContent = `#${game.nhl_game_id}`;
    meta.append(gameId);
    headerRow.append(meta);
    header.append(headerRow);
    article.append(header);

    const teams = document.createElement('div');
    teams.className = 'divide-y divide-gray-100';
    teams.append(createTeamSection(game.away, 'Away'));
    teams.append(createTeamSection(game.home, 'Home'));
    article.append(teams);

    const footer = document.createElement('footer');
    footer.className = 'border-t border-gray-100 bg-gray-50 px-5 py-3 text-right';
    const link = document.createElement('a');
    link.href = `/games/${game.nhl_game_id}`;
    link.className = 'text-sm font-semibold text-indigo-600 hover:text-indigo-500';
    link.textContent = 'View current lineups →';
    footer.append(link);
    article.append(footer);

    return article;
}

export function renderGames(container, games) {
    container.replaceChildren();
    if (!Array.isArray(games) || games.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'col-span-full rounded-lg border border-gray-200 bg-white px-4 py-10 text-center';
        const message = document.createElement('p');
        message.className = 'text-sm font-medium text-gray-900';
        message.textContent = 'No NHL games are scheduled for this date.';
        empty.append(message);
        container.append(empty);

        return;
    }

    games.forEach((game) => container.append(createGameCard(game)));
    localizeDateTimes(container);
}

export function initializeGamesPage(root = document.querySelector('[data-games-index-page]'), fetcher = window.fetch) {
    if (!root) {
        return null;
    }

    const input = root.querySelector('[data-games-date]');
    const previous = root.querySelector('[data-games-previous]');
    const next = root.querySelector('[data-games-next]');
    const description = root.querySelector('[data-games-description]');
    const status = root.querySelector('[data-games-status]');
    const games = root.querySelector('[data-games-list]');
    let currentDate = input.value;
    let requestController = null;

    const setLoading = (loading) => {
        input.disabled = loading;
        previous.disabled = loading;
        next.disabled = loading;
        root.setAttribute('aria-busy', String(loading));
    };

    const loadDate = async (date) => {
        if (!date || date === currentDate && root.getAttribute('aria-busy') === 'true') {
            return;
        }

        requestController?.abort();
        requestController = new AbortController();
        const activeController = requestController;
        setLoading(true);
        status.textContent = 'Loading games…';
        input.value = date;

        try {
            const response = await fetcher(gamePayloadUrl(root.dataset.payloadUrl, date), {
                headers: { Accept: 'application/json' },
                signal: activeController.signal,
            });
            if (!response.ok) {
                throw new Error('Could not load games.');
            }

            const payload = await response.json();
            currentDate = payload?.meta?.date ?? date;
            input.value = currentDate;
            description.textContent = `Current anticipated lineups for ${formatGameDate(currentDate)}.`;
            renderGames(games, payload?.games ?? []);
            window.history.replaceState(window.history.state, '', gamePageUrl(currentDate));
            status.textContent = `${payload?.meta?.count ?? 0} games loaded.`;
        } catch (error) {
            if (error?.name !== 'AbortError') {
                input.value = currentDate;
                status.textContent = 'Games could not be loaded. Please try again.';
            }
        } finally {
            if (requestController === activeController) {
                setLoading(false);
            }
        }
    };

    previous.addEventListener('click', () => loadDate(shiftDate(input.value, -1)));
    next.addEventListener('click', () => loadDate(shiftDate(input.value, 1)));
    input.addEventListener('change', () => loadDate(input.value));

    return { loadDate };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeGamesPage(), { once: true });
} else {
    initializeGamesPage();
}
