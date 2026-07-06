import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import mount, { mountAll } from '../LeaguesHubLayout';

const html = () => `
    <div data-component="leagues-hub-layout">
        <meta name="csrf-token" content="bad-location" />
        <button class="inline-flex h-8 w-8 shrink-0" data-leagues-options-open aria-expanded="false"></button>
        <button class="inline-flex h-8 w-8 shrink-0" data-provider-resync-button data-provider-resync-url="/leagues/resync" data-provider-resync-label="all leagues">
            <span data-provider-resync-icon></span>
        </button>
        <ul id="leagueList">
            <li>
                <a class="league-item" href="/leagues?active=1" data-league-id="1" data-panel-url="/leagues/1/panel" aria-current="false">
                    League One
                    <span class="hidden" data-league-sync-progress>
                        <span data-league-sync-progress-bar></span>
                    </span>
                </a>
            </li>
            <li>
                <a class="league-item" href="/leagues?active=2" data-league-id="2" data-panel-url="/leagues/2/panel" aria-current="false">
                    League Two
                    <span class="hidden" data-league-sync-progress>
                        <span data-league-sync-progress-bar></span>
                    </span>
                </a>
            </li>
        </ul>
        <main id="leagueMain">Initial</main>
        <form method="POST" action="/leagues/1/visibility" data-league-visibility-form>
            <input type="hidden" name="_method" value="PUT" />
            <input type="hidden" name="is_visible" value="1" data-league-visibility-input />
            <button
                type="button"
                class="bg-indigo-600"
                style="height: 14px; width: 28px;"
                data-league-visibility-toggle
                data-league-id="1"
                data-league-visibility-url="/leagues/1/visibility"
                data-league-visible="true"
                aria-pressed="true"
                aria-label="Hide League One"
            >
                <span style="height: 10px; width: 10px; transform: translateX(16px);" data-league-visibility-knob></span>
            </button>
        </form>
        <div
            data-league-option-row
            data-league-id="3"
            data-league-name="League Three"
            data-league-href="/leagues?active=3"
            data-league-panel-url="/leagues/3/panel"
            data-league-platform-label="Fantrax"
        >
            <form method="POST" action="/leagues/3/visibility" data-league-visibility-form>
                <input type="hidden" name="_method" value="PUT" />
                <input type="hidden" name="is_visible" value="0" data-league-visibility-input />
                <button
                    type="button"
                    class="bg-slate-200"
                    style="height: 14px; width: 28px;"
                    data-league-visibility-toggle
                    data-league-id="3"
                    data-league-visibility-url="/leagues/3/visibility"
                    data-league-visible="false"
                    aria-pressed="false"
                    aria-label="Show League Three"
                >
                    <span style="height: 10px; width: 10px; transform: translateX(2px);" data-league-visibility-knob></span>
                </button>
            </form>
        </div>
    </div>
`;

const pageHtml = (body = '<div data-component="leagues-hub-layout"><main id="leagueMain">Reloaded</main></div>') => `
    <html>
        <body>${body}</body>
    </html>
`;

const response = (body, ok = true, status = 200) => ({
    ok,
    status,
    text: async () => body,
    json: async () => JSON.parse(body),
});

