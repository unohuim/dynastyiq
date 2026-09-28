import "../components/RangeSlider/range-slider.css";
import { RangeSlider } from "../components/RangeSlider/range-slider.js";

function inputByName(form, name) {
    return form?.querySelector(`input[name="${name}"]`) ?? null;
}

function syncHiddenInput(input, value, defaultValue) {
    if (!input) return;

    const normalizedValue = String(Math.round(Number(value)));
    const normalizedDefault = String(Math.round(Number(defaultValue)));

    input.value = normalizedValue === normalizedDefault ? "" : normalizedValue;
}

function mountStatsUnitsFilters(root) {
    const form = root.dataset.formSelector
        ? document.querySelector(root.dataset.formSelector)
        : root.closest("form");

    if (!form || root.dataset.statsUnitsFiltersMounted === "true") return;

    root.dataset.statsUnitsFiltersMounted = "true";

    const sliders = Array.from(root.querySelectorAll("[data-stats-units-range]"))
        .map((sliderRoot) => {
            const baseConfig = JSON.parse(sliderRoot.dataset.rangeConfig || "{}");
            let slider = null;

            const mountSlider = (config = baseConfig) => {
                slider?.destroy();
                slider = new RangeSlider(sliderRoot, config);
                slider.reset();
            };

            mountSlider();

            sliderRoot.addEventListener("range-slider:change", (event) => {
                const detail = event.detail || {};

                syncHiddenInput(
                    inputByName(form, sliderRoot.dataset.filterMinInput),
                    detail.minValue,
                    sliderRoot.dataset.filterDefaultMin
                );
                syncHiddenInput(
                    inputByName(form, sliderRoot.dataset.filterMaxInput),
                    detail.maxValue,
                    sliderRoot.dataset.filterDefaultMax
                );
            });

            return {
                destroy() {
                    slider?.destroy();
                },
                reset() {
                    const resetConfig = {
                        ...baseConfig,
                        minValue: Number(sliderRoot.dataset.filterDefaultMin),
                        maxValue: Number(sliderRoot.dataset.filterDefaultMax),
                    };

                    mountSlider(resetConfig);

                    const minInput = inputByName(form, sliderRoot.dataset.filterMinInput);
                    const maxInput = inputByName(form, sliderRoot.dataset.filterMaxInput);

                    if (minInput) minInput.value = "";
                    if (maxInput) maxInput.value = "";
                },
            };
        });

    root.querySelector("[data-stats-units-filter-apply]")?.addEventListener("click", () => {
        form.requestSubmit();
    });

    root.querySelector("[data-stats-units-filter-reset]")?.addEventListener("click", () => {
        sliders.forEach((slider) => slider.reset());
    });

    return () => sliders.forEach((slider) => slider.destroy());
}

const scopeFields = ["season_id", "game_type", "pos[]", "team", "date", "sum", "nhl_game_id", "player_id"];
const gameScopeFields = ["season_id", "game_type", "pos[]", "team", "date"];
const volumeFields = ["gp", "shifts", "toi", "gf", "sf", "satf"];

/** Read the checkbox explicitly so its hidden unchecked value cannot win. */
export function formParams(form) {
    const params = new URLSearchParams(new FormData(form));
    params.set("sum", form.querySelector('[name="sum"][type="checkbox"]').checked ? "1" : "0");
    return params;
}

/** Discard dependent constraints when changing the set of games or grouping. */
export function filterParams(form, previous) {
    const params = formParams(form);
    const changed = (key) => params.getAll(key).join(",") !== previous.getAll(key).join(",");

    if (scopeFields.some(changed)) {
        volumeFields.forEach((key) => {
            params.delete(`${key}_min`);
            params.delete(`${key}_max`);
        });
    }
    if (gameScopeFields.some(changed)) params.delete("nhl_game_id");
    params.delete("page");
    return params;
}

/** Enhance the existing Blade report without introducing application state. */
export function mount(root) {
    if (!root || root.dataset.statsUnitsMounted === "true") return;
    root.dataset.statsUnitsMounted = "true";

    const content = root.querySelector("[data-stats-units-content]");
    const status = root.querySelector("[data-stats-units-status]");
    let previous = formParams(content.querySelector("form"));
    let committedHtml = content.innerHTML;
    let committedUrl = window.location.href;
    let controller = null;
    let requestId = 0;
    let filterCleanups = [];

    const mountFilters = () => {
        filterCleanups = Array.from(content.querySelectorAll("[data-stats-units-filters]"))
            .map(mountStatsUnitsFilters).filter(Boolean);
    };
    const report = (message) => {
        status.textContent = message;
        status.hidden = !message;
    };
    const replaceContent = (html) => {
        filterCleanups.forEach((cleanup) => cleanup());
        content.innerHTML = html;
        previous = formParams(content.querySelector("form"));
        mountFilters();
    };

    const refresh = async (url, push = true) => {
        controller?.abort();
        controller = new AbortController();
        const currentId = ++requestId;
        root.setAttribute("aria-busy", "true");
        report("Updating line combinations…");

        try {
            const response = await fetch(url, {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(Object.values(payload.errors ?? {}).flat()[0] || "Unable to update line combinations. Please try again.");
            }
            if (typeof payload.html !== "string" || typeof payload.url !== "string") {
                throw new Error("Unable to update line combinations. Please reload the page and try again.");
            }
            if (currentId !== requestId) return;

            committedHtml = payload.html;
            committedUrl = payload.url;
            replaceContent(committedHtml);
            if (push) window.history.pushState(null, "", payload.url);
            report("");
        } catch (error) {
            if (currentId !== requestId || error.name === "AbortError") return;
            // Keep controls and visible statistics in the same last successful scope.
            replaceContent(committedHtml);
            window.history.replaceState(null, "", committedUrl);
            report(error.message || "Unable to update line combinations. Please try again.");
        } finally {
            if (currentId === requestId) root.setAttribute("aria-busy", "false");
        }
    };

    const onSubmit = (event) => {
        if (event.target.id !== "stats-units-filter-form") return;
        event.preventDefault();
        const url = new URL(event.target.action, window.location.href);
        url.search = filterParams(event.target, previous).toString();
        refresh(url.href);
    };

    const onClick = (event) => {
        const clearDate = event.target.closest("[data-stats-units-clear-date]");
        if (clearDate) {
            const form = content.querySelector("form");
            const date = form.querySelector('[name="date"]');
            date.value = "";
            date.dispatchEvent(new Event("input", { bubbles: true }));
            form.querySelector('[name="nhl_game_id"]').value = "";
            form.requestSubmit();
            return;
        }

        const link = event.target.closest("[data-stats-units-pagination] a, [data-stats-units-reset]");
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        refresh(link.href);
    };

    const onPopState = () => refresh(window.location.href, false);
    root.addEventListener("submit", onSubmit);
    root.addEventListener("click", onClick);
    window.addEventListener("popstate", onPopState);
    mountFilters();

    return () => {
        requestId++;
        controller?.abort();
        filterCleanups.forEach((cleanup) => cleanup());
        root.removeEventListener("submit", onSubmit);
        root.removeEventListener("click", onClick);
        window.removeEventListener("popstate", onPopState);
        delete root.dataset.statsUnitsMounted;
    };
}

function mountStatsUnitsPage() {
    document.querySelectorAll('[data-page="stats-units"]').forEach(mount);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountStatsUnitsPage, { once: true });
} else {
    mountStatsUnitsPage();
}
