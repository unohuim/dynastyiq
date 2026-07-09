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
}

function mountStatsUnitsPage() {
    document
        .querySelectorAll("[data-stats-units-filters]")
        .forEach(mountStatsUnitsFilters);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountStatsUnitsPage, { once: true });
} else {
    mountStatsUnitsPage();
}
