// stats-desktop.js
import { formatStatValue, groupRowsByProspectPosition, isLeagueProspectMode, statValueForKey, teamBg } from "./stats-utils.js";

// === keep your colours exactly as set before ===
export const BORDER_COLOUR_F = "#7CCCF2";
export const BORDER_COLOUR_D = "#FAE919";
export const BORDER_COLOUR_G = "#fecaca";

export const TXT_COLOUR_POS = "#606971";
export const TXT_COLOUR_F = null;
export const TXT_COLOUR_D = null;
export const TXT_COLOUR_G = null;

const posTextColor = (p) => {
    const c = String(p || "").toUpperCase();
    if (c === "F" && TXT_COLOUR_F) return TXT_COLOUR_F;
    if (c === "D" && TXT_COLOUR_D) return TXT_COLOUR_D;
    if (c === "G" && TXT_COLOUR_G) return TXT_COLOUR_G;
    return TXT_COLOUR_POS;
};

const displayPosition = (raw) => {
    const first = String(raw ?? "")
        .split(/[,\s/]+/)
        .find(Boolean)?.trim().toUpperCase() || "";

    return first;
};

// AAV helpers
const isAAVKey = (k = "") =>
    ["aav", "contract_value", "contract_value_num"].includes(
        String(k).toLowerCase()
    );

const formatAAV = (val) => {
    let n = null;
    if (typeof val === "number") n = val;
    else if (typeof val === "string") {
        const s = val.replace(/[$,mM]/g, "");
        const parsed = parseFloat(s);
        if (Number.isFinite(parsed)) n = parsed;
    }
    if (n == null) return "";
    if (n > 1000) n = n / 1e6;
    return `$${n.toFixed(1)}`;
};

const formatDesktopNumber = (value) => {
    if (typeof value === "number" && Number.isFinite(value)) {
        return new Intl.NumberFormat("en-US", {
            maximumFractionDigits: 3,
        }).format(value);
    }

    if (typeof value === "string") {
        const trimmed = value.trim();
        if (/^-?\d+(\.\d+)?$/.test(trimmed)) {
            return new Intl.NumberFormat("en-US", {
                maximumFractionDigits: 3,
            }).format(Number(trimmed));
        }
    }

    return value ?? "";
};

const NON_RANKED_STAT_KEYS = new Set([
    "age",
    "gp",
    "contract",
    "contract_value",
    "contract_value_num",
    "contract_last_year",
    "contract_last_year_num",
    "contract_term",
    "contract_length",
    "contract_type",
]);

const sharedLeaguePlayerStatOrder = (heading) => {
    const key = String(heading?.key ?? "").toLowerCase();
    const label = String(heading?.label ?? "").trim().toLowerCase();

    if (key === "age") return 10;
    if (["salary", "fantasy_salary", "fantrax_salary", "contract", "contract_value", "contract_value_num"].includes(key)) return 20;
    if (key === "contract_type" || (key === "contract_last_year" && label === "type")) return 30;
    if (["contract_last_year", "contract_last_year_num", "contract_term", "contract_length"].includes(key)) return 40;
    if (key === "gp") return 50;

    return 90;
};

const SHARED_LEAGUE_PLAYER_STAT_KEYS = new Set([
    "age",
    "salary",
    "fantasy_salary",
    "fantrax_salary",
    "contract",
    "contract_value",
    "contract_value_num",
    "contract_last_year",
    "contract_last_year_num",
    "contract_term",
    "contract_length",
    "contract_type",
    "gp",
]);

const numericStatValue = (value) => {
    if (typeof value === "number") {
        return Number.isFinite(value) ? value : null;
    }

    if (typeof value === "string") {
        const trimmed = value.trim();
        if (trimmed === "") return null;

        const normalized = trimmed.replace(/[$,%]/g, "");
        if (/^-?\d+(\.\d+)?$/.test(normalized)) {
            const parsed = Number(normalized);

            return Number.isFinite(parsed) ? parsed : null;
        }
    }

    return null;
};

const buildSelectChevron = () => {
    const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    icon.setAttribute("viewBox", "0 0 20 20");
    icon.setAttribute("fill", "currentColor");
    icon.setAttribute("aria-hidden", "true");
    icon.classList.add("pointer-events-none", "col-start-1", "row-start-1", "mr-3", "size-4", "self-center", "justify-self-end", "text-gray-400");

    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("fill-rule", "evenodd");
    path.setAttribute("clip-rule", "evenodd");
    path.setAttribute("d", "M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z");
    icon.appendChild(path);

    return icon;
};

const wrapNativeSelect = (select) => {
    const wrapper = document.createElement("div");
    wrapper.className = "relative z-50 grid grid-cols-1";
    select.classList.add("appearance-none", "col-start-1", "row-start-1", "pr-9");
    wrapper.appendChild(select);
    wrapper.appendChild(buildSelectChevron());

    return wrapper;
};

const buildDropdownButtonChevron = () => {
    const icon = buildSelectChevron();
    icon.className.baseVal = "";
    icon.classList.add("pointer-events-none", "ml-auto", "size-4", "shrink-0", "text-gray-400");

    return icon;
};

const isRankableStatKey = (key) => {
    const normalized = String(key ?? "").toLowerCase();

    return normalized !== "" && !NON_RANKED_STAT_KEYS.has(normalized);
};

const buildCompetitionRankMaps = (rows, headings) => {
    const rankMaps = new Map();
    const sourceRows = Array.isArray(rows) ? rows : [];

    (Array.isArray(headings) ? headings : []).forEach((heading) => {
        const key = String(heading?.key ?? "");
        if (!isRankableStatKey(key)) return;

        const values = sourceRows
            .map((row, index) => ({
                index,
                value: numericStatValue(statValueForKey(row, key)),
            }))
            .filter((entry) => entry.value !== null)
            .sort((a, b) => b.value - a.value);

        const ranks = new Map();
        let previousValue = null;
        let previousRank = 0;

        values.forEach((entry, index) => {
            const rank = previousValue !== null && entry.value === previousValue
                ? previousRank
                : index + 1;

            ranks.set(entry.index, rank);
            previousValue = entry.value;
            previousRank = rank;
        });

        rankMaps.set(key, ranks);
    });

    return rankMaps;
};

const playerInitials = (name = "") => String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("") || "?";

const buildPlayerAvatar = (row, name) => {
    const avatarUrl = row?.avatar_url || row?.head_shot_url;
    const wrap = document.createElement("span");
    wrap.className =
        "inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-1 ring-gray-200";

    if (!avatarUrl) {
        wrap.textContent = playerInitials(name);
        return wrap;
    }

    const img = document.createElement("img");
    img.src = avatarUrl;
    img.alt = "";
    img.loading = "lazy";
    img.className = "h-7 w-7 rounded-full object-cover";
    img.addEventListener("error", () => {
        img.remove();
        wrap.textContent = playerInitials(name);
    });
    wrap.appendChild(img);

    return wrap;
};

const buildOwnerAvatar = (avatarUrl, name = "") => {
    const wrap = document.createElement("span");
    wrap.className =
        "inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-1 ring-gray-200";

    if (!avatarUrl) {
        wrap.textContent = playerInitials(name);
        return wrap;
    }

    const img = document.createElement("img");
    img.src = avatarUrl;
    img.alt = "";
    img.loading = "lazy";
    img.className = "h-7 w-7 rounded-full object-cover";
    img.addEventListener("error", () => {
        img.remove();
        wrap.textContent = playerInitials(name);
    });
    wrap.appendChild(img);

    return wrap;
};

const isOwnerColumn = (key) => String(key) === "__owner";

