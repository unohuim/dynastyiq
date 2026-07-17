const defaultMeta = {
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
};

const emptyMemberForm = () => ({
    id: null,
    name: "",
    email: "",
    membership_tier_id: null,
    status: "active",
});

const emptyTierForm = () => ({
    id: null,
    name: "",
    amount_cents: null,
    currency: "USD",
    description: "",
    is_active: true,
});

let createLeagueHandlerRegistered = false;
let detachLeagueHandlerRegistered = false;
let refreshDiscordMembersHandlerRegistered = false;

function csrfToken() {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content") ?? ""
    );
}

function showToast(type, message) {
    if (window.toast?.[type]) {
        window.toast[type](message);
        return;
    }

    if (window.toast?.show) {
        window.toast.show(message, { type });
    }
}

function resolveCreateLeagueAction(form) {
    const dataAction = form.dataset?.action?.trim() || "";
    if (dataAction) return dataAction;

    return (form.getAttribute("action") || "").trim();
}

async function submitCreateLeagueForm(form) {
    const url = resolveCreateLeagueAction(form);

    if (!url || url === "#" || url === "/" || /\/communities(\?|$)/.test(url)) {
        console.warn("[createLeague] Missing or invalid action URL on form:", url);
        showToast("error", "Cannot submit: missing endpoint to create a league.");
        return;
    }

    const name = form.querySelector('[name="name"]')?.value.trim() || "";
    const discordId = form.querySelector('[name="discord_server_id"]')?.value || "";
    const platform = form.querySelector('[name="platform"]')?.value || "";
    const platformId =
        form.querySelector('[name="platform_league_id"]')?.value || "";
    const providerScopeType =
        form.querySelector('[name="provider_scope_type"]')?.value || "";
    const providerScopeKey =
        form.querySelector('[name="provider_scope_key"]')?.value || "";
    const providerScopeLabel =
        form.querySelector('[name="provider_scope_label"]')?.value || "";
    const providerScopeMode =
        form.querySelector('[name="provider_scope_mode"]')?.value || "single";
    const providerScopeRequired =
        form.querySelector('[name="provider_scope_required"]')?.value === "1";

    if (!name) {
        showToast("error", "Please enter a league name.");
        return;
    }

    if (platform && !platformId) {
        showToast("error", "Please select or enter a Fantrax league ID.");
        return;
    }

    if (platform && providerScopeRequired && !providerScopeKey) {
        showToast("error", "Please choose a Fantrax scope.");
        return;
    }

    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    const payload = {
        name,
        ...(discordId ? { discord_server_id: discordId } : {}),
        ...(platform ? { platform, platform_league_id: platformId } : {}),
        ...(providerScopeMode ? { provider_scope_mode: providerScopeMode } : {}),
        ...(providerScopeType ? { provider_scope_type: providerScopeType } : {}),
        ...(providerScopeKey ? { provider_scope_key: providerScopeKey } : {}),
        ...(providerScopeLabel ? { provider_scope_label: providerScopeLabel } : {}),
    };

    try {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify(payload),
            credentials: "same-origin",
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data?.ok !== true) {
            showToast(
                "error",
                data?.message || `Create failed (${response.status})`
            );
            return;
        }

        showToast(
            "success",
            data?.league_count > 1
                ? `${data.league_count} leagues created successfully.`
                : "League created successfully."
        );
        window.setTimeout(() => window.location.reload(), 350);
    } catch (error) {
        console.error("[createLeague] Network or JavaScript error:", error);
        showToast("error", "Could not create league.");
    } finally {
        if (button) button.disabled = false;
    }
}

function registerCreateLeagueHandler() {
    if (createLeagueHandlerRegistered) return;

    createLeagueHandlerRegistered = true;

    document.addEventListener("submit", (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || form.id !== "createLeagueForm") {
            return;
        }

        event.preventDefault();
        submitCreateLeagueForm(form);
    });
}

