import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { filterParams, formParams, mount } from "./stats-units.js";

vi.mock("../components/RangeSlider/range-slider.js", () => ({
    RangeSlider: class {
        reset() {}
        destroy() {}
    },
}));

const fragment = ({ date = "2026-10-01", game = "2026020001", sum = true, text = "Original combinations" } = {}) => `
    <form id="stats-units-filter-form" action="/stats/units">
        <input name="season_id" value="20262027">
        <input name="game_type" value="2">
        <input name="pos[]" value="F">
        <input name="team" value="EDM">
        <input type="hidden" name="sum" value="0">
        <input type="checkbox" name="sum" value="1" ${sum ? "checked" : ""}>
        <input type="date" name="date" value="${date}">
        <button type="button" data-stats-units-clear-date>Clear date</button>
        <select name="nhl_game_id"><option value="">All Games</option><option value="2026020001" ${game === "2026020001" ? "selected" : ""}>First game</option><option value="2026020002" ${game === "2026020002" ? "selected" : ""}>Second game</option></select>
        <select name="player_id"><option value="">All players</option><option value="101">Connor McDavid</option></select>
        <input name="sort" value="gf">
        <input name="dir" value="desc">
        <input name="display" value="counts">
        <input name="per_page" value="30">
        <input name="page" value="3">
        <input name="gp_min" value="5">
        <input name="gp_max" value="82">
        <input name="toi_min" value="30">
        <input name="toi_max" value="90">
        <input name="shifts_min" value="10">
        <input name="shifts_max" value="100">
        <input name="gf_min" value="2">
        <input name="gf_max" value="50">
        <input name="sf_min" value="3">
        <input name="sf_max" value="100">
        <input name="satf_min" value="4">
        <input name="satf_max" value="200">
    </form>
    <p data-results>${text}</p>
    <div data-stats-units-pagination><a href="/stats/units?sum=0&date=2026-10-01&page=2">Next</a></div>
    <a href="/stats/units" data-stats-units-reset>Clear filters</a>`;

let root;
let form;
let cleanup;
let fetchMock;
const field = (name) => form.querySelector(`[name="${name}"]`);
const submit = () => form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
const response = (html, url = "/stats/units?sum=0") => ({ ok: true, json: async () => ({ html, url }) });
// Drain only promise continuations; no timers, polling, or external requests.
const settle = async () => {
    for (let step = 0; step < 8; step++) await Promise.resolve();
};

beforeEach(() => {
    window.history.replaceState(null, "", "/stats/units");
    document.body.innerHTML = `<section data-page="stats-units"><p data-stats-units-status hidden></p><div data-stats-units-content>${fragment()}</div></section>`;
    root = document.querySelector("section");
    form = root.querySelector("form");
    fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    cleanup = null;
});

