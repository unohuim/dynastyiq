// resources/js/components/LeaguesHubLayout.js
function mount(root) {
    if (!root) return;
    if (root.dataset.leaguesHubMounted === "true") return;

    root.dataset.leaguesHubMounted = "true";
    let inFlight;
    const syncCounts = new Map();
    const progressStates = new Map();
    const progressTimers = new Map();
    const completionTimers = new Map();
    const progressStartColor = [79, 70, 229];
    const progressEndColor = [163, 230, 53];

    function list() {
        return root.querySelector("#leagueList");
    }

    function main() {
        return root.querySelector("#leagueMain");
    }

    function notify(type, message) {
        if (window.toast?.[type]) {
            window.toast[type](message);
            return;
        }

        if (window.toast?.show) {
            window.toast.show(message, { type });
            return;
        }

        window.dispatchEvent(new CustomEvent("toast", { detail: { type, message } }));
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
    }

    function setActive(link) {
        const currentList = list();

        if (!currentList) return;
        currentList.querySelectorAll("a.league-item").forEach((el) => {
            el.setAttribute("aria-current", "false");
            el.classList.remove("ring-2", "ring-indigo-200", "bg-slate-50");
        });
        link.setAttribute("aria-current", "page");
        link.classList.add("ring-2", "ring-indigo-200", "bg-slate-50");
        clearCompletedProgress(link.dataset.leagueId);
    }

    function leagueLink(platformLeagueId) {
        const currentList = list();

        if (!currentList) return null;

        return currentList.querySelector(
            `a.league-item[data-league-id="${platformLeagueId}"]`
        );
    }

    function progressElements(platformLeagueId) {
        const link = leagueLink(platformLeagueId);

        return {
            track: link?.querySelector("[data-league-sync-progress]") || null,
            bar: link?.querySelector("[data-league-sync-progress-bar]") || null,
        };
    }

    function progressColor(percent) {
        const blend = percent <= 50 ? 0 : Math.min((percent - 50) / 50, 1);
        const values = progressStartColor.map((start, index) =>
            Math.round(start + (progressEndColor[index] - start) * blend)
        );

        return `rgb(${values.join(", ")})`;
    }

    function renderProgress(platformLeagueId) {
        const state = progressStates.get(platformLeagueId);
        const { track, bar } = progressElements(platformLeagueId);

        if (!track || !bar) return;

        if (!state || state.status === "idle") {
            track.classList.add("hidden");
            bar.style.width = "0%";
            bar.style.backgroundColor = "";
            return;
        }

        track.classList.remove("hidden");
        bar.style.width = `${Math.max(0, Math.min(100, state.percent))}%`;
        bar.style.backgroundColor =
            state.status === "completed" ? "rgb(163, 230, 53)" : progressColor(state.percent);
    }

    function ensureProgressState(platformLeagueId) {
        if (!progressStates.has(platformLeagueId)) {
            progressStates.set(platformLeagueId, {
                percent: 0,
                status: "idle",
            });
        }

        return progressStates.get(platformLeagueId);
    }

    function clearProgressTimer(platformLeagueId) {
        if (!progressTimers.has(platformLeagueId)) return;

        clearInterval(progressTimers.get(platformLeagueId));
        progressTimers.delete(platformLeagueId);
    }

    function clearCompletionTimer(platformLeagueId) {
        if (!completionTimers.has(platformLeagueId)) return;

        clearTimeout(completionTimers.get(platformLeagueId));
        completionTimers.delete(platformLeagueId);
    }

    function startEstimatedProgress(platformLeagueId) {
        const state = ensureProgressState(platformLeagueId);

        clearCompletionTimer(platformLeagueId);
        state.status = "processing";
        state.percent = Math.max(state.percent, 4);
        renderProgress(platformLeagueId);

        if (progressTimers.has(platformLeagueId)) return;

        progressTimers.set(
            platformLeagueId,
            setInterval(() => {
                const current = ensureProgressState(platformLeagueId);

                if (current.status !== "processing") return;

                const remaining = Math.max(92 - current.percent, 0);
                current.percent = Math.min(92, current.percent + Math.max(0.35, remaining * 0.045));
                renderProgress(platformLeagueId);
            }, 350)
        );
    }

    function completeProgress(platformLeagueId) {
        const state = ensureProgressState(platformLeagueId);

        clearProgressTimer(platformLeagueId);
        clearCompletionTimer(platformLeagueId);

        state.status = "completed";
        state.percent = 100;
        renderProgress(platformLeagueId);

        completionTimers.set(
            platformLeagueId,
            setTimeout(() => {
                clearCompletedProgress(platformLeagueId);
            }, 5000)
        );
    }

    function hideProgress(platformLeagueId) {
        clearProgressTimer(platformLeagueId);
        clearCompletionTimer(platformLeagueId);
        progressStates.delete(platformLeagueId);
        renderProgress(platformLeagueId);
    }

    function clearCompletedProgress(platformLeagueId) {
        if (!platformLeagueId) return;

        const state = progressStates.get(String(platformLeagueId));

        if (state?.status === "completed") {
            hideProgress(String(platformLeagueId));
        }
    }

    function restoreLeagueSyncState() {
        progressStates.forEach((_state, platformLeagueId) => renderProgress(platformLeagueId));
    }

    function handleLeagueSyncStatus(event) {
        const platformLeagueId = String(event?.platform_league_id || "");
        const status = String(event?.status || "");

        if (!platformLeagueId || !status) return;

        if (status === "processing") {
            syncCounts.set(platformLeagueId, (syncCounts.get(platformLeagueId) || 0) + 1);
            startEstimatedProgress(platformLeagueId);
            return;
        }

        if (status === "completed" || status === "failed") {
            const nextCount = Math.max((syncCounts.get(platformLeagueId) || 1) - 1, 0);

            if (nextCount === 0) {
                syncCounts.delete(platformLeagueId);

                if (status === "completed") {
                    completeProgress(platformLeagueId);
                } else {
                    hideProgress(platformLeagueId);
                }

                return;
            }

            syncCounts.set(platformLeagueId, nextCount);
            startEstimatedProgress(platformLeagueId);
        }
    }

    async function loadPanel(link, push = true) {
        const currentMain = main();

        if (!link || !currentMain) return;
        const url = link.dataset.panelUrl;

        if (inFlight) inFlight.abort();
        inFlight = new AbortController();

        try {
            const res = await fetch(url, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
                signal: inFlight.signal,
            });

            if (!res.ok) {
                const text = await res.text();
                console.error("Panel load failed", res.status, text);
                return;
            }

            const html = await res.text();
            currentMain.innerHTML = html;
            setActive(link);

            if (push) {
                const next = new URL(link.href, window.location.origin);
                history.pushState({}, "", next);
            }
        } catch (e) {
            console.error("Panel load error", e);
        }
    }

    function updateRootFromHtml(html) {
        const doc = new DOMParser().parseFromString(html, "text/html");
        const nextRoot = doc.querySelector('[data-component="leagues-hub-layout"]');

        if (!nextRoot) return false;

        root.innerHTML = nextRoot.innerHTML;
        document.documentElement.classList.remove("overflow-hidden");
        document.body.classList.remove("overflow-hidden");
        restoreLeagueSyncState();

        return true;
    }

    async function resyncProvider(button) {
        const url = button.dataset.providerResyncUrl;
        const label = button.dataset.providerResyncLabel || "leagues";
        const icon = button.querySelector("[data-provider-resync-icon]");

        if (!url || button.disabled) return;

        button.disabled = true;
        icon?.classList.add("animate-spin");

        try {
            const response = await fetch(url, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({}),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || `Could not refresh ${label}.`);
            }

            const page = await fetch(window.location.href, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (page.ok) {
                updateRootFromHtml(await page.text());
            }

            notify("success", payload.message || `${label} sync queued.`);
        } catch (error) {
            notify("error", error.message || `Could not refresh ${label}.`);
        } finally {
            button.disabled = false;
            icon?.classList.remove("animate-spin");
        }
    }

    function renderVisibilityToggle(button, isVisible) {
        const knob = button.querySelector("[data-league-visibility-knob]");
        const form = button.closest("[data-league-visibility-form]");
        const input = form?.querySelector("[data-league-visibility-input]");
        const label = button.getAttribute("aria-label") || "";

        button.dataset.leagueVisible = isVisible ? "true" : "false";
        button.setAttribute("aria-pressed", isVisible ? "true" : "false");
        button.classList.toggle("bg-indigo-600", isVisible);
        button.classList.toggle("bg-slate-200", !isVisible);
        knob?.classList.toggle("translate-x-4", isVisible);
        knob?.classList.toggle("translate-x-0.5", !isVisible);

        if (knob) {
            knob.style.transform = `translateX(${isVisible ? "16px" : "2px"})`;
        }

        if (input) {
            input.value = isVisible ? "1" : "0";
        }

        if (label.startsWith("Hide ") || label.startsWith("Show ")) {
            button.setAttribute(
                "aria-label",
                `${isVisible ? "Hide" : "Show"} ${label.replace(/^(Hide|Show) /, "")}`
            );
        }
    }

    function updateLeagueListVisibility(platformLeagueId, isVisible) {
        const currentList = list();
        const link = leagueLink(String(platformLeagueId));
        const item = link?.closest("li");

        if (!item && isVisible && currentList) {
            const optionRow = root.querySelector(
                `[data-league-option-row][data-league-id="${platformLeagueId}"]`
            );

            if (!optionRow) return;

            const name = optionRow.dataset.leagueName || "League";
            const platformLabel = optionRow.dataset.leaguePlatformLabel || "League";
            const listItem = document.createElement("li");
            const anchor = document.createElement("a");
            const content = document.createElement("div");
            const avatar = document.createElement("span");
            const textWrap = document.createElement("span");
            const title = document.createElement("span");
            const meta = document.createElement("span");
            const platform = document.createElement("span");
            const dot = document.createElement("span");
            const type = document.createElement("span");
            const status = document.createElement("span");
            const progress = document.createElement("span");
            const progressBar = document.createElement("span");

            anchor.href = optionRow.dataset.leagueHref || `/leagues?active=${platformLeagueId}`;
            anchor.className =
                "league-item group relative block overflow-hidden rounded-md px-2.5 py-2 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200";
            anchor.dataset.leagueId = String(platformLeagueId);
            anchor.dataset.panelUrl = optionRow.dataset.leaguePanelUrl || "";
            anchor.setAttribute("aria-current", "false");

            content.className = "flex items-center gap-2.5";
            avatar.className =
                "inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200 group-aria-[current=page]:bg-indigo-600 group-aria-[current=page]:text-white group-aria-[current=page]:ring-indigo-600";
            avatar.textContent = name.slice(0, 2).toUpperCase();
            textWrap.className = "min-w-0 flex-1";
            title.className =
                "block truncate text-[13px] font-medium leading-4 text-slate-900 group-aria-[current=page]:text-indigo-950";
            title.textContent = name;
            meta.className = "mt-0.5 flex items-center gap-1.5 text-[10px] font-medium leading-3 text-slate-500";
            platform.textContent = platformLabel;
            dot.className = "h-0.5 w-0.5 rounded-full bg-slate-300";
            type.textContent = "League";
            status.className = "h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300";
            progress.className = "absolute inset-x-0 bottom-0 hidden h-0.5 bg-slate-100";
            progress.dataset.leagueSyncProgress = "";
            progress.setAttribute("aria-hidden", "true");
            progressBar.className = "block h-full w-0 transition-[width,background-color] duration-300";
            progressBar.dataset.leagueSyncProgressBar = "";

            meta.append(platform, dot, type);
            textWrap.append(title, meta);
            content.append(avatar, textWrap, status);
            progress.append(progressBar);
            anchor.append(content, progress);
            listItem.append(anchor);
            currentList.append(listItem);
            restoreLeagueSyncState();
            return;
        }

        if (!item) return;

        item.classList.toggle("hidden", !isVisible);
    }

    async function toggleLeagueVisibility(form) {
        const button = form.querySelector("[data-league-visibility-toggle]");
        const input = form.querySelector("[data-league-visibility-input]");
        const url = form.getAttribute("action") || button?.dataset.leagueVisibilityUrl;

        if (!button || !url || button.disabled) return;

        const wasVisible = button.dataset.leagueVisible === "true";
        const isVisible = !wasVisible;

        button.disabled = true;
        renderVisibilityToggle(button, isVisible);

        try {
            const response = await fetch(url, {
                method: "PUT",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ is_visible: isVisible }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || "Could not update league visibility.");
            }

            updateLeagueListVisibility(payload.league_id || button.dataset.leagueId, isVisible);

            notify("success", payload.message || "League visibility updated.");
        } catch (error) {
            renderVisibilityToggle(button, wasVisible);
            notify("error", error.message || "Could not update league visibility.");
        } finally {
            button.disabled = false;
        }
    }

    root.addEventListener("click", (e) => {
        const visibilityToggle = e.target.closest("[data-league-visibility-toggle]");

        if (visibilityToggle) {
            const visibilityForm = visibilityToggle.closest("[data-league-visibility-form]");

            if (!visibilityForm || !root.contains(visibilityForm)) return;

            e.preventDefault();
            e.stopPropagation();
            toggleLeagueVisibility(visibilityForm);
            return;
        }

        const resyncButton = e.target.closest("[data-provider-resync-button]");

        if (resyncButton) {
            e.preventDefault();
            e.stopPropagation();
            resyncProvider(resyncButton);
            return;
        }

        const link = e.target.closest("a.league-item");

        if (!link || !root.contains(link)) return;

        e.preventDefault();
        loadPanel(link, true);
    });

    window.addEventListener("popstate", () => {
        const currentList = list();
        const params = new URLSearchParams(location.search);
        const active = params.get("active");
        const link = active
            ? currentList?.querySelector(
                  `a.league-item[data-league-id="${active}"]`
              )
            : currentList?.querySelector("a.league-item");

        if (link) loadPanel(link, false);
    });

    window.DIQ?.userChannel?.listen(".league.sync.status", handleLeagueSyncStatus);
}

function mountAll() {
    document
        .querySelectorAll('[data-component="leagues-hub-layout"]')
        .forEach(mount);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountAll);
} else {
    mountAll();
}

export { mountAll };
export default mount;