function updateCommunityLeagueCounts() {
    const remaining = document.querySelectorAll(
        "[data-community-league-row]"
    ).length;

    document.querySelectorAll("[data-community-league-count]").forEach((node) => {
        node.textContent = String(remaining);
    });

    document.querySelectorAll("[data-community-leagues-empty]").forEach((node) => {
        node.classList.toggle("hidden", remaining > 0);
    });
}

async function detachCommunityLeague(button) {
    const url = button.dataset.url || "";
    const leagueId = button.dataset.leagueId || "";

    if (!url || !leagueId) {
        showToast("error", "Cannot remove league: missing endpoint.");
        return;
    }

    button.disabled = true;

    try {
        const response = await fetch(url, {
            method: "DELETE",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data?.ok !== true) {
            showToast(
                "error",
                data?.message || `Remove failed (${response.status})`
            );
            return;
        }

        document
            .querySelectorAll(
                `[data-community-league-row="${leagueId}"], [data-community-sidebar-league-row="${leagueId}"]`
            )
            .forEach((node) => node.remove());

        updateCommunityLeagueCounts();
        showToast("success", "League removed from this community.");
    } catch (error) {
        console.error("[communityLeagueDetach] Network or JavaScript error:", error);
        showToast("error", "Could not remove league from this community.");
    } finally {
        button.disabled = false;
    }
}

function registerDetachLeagueHandler() {
    if (detachLeagueHandlerRegistered) return;

    detachLeagueHandlerRegistered = true;

    document.addEventListener("click", (event) => {
        const button = event.target.closest("[data-community-league-detach]");

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        event.preventDefault();
        detachCommunityLeague(button);
    });
}

async function refreshDiscordMembers(button) {
    const url = button.dataset.url || "";

    if (!url) {
        showToast("error", "Cannot refresh Discord members: missing endpoint.");
        return;
    }

    button.disabled = true;

    try {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data?.ok !== true) {
            showToast(
                "error",
                data?.message || `Discord refresh failed (${response.status})`
            );
            return;
        }

        const syncedCount = Number(data?.summary?.synced_count || 0);
        showToast(
            "success",
            syncedCount === 1
                ? "1 Discord member refreshed."
                : `${syncedCount} Discord members refreshed.`
        );
        window.dispatchEvent(new CustomEvent("community-members:refresh"));
    } catch (error) {
        console.error("[discordMembersRefresh] Network or JavaScript error:", error);
        showToast("error", "Could not refresh Discord members.");
    } finally {
        button.disabled = false;
    }
}

function registerRefreshDiscordMembersHandler() {
    if (refreshDiscordMembersHandlerRegistered) return;

    refreshDiscordMembersHandlerRegistered = true;

    document.addEventListener("click", (event) => {
        const button = event.target.closest("[data-discord-members-refresh]");

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        event.preventDefault();
        refreshDiscordMembers(button);
    });
}