afterEach(() => {
    cleanup?.();
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("line combination filter constraints", () => {
    it("submits only the checked Sum value despite the hidden unchecked input", () => {
        expect(formParams(form).getAll("sum")).toEqual(["1"]);
    });

    it("submits zero when Sum is unchecked", () => {
        form.querySelector('[type="checkbox"]').checked = false;
        expect(formParams(form).getAll("sum")).toEqual(["0"]);
    });

    it("clears the game and volume thresholds when the date changes", () => {
        const previous = formParams(form);
        field("date").value = "2026-10-02";
        const params = filterParams(form, previous);
        expect(params.get("date")).toBe("2026-10-02");
        expect(params.has("nhl_game_id")).toBe(false);
        for (const key of ["gp", "shifts", "toi", "gf", "sf", "satf"]) {
            expect(params.has(`${key}_min`)).toBe(false);
            expect(params.has(`${key}_max`)).toBe(false);
        }
    });

    it("removes the game constraint when the date is cleared", () => {
        const previous = formParams(form);
        field("date").value = "";
        const params = filterParams(form, previous);
        expect(params.get("date")).toBe("");
        expect(params.has("nhl_game_id")).toBe(false);
    });

    it("preserves a newly selected game when its parent scope has not changed", () => {
        const previous = formParams(form);
        field("nhl_game_id").value = "2026020002";
        const params = filterParams(form, previous);
        expect(params.get("nhl_game_id")).toBe("2026020002");
        expect(params.has("gp_min")).toBe(false);
    });

    it("retains the date when All Games is selected", () => {
        const previous = formParams(form);
        field("nhl_game_id").value = "";
        const params = filterParams(form, previous);
        expect(params.get("nhl_game_id")).toBe("");
        expect(params.get("date")).toBe("2026-10-01");
    });

    it("resets volume limits but retains the game when grouping changes", () => {
        const previous = formParams(form);
        form.querySelector('[type="checkbox"]').checked = false;
        const params = filterParams(form, previous);
        expect(params.get("nhl_game_id")).toBe("2026020001");
        expect(params.has("toi_min")).toBe(false);
    });

    it("resets volume limits when a player is selected", () => {
        const previous = formParams(form);
        field("player_id").value = "101";
        const params = filterParams(form, previous);
        expect(params.get("player_id")).toBe("101");
        expect(params.get("nhl_game_id")).toBe("2026020001");
        expect(params.has("shifts_min")).toBe(false);
    });

    it("resets game constraints when the season changes", () => {
        const previous = formParams(form);
        field("season_id").value = "20252026";
        expect(filterParams(form, previous).has("nhl_game_id")).toBe(false);
    });

    it("resets game constraints when the game type changes", () => {
        const previous = formParams(form);
        field("game_type").value = "1";
        expect(filterParams(form, previous).has("nhl_game_id")).toBe(false);
    });

    it("resets game constraints when the team changes", () => {
        const previous = formParams(form);
        field("team").value = "CGY";
        expect(filterParams(form, previous).has("nhl_game_id")).toBe(false);
    });

    it("resets game constraints when the unit type changes", () => {
        const previous = formParams(form);
        field("pos[]").value = "D";
        expect(filterParams(form, previous).has("nhl_game_id")).toBe(false);
    });

    it("preserves explicit thresholds when only sort or display changes", () => {
        const previous = formParams(form);
        field("sort").value = "sf";
        field("display").value = "share";
        const params = filterParams(form, previous);
        expect(params.get("gp_min")).toBe("5");
        expect(params.get("toi_max")).toBe("90");
        expect(params.get("nhl_game_id")).toBe("2026020001");
        expect(params.get("per_page")).toBe("30");
    });

    it("starts at the first page after filter submission", () => {
        expect(filterParams(form, formParams(form)).has("page")).toBe(false);
    });
});

describe("line combination report updates", () => {
    it("updates the report and address without navigating the document", async () => {
        fetchMock.mockResolvedValue(response(fragment({ text: "Updated combinations", sum: false })));
        cleanup = mount(root);
        form.querySelector('[type="checkbox"]').checked = false;
        expect(submit()).toBe(false);
        await settle();
        expect(root.querySelector("[data-results]").textContent).toBe("Updated combinations");
        expect(window.location.search).toBe("?sum=0");
        expect(fetchMock.mock.calls[0][1].headers.Accept).toBe("application/json");
    });

    it("shows a loading status while the request is pending", async () => {
        let resolve;
        fetchMock.mockReturnValue(new Promise((done) => { resolve = done; }));
        cleanup = mount(root);
        submit();
        expect(root.getAttribute("aria-busy")).toBe("true");
        expect(root.querySelector("[data-stats-units-status]").hidden).toBe(false);
        resolve(response(fragment()));
        await settle();
        expect(root.getAttribute("aria-busy")).toBe("false");
        expect(root.querySelector("[data-stats-units-status]").hidden).toBe(true);
    });

    it("clears date and game together while keeping the other constraints", async () => {
        fetchMock.mockResolvedValue(response(fragment({ date: "", game: "" })));
        cleanup = mount(root);
        root.querySelector("[data-stats-units-clear-date]").click();
        const params = new URL(fetchMock.mock.calls[0][0]).searchParams;
        expect(params.get("date")).toBe("");
        expect(params.has("nhl_game_id")).toBe(false);
        expect(params.get("team")).toBe("EDM");
        expect(params.get("sum")).toBe("1");
        await settle();
    });

    it("restores the checked Sum state and old results if the request fails", async () => {
        fetchMock.mockRejectedValue(new Error("Connection failed"));
        cleanup = mount(root);
        form.querySelector('[type="checkbox"]').checked = false;
        submit();
        await settle();
        expect(root.querySelector('[type="checkbox"]').checked).toBe(true);
        expect(root.querySelector("[data-results]").textContent).toBe("Original combinations");
        expect(root.querySelector("[data-stats-units-status]").textContent).toBe("Connection failed");
        expect(root.getAttribute("aria-busy")).toBe("false");
    });

    it("shows the server validation message without replacing successful results", async () => {
        fetchMock.mockResolvedValue({ ok: false, json: async () => ({ errors: { date: ["Choose a valid date."] } }) });
        cleanup = mount(root);
        submit();
        await settle();
        expect(root.querySelector("[data-stats-units-status]").textContent).toBe("Choose a valid date.");
        expect(root.querySelector("[data-results]").textContent).toBe("Original combinations");
    });

    it("aborts an older request and ignores its late response", async () => {
        let first;
        let second;
        fetchMock.mockReturnValueOnce(new Promise((done) => { first = done; }))
            .mockReturnValueOnce(new Promise((done) => { second = done; }));
        cleanup = mount(root);
        submit();
        field("date").value = "2026-10-02";
        submit();
        expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
        second(response(fragment({ text: "Latest selection" })));
        await settle();
        first(response(fragment({ text: "Stale selection" })));
        await settle();
        expect(root.querySelector("[data-results]").textContent).toBe("Latest selection");
    });

    it("follows pagination through the same authenticated report request", async () => {
        fetchMock.mockResolvedValue(response(fragment({ text: "Second page" }), "/stats/units?page=2"));
        cleanup = mount(root);
        root.querySelector("[data-stats-units-pagination] a").click();
        expect(new URL(fetchMock.mock.calls[0][0]).searchParams.get("page")).toBe("2");
        await settle();
        expect(root.querySelector("[data-results]").textContent).toBe("Second page");
    });

    it("clears all constraints through the empty-state action", async () => {
        fetchMock.mockResolvedValue(response(fragment({ date: "", game: "" }), "/stats/units"));
        cleanup = mount(root);
        root.querySelector("[data-stats-units-reset]").click();
        expect(new URL(fetchMock.mock.calls[0][0]).search).toBe("");
        await settle();
    });

    it("reloads the report for browser history without pushing another entry", async () => {
        fetchMock.mockResolvedValue(response(fragment({ text: "Previous selection" }), "/stats/units?sum=1"));
        cleanup = mount(root);
        const push = vi.spyOn(window.history, "pushState");
        window.dispatchEvent(new PopStateEvent("popstate"));
        await settle();
        expect(push).not.toHaveBeenCalled();
        expect(root.querySelector("[data-results]").textContent).toBe("Previous selection");
    });

    it("does not register duplicate handlers when mounted again", async () => {
        fetchMock.mockResolvedValue(response(fragment()));
        cleanup = mount(root);
        mount(root);
        submit();
        await settle();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("uses the returned controls as the baseline for subsequent game selection", async () => {
        fetchMock.mockResolvedValue(response(fragment({ date: "2026-10-02", game: "" })));
        cleanup = mount(root);
        field("date").value = "2026-10-02";
        submit();
        await settle();
        form = root.querySelector("form");
        field("nhl_game_id").value = "2026020002";
        submit();
        expect(new URL(fetchMock.mock.calls[1][0]).searchParams.get("nhl_game_id")).toBe("2026020002");
        await settle();
    });

    it("rejects an unexpected response instead of erasing the report", async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => ({}) });
        cleanup = mount(root);
        submit();
        await settle();
        expect(root.querySelector("[data-results]").textContent).toBe("Original combinations");
        expect(root.querySelector("[data-stats-units-status]").textContent).toContain("reload the page");
    });

    it("removes history and submit listeners when disposed", () => {
        cleanup = mount(root);
        cleanup();
        cleanup = null;
        window.dispatchEvent(new PopStateEvent("popstate"));
        submit();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