const rosterOrderValue = (row, key, fallback = 999) => {
    const value = Number(row?.[key]);

    return Number.isFinite(value) ? value : fallback;
};

const sortByRosterOrder = (rows) => [...rows].sort((a, b) => {
    const group = rosterOrderValue(a, "roster_group_sort_order") - rosterOrderValue(b, "roster_group_sort_order");
    if (group !== 0) return group;

    const slot = rosterOrderValue(a, "roster_sort_order") - rosterOrderValue(b, "roster_sort_order");
    if (slot !== 0) return slot;

    const status = rosterOrderValue(a, "roster_status_sort_order") - rosterOrderValue(b, "roster_status_sort_order");
    if (status !== 0) return status;

    return String(a?.name ?? "").localeCompare(String(b?.name ?? ""));
});

const rowIsGoalie = (row) => row?.is_goalie === true
    || row?.is_goalie === 1
    || row?.is_goalie === "1"
    || String(row?.pos_type ?? "").toUpperCase() === "G"
    || String(row?.pos ?? "").toUpperCase() === "G";

const rowIsReserve = (row) => {
    const slot = String(row?.roster_slot ?? "").trim().toUpperCase();
    const status = String(row?.roster_status ?? "").trim().toLowerCase();

    return ["BN", "BEN", "BENCH", "RES", "RESERVE"].includes(slot)
        || ["bench", "reserve"].includes(status);
};

const rowIsIr = (row) => {
    const slot = String(row?.roster_slot ?? "").trim().toUpperCase();
    const status = String(row?.roster_status ?? "").trim().toLowerCase();

    return ["IR", "IR+"].includes(slot) || status === "ir";
};

const goalieRosterSortValue = (row) => {
    if (row?.roster_group === "minor") return 4000 + rosterOrderValue(row, "roster_sort_order", 999);
    if (rowIsIr(row)) return 3000 + rosterOrderValue(row, "roster_sort_order", 999);
    if (rowIsReserve(row)) return 2000 + rosterOrderValue(row, "roster_sort_order", 999);

    return 1000 + rosterOrderValue(row, "roster_sort_order", 999);
};

const sortGoalieRosterRows = (rows) => [...rows].sort((a, b) => {
    const goalieOrder = goalieRosterSortValue(a) - goalieRosterSortValue(b);
    if (goalieOrder !== 0) return goalieOrder;

    return String(a?.name ?? "").localeCompare(String(b?.name ?? ""));
});

const rosterRowClass = (row, allowRosterColors = false) => {
    if (!allowRosterColors) return "hover:bg-gray-50";

    const slot = String(row?.roster_slot ?? "").trim().toUpperCase();
    const status = String(row?.roster_status ?? "").trim().toLowerCase();

    if (row?.roster_group === "minor") return "bg-blue-50 hover:bg-blue-100";
    if (status === "ir" || ["IR", "IR+"].includes(slot)) return "bg-red-100 hover:bg-red-100";
    if (["bench", "reserve"].includes(status) || ["BN", "BEN", "BENCH", "RES", "RESERVE"].includes(slot)) {
        return "bg-yellow-100 hover:bg-yellow-100";
    }

    return "hover:bg-gray-50";
};

// Persist filters per-container across re-renders
const desktopState = new WeakMap();

const splitLeagueOwnerHeadings = (headings, useRosterSlotColumn = false) => {
    const srcHeadings = Array.isArray(headings) ? [...headings] : [];
    const typeOrigIdx = srcHeadings.findIndex((h) =>
        ["type", "pos_type"].includes(String(h?.key || "").toLowerCase())
    );
    const ordered = [
        { key: "__rk", label: "Rk" },
        ...(typeOrigIdx > -1 ? [srcHeadings[typeOrigIdx]] : []),
        ...srcHeadings.filter((_, i) => i !== typeOrigIdx),
    ].map((heading) => {
        const key = String(heading?.key ?? "").toLowerCase();

        return useRosterSlotColumn && ["type", "pos_type"].includes(key)
            ? { ...heading, label: "Slot" }
            : heading;
    });
    const fixedKeys = new Set(["__rk", "type", "pos_type", "player", "name", "team", "league"]);
    const left = ordered.filter((heading) => fixedKeys.has(String(heading?.key ?? "").toLowerCase()));
    const stats = ordered.filter((heading) => !fixedKeys.has(String(heading?.key ?? "").toLowerCase()));

    return { left, stats };
};

const uniqueHeadings = (headings) => {
    const seen = new Set();

    return (Array.isArray(headings) ? headings : []).filter((heading) => {
        const key = String(heading?.key ?? "");
        if (key === "" || seen.has(key)) return false;

        seen.add(key);
        return true;
    });
};

const sharedLeaguePlayerStatHeadings = (headings, { includeGp = false } = {}) => uniqueHeadings([
    ...(Array.isArray(headings) ? headings : []),
    ...(includeGp ? [{ key: "gp", label: "GP" }] : []),
])
    .filter((heading) => SHARED_LEAGUE_PLAYER_STAT_KEYS.has(String(heading?.key ?? "").toLowerCase()))
    .map((heading) => {
        const key = String(heading?.key ?? "").toLowerCase();
        const label = String(heading?.label ?? "").trim().toLowerCase();

        if (key === "contract_type" && label === "contract type") {
            return { ...heading, label: "Type" };
        }

        if (key === "contract_last_year" && label === "term end") {
            return { ...heading, label: "Term" };
        }

        return heading;
    })
    .sort((a, b) => sharedLeaguePlayerStatOrder(a) - sharedLeaguePlayerStatOrder(b));

const statHeadingsForGroup = (settings, group, fallback = [], shared = []) => {
    const groupHeadings = Array.isArray(settings?.columnGroups?.[group])
        ? settings.columnGroups[group]
        : fallback;

    return uniqueHeadings([...shared, ...groupHeadings]);
};

const headingWidth = (key, settings = {}) => {
    const normalized = String(key ?? "").toLowerCase();

    if (normalized === "__rk") return "44px";
    if (["fantrax", "yahoo"].includes(settings?.leaguePlatform) && ["type", "pos_type"].includes(normalized)) return "52px";
    if (["type", "pos_type"].includes(normalized)) return "36px";
    if (normalized === "team") return "76px";
    if (normalized === "league") return "72px";
    if (/^(player|name)$/i.test(normalized)) return "190px";

    return "72px";
};

