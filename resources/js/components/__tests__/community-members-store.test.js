import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createCommunityMembersStore } from '../community-members-store';

describe('community members store', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="token-123" />';
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: { id: 1 } }),
        });
        window.toast = { error: vi.fn() };
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
    });

    it('blocks opening the add member modal when no tiers exist', () => {
        const store = createCommunityMembersStore();
        store.tiers = [];

        store.openMemberModal();

        expect(window.toast.error).toHaveBeenCalledWith(
            'Add a tier before adding members.'
        );
        expect(store.modals.member).toBe(false);
    });

    it('opens the member modal when at least one tier exists', () => {
        const store = createCommunityMembersStore();
        store.tiers = [{ id: 1, name: 'Tier' }];

        store.openMemberModal();

        expect(store.modals.member).toBe(true);
    });

    it('preserves zero-valued tier amounts when saving a tier', async () => {
        const store = createCommunityMembersStore();
        store.endpoints.tiers = '/tiers';
        store.tierForm = {
            id: null,
            name: 'Free',
            amount_cents: 0,
            currency: 'USD',
            description: '',
            is_active: true,
        };

        await store.saveTier();

        expect(fetch).toHaveBeenCalledWith(
            '/tiers',
            expect.objectContaining({
                method: 'POST',
                credentials: 'include',
                body: JSON.stringify({
                    name: 'Free',
                    amount_cents: 0,
                    currency: 'USD',
                    description: null,
                    is_active: true,
                }),
            })
        );
    });

    it('normalizes empty tier amounts to null for validation', async () => {
        const store = createCommunityMembersStore();
        store.endpoints.tiers = '/tiers';
        store.tierForm = {
            id: null,
            name: 'Unset',
            amount_cents: '',
            currency: 'USD',
            description: '',
            is_active: true,
        };

        await store.saveTier();

        expect(fetch).toHaveBeenCalledWith(
            '/tiers',
            expect.objectContaining({
                body: JSON.stringify({
                    name: 'Unset',
                    amount_cents: null,
                    currency: 'USD',
                    description: null,
                    is_active: true,
                }),
            })
        );
    });

    it('formats cents into currency strings with two decimals', () => {
        const store = createCommunityMembersStore();

        const formatted = store.formatMoney(1234, 'USD');

        expect(formatted).toMatch(/12\.34/);
    });
});
