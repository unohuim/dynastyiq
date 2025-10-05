// resources/js/components/LeaguesHubLayout.js
function mount(root) {
    if (!root) return;

    const list = root.querySelector("#leagueList");
    const main = root.querySelector("#leagueMain");
    let inFlight;

    function setActive(link) {
        if (!list) return;
        list.querySelectorAll("a.league-item").forEach((el) => {
            el.setAttribute("aria-current", "false");
            el.classList.remove("ring-2", "ring-indigo-200", "bg-slate-50");
        });
        link.setAttribute("aria-current", "page");
        link.classList.add("ring-2", "ring-indigo-200", "bg-slate-50");
    }

    async function loadPanel(link, push = true) {
        if (!link || !main) return;
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
            main.innerHTML = html;
            setActive(link);

            if (push) {
                const next = new URL(link.href, window.location.origin);
                history.pushState({}, "", next);
            }
        } catch (e) {
            console.error("Panel load error", e);
        }
    }

    if (list) {
        list.addEventListener("click", (e) => {
            const link = e.target.closest("a.league-item");
            if (!link) return;
            e.preventDefault();
            loadPanel(link, true);
        });

        window.addEventListener("popstate", () => {
            const params = new URLSearchParams(location.search);
            const active = params.get("active");
            const link = active
                ? list.querySelector(
                      `a.league-item[data-league-id="${active}"]`
                  )
                : list.querySelector("a.league-item");
            if (link) loadPanel(link, false);
        });
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll('[data-component="leagues-hub-layout"]')
        .forEach(mount);
});

export default mount;