const renderLeagueOwnerStatsDesktop = (
    container,
    data,
    headings,
    settings,
    onSortChange
) => {
    const prev = desktopState.get(container) || {};
    const state = {
        nameFilter: typeof prev.nameFilter === "string" ? prev.nameFilter : "",
        fantasyTeamFilter: typeof prev.fantasyTeamFilter === "string" ? prev.fantasyTeamFilter : "",
        leagueFilter: typeof prev.leagueFilter === "string" ? prev.leagueFilter : "",
        showRanks: prev.showRanks === true,
    };
    desktopState.set(container, state);

    const isRosterSlotLeague = ["fantrax", "yahoo"].includes(settings?.leaguePlatform);
    const isGoalieFilterActive = settings?.goalieFilterActive === true;
    const isProspectMode = isLeagueProspectMode(settings);
    const isFreeAgentFantasyFilter = () => state.fantasyTeamFilter.trim() === "__free_agents";
    const hasSelectedFantasyTeam = () => state.fantasyTeamFilter.trim() !== "" && !isFreeAgentFantasyFilter();
    const selectedPositionFilters = [
        ...(Array.isArray(settings?.selectedPosTypes) ? settings.selectedPosTypes : []),
        ...(Array.isArray(settings?.selectedPos) ? settings.selectedPos : []),
    ].filter(Boolean);
    const hasExplicitPositionFilter = () => selectedPositionFilters.length > 0 && settings?.leagueAutoSkaterFilter !== true;
    const hasRosterSlotRows = () => (Array.isArray(data) ? data : []).some((row) =>
        row?.roster_slot != null || row?.roster_sort_order != null || row?.league_roster_placeholder === true
    );
    const useRosterSlotColumn = () => isRosterSlotLeague && !isProspectMode && hasSelectedFantasyTeam();
    const isRosterSlotSortActive = () => isRosterSlotLeague && !isProspectMode && hasRosterSlotRows() && settings.leagueUserSortActive !== true;
    const { left, stats } = splitLeagueOwnerHeadings(headings, useRosterSlotColumn());
    const sharedStats = sharedLeaguePlayerStatHeadings(stats, { includeGp: isRosterSlotLeague });
    const skaterStats = statHeadingsForGroup(settings, "skater", stats, sharedStats);
    const goalieStats = statHeadingsForGroup(settings, "goalie", stats, sharedStats);
    const shouldSplitSelectedTeamRoster = () => isRosterSlotLeague
        && !isProspectMode
        && hasSelectedFantasyTeam()
        && !hasExplicitPositionFilter();
    const shouldAutoSkaterOnly = () => isRosterSlotLeague
        && !isProspectMode
        && !hasSelectedFantasyTeam()
        && !hasExplicitPositionFilter();
    const visibleStats = () => shouldSplitSelectedTeamRoster() || shouldAutoSkaterOnly()
        ? skaterStats
        : stats;
    const rosterSlotHeadingKey = () => left.find((heading) =>
        ["type", "pos_type"].includes(String(heading?.key ?? "").toLowerCase())
    )?.key ?? "pos_type";
    const isDefaultProspectSort = () => isProspectMode && settings.leagueUserSortActive !== true;
    const activeSortKey = () => {
        if (isDefaultProspectSort()) return rosterSlotHeadingKey();

        return isRosterSlotSortActive() ? rosterSlotHeadingKey() : settings.sortKey;
    };
    const leftGridCols = left.map((heading) => headingWidth(heading?.key, settings)).join(" ");
    const statGridCols = visibleStats().map((heading) => headingWidth(heading?.key, settings)).join(" ") || "72px";
    const goalieStatGridCols = goalieStats.map((heading) => headingWidth(heading?.key, settings)).join(" ") || statGridCols;
    const rankMaps = buildCompetitionRankMaps(data, uniqueHeadings([...stats, ...skaterStats, ...goalieStats]));

    const leagues = Array.from(
        new Set(
            (Array.isArray(data) ? data : [])
                .map((p) => (p?.league ?? "").toString().trim())
                .filter(Boolean)
        )
    ).sort((a, b) => a.localeCompare(b));
    const fantasyTeamsByName = new Map();
    (Array.isArray(data) ? data : []).forEach((row) => {
        const name = String(row?.fantasy_team_name ?? "").trim();

        if (name !== "" && !fantasyTeamsByName.has(name)) {
            fantasyTeamsByName.set(name, {
                name,
                avatarUrl: String(row?.fantasy_team_avatar_url ?? "").trim(),
                isUserTeam: row?.fantasy_team_is_user_team === true,
            });
        }
    });
    const fantasyTeams = [...fantasyTeamsByName.values()].sort((a, b) => {
        if (a.isUserTeam !== b.isUserTeam) return a.isUserTeam ? -1 : 1;

        return a.name.localeCompare(b.name);
    });
    const userFantasyTeam = fantasyTeams.find((team) => team.isUserTeam) || null;
    if (prev.leagueFantasyTeamInitialized !== true && state.fantasyTeamFilter === "" && userFantasyTeam) {
        state.fantasyTeamFilter = userFantasyTeam.name;
    }
    state.leagueFantasyTeamInitialized = true;
    desktopState.set(container, state);

    const notifyFantasyTeamFilterChange = () => {
        if (typeof settings?.onLeagueFantasyTeamFilterChange !== "function") return;

        settings.onLeagueFantasyTeamFilterChange({
            teamSpecific: hasSelectedFantasyTeam(),
            fantasyTeam: state.fantasyTeamFilter,
        });
    };

    container.innerHTML = "";

    const wrapper = document.createElement("div");
    wrapper.className = "w-full overflow-visible bg-white shadow rounded-lg border border-gray-200";

    const controls = document.createElement("div");
    controls.className = "sticky top-0 z-50 bg-gray-50 border-b px-4 py-4 flex items-center gap-3";

    const nameInput = document.createElement("input");
    nameInput.type = "text";
    nameInput.placeholder = "Filter by name…";
    nameInput.value = state.nameFilter;
    nameInput.className =
        "flex-1 max-w-md rounded-md border border-gray-300 px-3 py-2 text-sm " +
        "focus:outline-none focus:ring-2 focus:ring-indigo-500";
    controls.appendChild(nameInput);

    const fantasyTeamPicker = document.createElement("div");
    fantasyTeamPicker.className = "relative z-50 w-56";
    const fantasyTeamButton = document.createElement("button");
    fantasyTeamButton.type = "button";
    fantasyTeamButton.className =
        "flex h-10 w-full items-center gap-2 rounded-md border border-gray-300 bg-white px-2 text-left text-sm " +
        "focus:outline-none focus:ring-2 focus:ring-indigo-500";
    const selectedFantasyTeam = () => fantasyTeams.find((team) => team.name === state.fantasyTeamFilter) || null;
    const renderFantasyTeamButton = () => {
        const selected = selectedFantasyTeam();
        fantasyTeamButton.innerHTML = "";

        if (selected?.avatarUrl) {
            fantasyTeamButton.appendChild(buildOwnerAvatar(selected.avatarUrl, selected.name));
        }

        const label = document.createElement("span");
        label.className = "min-w-0 flex-1 truncate";
        label.textContent = isFreeAgentFantasyFilter()
            ? "Free Agents"
            : (selected?.name || "All Players");
        fantasyTeamButton.appendChild(label);
        fantasyTeamButton.appendChild(buildDropdownButtonChevron());
    };
    const fantasyTeamMenu = document.createElement("div");
    fantasyTeamMenu.className =
        "absolute left-0 top-11 z-50 hidden max-h-72 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg";
    const addFantasyTeamOption = (team) => {
        const option = document.createElement("button");
        option.type = "button";
        option.className = "flex w-full items-center gap-2 px-2 py-2 text-left hover:bg-gray-50";

        if (team.avatarUrl) {
            option.appendChild(buildOwnerAvatar(team.avatarUrl, team.name));
        } else {
            const spacer = document.createElement("span");
            spacer.className = "h-7 w-7 shrink-0";
            option.appendChild(spacer);
        }

        const label = document.createElement("span");
        label.className = "min-w-0 truncate";
        label.textContent = team.label || team.name || "All Players";
        option.appendChild(label);
        option.addEventListener("click", () => {
            state.fantasyTeamFilter = team.value ?? team.name ?? "";
            desktopState.set(container, state);
            fantasyTeamMenu.classList.add("hidden");
            renderFantasyTeamButton();
            syncOwnerPaneVisibility();
            syncRosterSlotHeader();
            notifyFantasyTeamFilterChange();
            renderRows();
        });
        fantasyTeamMenu.appendChild(option);
    };
    addFantasyTeamOption({ name: "", value: "", label: "All Players", avatarUrl: "" });
    addFantasyTeamOption({ name: "Free Agents", value: "__free_agents", label: "Free Agents", avatarUrl: "" });
    fantasyTeams.forEach(addFantasyTeamOption);
    fantasyTeamButton.addEventListener("click", () => {
        fantasyTeamMenu.classList.toggle("hidden");
    });
    renderFantasyTeamButton();
    fantasyTeamPicker.appendChild(fantasyTeamButton);
    fantasyTeamPicker.appendChild(fantasyTeamMenu);
    controls.appendChild(fantasyTeamPicker);

    const ranksButton = document.createElement("button");
    ranksButton.type = "button";
    ranksButton.className = state.showRanks
        ? "h-10 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        : "h-10 rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500";
    ranksButton.textContent = "Ranks";
    ranksButton.title = "Show stat ranks among all players in this view";
    ranksButton.setAttribute("aria-pressed", state.showRanks ? "true" : "false");
    ranksButton.addEventListener("click", () => {
        state.showRanks = !state.showRanks;
        desktopState.set(container, state);
        ranksButton.className = state.showRanks
            ? "h-10 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            : "h-10 rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500";
        ranksButton.setAttribute("aria-pressed", state.showRanks ? "true" : "false");
        renderRows();
    });
    controls.appendChild(ranksButton);

    let leagueSelect = null;
    if (leagues.length > 0) {
        leagueSelect = document.createElement("select");
        leagueSelect.className =
            "w-40 rounded-md border border-gray-300 px-2 py-2 text-sm " +
            "bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500";
        const optAllLeagues = document.createElement("option");
        optAllLeagues.value = "";
        optAllLeagues.textContent = "All Leagues";
        leagueSelect.appendChild(optAllLeagues);
        leagues.forEach((league) => {
            const opt = document.createElement("option");
            opt.value = league;
            opt.textContent = league;
            leagueSelect.appendChild(opt);
        });
        leagueSelect.value = state.leagueFilter;
        controls.appendChild(wrapNativeSelect(leagueSelect));
    }

    const headerTable = document.createElement("div");
    headerTable.className = "sticky top-0 z-30 grid min-w-0";
    const table = document.createElement("div");
    table.className = "grid min-w-0";
    const syncOwnerPaneVisibility = () => {
        const columns = hasSelectedFantasyTeam()
            ? "minmax(0, 418px) minmax(0, 1fr)"
            : "minmax(0, 418px) minmax(0, 1fr) 190px";
        table.style.gridTemplateColumns = columns;
        headerTable.style.gridTemplateColumns = columns;
        ownerPane.classList.toggle("hidden", hasSelectedFantasyTeam());
        ownerHeader.classList.toggle("hidden", hasSelectedFantasyTeam());
    };

    const leftPane = document.createElement("div");
    leftPane.className = "min-w-0 bg-white";
    const statsViewport = document.createElement("div");
    statsViewport.className = "relative min-w-0";
    const statsScroll = document.createElement("div");
    statsScroll.className = "min-w-0 overflow-x-auto";
    const statsPane = document.createElement("div");
    statsPane.className = "min-w-max";
    const ownerPane = document.createElement("div");
    ownerPane.className = "min-w-0 bg-white";
    const leftHint = document.createElement("div");
    leftHint.className =
        "pointer-events-none absolute left-1 top-2 z-20 hidden rounded-full bg-white/95 p-0.5 text-gray-400 shadow-sm ring-1 ring-gray-200/70";
    leftHint.innerHTML = `
        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
            <path fill-rule="evenodd" d="M12.78 15.53a.75.75 0 0 1-1.06 0l-5-5a.75.75 0 0 1 0-1.06l5-5a.75.75 0 1 1 1.06 1.06L8.31 10l4.47 4.47a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" />
        </svg>
    `;
    const rightHint = document.createElement("div");
    rightHint.className =
        "pointer-events-none absolute right-1 top-2 z-20 hidden rounded-full bg-white/95 p-0.5 text-gray-400 shadow-sm ring-1 ring-gray-200/70";
    rightHint.innerHTML = `
        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.22 4.47a.75.75 0 0 1 1.06 0l5 5a.75.75 0 0 1 0 1.06l-5 5a.75.75 0 0 1-1.06-1.06L11.69 10 7.22 5.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
    `;

    const leftHeader = document.createElement("div");
    leftHeader.className = "grid h-9 bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-700";
    leftHeader.style.gridTemplateColumns = leftGridCols;

    const statsHeaderViewport = document.createElement("div");
    statsHeaderViewport.className = "min-w-0 overflow-hidden bg-gray-100";
    const statsHeaderPane = document.createElement("div");
    statsHeaderPane.className = "min-w-max";
    const statsHeader = document.createElement("div");
    statsHeader.className = "grid h-9 bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-700";
    statsHeader.style.gridTemplateColumns = statGridCols;

    const ownerHeader = document.createElement("div");
    ownerHeader.className = "flex h-9 items-center justify-end bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-700";

    const leftBody = document.createElement("div");
    const statsBody = document.createElement("div");
    const ownerBody = document.createElement("div");
    const typeHeaderCells = [];

    const syncHeaderHorizontalScroll = () => {
        statsHeaderPane.style.transform = `translateX(${-statsScroll.scrollLeft}px)`;
    };

    const sortableHeader = (heading, className) => {
        const key = heading?.key;
        const th = document.createElement("div");
        th.className = className;
        th.textContent = heading?.label ?? "";

        if (["type", "pos_type"].includes(String(key).toLowerCase())) {
            typeHeaderCells.push(th);
        }

        if (key !== "__rk") {
            th.classList.add("cursor-pointer");
            if (activeSortKey() === key) {
                const arrow = document.createElement("span");
                arrow.textContent = isRosterSlotSortActive() || isDefaultProspectSort()
                    ? "↓"
                    : (settings.sortDirection === "asc" ? "↑" : "↓");
                th.appendChild(arrow);
                th.classList.add("text-gray-900");
            }
            th.addEventListener("click", () => {
                if (["type", "pos_type"].includes(String(key).toLowerCase()) && useRosterSlotColumn()) {
                    onSortChange?.({
                        sortKey: settings.defaultSort ?? settings.sortKey ?? key,
                        sortDirection: settings.defaultSortDirection ?? "desc",
                        leagueUserSortActive: false,
                    });
                    return;
                }

                const same = settings.sortKey === key && settings.leagueUserSortActive === true;
                onSortChange?.({
                    sortKey: key,
                    sortDirection: same && settings.sortDirection === "desc" ? "asc" : "desc",
                    leagueUserSortActive: true,
                });
            });
        }

        return th;
    };

    const syncRosterSlotHeader = () => {
        typeHeaderCells.forEach((cell) => {
            if (cell.firstChild) {
                cell.firstChild.textContent = useRosterSlotColumn() ? "Slot" : "Type";

                return;
            }

            cell.textContent = useRosterSlotColumn() ? "Slot" : "Type";
        });
    };

    left.forEach((heading) => {
        leftHeader.appendChild(sortableHeader(
            heading,
            "flex items-center justify-center gap-1 whitespace-nowrap overflow-hidden text-ellipsis"
        ));
    });
    let activeStatsHeaderGroup = "";
    const renderStatsHeader = (headerStats, headerGridCols, group = "") => {
        if (activeStatsHeaderGroup === group) return;

        activeStatsHeaderGroup = group;
        statsHeader.innerHTML = "";
        statsHeader.style.gridTemplateColumns = headerGridCols;
        headerStats.forEach((heading) => {
            statsHeader.appendChild(sortableHeader(
                heading,
                "flex items-center justify-center gap-1 whitespace-nowrap overflow-hidden text-ellipsis"
            ));
        });
        syncHeaderHorizontalScroll();
    };
    renderStatsHeader(visibleStats(), statGridCols, shouldSplitSelectedTeamRoster() ? "skater" : "default");

    const buildPosShape = (raw, rawType) => {
        const v = displayPosition(raw);
        const shapeType = displayPosition(rawType);
        const wrap = document.createElement("div");
        wrap.className = "h-8 w-full flex items-center justify-center";
        const box = document.createElement("div");
        box.className = "h-5 w-5 flex items-center justify-center";
        wrap.appendChild(box);

        const inner = document.createElement("div");
        inner.className = "h-full w-full flex items-center justify-center font-semibold text-[9px]";
        inner.style.color = posTextColor(shapeType);

        if (shapeType === "F") {
            inner.className += " rounded-[6px] border transform scale-110";
            inner.style.borderColor = BORDER_COLOUR_F;
            inner.textContent = v || "F";
        } else if (shapeType === "D") {
            inner.className += " rounded-[6px] border transform scale-110";
            inner.style.borderColor = BORDER_COLOUR_D;
            inner.textContent = v || "D";
        } else if (shapeType === "G") {
            inner.className += " rounded-full border-2";
            inner.style.borderColor = BORDER_COLOUR_G;
            inner.textContent = v || "G";
        } else {
            inner.className += " rounded border-2";
            inner.style.borderColor = "#e5e7eb";
            inner.textContent = v || "—";
        }

        box.appendChild(inner);
        return wrap;
    };

    const applyFilters = (rows) => {
        const nameQ = state.nameFilter.trim().toLowerCase();
        const fantasyTeamQ = state.fantasyTeamFilter.trim().toUpperCase();
        const leagueQ = state.leagueFilter.trim().toUpperCase();

        const filtered = rows.map((row, sourceIndex) => ({ row, sourceIndex })).filter(({ row }) => {
            const isRosterOnly = row?.league_roster_only === true;
            const isRosterPlaceholder = row?.league_roster_placeholder === true;
            const isGoalie = rowIsGoalie(row);
            const name = String(row?.name ?? "").toLowerCase();
            const hitName = !nameQ || name.includes(nameQ);
            const rowFantasyTeam = String(row?.fantasy_team_name ?? "").trim();
            const hitFantasyTeam = isFreeAgentFantasyFilter()
                ? rowFantasyTeam === ""
                : (!fantasyTeamQ || rowFantasyTeam.toUpperCase() === fantasyTeamQ);
            const hitLeague = !leagueQ || String(row?.league ?? "").toUpperCase() === leagueQ;
            const hitAutoSkaterOnly = !shouldAutoSkaterOnly() || !isGoalie;

            const canShowRosterOnly = !isRosterOnly
                || hasSelectedFantasyTeam()
                || (isGoalieFilterActive && isGoalie && !isRosterPlaceholder);

            return canShowRosterOnly && hitName && hitFantasyTeam && hitLeague && hitAutoSkaterOnly;
        });

        const rowsWithRankSource = filtered.map((entry) => ({
            ...entry.row,
            __rankSourceIndex: entry.sourceIndex,
        }));

        return isRosterSlotSortActive()
            ? sortByRosterOrder(rowsWithRankSource)
            : rowsWithRankSource;
    };

    const renderLeftCell = (row, heading, idx, i) => {
        const key = heading?.key;
        const cell = document.createElement("div");

        if (key === "__rk") {
            cell.className = "flex items-center justify-center text-gray-500";
            cell.textContent = String(idx + 1);
        } else if (["type", "pos_type"].includes(String(key).toLowerCase())) {
            cell.className = "flex items-center justify-center text-gray-500";

            if (useRosterSlotColumn()) {
                const slot = String(row?.roster_slot ?? "").trim();
                cell.textContent = slot;
            } else {
                const val = row.pos ?? row.position ?? row[key] ?? row.pos_type ?? row.type;
                const typeVal = row.pos_type ?? row.type;
                cell.appendChild(buildPosShape(val, typeVal));
            }
        } else if (String(key).toLowerCase() === "team") {
            if (row?.league_roster_placeholder === true || String(row?.team ?? "").trim() === "") {
                cell.className = "flex items-center justify-center text-gray-500";

                return cell;
            }

            const badge = document.createElement("div");
            badge.className =
                "inline-flex h-7 px-3 rounded-md items-center justify-center " +
                "text-white font-semibold text-xs tracking-wide shadow-sm";
            badge.style.background = teamBg(row?.team);
            badge.textContent = row?.team ?? "—";
            cell.className = "flex items-center justify-center text-gray-500";
            cell.appendChild(badge);
        } else if (String(key).toLowerCase() === "league") {
            const rawVal = statValueForKey(row, key);
            const val = formatStatValue(key, rawVal);
            cell.className = "flex items-center justify-center whitespace-nowrap text-xs font-semibold text-gray-500";
            cell.textContent = val ?? "";
        } else if (/^(player|name)$/i.test(String(key))) {
            if (row?.league_roster_placeholder === true) {
                const slot = String(row?.roster_slot ?? "").trim();
                cell.className = "flex min-w-0 items-center justify-start text-xs font-medium text-gray-400";
                cell.textContent = slot ? `Open ${slot}` : "Open slot";

                return cell;
            }

            const rawVal = statValueForKey(row, key);
            const val = formatStatValue(key, rawVal);
            cell.className = "flex min-w-0 items-center justify-start gap-2 whitespace-nowrap overflow-hidden pr-2 text-gray-700";
            cell.title = String(val ?? "");
            const name = document.createElement("span");
            name.className = "min-w-0 overflow-hidden text-ellipsis";
            name.textContent = val ?? "";
            cell.appendChild(buildPlayerAvatar(row, val));
            cell.appendChild(name);
        } else {
            const rawVal = statValueForKey(row, key);
            const val = formatStatValue(key, rawVal);
            cell.className = "flex items-center justify-center whitespace-nowrap text-gray-500";
            cell.textContent = formatDesktopNumber(val);
        }

        return cell;
    };

    const statCellValue = (row, key) => {
        return statValueForKey(row, key);
    };

    const renderStatCell = (row, heading, rowGroup = "") => {
        const key = heading?.key;
        const cell = document.createElement("div");
        const rawVal = statCellValue(row, key, rowGroup);
        const val = formatStatValue(key, rawVal);
        const common = "flex items-center justify-center whitespace-nowrap tabular-nums text-[11px] leading-5 text-gray-500";
        cell.className = activeSortKey() === key ? `${common} font-semibold` : common;

        if (state.showRanks && isRankableStatKey(key)) {
            const sourceIndex = Number(row?.__rankSourceIndex);
            const rank = Number.isInteger(sourceIndex) ? rankMaps.get(String(key))?.get(sourceIndex) : null;
            cell.textContent = rank ? String(rank) : "-";

            return cell;
        }

        cell.textContent = isAAVKey(key) ? formatAAV(rawVal) : formatDesktopNumber(val);

        return cell;
    };

    const renderRows = () => {
        leftBody.innerHTML = "";
        statsBody.innerHTML = "";
        ownerBody.innerHTML = "";
        let goalieHeaderRow = null;

        const appendGroupSeparator = (label, tone = "blue") => {
            const separatorClass = tone === "gray"
                ? "border-t bg-gray-100 text-gray-700"
                : "border-t bg-blue-100 text-blue-700";
            const emptySeparatorClass = tone === "gray"
                ? "border-t bg-gray-100"
                : "border-t bg-blue-100";
            const leftRow = document.createElement("div");
            leftRow.className = `grid h-8 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide ${separatorClass}`;
            leftRow.style.gridTemplateColumns = leftGridCols;
            const leftLabel = document.createElement("div");
            leftLabel.style.gridColumn = "1 / -1";
            leftLabel.textContent = label;
            leftRow.appendChild(leftLabel);
            leftBody.appendChild(leftRow);

            const statsRow = document.createElement("div");
            statsRow.className = `grid h-8 px-4 py-1.5 ${emptySeparatorClass}`;
            statsRow.style.gridTemplateColumns = statGridCols;
            statsBody.appendChild(statsRow);

            const ownerRow = document.createElement("div");
            ownerRow.className = `h-8 px-4 py-1.5 ${emptySeparatorClass}`;
            ownerBody.appendChild(ownerRow);
        };

        const appendGoalieHeader = () => {
            const rowClass = "border-t bg-gray-100 text-gray-700";

            const leftRow = document.createElement("div");
            leftRow.className = `grid h-8 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide ${rowClass}`;
            leftRow.style.gridTemplateColumns = leftGridCols;
            const leftLabel = document.createElement("div");
            leftLabel.style.gridColumn = "1 / -1";
            leftLabel.textContent = "Goalies";
            leftRow.appendChild(leftLabel);
            leftBody.appendChild(leftRow);
            goalieHeaderRow = leftRow;

            const statsRow = document.createElement("div");
            statsRow.className = `grid h-8 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide ${rowClass}`;
            statsRow.style.gridTemplateColumns = goalieStatGridCols;
            goalieStats.forEach((heading) => {
                const cell = document.createElement("div");
                cell.className = "flex items-center justify-center gap-1 whitespace-nowrap overflow-hidden text-ellipsis";
                cell.textContent = heading?.label ?? "";
                statsRow.appendChild(cell);
            });
            statsBody.appendChild(statsRow);

            const ownerRow = document.createElement("div");
            ownerRow.className = `h-8 px-4 py-1.5 ${rowClass}`;
            ownerBody.appendChild(ownerRow);
        };

        const rows = applyFilters(data);
        const renderPlayerRow = (row, idx, rowStats = visibleStats(), rowStatGridCols = statGridCols, rowGroup = "") => {
            const allowRosterColors = hasSelectedFantasyTeam() && !isProspectMode;

            const leftRow = document.createElement("div");
            leftRow.className = `grid h-12 border-t px-4 py-2 text-sm transition-colors ${rosterRowClass(row, allowRosterColors)}`;
            leftRow.style.gridTemplateColumns = leftGridCols;
            left.forEach((heading, i) => leftRow.appendChild(renderLeftCell(row, heading, idx, i)));
            leftBody.appendChild(leftRow);

            const statsRow = document.createElement("div");
            statsRow.className = `grid h-12 border-t px-4 py-2 text-sm transition-colors ${rosterRowClass(row, allowRosterColors)}`;
            statsRow.style.gridTemplateColumns = rowStatGridCols;
            rowStats.forEach((heading) => statsRow.appendChild(renderStatCell(row, heading, rowGroup)));
            statsBody.appendChild(statsRow);

            const ownerRow = document.createElement("div");
            ownerRow.className = `flex h-12 min-w-0 items-center justify-end gap-2 border-t px-4 py-2 text-right text-xs text-gray-600 transition-colors ${rosterRowClass(row, allowRosterColors)}`;
            const ownerName = String(row?.fantasy_team_name ?? "").trim();
            const ownerAvatarUrl = String(row?.fantasy_team_avatar_url ?? "").trim();

            if (ownerName !== "") {
                const name = document.createElement("span");
                name.className = "min-w-0 truncate font-medium";
                name.textContent = ownerName;
                name.title = ownerName;
                ownerRow.appendChild(name);
                ownerRow.appendChild(buildOwnerAvatar(ownerAvatarUrl, ownerName));
            }

            ownerBody.appendChild(ownerRow);
        };

        if (isDefaultProspectSort()) {
            let rowIndex = 0;
            groupRowsByProspectPosition(rows).forEach((group) => {
                group.rows.forEach((row) => {
                    renderPlayerRow(row, rowIndex);
                    rowIndex += 1;
                });
            });
        } else if (shouldSplitSelectedTeamRoster()) {
            let rowIndex = 0;
            const skaterRows = sortByRosterOrder(rows.filter((row) => !rowIsGoalie(row)));
            const goalieRows = sortGoalieRosterRows(rows.filter((row) => rowIsGoalie(row)));

            skaterRows.forEach((row, idx) => {
                renderPlayerRow(row, rowIndex, skaterStats, statGridCols);
                rowIndex += 1;
            });

            if (goalieRows.length > 0) {
                appendGoalieHeader();
            }

            goalieRows.forEach((row) => {
                renderPlayerRow(row, rowIndex, goalieStats, goalieStatGridCols, "goalie");
                rowIndex += 1;
            });
        } else {
            rows.forEach((row, idx) => {
                if (
                    !isProspectMode
                    && hasSelectedFantasyTeam()
                    && row?.roster_group === "minor"
                    && rows?.[idx - 1]?.roster_group !== "minor"
                ) {
                    appendGroupSeparator("Minor League");
                }

                renderPlayerRow(row, idx);
            });
        }

        const updateActiveStatsHeader = () => {
            if (!shouldSplitSelectedTeamRoster() || !goalieHeaderRow) {
                renderStatsHeader(visibleStats(), statGridCols, shouldSplitSelectedTeamRoster() ? "skater" : "default");
                return;
            }

            const headerBottom = statsHeader.getBoundingClientRect().bottom;
            const goalieTop = goalieHeaderRow.getBoundingClientRect().top;
            if (goalieTop <= headerBottom + 1) {
                renderStatsHeader(goalieStats, goalieStatGridCols, "goalie");
            } else {
                renderStatsHeader(skaterStats, statGridCols, "skater");
            }
        };

        if (container.__diqStatsHeaderScrollHandler) {
            document.removeEventListener("scroll", container.__diqStatsHeaderScrollHandler, true);
        }
        container.__diqStatsHeaderScrollHandler = updateActiveStatsHeader;
        document.addEventListener("scroll", updateActiveStatsHeader, true);
        updateActiveStatsHeader();
        updateScrollHints();
    };

    const updateScrollHints = () => {
        const maxScroll = Math.max(0, statsScroll.scrollWidth - statsScroll.clientWidth);
        const hasOverflow = maxScroll > 1;
        const isAtLeftEdge = hasOverflow && statsScroll.scrollLeft <= 1;
        const hasHiddenLeftContent = hasOverflow && statsScroll.scrollLeft > 1;

        leftHint.classList.toggle("hidden", !hasHiddenLeftContent);
        rightHint.classList.toggle("hidden", !isAtLeftEdge);
        syncHeaderHorizontalScroll();
    };

    nameInput.addEventListener("input", () => {
        state.nameFilter = nameInput.value || "";
        desktopState.set(container, state);
        renderRows();
    });
    leagueSelect?.addEventListener("change", () => {
        state.leagueFilter = leagueSelect.value || "";
        desktopState.set(container, state);
        renderRows();
    });

    leftPane.appendChild(leftBody);
    statsHeaderPane.appendChild(statsHeader);
    statsHeaderViewport.appendChild(statsHeaderPane);
    statsPane.appendChild(statsBody);
    statsScroll.appendChild(statsPane);
    statsViewport.appendChild(statsScroll);
    statsViewport.appendChild(leftHint);
    statsViewport.appendChild(rightHint);
    ownerPane.appendChild(ownerBody);
    headerTable.appendChild(leftHeader);
    headerTable.appendChild(statsHeaderViewport);
    headerTable.appendChild(ownerHeader);
    table.appendChild(leftPane);
    table.appendChild(statsViewport);
    table.appendChild(ownerPane);
    wrapper.appendChild(controls);
    wrapper.appendChild(headerTable);
    wrapper.appendChild(table);
    container.appendChild(wrapper);

    const updateStickyHeaderOffset = () => {
        headerTable.style.top = `${controls.offsetHeight || 0}px`;
    };

    syncOwnerPaneVisibility();
    statsScroll.addEventListener("scroll", updateScrollHints, { passive: true });
    window.addEventListener("resize", updateScrollHints, { passive: true });
    window.addEventListener("resize", updateStickyHeaderOffset, { passive: true });

    renderRows();
    syncRosterSlotHeader();
    notifyFantasyTeamFilterChange();
    updateStickyHeaderOffset();
    window.requestAnimationFrame(updateScrollHints);

    const observer = new MutationObserver(() => {
        if (!container.contains(wrapper)) {
            window.removeEventListener("resize", updateScrollHints);
            window.removeEventListener("resize", updateStickyHeaderOffset);
            statsScroll.removeEventListener("scroll", updateScrollHints);
            if (container.__diqStatsHeaderScrollHandler) {
                document.removeEventListener("scroll", container.__diqStatsHeaderScrollHandler, true);
            }
            observer.disconnect();
        }
    });
    observer.observe(container, { childList: true });
};