describe('LeaguesHubLayout', () => {
    let root;

    beforeEach(() => {
        vi.useFakeTimers();
        document.head.innerHTML = '<meta name="csrf-token" content="token-123" />';
        document.body.innerHTML = html();
        root = document.querySelector('[data-component="leagues-hub-layout"]');
        global.fetch = vi.fn();
        global.requestAnimationFrame = (callback) => callback();
        window.toast = { success: vi.fn(), error: vi.fn(), show: vi.fn() };
        window.DIQ = { userChannel: { listen: vi.fn() } };
        vi.spyOn(window.history, 'pushState').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        document.head.innerHTML = '';
        document.body.innerHTML = '';
        delete window.toast;
        delete window.DIQ;
    });

    it('marks the root as mounted', () => {
        mount(root);

        expect(root.dataset.leaguesHubMounted).toBe('true');
    });

    it('mounts all league hubs when the document is already loaded', () => {
        mountAll();

        expect(root.dataset.leaguesHubMounted).toBe('true');
    });

    it('does not mount the same root twice', () => {
        mount(root);
        mount(root);

        expect(window.DIQ.userChannel.listen).toHaveBeenCalledTimes(1);
    });

    it('keeps header icon buttons from shrinking inside the sidebar header', () => {
        const gear = root.querySelector('[data-leagues-options-open]');
        const refresh = root.querySelector('[data-provider-resync-button]');

        expect(gear.classList.contains('shrink-0')).toBe(true);
        expect(refresh.classList.contains('shrink-0')).toBe(true);
    });

    it('optimistically flips a visible league toggle off', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Hidden","league_id":1}'));
        mount(root);
        const button = root.querySelector('[data-league-visibility-toggle]');

        button.click();
        await Promise.resolve();

        expect(button.dataset.leagueVisible).toBe('false');
        expect(button.getAttribute('aria-pressed')).toBe('false');
        expect(button.classList.contains('bg-slate-200')).toBe(true);
        expect(root.querySelector('[data-league-visibility-input]').value).toBe('0');
        expect(root.querySelector('[data-league-visibility-knob]').style.transform).toBe('translateX(2px)');
    });

    it('keeps enhanced visibility toggles from submitting the fallback form', () => {
        const button = root.querySelector('[data-league-visibility-toggle]');

        expect(button.type).toBe('button');
        expect(root.querySelector('[data-league-visibility-input]').value).toBe('1');
    });

    it('sends the visibility update with csrf and json payload', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Hidden","league_id":1}'));
        mount(root);

        root.querySelector('[data-league-visibility-toggle]').click();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/leagues/1/visibility', expect.objectContaining({
            method: 'PUT',
            headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'token-123' }),
            body: JSON.stringify({ is_visible: false }),
        }));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('reverts the visibility toggle on server failure', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Nope"}', false, 422));
        mount(root);
        const button = root.querySelector('[data-league-visibility-toggle]');

        button.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(button.dataset.leagueVisible).toBe('true');
        expect(button.classList.contains('bg-indigo-600')).toBe(true);
        expect(window.toast.error).toHaveBeenCalledWith('Nope');
    });

    it('hides the matching league list item without replacing the root markup', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Hidden","league_id":1}'));
        mount(root);

        root.querySelector('[data-league-visibility-toggle]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(root.querySelector('#leagueMain').textContent).toBe('Initial');
        expect(root.querySelector('a.league-item[data-league-id="1"]').closest('li').classList.contains('hidden')).toBe(true);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('adds a league list item when a hidden league is shown', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Shown","league_id":3}'));
        mount(root);

        root.querySelector('[data-league-id="3"] [data-league-visibility-toggle]').click();
        await Promise.resolve();
        await Promise.resolve();

        const link = root.querySelector('a.league-item[data-league-id="3"]');

        expect(link).not.toBeNull();
        expect(link.textContent).toContain('League Three');
        expect(link.dataset.panelUrl).toBe('/leagues/3/panel');
        expect(fetch).toHaveBeenCalledWith('/leagues/3/visibility', expect.objectContaining({
            body: JSON.stringify({ is_visible: true }),
        }));
    });

    it('shows a success toast after a successful visibility update', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Hidden","league_id":1}'));
        mount(root);

        root.querySelector('[data-league-visibility-toggle]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(window.toast.success).toHaveBeenCalledWith('Hidden');
    });

    it('does not submit visibility changes without a toggle url', async () => {
        mount(root);
        const form = root.querySelector('[data-league-visibility-form]');
        const button = root.querySelector('[data-league-visibility-toggle]');
        form.removeAttribute('action');
        delete button.dataset.leagueVisibilityUrl;

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await Promise.resolve();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('does not submit visibility changes for disabled toggles', async () => {
        mount(root);
        const button = root.querySelector('[data-league-visibility-toggle]');
        button.disabled = true;

        button.click();
        await Promise.resolve();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('uses thin visibility toggle dimensions', () => {
        const button = root.querySelector('[data-league-visibility-toggle]');
        const knob = root.querySelector('[data-league-visibility-knob]');

        expect(button.style.height).toBe('14px');
        expect(button.style.width).toBe('28px');
        expect(knob.style.height).toBe('10px');
        expect(knob.style.width).toBe('10px');
    });

    it('posts refresh requests with csrf headers', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Queued"}')).mockResolvedValueOnce(response(pageHtml()));
        mount(root);

        root.querySelector('[data-provider-resync-button]').click();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/leagues/resync', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'token-123' }),
        }));
    });

    it('spins the refresh icon while refresh is pending', () => {
        fetch.mockReturnValue(new Promise(() => {}));
        mount(root);

        root.querySelector('[data-provider-resync-button]').click();

        expect(root.querySelector('[data-provider-resync-icon]').classList.contains('animate-spin')).toBe(true);
    });

    it('updates the root markup after a successful refresh', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Queued"}')).mockResolvedValueOnce(response(pageHtml()));
        mount(root);

        root.querySelector('[data-provider-resync-button]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(root.querySelector('#leagueMain').textContent).toBe('Reloaded');
    });

    it('shows refresh errors as toasts', async () => {
        fetch.mockResolvedValueOnce(response('{"message":"Forbidden"}', false, 403));
        mount(root);

        root.querySelector('[data-provider-resync-button]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(window.toast.error).toHaveBeenCalledWith('Forbidden');
    });

    it('loads league panels into the main region', async () => {
        fetch.mockResolvedValueOnce(response('<section>Panel One</section>'));
        mount(root);

        root.querySelector('a.league-item[data-league-id="1"]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(root.querySelector('#leagueMain').innerHTML).toBe('<section>Panel One</section>');
    });

    it('pushes browser history when loading a league panel', async () => {
        fetch.mockResolvedValueOnce(response('<section>Panel One</section>'));
        mount(root);

        root.querySelector('a.league-item[data-league-id="1"]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(window.history.pushState).toHaveBeenCalled();
    });

    it('marks the loaded league link active', async () => {
        fetch.mockResolvedValueOnce(response('<section>Panel One</section>'));
        mount(root);
        const link = root.querySelector('a.league-item[data-league-id="1"]');

        link.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(link.getAttribute('aria-current')).toBe('page');
    });

    it('renders processing league sync progress from broadcasts', () => {
        let handler;
        window.DIQ.userChannel.listen.mockImplementation((_event, callback) => {
            handler = callback;
        });
        mount(root);

        handler({ platform_league_id: '1', status: 'processing' });

        expect(root.querySelector('[data-league-sync-progress]').classList.contains('hidden')).toBe(false);
    });

    it('renders completed league sync progress at full width', () => {
        let handler;
        window.DIQ.userChannel.listen.mockImplementation((_event, callback) => {
            handler = callback;
        });
        mount(root);

        handler({ platform_league_id: '1', status: 'processing' });
        handler({ platform_league_id: '1', status: 'completed' });

        expect(root.querySelector('[data-league-sync-progress-bar]').style.width).toBe('100%');
    });

    it('ignores league sync broadcasts without ids', () => {
        let handler;
        window.DIQ.userChannel.listen.mockImplementation((_event, callback) => {
            handler = callback;
        });
        mount(root);

        handler({ status: 'processing' });

        expect(root.querySelector('[data-league-sync-progress]').classList.contains('hidden')).toBe(true);
    });
});
