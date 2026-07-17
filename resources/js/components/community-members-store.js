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

const teamGradients = {
    ANA: "linear-gradient(to bottom, #FF6F00, #000000)",
    ARI: "linear-gradient(to bottom, #8C2633, #000000)",
    BOS: "linear-gradient(to bottom, #FFB81C, #000000)",
    BUF: "linear-gradient(to bottom, #002654, #FDBB2F)",
    CGY: "linear-gradient(to bottom, #C8102E, #F1BE48)",
    CAR: "linear-gradient(to bottom, #CC0000, #000000)",
    CHI: "linear-gradient(to bottom, #CF0A2C, #000000)",
    COL: "linear-gradient(to bottom, #6F263D, #236192)",
    CBJ: "linear-gradient(to bottom, #002654, #A6A6A6)",
    DAL: "linear-gradient(to bottom, #006847, #000000)",
    DET: "linear-gradient(to bottom, #CE1126, #FFFFFF)",
    EDM: "linear-gradient(to bottom, #FF4C00, #041E42)",
    FLA: "linear-gradient(to bottom, #041E42, #C8102E)",
    LAK: "linear-gradient(to bottom, #A2AAAD, #000000)",
    MIN: "linear-gradient(to bottom, #154734, #A6192E)",
    MTL: "linear-gradient(to bottom, #AF1E2D, #192168)",
    NSH: "linear-gradient(to bottom, #FFB81C, #041E42)",
    NJD: "linear-gradient(to bottom, #CE1126, #000000)",
    NYI: "linear-gradient(to bottom, #00539B, #F47D30)",
    NYR: "linear-gradient(to bottom, #0038A8, #CE1126)",
    OTT: "linear-gradient(to bottom, #E31837, #000000)",
    PHI: "linear-gradient(to bottom, #FA4616, #000000)",
    PIT: "linear-gradient(to bottom, #FFB81C, #000000)",
    SEA: "linear-gradient(to bottom, #001628, #99D9D9)",
    SJS: "linear-gradient(to bottom, #006D75, #000000)",
    STL: "linear-gradient(to bottom, #002F87, #FDB827)",
    TBL: "linear-gradient(to bottom, #002868, #00529B)",
    TOR: "linear-gradient(to bottom, #00205B, #003E7E)",
    UTA: "linear-gradient(to bottom, #6CACE4, #000000)",
    VAN: "linear-gradient(to bottom, #00205B, #00843D)",
    VGK: "linear-gradient(to bottom, #B4975A, #333F48)",
    WSH: "linear-gradient(to bottom, #C8102E, #041E42)",
    WPG: "linear-gradient(to bottom, #041E42, #7B303D)",
};