export function createCommunityMembersStore() {
    return {
        organizationId: null,
        organizationName: "",
        endpoints: { members: "", tiers: "", settings: "" },
        members: [],
        memberMeta: { ...defaultMeta },
        tiers: [],
        loading: {
            members: false,
            tiers: false,
            savingMember: false,
            savingTier: false,
            savingSettings: false,
        },
        activeCollectionTab: "members",
        modals: {
            member: false,
            tier: false,
            settings: false,
            confirmMemberId: null,
            confirmTierId: null,
        },
        memberForm: emptyMemberForm(),
        tierForm: emptyTierForm(),
        settingsForm: { name: "" },
        errors: { member: {}, tier: {}, settings: {} },
        bootstrap(config) {
            this.organizationId = config.organizationId;
            this.organizationName = config.organizationName || "";
            this.endpoints = config.endpoints;
            this.settingsForm.name = config.organizationName || "";

            if (config.initialMembers?.data) {
                this.members = config.initialMembers.data;
                this.memberMeta = config.initialMembers.meta || { ...defaultMeta };
            } else {
                this.fetchMembers();
            }

            if (config.initialTiers?.length) {
                this.tiers = config.initialTiers;
            } else {
                this.fetchTiers();
            }

            this.listenForReconciliation();
        },
        listenForReconciliation() {
            window.addEventListener("community-members:refresh", () => {
                this.fetchMembers(this.memberMeta.current_page || 1);
                this.fetchTiers();
            });
        },
        async fetchMembers(page = 1) {
            this.loading.members = true;
            try {
                const res = await fetch(`${this.endpoints.members}?page=${page}`, {
                    headers: { Accept: "application/json" },
                    credentials: "include",
                });
                const data = await res.json();
                if (!res.ok) throw data;
                this.members = data.data || [];
                this.memberMeta = data.meta || { ...defaultMeta };
            } catch (error) {
                console.error("Failed to load members", error);
            } finally {
                this.loading.members = false;
            }
        },
        async fetchTiers() {
            this.loading.tiers = true;
            try {
                const res = await fetch(this.endpoints.tiers, {
                    headers: { Accept: "application/json" },
                    credentials: "include",
                });
                const data = await res.json();
                if (!res.ok) throw data;
                this.tiers = data.data || [];
            } catch (error) {
                console.error("Failed to load tiers", error);
            } finally {
                this.loading.tiers = false;
            }
        },
        openMemberModal(member = null) {
            if (!member && !this.hasTiers) {
                window.toast?.error?.("Add a tier before adding members.");
                return;
            }

            this.errors.member = {};
            if (member) {
                this.memberForm = {
                    id: member.id,
                    name: member.display_name || "",
                    email: member.email || "",
                    membership_tier_id: member.membership_tier_id,
                    status: member.status || "active",
                };
            } else {
                this.memberForm = emptyMemberForm();
            }
            this.modals.member = true;
        },
        openTierModal(tier = null) {
            this.errors.tier = {};
            if (tier) {
                this.tierForm = {
                    id: tier.id,
                    name: tier.name || "",
                    amount_cents: tier.amount_cents,
                    currency: tier.currency || "USD",
                    description: tier.description || "",
                    is_active: tier.is_active ?? true,
                };
            } else {
                this.tierForm = emptyTierForm();
            }
            this.modals.tier = true;
        },
        openSettings() {
            this.errors.settings = {};
            this.modals.settings = true;
        },
        async saveMember() {
            this.loading.savingMember = true;
            this.errors.member = {};

            const payload = {
                name: this.memberForm.name,
                email: this.memberForm.email,
                membership_tier_id: this.memberForm.membership_tier_id || null,
                status: this.memberForm.status || "active",
            };

            const isUpdate = Boolean(this.memberForm.id);
            const url = isUpdate
                ? `${this.endpoints.members}/${this.memberForm.id}`
                : this.endpoints.members;

            try {
                const res = await fetch(url, {
                    method: isUpdate ? "PUT" : "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                    },
                    credentials: "include",
                    body: JSON.stringify(payload),
                });

                const data = await res.json();
                if (!res.ok) throw data;

                const saved = data.data || data;
                if (isUpdate) {
                    this.members = this.members.map((item) =>
                        item.id === saved.id ? saved : item
                    );
                } else {
                    this.members = [saved, ...this.members].slice(
                        0,
                        this.memberMeta.per_page || 10
                    );
                    this.memberMeta.total = (this.memberMeta.total || 0) + 1;
                }

                this.modals.member = false;
            } catch (error) {
                this.errors.member = error?.errors || { general: [error?.message] };
            } finally {
                this.loading.savingMember = false;
            }
        },
        async saveTier() {
            this.loading.savingTier = true;
            this.errors.tier = {};

            const normalizedAmount =
                this.tierForm.amount_cents === "" ||
                this.tierForm.amount_cents === null ||
                typeof this.tierForm.amount_cents === "undefined"
                    ? null
                    : Number(this.tierForm.amount_cents);

            const payload = {
                name: this.tierForm.name,
                amount_cents: Number.isFinite(normalizedAmount)
                    ? normalizedAmount
                    : null,
                currency: this.tierForm.currency || "USD",
                description: this.tierForm.description || null,
                is_active: this.tierForm.is_active,
            };

            const isUpdate = Boolean(this.tierForm.id);
            const url = isUpdate
                ? `${this.endpoints.tiers}/${this.tierForm.id}`
                : this.endpoints.tiers;

            try {
                const res = await fetch(url, {
                    method: isUpdate ? "PUT" : "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                    },
                    credentials: "include",
                    body: JSON.stringify(payload),
                });

                const data = await res.json();
                if (!res.ok) throw data;

                const saved = data.data || data;
                if (isUpdate) {
                    this.tiers = this.tiers.map((item) =>
                        item.id === saved.id ? saved : item
                    );
                } else {
                    this.tiers = [saved, ...this.tiers];
                }

                this.modals.tier = false;
            } catch (error) {
                this.errors.tier = error?.errors || { general: [error?.message] };

                const message =
                    error?.message ||
                    this.errors.tier?.general?.[0] ||
                    this.errors.tier?.amount_cents?.[0];

                if (message && window.toast?.error) {
                    window.toast.error(message);
                }
            } finally {
                this.loading.savingTier = false;
            }
        },
        async deleteMember(id) {
            this.errors.member = {};
            this.modals.confirmMemberId = id;
        },
        async confirmDeleteMember() {
            if (!this.modals.confirmMemberId) return;
            const id = this.modals.confirmMemberId;
            try {
                const res = await fetch(`${this.endpoints.members}/${id}`, {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                    },
                    credentials: "include",
                });
                const data = res.status !== 204 ? await res.json() : {};
                if (!res.ok) throw data;

                this.members = this.members.filter((item) => item.id !== id);
                this.memberMeta.total = Math.max(
                    (this.memberMeta.total || 1) - 1,
                    0
                );
            } catch (error) {
                this.errors.member = error?.errors || { general: [error?.message] };
            } finally {
                this.modals.confirmMemberId = null;
            }
        },
        async deleteTier(id) {
            this.errors.tier = {};
            this.modals.confirmTierId = id;
        },
        async confirmDeleteTier() {
            if (!this.modals.confirmTierId) return;
            const id = this.modals.confirmTierId;
            try {
                const res = await fetch(`${this.endpoints.tiers}/${id}`, {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                    },
                    credentials: "include",
                });
                const data = res.status !== 204 ? await res.json() : {};
                if (!res.ok) throw data;

                this.tiers = this.tiers.filter((item) => item.id !== id);
            } catch (error) {
                this.errors.tier = error?.errors || { general: [error?.message] };
            } finally {
                this.modals.confirmTierId = null;
            }
        },
        async saveSettings() {
            this.loading.savingSettings = true;
            this.errors.settings = {};
            try {
                const res = await fetch(this.endpoints.settings, {
                    method: "PUT",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                    },
                    credentials: "include",
                    body: JSON.stringify({
                        enabled: true,
                        name: this.settingsForm.name,
                    }),
                });

                const data = await res.json();
                if (!res.ok || !data.ok) throw data;

                this.organizationName = data.organization?.name || this.settingsForm.name;
                this.modals.settings = false;
            } catch (error) {
                this.errors.settings = error?.errors || { general: [error?.message] };
            } finally {
                this.loading.savingSettings = false;
            }
        },
        formatMoney(amountCents, currency = "USD") {
            if (amountCents === null || amountCents === undefined) {
                return "No amount set";
            }

            const value = Number(amountCents) / 100;
            const code = currency || "USD";

            try {
                return new Intl.NumberFormat(undefined, {
                    style: "currency",
                    currency: code,
                    minimumFractionDigits: 2,
                }).format(value);
            } catch (error) {
                console.warn("Unable to format currency", error);
                return `${code} ${value.toFixed(2)}`;
            }
        },
        statusLabel(status) {
            if (!status) return "Unknown";
            return status
                .replace(/_/g, " ")
                .replace(/^./, (s) => s.toUpperCase());
        },
        get hasTiers() {
            return Array.isArray(this.tiers) && this.tiers.length > 0;
        },
    };
}

