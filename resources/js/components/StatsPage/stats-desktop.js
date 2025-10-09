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
    const typeIdx = displayHeadings.findIndex((h) =>
        ["type", "pos_type"].includes(String(h.key).toLowerCase())
    );
    const playerIdx = displayHeadings.findIndex((h) =>
        /^(player|name)$/i.test(String(h.key))
    );

    const gridCols = displayHeadings
        .map((_, i) => {
            if (i === rkIdx) return "48px";
            if (i === typeIdx) return "56px";
            if (i === teamIdx) return "92px";
            if (i === playerIdx) return "minmax(260px,2fr)";
            return "minmax(0,1fr)";
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

    // ----- DOM build -----
    container.innerHTML = "";
    const wrapper = document.createElement("div");
    wrapper.className =
        "min-w-full bg-white shadow rounded-lg border border-gray-200 relative";

    // Controls bar (sticky)
    const controls = document.createElement("div");
    controls.className =
        "sticky top-0 z-30 bg-gray-50 border-b px-4 py-4 flex items-center gap-3";

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
        th.className = "select-none flex items-center justify-center gap-1";
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
    const buildPosShape = (raw) => {
        const v = String(raw ?? "")
            .trim()
            .toUpperCase();
        const wrap = document.createElement("div");
        wrap.className = "h-10 w-full flex items-center justify-center";
        const box = document.createElement("div");
        box.className = "h-8 w-8 flex items-center justify-center";
        wrap.appendChild(box);

        if (v === "F") {
            const svg = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "svg"
            );
            svg.setAttribute("viewBox", "0 0 100 100");
            svg.setAttribute("width", "100%");
            svg.setAttribute("height", "100%");

            const poly = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "polygon"
            );
            poly.setAttribute("points", "50,3 3,97 97,97");
            poly.setAttribute("fill", "none");
            poly.setAttribute("stroke", BORDER_COLOUR_F);
            poly.setAttribute("stroke-width", "2");
            poly.setAttribute("stroke-linejoin", "round");

            const txt = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "text"
            );
            txt.setAttribute("x", "50");
            txt.setAttribute("y", "66");
            txt.setAttribute("text-anchor", "middle");
            txt.setAttribute("dominant-baseline", "middle");
            txt.setAttribute("fill", posTextColor("F"));
            txt.setAttribute("font-size", "32");
            txt.setAttribute("font-weight", "700");
            txt.textContent = "F";

            svg.appendChild(poly);
            svg.appendChild(txt);
            box.appendChild(svg);
            return wrap;
        }

        const inner = document.createElement("div");
        inner.className =
            "h-full w-full flex items-center justify-center border-2 font-semibold text-[12px]";
        inner.style.color = posTextColor(v);

        if (v === "D") {
            inner.className += " rounded-[6px] transform scale-110";
            inner.style.borderColor = BORDER_COLOUR_D;
            inner.textContent = "D";
        } else if (v === "G") {
            inner.className += " rounded-full";
            inner.style.borderColor = BORDER_COLOUR_G;
            inner.textContent = "G";
        } else {
            inner.className += " rounded";
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
        return rows.filter((r) => {
            const name = String(r?.name ?? "").toLowerCase();
            const hitName = !nameQ || name.includes(nameQ);
            const hitTeam =
                !teamQ || String(r?.team ?? "").toUpperCase() === teamQ;
            return hitName && hitTeam;
        });
    };

    const renderRows = () => {
        bodyWrap.innerHTML = "";
        const rows = applyFilters(data);

        rows.forEach((row, idx) => {
            const tr = document.createElement("div");
            tr.className =
                "grid border-t px-4 py-3 text-sm hover:bg-gray-50 transition-colors";
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
                        "inline-flex h-8 px-3 rounded-md items-center justify-center " +
                        "text-white font-semibold text-xs tracking-wide shadow-sm";
                    badge.style.background = teamBg(row?.team);
                    badge.textContent = row?.team ?? "—";
                    cell.className =
                        "flex items-center justify-center text-gray-500";
                    cell.appendChild(badge);
                } else if (i === typeIdx) {
                    const val = row[key] ?? row.pos_type ?? row.type;
                    cell.className =
                        "flex items-center justify-center text-gray-500";
                    cell.appendChild(buildPosShape(val));
                } else if (isAAVKey(key)) {
                    const raw = row.stats?.[key] ?? row[key];
                    cell.className =
                        "flex items-center justify-center text-sm text-gray-500";
                    cell.textContent = formatAAV(raw);
                } else {
                    const rawVal = row.stats?.[key] ?? row[key];
                    const val = formatStatValue(key, rawVal);
                    const common =
                        "flex items-center justify-center text-gray-500";
                    cell.className =
                        settings.sortKey === key
                            ? `${common} font-semibold`
                            : `${common} text-[13px]`;
                    cell.textContent = val ?? "";
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

    // mount
    wrapper.appendChild(controls);
    wrapper.appendChild(headerRow);
    wrapper.appendChild(bodyWrap);
    container.appendChild(wrapper);

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