export function renderStatsDesktop(
    container,
    data,
    headings,
    settings,
    onSortChange
) {
    if (settings?.ownerColumn === true) {
        renderLeagueOwnerStatsDesktop(container, data, headings, settings, onSortChange);
        return;
    }

    const showOwnerColumn = settings?.ownerColumn === true;

    // ----- state (persisted via WeakMap) -----
    const prev = desktopState.get(container) || {};
    const state = {
        nameFilter: typeof prev.nameFilter === "string" ? prev.nameFilter : "",
        teamFilter: typeof prev.teamFilter === "string" ? prev.teamFilter : "",
        leagueFilter: typeof prev.leagueFilter === "string" ? prev.leagueFilter : "",
    };
    desktopState.set(container, state);

    // --- Build display headings with Rk first, Type second ---
    const srcHeadings = Array.isArray(headings) ? [...headings] : [];
    const typeOrigIdx = srcHeadings.findIndex((h) =>
        ["type", "pos_type"].includes(String(h?.key || "").toLowerCase())
    );
    const displayHeadings = [
        { key: "__rk", label: "Rk" },
        ...(typeOrigIdx > -1 ? [srcHeadings[typeOrigIdx]] : []),
        ...srcHeadings.filter((_, i) => i !== typeOrigIdx),
        ...(showOwnerColumn ? [{ key: "__owner", label: "" }] : []),
    ];

    // Column sizing
    const rkIdx = displayHeadings.findIndex((h) => h.key === "__rk");
    const teamIdx = displayHeadings.findIndex(
        (h) => String(h.key).toLowerCase() === "team"
    );
    const leagueIdx = displayHeadings.findIndex(
        (h) => String(h.key).toLowerCase() === "league"
    );
    const typeIdx = displayHeadings.findIndex((h) =>
        ["type", "pos_type"].includes(String(h.key).toLowerCase())
    );
    const playerIdx = displayHeadings.findIndex((h) =>
        /^(player|name)$/i.test(String(h.key))
    );
    const ownerIdx = displayHeadings.findIndex((h) => isOwnerColumn(h.key));

    const gridCols = displayHeadings
        .map((_, i) => {
            if (i === rkIdx) return "44px";
            if (i === typeIdx) return "36px";
            if (i === teamIdx) return "76px";
            if (i === leagueIdx) return "72px";
            if (i === playerIdx) return "190px";
            if (i === ownerIdx) return "180px";
            return "72px";
        })
        .join(" ");

    // Unique teams from data
    const teams = Array.from(
        new Set(
            (Array.isArray(data) ? data : [])
                .map((p) => (p?.team ?? "").toString().trim())
                .filter(Boolean)
        )
    ).sort((a, b) => a.localeCompare(b));
    const leagues = Array.from(
        new Set(
            (Array.isArray(data) ? data : [])
                .map((p) => (p?.league ?? "").toString().trim())
                .filter(Boolean)
        )
    ).sort((a, b) => a.localeCompare(b));

    // ----- DOM build -----
    container.innerHTML = "";
    const scrollWrap = document.createElement("div");
    scrollWrap.className = "w-full overflow-x-auto pb-2";

    const wrapper = document.createElement("div");
    wrapper.className =
        "min-w-max bg-white shadow rounded-lg border border-gray-200 relative";

    // Controls bar (sticky)
    const controls = document.createElement("div");
    controls.className =
        "sticky top-0 z-50 bg-gray-50 border-b px-4 py-4 flex items-center gap-3";

    // Name filter input
    const nameInput = document.createElement("input");
    nameInput.type = "text";
    nameInput.placeholder = "Filter by name…";
    nameInput.value = state.nameFilter;
    nameInput.className =
        "flex-1 max-w-md rounded-md border border-gray-300 px-3 py-2 text-sm " +
        "focus:outline-none focus:ring-2 focus:ring-indigo-500";
    controls.appendChild(nameInput);

    // Team dropdown
    const teamSelect = document.createElement("select");
    teamSelect.className =
        "w-40 rounded-md border border-gray-300 px-2 py-2 text-sm " +
        "bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500";
    const optAll = document.createElement("option");
    optAll.value = "";
    optAll.textContent = "All Teams";
    teamSelect.appendChild(optAll);
    teams.forEach((t) => {
        const opt = document.createElement("option");
        opt.value = t;
        opt.textContent = t;
        teamSelect.appendChild(opt);
    });
    teamSelect.value = state.teamFilter;
    controls.appendChild(wrapNativeSelect(teamSelect));

    let leagueSelect = null;
    if (leagues.length > 0) {
        leagueSelect = document.createElement("select");
        leagueSelect.className =
            "w-40 rounded-md border border-gray-300 px-2 py-2 text-sm " +
            "bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500";
        const optAllLeagues = document.createElement("option");
        optAllLeagues.value = "";
        optAllLeagues.textContent = "All Leagues";
        leagueSelect.appendChild(optAllLeagues);
        leagues.forEach((league) => {
            const opt = document.createElement("option");
            opt.value = league;
            opt.textContent = league;
            leagueSelect.appendChild(opt);
        });
        leagueSelect.value = state.leagueFilter;
        controls.appendChild(wrapNativeSelect(leagueSelect));
    }

    // Columns header (original height, sticky under controls)
    const headerRow = document.createElement("div");
    headerRow.className =
        "grid text-xs font-semibold bg-gray-100 text-gray-700 px-4 py-2 border-b border-gray-200";
    headerRow.style.position = "sticky";
    headerRow.style.zIndex = "20";
    headerRow.style.gridTemplateColumns = gridCols;

    // dynamic sticky offset so it always sits below the controls bar
    const updateHeaderOffset = () => {
        const h = controls.offsetHeight || 0;
        headerRow.style.top = `${h}px`;
    };

    displayHeadings.forEach(({ key, label }) => {
        const th = document.createElement("div");
        th.className = isOwnerColumn(key)
            ? "sticky right-0 z-30 flex items-center justify-end gap-1 whitespace-nowrap bg-gray-100 pl-3 text-right"
            : "flex items-center justify-center gap-1 whitespace-nowrap overflow-hidden text-ellipsis";
        th.textContent = label;

        if (key !== "__rk" && !isOwnerColumn(key)) {
            th.classList.add("cursor-pointer");
            if (settings.sortKey === key) {
                const arrow = document.createElement("span");
                arrow.textContent =
                    settings.sortDirection === "asc" ? "↑" : "↓";
                th.appendChild(arrow);
                th.classList.add("text-gray-900");
            }
            th.addEventListener("click", () => {
                const same = settings.sortKey === key;
                onSortChange?.({
                    sortKey: key,
                    sortDirection:
                        same && settings.sortDirection === "desc"
                            ? "asc"
                            : "desc",
                });
            });
        }

        headerRow.appendChild(th);
    });

    // Table body wrapper (for re-rendering rows only)
    const bodyWrap = document.createElement("div");

    // POS shape
    const buildPosShape = (raw, rawType) => {
        const v = displayPosition(raw);
        const shapeType = displayPosition(rawType);
        const wrap = document.createElement("div");
        wrap.className = "h-8 w-full flex items-center justify-center";
        const box = document.createElement("div");
        box.className = "h-5 w-5 flex items-center justify-center";
        wrap.appendChild(box);

        const inner = document.createElement("div");
        inner.className =
            "h-full w-full flex items-center justify-center font-semibold text-[9px]";
        inner.style.color = posTextColor(shapeType);

        if (shapeType === "F") {
            inner.className += " rounded-[6px] border transform scale-110";
            inner.style.borderColor = BORDER_COLOUR_F;
            inner.textContent = v || "F";
        } else if (shapeType === "D") {
            inner.className += " rounded-[6px] border transform scale-110";
            inner.style.borderColor = BORDER_COLOUR_D;
            inner.textContent = v || "D";
        } else if (shapeType === "G") {
            inner.className += " rounded-full border-2";
            inner.style.borderColor = BORDER_COLOUR_G;
            inner.textContent = v || "G";
        } else {
            inner.className += " rounded border-2";
            inner.style.borderColor = "#e5e7eb";
            inner.textContent = v || "—";
        }

        box.appendChild(inner);
        return wrap;
    };

    // ----- filtering + rows render -----
    const applyFilters = (rows) => {
        const nameQ = state.nameFilter.trim().toLowerCase();
        const teamQ = state.teamFilter.trim().toUpperCase();
        const leagueQ = state.leagueFilter.trim().toUpperCase();
        return rows.filter((r) => {
            const name = String(r?.name ?? "").toLowerCase();
            const hitName = !nameQ || name.includes(nameQ);
            const hitTeam =
                !teamQ || String(r?.team ?? "").toUpperCase() === teamQ;
            const hitLeague =
                !leagueQ || String(r?.league ?? "").toUpperCase() === leagueQ;
            return hitName && hitTeam && hitLeague;
        });
    };

    const renderRows = () => {
        bodyWrap.innerHTML = "";
        const rows = applyFilters(data);

        rows.forEach((row, idx) => {
            const tr = document.createElement("div");
            tr.className =
                "grid border-t px-4 py-2 text-sm hover:bg-gray-50 transition-colors";
            tr.style.gridTemplateColumns = gridCols;

            displayHeadings.forEach(({ key }, i) => {
                const cell = document.createElement("div");

                if (key === "__rk") {
                    cell.className =
                        "flex items-center justify-center text-gray-500";
                    cell.textContent = String(idx + 1);
                } else if (isOwnerColumn(key)) {
                    const ownerName = String(row?.fantasy_team_name ?? "").trim();
                    const ownerAvatarUrl = String(row?.fantasy_team_avatar_url ?? "").trim();

                    cell.className =
                        "sticky right-0 z-10 flex min-w-0 items-center justify-end gap-2 border-l border-gray-100 bg-white pl-3 text-right text-xs text-gray-600";

                    if (ownerName !== "") {
                        const name = document.createElement("span");
                        name.className = "min-w-0 truncate font-medium";
                        name.textContent = ownerName;
                        name.title = ownerName;
                        cell.appendChild(buildOwnerAvatar(ownerAvatarUrl, ownerName));
                        cell.appendChild(name);
                    }
                } else if (i === teamIdx) {
                    const badge = document.createElement("div");
                    badge.className =
                        "inline-flex h-7 px-3 rounded-md items-center justify-center " +
                        "text-white font-semibold text-xs tracking-wide shadow-sm";
                    badge.style.background = teamBg(row?.team);
                    badge.textContent = row?.team ?? "—";
                    cell.className =
                        "flex items-center justify-center text-gray-500";
                    cell.appendChild(badge);
                } else if (i === leagueIdx) {
                    const rawVal = statValueForKey(row, key);
                    const val = formatStatValue(key, rawVal);
                    cell.className =
                        "flex items-center justify-center whitespace-nowrap text-xs font-semibold text-gray-500";
                    cell.textContent = val ?? "";
                } else if (i === typeIdx) {
                    const val = row.pos ?? row.position ?? row[key] ?? row.pos_type ?? row.type;
                    const typeVal = row.pos_type ?? row.type;
                    cell.className =
                        "flex items-center justify-center text-gray-500";
                    cell.appendChild(buildPosShape(val, typeVal));
                } else if (isAAVKey(key)) {
                    const raw = statValueForKey(row, key);
                    cell.className =
                        "flex items-center justify-center whitespace-nowrap text-sm text-gray-500";
                    cell.textContent = formatAAV(raw);
                } else if (i === playerIdx) {
                    const rawVal = statValueForKey(row, key);
                    const val = formatStatValue(key, rawVal);
                    cell.className =
                        "flex min-w-0 items-center justify-start gap-2 whitespace-nowrap overflow-hidden pr-2 text-gray-700";
                    cell.title = String(val ?? "");
                    const name = document.createElement("span");
                    name.className = "min-w-0 overflow-hidden text-ellipsis";
                    name.textContent = val ?? "";
                    cell.appendChild(buildPlayerAvatar(row, val));
                    cell.appendChild(name);
                } else {
                    const rawVal = statValueForKey(row, key);
                    const val = formatStatValue(key, rawVal);
                    const common =
                        "flex items-center justify-center whitespace-nowrap tabular-nums text-[11px] leading-5 text-gray-500";
                    cell.className =
                        settings.sortKey === key
                            ? `${common} font-semibold`
                            : common;
                    cell.textContent = formatDesktopNumber(val);
                }

                tr.appendChild(cell);
            });

            bodyWrap.appendChild(tr);
        });
    };

    // listeners
    nameInput.addEventListener("input", () => {
        state.nameFilter = nameInput.value || "";
        desktopState.set(container, state);
        renderRows();
    });
    teamSelect.addEventListener("change", () => {
        state.teamFilter = teamSelect.value || "";
        desktopState.set(container, state);
        renderRows();
    });
    leagueSelect?.addEventListener("change", () => {
        state.leagueFilter = leagueSelect.value || "";
        desktopState.set(container, state);
        renderRows();
    });

    // mount
    wrapper.appendChild(controls);
    wrapper.appendChild(headerRow);
    wrapper.appendChild(bodyWrap);
    scrollWrap.appendChild(wrapper);
    container.appendChild(scrollWrap);

    // ensure correct sticky offset for header (on mount + resize)
    const onResize = () => updateHeaderOffset();
    updateHeaderOffset();
    window.addEventListener("resize", onResize, { passive: true });

    // initial rows
    renderRows();

    // cleanup on outside rerender
    const observer = new MutationObserver(() => {
        if (!container.contains(wrapper)) {
            window.removeEventListener("resize", onResize);
            observer.disconnect();
        }
    });
    observer.observe(container, { childList: true });
}