function registerCommunityMembersStore() {
    const alpine = window.Alpine;

    if (!alpine) return;

    // Register the global store + component only once per page load.
    if (!alpine.store("communityMembers")) {
        alpine.store("communityMembers", createCommunityMembersStore());
    }

    alpine.data("communityMembersHub", communityMembersHub);
    alpine.data("dropdownSelect", ({ options = [] }) => ({
        openList: false,
        options,
        selected: null,
        select(option) {
            this.selected = option;
            this.openList = false;
        },
    }));
}

// Ensure registration works whether Alpine has already been injected or not.
if (window.Alpine) {
    registerCommunityMembersStore();
}

document.addEventListener("alpine:init", registerCommunityMembersStore);

registerCreateLeagueHandler();
registerDetachLeagueHandler();
registerRefreshDiscordMembersHandler();

function communityMembersHub(config) {
    return {
        activeTab: "home",
        activeLeagueTab: "draft",
        activeCommunityDraftTab: "live",
        theme: "light",
        selectedLeague: null,
        leagueTeams: [],
        leagueTeamsLoading: false,
        leagueTeamsError: "",
        leagueTeamsRequestUrl: "",
        leagueDraftSummary: null,
        leagueDraftLiveHtml: "",
        leagueDraftSummaryLoading: false,
        leagueDraftSummaryError: "",
        leagueDraftSummaryRequestUrl: "",
        leagueDraftClockNow: Date.now(),
        leagueDraftClockTimer: null,
        activeRound: 0,
        roundScrollCanLeft: false,
        roundScrollCanRight: false,
        showAvatars: true,
        showTeamBadges: true,
        init() {
            // Ensure the store exists even if Alpine boot order changes.
            if (!this.$store.communityMembers) {
                registerCommunityMembersStore();
            }

            this.$store.communityMembers.bootstrap(config);
            this.leagueDraftClockTimer = window.setInterval(() => {
                this.leagueDraftClockNow = Date.now();
            }, 1000);
        },
        destroy() {
            if (this.leagueDraftClockTimer) {
                window.clearInterval(this.leagueDraftClockTimer);
            }
        },
        selectCommunityTab(tab) {
            this.selectedLeague = null;
            this.activeTab = tab;
        },
        openCommunityLeague(league, tab = "draft") {
            this.selectedLeague = league;
            this.activeLeagueTab = ["home", "teams", "draft", "setup"].includes(tab)
                ? tab
                : "draft";
            this.activeCommunityDraftTab = "live";
            this.leagueTeams = [];
            this.leagueTeamsError = "";
            this.leagueDraftSummary = null;
            this.leagueDraftLiveHtml = "";
            this.leagueDraftSummaryError = "";

            if (this.activeLeagueTab === "teams") {
                this.loadCommunityLeagueTeams();
            } else if (this.activeLeagueTab === "draft") {
                this.loadCommunityLeagueDraftSummary();
            }
        },
        openCommunityLeagueTab(tab) {
            this.activeLeagueTab = ["home", "teams", "draft", "setup"].includes(tab)
                ? tab
                : "draft";

            if (this.activeLeagueTab === "teams") {
                this.loadCommunityLeagueTeams();
            } else if (this.activeLeagueTab === "draft") {
                this.loadCommunityLeagueDraftSummary();
            }
        },
        setCommunityDraftTab(tab) {
            this.activeCommunityDraftTab = ["live", "players"].includes(tab)
                ? tab
                : "live";

            if (this.activeCommunityDraftTab === "live") {
                this.$nextTick(() => this.initializeCommunityDraftLivePanel());
            }
        },
        async loadCommunityLeagueTeams() {
            const url = this.selectedLeague?.teamsUrl || "";

            if (!url) {
                return;
            }

            this.leagueTeamsLoading = true;
            this.leagueTeamsError = "";
            this.leagueTeamsRequestUrl = url;

            try {
                const response = await fetch(url, {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not load teams.");
                }

                if (this.leagueTeamsRequestUrl !== url) {
                    return;
                }

                this.leagueTeams = Array.isArray(payload.teams) ? payload.teams : [];
            } catch (error) {
                if (this.leagueTeamsRequestUrl !== url) {
                    return;
                }

                console.error("[communityLeagueTeams] Unable to load teams:", error);
                this.leagueTeams = [];
                this.leagueTeamsError = error?.message || "Could not load teams.";
            } finally {
                if (this.leagueTeamsRequestUrl === url) {
                    this.leagueTeamsLoading = false;
                }
            }
        },
        async loadCommunityLeagueDraftSummary() {
            const url = this.selectedLeague?.draftSummaryUrl || "";

            if (!url) {
                return;
            }

            this.leagueDraftSummaryLoading = true;
            this.leagueDraftSummaryError = "";
            this.leagueDraftSummaryRequestUrl = url;

            try {
                const response = await fetch(url, {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not load draft summary.");
                }

                if (this.leagueDraftSummaryRequestUrl !== url) {
                    return;
                }

                this.leagueDraftSummary = payload.summary || null;
                this.leagueDraftLiveHtml = String(payload.live_html || "");
                this.activeRound = Number(payload.active_round_index || 0);
                this.$nextTick(() => this.initializeCommunityDraftLivePanel());
            } catch (error) {
                if (this.leagueDraftSummaryRequestUrl !== url) {
                    return;
                }

                console.error("[communityLeagueDraft] Unable to load draft summary:", error);
                this.leagueDraftSummary = null;
                this.leagueDraftLiveHtml = "";
                this.leagueDraftSummaryError =
                    error?.message || "Could not load draft summary.";
            } finally {
                if (this.leagueDraftSummaryRequestUrl === url) {
                    this.leagueDraftSummaryLoading = false;
                }
            }
        },
        draftStatusDotClass() {
            const tone = this.leagueDraftSummary?.status_tone || "slate";

            if (tone === "green") return "bg-emerald-500";
            if (tone === "blue") return "bg-blue-500";

            return "bg-slate-400";
        },
        draftTimeRemainingLabel() {
            const expiresAt = this.leagueDraftSummary?.countdown_expires_at || "";

            if (!expiresAt || this.leagueDraftSummary?.is_completed) {
                return this.leagueDraftSummary?.time_remaining_label || "--:--";
            }

            const expires = Date.parse(expiresAt);

            if (!Number.isFinite(expires)) {
                return this.leagueDraftSummary?.time_remaining_label || "--:--";
            }

            const remaining = Math.max(
                0,
                Math.floor((expires - this.leagueDraftClockNow) / 1000)
            );
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;

            if (hours > 0) {
                return `${hours}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
            }

            return `${minutes}:${String(seconds).padStart(2, "0")}`;
        },
        initializeCommunityDraftLivePanel() {
            const panel = this.$refs.communityDraftLivePanel;

            if (!panel) {
                return;
            }

            const html = String(this.leagueDraftLiveHtml || "");
            const signature = `${html.length}:${html.slice(0, 48)}:${html.slice(-48)}`;

            if (panel.dataset.communityDraftLiveInitialized === signature) {
                this.$nextTick(() => this.updateRoundScrollAffordance());
                return;
            }

            window.Alpine?.initTree(panel);
            panel.dataset.communityDraftLiveInitialized = signature;
            this.$nextTick(() => this.updateRoundScrollAffordance());
        },
        setActiveRound(index) {
            this.activeRound = Number(index || 0);
            this.$nextTick(() => this.updateRoundScrollAffordance());
        },
        updateRoundScrollAffordance() {
            const scroller = this.$refs.roundTabsScroller;

            if (!scroller) {
                this.roundScrollCanLeft = false;
                this.roundScrollCanRight = false;
                return;
            }

            const maxScrollLeft = scroller.scrollWidth - scroller.clientWidth;

            this.roundScrollCanLeft = scroller.scrollLeft > 2;
            this.roundScrollCanRight = scroller.scrollLeft < maxScrollLeft - 2;
        },
    };
}

// Also expose the factory globally so x-data can resolve it even before Alpine
// processes named data providers.
window.communityMembersHub = communityMembersHub;

export { registerCommunityMembersStore };
