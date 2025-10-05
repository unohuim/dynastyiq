// CommunityHubLayout.js
// Progressive enhancement for the sidebar + hub behavior.

function toBool(v) {
    return v === true || v === "true" || v === "1" || v === 1;
}

function mount(root) {
    if (!root) return;

    const isHub = toBool(root.dataset.isHub);
    const mobileBreakpoint = parseInt(
        root.dataset.mobileBreakpoint || "768",
        10
    );
    const initial = (() => {
        try {
            return JSON.parse(root.dataset.initial || "null");
        } catch {
            return null;
        }
    })();

    const listEl = root.querySelector("#communityList");
    const rootView = root.querySelector("#rootView");
    const tplDesktop = document.getElementById("tpl-desktop");
    const tplMobile = document.getElementById("tpl-mobile");

    // Intercept sidebar link clicks on the hub page only
    if (isHub && listEl) {
        listEl.addEventListener("click", (e) => {
            const link = e.target.closest("a.community-item");
            if (!link) return;
            e.preventDefault();

            // Update URL (?active=ID) without full reload
            const next = new URL(link.href, window.location.origin);
            history.pushState({}, "", next);

            // Toggle active styles/aria
            listEl.querySelectorAll("a.community-item").forEach((el) => {
                el.setAttribute("aria-current", "false");
                el.classList.remove("ring-2", "ring-indigo-200", "bg-slate-50");
            });
            link.setAttribute("aria-current", "page");
            link.classList.add("ring-2", "ring-indigo-200", "bg-slate-50");

            // Notify listeners to swap main content via AJAX
            document.dispatchEvent(
                new CustomEvent("community:changed", {
                    detail: {
                        id: link.dataset.orgId,
                        slug: link.dataset.slug,
                        name: link.dataset.name,
                    },
                })
            );
        });

        // Back/forward support: reflect ?active in sidebar + notify
        window.addEventListener("popstate", () => {
            const params = new URLSearchParams(location.search);
            const active = params.get("active");
            const link = active
                ? listEl.querySelector(
                      `a.community-item[data-org-id="${active}"]`
                  )
                : null;
            if (link) link.click();
        });
    }

    // Optional: responsive title/template swap (if templates are present)
    if (!(isHub && rootView && (tplDesktop || tplMobile))) return;

    const state = {
        isMobile: window.innerWidth < mobileBreakpoint,
        activeCommunity: initial
            ? { slug: initial.slug ?? null, name: initial.name ?? null }
            : { slug: null, name: null },
    };

    function bindDesktopEvents() {
        const title = rootView.querySelector("#desktopCommunityTitle");
        if (title && state.activeCommunity.name)
            title.textContent = state.activeCommunity.name;
    }

    function bindMobileEvents() {
        const title = rootView.querySelector("#mobileCommunityTitle");
        const select = rootView.querySelector("#mobileCommunitySelect");
        if (title && state.activeCommunity.name)
            title.textContent = state.activeCommunity.name;

        if (select) {
            select.addEventListener("change", (e) => {
                const opt = e.target.selectedOptions[0];
                if (!opt) return;
                const href = `${
                    opt.dataset.href || "/communities"
                }?active=${encodeURIComponent(opt.value)}`;
                history.pushState({}, "", href);
                if (title)
                    title.textContent = opt.getAttribute("data-name") || "";
            });
        }
    }

    function render() {
        const nextIsMobile = window.innerWidth < mobileBreakpoint;

        if (rootView.children.length === 0 || nextIsMobile !== state.isMobile) {
            state.isMobile = nextIsMobile;
            rootView.innerHTML = "";
            const tpl = state.isMobile ? tplMobile : tplDesktop;
            if (!tpl) return;
            const frag = tpl.content.cloneNode(true);
            rootView.appendChild(frag);
        }

        if (state.isMobile) {
            bindMobileEvents();
        } else {
            bindDesktopEvents();
        }
    }

    render();
    window.addEventListener("resize", render);
}

// Auto-mount any instance on the page
document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll('[data-component="community-hub-layout"]')
        .forEach(mount);
});

export { mount };
