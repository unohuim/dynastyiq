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

function csrfToken() {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content") ?? ""
    );
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
}

// Ensure registration works whether Alpine has already been injected or not.
if (window.Alpine) {
    registerCommunityMembersStore();
}

document.addEventListener("alpine:init", registerCommunityMembersStore);

function communityMembersHub(config) {
    return {
        activeTab: "members",
        init() {
            // Ensure the store exists even if Alpine boot order changes.
            if (!this.$store.communityMembers) {
                registerCommunityMembersStore();
            }

            this.$store.communityMembers.bootstrap(config);
        },
    };
}

// Also expose the factory globally so x-data can resolve it even before Alpine
// processes named data providers.
window.communityMembersHub = communityMembersHub;

export { registerCommunityMembersStore };
