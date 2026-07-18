import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    closeDiscordServerDetachModal,
    communityMembersRefreshMessage,
    confirmDiscordServerDetach,
    createCommunityMembersStore,
    detachDiscordServer,
    initialCommunityTab,
    openDiscordServerDetachModal,
    refreshDiscordBotStatus,
    setCommunityMembersRefreshLoading,
    updateDiscordServerEmptyStates,
} from '../community-members-store';

describe('community members store', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="token-123" />';
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: { id: 1 } }),
        });
        window.toast = { error: vi.fn(), success: vi.fn() };
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
        document.body.innerHTML = '';
        delete window.toast;
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

    it('uses the connections tab from the community URL query', () => {
        expect(initialCommunityTab('?active=7&tab=connections')).toBe('connections');
    });

    it('falls back to home for unknown community URL tabs', () => {
        expect(initialCommunityTab('?active=7&tab=unknown')).toBe('home');
    });

    it('formats a combined discord and patreon refresh message', () => {
        expect(
            communityMembersRefreshMessage({
                discord: { synced_count: 2, server_count: 1 },
                patreon: { connected: true, members_synced: 3 },
            })
        ).toBe('2 Discord members and 3 Patreon members refreshed.');
    });

    it('formats a community refresh message without connected providers', () => {
        expect(
            communityMembersRefreshMessage({
                discord: { synced_count: 0, server_count: 0 },
                patreon: { connected: false, members_synced: 0 },
            })
        ).toBe('Community members refreshed.');
    });

    it('keeps formatting legacy discord-only refresh summaries', () => {
        expect(
            communityMembersRefreshMessage({
                synced_count: 2,
                server_count: 2,
            })
        ).toBe('2 Discord members across 2 servers refreshed.');
    });

    it('toggles the community member refresh loading state', () => {
        const button = document.createElement('button');
        button.title = 'Refresh community members';
        button.setAttribute('aria-label', 'Refresh community members');
        button.dataset.idleTitle = 'Refresh community members';
        button.dataset.idleLabel = 'Refresh community members';
        button.dataset.loadingTitle = 'Refreshing community members';
        button.dataset.loadingLabel = 'Refreshing community members';
        button.innerHTML = '<svg data-community-members-refresh-icon class="h-4 w-4"></svg>';

        setCommunityMembersRefreshLoading(button, true);

        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(button.title).toBe('Refreshing community members');
        expect(button.querySelector('svg').classList.contains('animate-spin')).toBe(true);

        setCommunityMembersRefreshLoading(button, false);

        expect(button.disabled).toBe(false);
        expect(button.getAttribute('aria-busy')).toBe('false');
        expect(button.title).toBe('Refresh community members');
        expect(button.querySelector('svg').classList.contains('animate-spin')).toBe(false);
    });

    it('shows an error when discord server removal has no endpoint', async () => {
        const button = document.createElement('button');
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(window.toast.error).toHaveBeenCalledWith(
            'Cannot remove Discord server: missing endpoint.'
        );
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows an error when discord server removal has no server id', async () => {
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';

        await detachDiscordServer(button);

        expect(window.toast.error).toHaveBeenCalledWith(
            'Cannot remove Discord server: missing endpoint.'
        );
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends ajax delete requests for discord server removal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(fetch).toHaveBeenCalledWith(
            '/discord-server/7',
            expect.objectContaining({
                method: 'DELETE',
                credentials: 'same-origin',
                headers: expect.objectContaining({
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': 'token-123',
                    'X-Requested-With': 'XMLHttpRequest',
                }),
                body: JSON.stringify({
                    remove_members: false,
                }),
            })
        );
    });

    it('sends the member removal choice with discord server removal requests', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button, true);

        expect(fetch).toHaveBeenCalledWith(
            '/discord-server/7',
            expect.objectContaining({
                body: JSON.stringify({
                    remove_members: true,
                }),
            })
        );
    });

    it('removes the deleted discord server row after ajax success', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = '<div data-discord-server-row="7"></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-server-row="7"]')).toBeNull();
    });

    it('removes all matching discord server row instances after ajax success', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7"></div>',
            '<div data-discord-server-row="7"></div>',
            '<div data-discord-server-row="8"></div>',
        ].join('');
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelectorAll('[data-discord-server-row="7"]')).toHaveLength(0);
        expect(document.querySelectorAll('[data-discord-server-row="8"]')).toHaveLength(1);
    });

    it('shows the discord empty state when the last server row is removed', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7"></div>',
            '<div class="hidden" data-discord-servers-empty></div>',
        ].join('');
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-servers-empty]').classList.contains('hidden')).toBe(false);
    });

    it('keeps the discord empty state hidden when other server rows remain', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7"></div>',
            '<div data-discord-server-row="8"></div>',
            '<div class="hidden" data-discord-servers-empty></div>',
        ].join('');
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-servers-empty]').classList.contains('hidden')).toBe(true);
    });

    it('updates discord server count labels after removal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7"></div>',
            '<div data-discord-server-row="8"></div>',
            '<span data-discord-server-count>2</span>',
        ].join('');
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-server-count]').textContent).toBe('1');
    });

    it('shows a success toast after discord server removal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(window.toast.success).toHaveBeenCalledWith(
            'Discord server removed from this community.'
        );
    });

    it('shows a member removal success toast after discord server removal with members', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true, removed_members_count: 2 }),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button, true);

        expect(window.toast.success).toHaveBeenCalledWith(
            'Discord server removed with 2 members.'
        );
    });

    it('refreshes the community members list after removing discord members', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true, removed_members_count: 1 }),
        });
        const dispatch = vi.spyOn(window, 'dispatchEvent');
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button, true);

        expect(dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'community-members:refresh' })
        );
    });

    it('keeps discord server rows when ajax removal returns an error response', async () => {
        fetch.mockResolvedValueOnce({
            ok: false,
            status: 403,
            json: async () => ({ message: 'Forbidden' }),
        });
        document.body.innerHTML = '<div data-discord-server-row="7"></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-server-row="7"]')).not.toBeNull();
        expect(window.toast.error).toHaveBeenCalledWith('Forbidden');
    });

    it('keeps discord server rows when ajax removal returns ok false', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 422,
            json: async () => ({ ok: false, message: 'Cannot remove' }),
        });
        document.body.innerHTML = '<div data-discord-server-row="7"></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-server-row="7"]')).not.toBeNull();
        expect(window.toast.error).toHaveBeenCalledWith('Cannot remove');
    });

    it('shows the status fallback when ajax removal fails without a message', async () => {
        fetch.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => ({}),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(window.toast.error).toHaveBeenCalledWith('Remove failed (500)');
    });

    it('shows a network error toast when discord server removal throws', async () => {
        fetch.mockRejectedValueOnce(new Error('Network failed'));
        document.body.innerHTML = '<div data-discord-server-row="7"></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(document.querySelector('[data-discord-server-row="7"]')).not.toBeNull();
        expect(window.toast.error).toHaveBeenCalledWith(
            'Could not remove Discord server from this community.'
        );
    });

    it('re-enables the remove button after successful discord server removal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(button.disabled).toBe(false);
    });

    it('re-enables the remove button after failed discord server removal', async () => {
        fetch.mockResolvedValueOnce({
            ok: false,
            status: 403,
            json: async () => ({}),
        });
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        await detachDiscordServer(button);

        expect(button.disabled).toBe(false);
    });

    it('updates discord server empty states without removing rows', () => {
        document.body.innerHTML = [
            '<div data-discord-server-row="7"></div>',
            '<div class="hidden" data-discord-servers-empty></div>',
            '<span data-discord-server-count>0</span>',
        ].join('');

        updateDiscordServerEmptyStates();

        expect(document.querySelector('[data-discord-servers-empty]').classList.contains('hidden')).toBe(true);
        expect(document.querySelector('[data-discord-server-count]').textContent).toBe('1');
    });

    it('opens the discord server removal modal with the server name', () => {
        document.body.innerHTML = [
            '<div class="hidden" data-discord-server-detach-modal>',
            '<span data-discord-server-detach-name></span>',
            '<button data-discord-server-detach-cancel></button>',
            '</div>',
        ].join('');
        const button = document.createElement('button');
        button.dataset.discordServerName = 'Guild One';

        openDiscordServerDetachModal(button);

        const modal = document.querySelector('[data-discord-server-detach-modal]');
        expect(modal.classList.contains('hidden')).toBe(false);
        expect(modal.classList.contains('flex')).toBe(true);
        expect(document.querySelector('[data-discord-server-detach-name]').textContent).toBe('Guild One');
    });

    it('closes the discord server removal modal without deleting anything', () => {
        document.body.innerHTML = '<div class="flex" data-discord-server-detach-modal></div>';

        closeDiscordServerDetachModal();

        const modal = document.querySelector('[data-discord-server-detach-modal]');
        expect(modal.classList.contains('hidden')).toBe(true);
        expect(modal.classList.contains('flex')).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('confirms discord server removal without members from the modal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true }),
        });
        document.body.innerHTML = '<div class="flex" data-discord-server-detach-modal></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        openDiscordServerDetachModal(button);
        await confirmDiscordServerDetach(false);

        expect(fetch).toHaveBeenCalledWith(
            '/discord-server/7',
            expect.objectContaining({
                body: JSON.stringify({
                    remove_members: false,
                }),
            })
        );
    });

    it('confirms discord server removal with members from the modal', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true, removed_members_count: 1 }),
        });
        document.body.innerHTML = '<div class="flex" data-discord-server-detach-modal></div>';
        const button = document.createElement('button');
        button.dataset.url = '/discord-server/7';
        button.dataset.discordServerId = '7';

        openDiscordServerDetachModal(button);
        await confirmDiscordServerDetach(true);

        expect(fetch).toHaveBeenCalledWith(
            '/discord-server/7',
            expect.objectContaining({
                body: JSON.stringify({
                    remove_members: true,
                }),
            })
        );
    });

    it('updates the discord bot install controls when bot status is installed', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true, installed: true }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7" data-discord-bot-status-url="/bot-status/7">',
            '<span class="hidden" data-discord-bot-installed-badge>Bot installed</span>',
            '<a data-discord-bot-install>Install bot</a>',
            '<span data-discord-bot-needs-badge>Needs bot</span>',
            '</div>',
        ].join('');

        const installed = await refreshDiscordBotStatus('7');

        expect(installed).toBe(true);
        expect(fetch).toHaveBeenCalledWith(
            '/bot-status/7',
            expect.objectContaining({
                method: 'GET',
                credentials: 'same-origin',
                headers: expect.objectContaining({
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }),
            })
        );
        expect(document.querySelector('[data-discord-bot-installed-badge]').classList.contains('hidden')).toBe(false);
        expect(document.querySelector('[data-discord-bot-install]').classList.contains('hidden')).toBe(true);
        expect(document.querySelector('[data-discord-bot-needs-badge]').classList.contains('hidden')).toBe(true);
    });

    it('keeps discord bot install controls visible when bot status is not installed', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ok: true, installed: false }),
        });
        document.body.innerHTML = [
            '<div data-discord-server-row="7" data-discord-bot-status-url="/bot-status/7">',
            '<span class="hidden" data-discord-bot-installed-badge>Bot installed</span>',
            '<a data-discord-bot-install>Install bot</a>',
            '</div>',
        ].join('');

        const installed = await refreshDiscordBotStatus('7');

        expect(installed).toBe(false);
        expect(document.querySelector('[data-discord-bot-installed-badge]').classList.contains('hidden')).toBe(true);
        expect(document.querySelector('[data-discord-bot-install]').classList.contains('hidden')).toBe(false);
        expect(window.toast.error).toHaveBeenCalledWith(
            'DIQ bot install has not been detected yet.'
        );
    });

    it('does not check discord bot status when the row is missing', async () => {
        const installed = await refreshDiscordBotStatus('7');

        expect(installed).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });
});
