// stats-desktop.js
import { formatStatValue, teamBg } from "./stats-utils.js";

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

// Persist filters per-container across re-renders
const desktopState = new WeakMap();

export function renderStatsDesktop(
    container,
    data,
    headings,
    settings,
    onSortChange
) {
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

    const gridCols = displayHeadings
        .map((_, i) => {
            if (i === rkIdx) return "44px";
            if (i === typeIdx) return "36px";
            if (i === teamIdx) return "76px";
            if (i === leagueIdx) return "72px";
            if (i === playerIdx) return "190px";
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
        "sticky top-0 z-10 bg-gray-50 border-b px-4 py-4 flex items-center gap-3";

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
    controls.appendChild(teamSelect);

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
        controls.appendChild(leagueSelect);
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
        th.className = "select-none flex items-center justify-center gap-1 whitespace-nowrap overflow-hidden text-ellipsis";
        th.textContent = label;

        if (key !== "__rk") {
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
                    const rawVal = row.stats?.[key] ?? row[key];
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
                    const raw = row.stats?.[key] ?? row[key];
                    cell.className =
                        "flex items-center justify-center whitespace-nowrap text-sm text-gray-500";
                    cell.textContent = formatAAV(raw);
                } else if (i === playerIdx) {
                    const rawVal = row.stats?.[key] ?? row[key];
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
                    const rawVal = row.stats?.[key] ?? row[key];
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