const fallbackTeamGradient = "linear-gradient(to bottom, #e5e7eb, #9ca3af)";

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
        draftOptionsOpen: false,
        draftOptionsLoading: false,
        draftOptionsError: "",
        draftOptionsLoadedUrl: "",
        draftSettingsActionUrl: "",
        draftDiscordConnected: false,
        draftChannelOptions: [],
        draftChannelsMessage: "",
        draftChannelOpen: false,
        draftChannelQuery: "",
        draftChannelId: "",
        draftChannelSaving: false,
        draftChannelMessage: "",
        draftAnnounceOtc: true,
        draftAnnounceOnDeck: false,
        draftPickClockHours: 0,
        draftPickClockMinutes: 5,
        draftPickClockSeconds: 0,
        draftPauseSeconds: 0,
        draftAutoPickEnabled: false,
        draftTimerCanUpdate: false,
        draftTimerSaving: false,
        draftTimerMessage: "",
        communityDraftPlayerSearch: "",
        communityDraftPlayerTeam: "",
        communityDraftPlayerTeams: [],
        communityDraftPlayerPerspectives: [
            { slug: "entry-draft", name: "2026 Entry Draft", entry_draft_year: 2026 },
            { slug: "entry-draft-goalies", name: "2026 Entry Draft - Goalies", entry_draft_year: 2026 },
        ],
        communityDraftSelectedPerspective: "entry-draft",
        communityDraftLatestEntryDraftYear: 2026,
        communityDraftPlayersPayloadUrl: "",
        communityDraftStatsPayloadUrl: "",
        communityDraftPlayersLoading: false,
        communityDraftPlayersError: "",
        communityDraftPlayerRows: [],
        communityDraftPlayerHeadings: [],
        communityDraftPlayerLoaded: false,
        communityDraftPlayerRequestKey: "",
        communityDraftPlayerRequestToken: 0,
        communityDraftPlayerCache: {},
        communityDraftPlayerSortKey: "drafted_overall_pick",
        communityDraftPlayerSortDirection: "asc",
        communityDraftTestingUrl: "",
        communityDraftTestingSimulateUrl: "",
        communityDraftTestingLoading: false,
        communityDraftTestingSimulating: false,
        communityDraftTestingError: "",
        communityDraftTestingMessage: "",
        communityDraftTestingPlayers: [],
        communityDraftTestingSelectedPlayerId: "",
        communityDraftTestingCurrentPick: null,
        communityDraftTestingOnDeckPick: null,
        communityDraftTestingSimulatedPickKeys: [],
        communityDraftTestingSimulatedPlayerIds: [],
        communityDraftTestingSimulatedPlayersByPickKey: {},
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
            this.resetCommunityDraftPlayers();
            this.resetCommunityDraftTesting();
            this.resetDraftOptions();

            if (this.activeLeagueTab === "teams") {
                this.loadCommunityLeagueTeams();
            } else if (this.activeLeagueTab === "draft") {
                this.loadCommunityLeagueDraftSummary();
            }
        },
        resetDraftOptions() {
            this.draftOptionsOpen = false;
            this.draftOptionsLoading = false;
            this.draftOptionsError = "";
            this.draftOptionsLoadedUrl = "";
            this.draftSettingsActionUrl = "";
            this.draftDiscordConnected = false;
            this.draftChannelOptions = [];
            this.draftChannelsMessage = "";
            this.draftChannelOpen = false;
            this.draftChannelQuery = "";
            this.draftChannelId = "";
            this.draftChannelSaving = false;
            this.draftChannelMessage = "";
            this.draftAnnounceOtc = true;
            this.draftAnnounceOnDeck = false;
            this.draftPickClockHours = 0;
            this.draftPickClockMinutes = 5;
            this.draftPickClockSeconds = 0;
            this.draftPauseSeconds = 0;
            this.draftAutoPickEnabled = false;
            this.draftTimerCanUpdate = false;
            this.draftTimerSaving = false;
            this.draftTimerMessage = "";
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
            this.activeCommunityDraftTab = ["live", "players", "testing"].includes(tab)
                ? tab
                : "live";

            if (["live", "testing"].includes(this.activeCommunityDraftTab)) {
                if (this.activeCommunityDraftTab === "testing") {
                    this.loadCommunityDraftTesting();
                }

                this.$nextTick(() => this.initializeCommunityDraftLivePanel());
            } else {
                this.loadCommunityDraftPlayers();
            }
        },
        resetCommunityDraftPlayers() {
            this.communityDraftPlayerSearch = "";
            this.communityDraftPlayerTeam = "";
            this.communityDraftPlayerTeams = [];
            this.communityDraftPlayerPerspectives = [
                { slug: "entry-draft", name: "2026 Entry Draft", entry_draft_year: 2026 },
                { slug: "entry-draft-goalies", name: "2026 Entry Draft - Goalies", entry_draft_year: 2026 },
            ];
            this.communityDraftSelectedPerspective = "entry-draft";
            this.communityDraftLatestEntryDraftYear = 2026;
            this.communityDraftPlayersPayloadUrl = this.selectedLeague?.playersPayloadUrl || "";
            this.communityDraftStatsPayloadUrl = this.selectedLeague?.leagueStatsPayloadUrl || "";
            this.communityDraftPlayersLoading = false;
            this.communityDraftPlayersError = "";
            this.communityDraftPlayerRows = [];
            this.communityDraftPlayerHeadings = [];
            this.communityDraftPlayerLoaded = false;
            this.communityDraftPlayerRequestKey = "";
            this.communityDraftPlayerRequestToken = 0;
            this.communityDraftPlayerCache = {};
            this.communityDraftPlayerSortKey = "drafted_overall_pick";
            this.communityDraftPlayerSortDirection = "asc";
        },
        resetCommunityDraftTesting() {
            this.communityDraftTestingUrl = this.selectedLeague?.draftTestingUrl || "";
            this.communityDraftTestingSimulateUrl = this.selectedLeague?.draftTestingSimulateUrl || "";
            this.communityDraftTestingLoading = false;
            this.communityDraftTestingSimulating = false;
            this.communityDraftTestingError = "";
            this.communityDraftTestingMessage = "";
            this.communityDraftTestingPlayers = [];
            this.communityDraftTestingSelectedPlayerId = "";
            this.communityDraftTestingCurrentPick = null;
            this.communityDraftTestingOnDeckPick = null;
            this.communityDraftTestingSimulatedPickKeys = [];
            this.communityDraftTestingSimulatedPlayerIds = [];
            this.communityDraftTestingSimulatedPlayersByPickKey = {};
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
        openDraftOptions() {
            this.draftOptionsOpen = true;
            this.loadDraftOptions();
        },
        async loadDraftOptions() {
            const url = this.selectedLeague?.draftSettingsUrl || "";

            if (!url || this.draftOptionsLoading || this.draftOptionsLoadedUrl === url) {
                return;
            }

            this.draftOptionsLoading = true;
            this.draftOptionsError = "";
            this.draftOptionsLoadedUrl = url;

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
                    throw new Error(payload?.message || "Could not load draft options.");
                }

                const config = payload.config || {};
                const selectedChannel = config.selected_channel || {};

                this.draftSettingsActionUrl = config.action_url || "";
                this.draftDiscordConnected = Boolean(config.discord_connected);
                this.draftChannelOptions = Array.isArray(config.channels) ? config.channels : [];
                this.draftChannelsMessage = config.channels_message || "";
                this.draftChannelId = selectedChannel.id || "";
                this.draftChannelQuery = selectedChannel.name
                    ? `#${selectedChannel.name}`
                    : "";
                this.applyDraftNotificationConfig(config.notifications || {});
                this.applyDraftTimerConfig(config.timer || {});
            } catch (error) {
                console.error("[communityDraftOptions] Unable to load options:", error);
                this.draftOptionsError = error?.message || "Could not load draft options.";
                this.draftOptionsLoadedUrl = "";
            } finally {
                this.draftOptionsLoading = false;
            }
        },
        applyDraftTimerConfig(timer) {
            this.draftPickClockHours = Number(timer.pick_clock_hours || 0);
            this.draftPickClockMinutes = Number(timer.pick_clock_minutes || 0);
            this.draftPickClockSeconds = Number(timer.pick_clock_seconds_remainder || 0);
            this.draftPauseSeconds = Number(timer.pause_between_picks_seconds || 0);
            this.draftAutoPickEnabled = Boolean(timer.auto_pick_enabled);
            this.draftTimerCanUpdate = Boolean(timer.can_update);
        },
        applyDraftNotificationConfig(notifications) {
            this.draftAnnounceOtc = Boolean(notifications.announce_otc);
            this.draftAnnounceOnDeck = Boolean(notifications.announce_on_deck);
        },
        normalizedDraftClockSeconds() {
            const hours = Math.max(0, Number(this.draftPickClockHours || 0));
            const minutes = Math.max(0, Number(this.draftPickClockMinutes || 0));
            const seconds = Math.max(0, Number(this.draftPickClockSeconds || 0));

            return Math.min(86400, Math.floor(hours * 3600 + minutes * 60 + seconds));
        },
        selectDraftChannel(channel) {
            this.draftChannelId = channel?.id || "";
            this.draftChannelQuery = channel?.name ? `#${channel.name}` : "";
            this.draftChannelOpen = false;
        },
        async saveDraftChannel() {
            if (!this.draftSettingsActionUrl || this.draftChannelSaving) {
                return;
            }

            this.draftChannelSaving = true;
            this.draftChannelMessage = "";

            try {
                const response = await fetch(this.draftSettingsActionUrl, {
                    method: "PUT",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        draft_channel_id: this.draftChannelId || null,
                        draft_channel_name: this.draftChannelQuery.replace(/^#/, "").trim() || null,
                        announce_otc: Boolean(this.draftAnnounceOtc),
                        announce_on_deck: Boolean(this.draftAnnounceOnDeck),
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not save draft options.");
                }

                const channel = payload.channel || {};
                this.draftChannelId = channel.id || "";
                this.draftChannelQuery = channel.name ? `#${channel.name}` : "";
                this.applyDraftNotificationConfig(payload.notifications || {});
                this.applyDraftTimerConfig(payload.timer || {});
                this.draftChannelMessage = channel.name
                    ? `Draft picks will post to #${channel.name}.`
                    : "Draft pick channel cleared.";
            } catch (error) {
                console.error("[communityDraftOptions] Unable to save channel:", error);
                this.draftChannelMessage = error?.message || "Could not save draft options.";
            } finally {
                this.draftChannelSaving = false;
            }
        },
        async saveDraftTimerSettings() {
            if (!this.draftSettingsActionUrl || this.draftTimerSaving) {
                return;
            }

            this.draftTimerSaving = true;
            this.draftTimerMessage = "";

            try {
                const response = await fetch(this.draftSettingsActionUrl, {
                    method: "PUT",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        pick_clock_seconds: this.normalizedDraftClockSeconds(),
                        pause_between_picks_seconds: Number(this.draftPauseSeconds || 0),
                        auto_pick_enabled: Boolean(this.draftAutoPickEnabled),
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not save draft timer.");
                }

                this.applyDraftTimerConfig(payload.timer || {});
                this.draftTimerMessage = "Draft timer settings saved.";
                if (this.selectedLeague?.draftSummaryUrl) {
                    this.loadCommunityLeagueDraftSummary();
                }
            } catch (error) {
                console.error("[communityDraftOptions] Unable to save timer:", error);
                this.draftTimerMessage = error?.message || "Could not save draft timer.";
            } finally {
                this.draftTimerSaving = false;
            }
        },
        get filteredDraftChannels() {
            const query = this.draftChannelQuery.replace(/^#/, "").toLowerCase().trim();

            if (!query) {
                return this.draftChannelOptions;
            }

            return this.draftChannelOptions.filter((channel) => (
                String(channel?.name || "").toLowerCase().includes(query)
            ));
        },
        async loadCommunityDraftTesting(force = false) {
            if (!this.communityDraftTestingUrl || (this.communityDraftTestingLoading && !force)) {
                return;
            }

            if (!force && this.communityDraftTestingPlayers.length > 0) {
                return;
            }

            this.communityDraftTestingLoading = true;
            this.communityDraftTestingError = "";

            try {
                const params = new URLSearchParams();
                this.communityDraftTestingSimulatedPickKeys.forEach((key) => {
                    params.append("simulated_pick_keys[]", key);
                });
                const url = params.toString()
                    ? `${this.communityDraftTestingUrl}?${params.toString()}`
                    : this.communityDraftTestingUrl;
                const response = await fetch(url, {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not load draft testing.");
                }

                this.communityDraftTestingPlayers = Array.isArray(payload.players)
                    ? payload.players
                    : [];
                this.communityDraftTestingCurrentPick = payload.current_pick || null;
                this.communityDraftTestingOnDeckPick = payload.on_deck_pick || null;
                if (this.communityDraftTestingCurrentPick?.round) {
                    this.setActiveRoundForTestingPick(this.communityDraftTestingCurrentPick);
                }
                this.applyDraftNotificationConfig(payload.notifications || {});
            } catch (error) {
                console.error("[communityDraftTesting] Unable to load testing controls:", error);
                this.communityDraftTestingError = error?.message || "Could not load draft testing.";
            } finally {
                this.communityDraftTestingLoading = false;
            }
        },
        async simulateCommunityDraftPick() {
            if (!this.communityDraftTestingSimulateUrl || this.communityDraftTestingSimulating) {
                return;
            }

            const playerId = Number(this.communityDraftTestingSelectedPlayerId || 0);

            if (playerId <= 0) {
                this.communityDraftTestingError = "Select an entry draft player first.";
                return;
            }

            this.communityDraftTestingSimulating = true;
            this.communityDraftTestingError = "";
            this.communityDraftTestingMessage = "";

            try {
                const response = await fetch(this.communityDraftTestingSimulateUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        player_id: playerId,
                        simulated_pick_keys: this.communityDraftTestingSimulatedPickKeys,
                        announce_otc: Boolean(this.draftAnnounceOtc),
                        announce_on_deck: Boolean(this.draftAnnounceOnDeck),
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not simulate draft pick.");
                }

                const selectedPlayer = this.communityDraftTestingPlayers
                    .find((player) => Number(player?.id || 0) === playerId) || null;
                const pickedKey = payload.picked_key || "";

                this.communityDraftTestingSimulatedPickKeys = Array.isArray(payload.simulated_pick_keys)
                    ? payload.simulated_pick_keys
                    : [...this.communityDraftTestingSimulatedPickKeys, pickedKey].filter(Boolean);
                this.communityDraftTestingSimulatedPlayerIds = [
                    ...new Set([...this.communityDraftTestingSimulatedPlayerIds, playerId]),
                ];
                if (pickedKey && selectedPlayer) {
                    this.communityDraftTestingSimulatedPlayersByPickKey = {
                        ...this.communityDraftTestingSimulatedPlayersByPickKey,
                        [pickedKey]: selectedPlayer,
                    };
                }
                this.communityDraftTestingCurrentPick = payload.current_pick || null;
                this.communityDraftTestingOnDeckPick = payload.on_deck_pick || null;
                if (this.communityDraftTestingCurrentPick?.round) {
                    this.setActiveRoundForTestingPick(this.communityDraftTestingCurrentPick);
                }
                this.communityDraftTestingSelectedPlayerId = "";
                this.communityDraftTestingMessage = payload.message || "Discord test announcement sent.";
            } catch (error) {
                console.error("[communityDraftTesting] Unable to simulate pick:", error);
                this.communityDraftTestingError = error?.message || "Could not simulate draft pick.";
            } finally {
                this.communityDraftTestingSimulating = false;
            }
        },
        communityDraftTestingPickLabel(pick) {
            if (!pick) return "No OTC pick available";

            const round = pick.round || "-";
            const pickInRound = pick.pick_in_round || pick.pick || "-";
            const overall = pick.overall_pick ? `, #${pick.overall_pick}` : "";

            return `${pick.team_name || "Unknown team"} - Round ${round}, Pick ${pickInRound}${overall}`;
        },
        communityDraftTestingIsCurrentPick(pickKey) {
            return String(this.communityDraftTestingCurrentPick?.key || "") === String(pickKey || "");
        },
        communityDraftTestingSimulatedPlayerForPick(pickKey) {
            return this.communityDraftTestingSimulatedPlayersByPickKey[String(pickKey || "")] || null;
        },
        setActiveRoundForTestingPick(pick) {
            const round = Number(pick?.round || 0);

            if (round <= 0 || !this.leagueDraftSummary?.rounds) {
                return;
            }

            const index = this.leagueDraftSummary.rounds.findIndex((item) => Number(item?.round || 0) === round);

            if (index >= 0) {
                this.setActiveRound(index);
            }
        },
        communityDraftTestingPlayerLabel(player) {
            const parts = [];

            if (player?.draft_oa) parts.push(`#${player.draft_oa}`);
            if (player?.position) parts.push(player.position);
            if (player?.team_abbrev) parts.push(player.team_abbrev);

            return parts.length > 0
                ? `${player?.name || "Unknown player"} (${parts.join(" / ")})`
                : (player?.name || "Unknown player");
        },
        get communityDraftTestingAvailablePlayers() {
            const used = new Set(this.communityDraftTestingSimulatedPlayerIds.map(Number));

            return this.communityDraftTestingPlayers.filter((player) => !used.has(Number(player?.id || 0)));
        },
        async loadCommunityDraftPlayers(force = false) {
            if (this.communityDraftPlayersLoading && !force) {
                return;
            }

            if (!this.communityDraftPlayersPayloadUrl) {
                this.communityDraftPlayersError = "Could not load draft players.";
                return;
            }

            this.communityDraftPlayersLoading = true;
            this.communityDraftPlayersError = "";

            try {
                const response = await fetch(this.communityDraftPlayersPayloadUrl, {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.ok !== true) {
                    throw new Error(payload?.message || "Could not load draft players.");
                }

                this.communityDraftLatestEntryDraftYear =
                    Number(payload.latestEntryDraftYear || 0) || null;
                this.communityDraftStatsPayloadUrl =
                    payload.leagueStatsPayloadUrl || this.communityDraftStatsPayloadUrl;
                this.communityDraftPlayerPerspectives = Array.isArray(payload.perspectives)
                    ? payload.perspectives
                    : [];

                this.communityDraftSelectedPerspective = this.communityDraftPlayerPerspectives
                    .some((perspective) => String(perspective?.slug || "") === "entry-draft")
                    ? "entry-draft"
                    : (this.communityDraftPlayerPerspectives[0]?.slug || "prospects");
                this.applyCommunityDraftPlayerSort(this.communityDraftSelectedPerspective);

                await this.loadCommunityDraftPlayerStats(true);
            } catch (error) {
                console.error("[communityDraftPlayers] Unable to load players:", error);
                this.communityDraftPlayersError =
                    error?.message || "Could not load draft players.";
            } finally {
                this.communityDraftPlayersLoading = false;
            }
        },
        async loadCommunityDraftPlayerStats(force = false) {
            if (!this.communityDraftStatsPayloadUrl) {
                this.communityDraftPlayerLoaded = true;
                return;
            }

            const selectedPerspective = this.communityDraftSelectedPerspective || "prospects";
            const isEntryDraftPerspective = ["entry-draft", "entry-draft-goalies"].includes(selectedPerspective);
            const entryDraftYear = isEntryDraftPerspective
                ? Number(this.communityDraftLatestEntryDraftYear || 0)
                : 0;
            const requestPerspective = selectedPerspective === "entry-draft"
                ? "prospects"
                : (selectedPerspective === "entry-draft-goalies" ? "prospects-goalies" : selectedPerspective);
            const requestKey = isEntryDraftPerspective
                ? `${selectedPerspective}:${entryDraftYear}`
                : requestPerspective;

            if (!force && this.communityDraftPlayerRequestKey === requestKey) {
                return;
            }

            if (this.communityDraftPlayerCache[requestKey]) {
                this.applyCommunityDraftPlayerStats(this.communityDraftPlayerCache[requestKey], requestKey);
                return;
            }

            const requestToken = this.communityDraftPlayerRequestToken + 1;
            this.communityDraftPlayerRequestToken = requestToken;
            this.communityDraftPlayersLoading = true;

            const params = new URLSearchParams();
            params.set("perspective", requestPerspective);
            params.set("resource", "players");
            params.set("period", "season");
            params.set("slice", "total");
            params.set("game_type", "2");
            params.set("availability", "0");
            params.set("draft_context", "1");

            if (isEntryDraftPerspective && entryDraftYear > 0) {
                params.set("entry_draft_year", String(entryDraftYear));
            }

            try {
                const response = await fetch(`${this.communityDraftStatsPayloadUrl}?${params.toString()}`, {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload?.message || "Could not load player stats.");
                }

                if (this.communityDraftPlayerRequestToken !== requestToken) {
                    return;
                }

                this.communityDraftPlayerCache[requestKey] = payload;
                this.applyCommunityDraftPlayerStats(payload, requestKey);
            } catch (error) {
                if (this.communityDraftPlayerRequestToken === requestToken) {
                    console.error("[communityDraftPlayers] Unable to load stats:", error);
                    this.communityDraftPlayersError =
                        error?.message || "Could not load player stats.";
                    this.communityDraftPlayerLoaded = true;
                }
            } finally {
                if (this.communityDraftPlayerRequestToken === requestToken) {
                    this.communityDraftPlayersLoading = false;
                }
            }
        },
        applyCommunityDraftPlayerStats(payload, requestKey) {
            this.communityDraftPlayerHeadings = Array.isArray(payload.headings)
                ? payload.headings
                : [];
            this.communityDraftPlayerRows = Array.isArray(payload.data)
                ? payload.data
                : [];
            this.communityDraftPlayerTeams = [...new Set(
                this.communityDraftPlayerRows
                    .map((player) => String(player.team_abbrev || player.team || "").toUpperCase().trim())
                    .filter(Boolean)
            )].sort();
            this.communityDraftPlayerRequestKey = requestKey;
            this.communityDraftPlayerLoaded = true;
        },
        setCommunityDraftPlayerPerspective(value) {
            this.communityDraftSelectedPerspective = value;
            this.communityDraftPlayerTeam = "";
            this.applyCommunityDraftPlayerSort(value);
            this.loadCommunityDraftPlayerStats(true);
        },
        applyCommunityDraftPlayerSort(value) {
            if (["entry-draft", "entry-draft-goalies"].includes(value)) {
                this.communityDraftPlayerSortKey = "drafted_overall_pick";
                this.communityDraftPlayerSortDirection = "asc";
                return;
            }

            this.communityDraftPlayerSortKey = "pts";
            this.communityDraftPlayerSortDirection = "desc";
        },
        sortCommunityDraftPlayers(key) {
            if (this.communityDraftPlayerSortKey === key) {
                this.communityDraftPlayerSortDirection =
                    this.communityDraftPlayerSortDirection === "desc" ? "asc" : "desc";
                return;
            }

            this.communityDraftPlayerSortKey = key;
            this.communityDraftPlayerSortDirection = "desc";
        },
        communityDraftPlayerSortIndicator(key) {
            if (this.communityDraftPlayerSortKey !== key) return "";

            return this.communityDraftPlayerSortDirection === "desc" ? "↓" : "↑";
        },
        communityDraftPlayerInitials(player) {
            return String(player?.name || player?.full_name || "?")
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part[0]?.toUpperCase() || "")
                .join("") || "?";
        },
        communityDraftTeamBadgeStyle(team) {
            const abbrev = String(team || "").toUpperCase().trim();

            return `background: ${teamGradients[abbrev] || fallbackTeamGradient};`;
        },
        communityDraftPlayerAge(player) {
            return player?.age ?? "-";
        },
        communityDraftPlayerDraftedOverall(player) {
            return player?.drafted_overall_pick ?? player?.draft_oa ?? "-";
        },
        communityDraftPlayerDraftedLabel(player) {
            return player?.drafted_label ?? "-";
        },
        communityDraftPlayerLeagueName(player) {
            return player?.league || player?.league_abbrev || "-";
        },
        communityDraftPlayerGp(player) {
            return player?.gp ?? player?.stats?.gp ?? "-";
        },
        communityDraftPlayerValue(player, key) {
            const stats = player?.stats && typeof player.stats === "object"
                ? player.stats
                : {};

            return stats[key] ?? player?.[key] ?? null;
        },
        communityDraftFormatValue(value) {
            if (value === null || value === undefined || value === "") return "-";
            if (typeof value === "number") {
                return Number.isInteger(value)
                    ? String(value)
                    : value.toFixed(2).replace(/\.?0+$/, "");
            }

            return String(value);
        },
        communityDraftPlayerSortValue(player, key) {
            if (key === "name") return String(player?.name || player?.full_name || "");
            if (key === "age") return Number(player?.age ?? 0);
            if (key === "team") return String(player?.team_abbrev || player?.team || "");
            if (key === "league") return this.communityDraftPlayerLeagueName(player);
            if (key === "gp") return Number(this.communityDraftPlayerGp(player) ?? 0);
            if (key === "drafted_overall_pick") {
                const value = this.communityDraftPlayerDraftedOverall(player);

                return value === "-"
                    ? (this.communityDraftPlayerSortDirection === "asc"
                        ? Number.POSITIVE_INFINITY
                        : Number.NEGATIVE_INFINITY)
                    : Number(value);
            }

            const value = this.communityDraftPlayerValue(player, key);

            if (value === null || value === undefined || value === "") {
                return this.communityDraftPlayerSortDirection === "asc"
                    ? Number.POSITIVE_INFINITY
                    : Number.NEGATIVE_INFINITY;
            }

            const numeric = Number(value);

            return Number.isNaN(numeric) ? String(value) : numeric;
        },
        get communityDraftPlayerStatHeadings() {
            const identityKeys = new Set([
                "name",
                "player",
                "team",
                "league",
                "pos",
                "pos_type",
                "age",
                "avatar_url",
                "head_shot_url",
                "id",
                "nhl_player_id",
                "drafted_overall_pick",
                "drafted_year",
                "drafted_label",
                "gp",
            ]);

            return this.communityDraftPlayerHeadings.filter((heading) => {
                const key = String(heading?.key || "").toLowerCase();

                return key && !identityKeys.has(key);
            });
        },
        get filteredCommunityDraftPlayers() {
            const query = this.communityDraftPlayerSearch.toLowerCase().trim();
            const teamFilter = String(this.communityDraftPlayerTeam || "").toUpperCase();
            const direction = this.communityDraftPlayerSortDirection === "asc" ? 1 : -1;
            const sortKey = this.communityDraftPlayerSortKey;

            return this.communityDraftPlayerRows
                .filter((player) => {
                    const name = String(player?.name || player?.full_name || "").toLowerCase();
                    const team = String(player?.team_abbrev || player?.team || "").toUpperCase();
                    const position = String(player?.position || player?.pos || "").toLowerCase();
                    const matchesSearch = query === ""
                        || name.includes(query)
                        || team.toLowerCase().includes(query)
                        || position.includes(query);
                    const matchesTeam = teamFilter === "" || team === teamFilter;

                    return matchesSearch && matchesTeam;
                })
                .sort((left, right) => {
                    const leftValue = this.communityDraftPlayerSortValue(left, sortKey);
                    const rightValue = this.communityDraftPlayerSortValue(right, sortKey);

                    if (typeof leftValue === "number" && typeof rightValue === "number") {
                        return (leftValue - rightValue) * direction;
                    }

                    return String(leftValue).localeCompare(String(rightValue), undefined, {
                        numeric: true,
                        sensitivity: "base",
                    }) * direction;
                });
        },
        communityDraftResetPlayerFilters() {
            this.communityDraftPlayerSearch = "";
            this.communityDraftPlayerTeam = "";
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
