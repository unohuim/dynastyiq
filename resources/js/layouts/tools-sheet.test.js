/* @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick, reactive } from 'vue';
import * as inertia from '@inertiajs/vue3';
import * as shellRealtime from './shell-realtime';
import AppLayout from './AppLayout.vue';

describe('Mobile Tools bottom sheet', () => {
    let app;
    let page;
    let visit;
    const trigger = () => document.querySelector('[aria-label="Open tools navigation"]');
    const panel = () => document.querySelector('#tools-navigation-panel');
    const option = name => [...document.querySelectorAll('[aria-label="Tools navigation"] a')].find(link => link.textContent.includes(name));
    const callbacks = () => visit.mock.calls.at(-1)[1];
    const settle = async () => { await nextTick(); await nextTick(); await nextTick(); };
    const mount = async () => { app = createApp(AppLayout); app.mount('#shell'); await settle(); };
    const open = async () => { await mount(); trigger().click(); await settle(); };

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-17T16:00:00Z'));
        document.body.innerHTML = '<div id="shell"></div>';
        page = reactive({ url: '/', component: 'Dashboard', props: { auth: { user: null }, shell: {
            primary: [{ label: 'Home', href: '/', active: true }, { label: 'Stats', href: '/stats', active: false }],
            tools: [{ label: 'Schedule', href: '/tools/schedule', active: false }, { label: 'Lines', href: '/tools/lines', active: false }],
            news: [], organization: { id: null }, csrf: 'test', flashes: [], login_url: '/auth/discord',
        } } });
        vi.spyOn(inertia, 'usePage').mockImplementation(() => page);
        visit = vi.spyOn(inertia.router, 'visit').mockImplementation(() => {});
        vi.spyOn(shellRealtime, 'connectShellRealtime').mockImplementation(() => () => {});
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('Unexpected external request'))));
    });

    afterEach(() => {
        app?.unmount(); app = null;
        vi.clearAllTimers(); vi.useRealTimers(); vi.restoreAllMocks(); vi.unstubAllGlobals();
        document.body.innerHTML = ''; document.body.style.pointerEvents = ''; document.body.style.overflow = '';
    });

    it('offers the mobile Tools sheet to guests through the existing trigger', async () => {
        await open();
        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        expect(panel().getAttribute('role')).toBe('dialog');
        expect(panel().getAttribute('aria-modal')).toBe('true');
    });

    it('anchors the mobile sheet at the bottom at half the dynamic viewport height', async () => {
        await open();
        expect(panel().classList.contains('bottom-0')).toBe(true);
        expect(panel().classList.contains('h-[50dvh]')).toBe(true);
        expect(panel().classList.contains('inset-x-0')).toBe(true);
        expect(panel().classList.contains('top-0')).toBe(false);
    });

    it('uses rounded graphite framing and a visible highlighted boundary', async () => {
        await open();
        expect(panel().classList.contains('rounded-t-3xl')).toBe(true);
        expect(panel().classList.contains('bg-slate-900')).toBe(true);
        expect(panel().classList.contains('border-slate-600')).toBe(true);
        expect(panel().classList.contains('shadow-2xl')).toBe(true);
    });

    it('keeps its frame visible when no tool content remains', async () => {
        await open(); page.props.shell.tools = []; await settle();
        expect(panel().classList.contains('h-[50dvh]')).toBe(true);
        expect(panel().classList.contains('ring-1')).toBe(true);
    });

    it('shows both backend-owned options with icons descriptions and preserved hrefs', async () => {
        await open();
        expect(option('Schedule').getAttribute('href')).toBe('/tools/schedule');
        expect(option('Lines').getAttribute('href')).toBe('/tools/lines');
        expect(option('Schedule').textContent).toContain('Plan your week and off nights');
        expect(option('Lines').querySelectorAll('svg')).toHaveLength(2);
    });

    it('preserves active-page semantics on a tool option', async () => {
        page.props.shell.tools[0].active = true; await open();
        expect(option('Schedule').getAttribute('aria-current')).toBe('page');
        expect(option('Lines').hasAttribute('aria-current')).toBe(false);
    });

    it('shows Schedule loading immediately while retaining the drawer', async () => {
        await open(); option('Schedule').click(); await settle();
        expect(option('Schedule').textContent).toContain('Opening Schedule…');
        expect(option('Schedule').querySelector('[role="status"]')).not.toBeNull();
        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        expect(visit).toHaveBeenCalledWith('/tools/schedule', expect.any(Object));
    });

    it('shows Lines loading without changing the other option label', async () => {
        await open(); option('Lines').click(); await settle();
        expect(option('Lines').textContent).toContain('Opening Lines…');
        expect(option('Schedule').textContent).toContain('Plan your week and off nights');
        expect(visit.mock.calls[0][0]).toBe('/tools/lines');
    });

    it('marks navigation busy and blocks repeated or alternate option taps', async () => {
        await open(); option('Schedule').click(); option('Schedule').click(); option('Lines').click(); await settle();
        expect(visit).toHaveBeenCalledTimes(1);
        expect(option('Lines').getAttribute('aria-disabled')).toBe('true');
        expect(document.querySelector('[aria-label="Tools navigation"]').getAttribute('aria-busy')).toBe('true');
    });

    it('closes the drawer only after a successful visit', async () => {
        await open(); option('Schedule').click(); await settle();
        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        callbacks().onSuccess(); await settle();
        expect(trigger().getAttribute('aria-expanded')).toBe('false');
    });

    it('handles network failure locally and offers Retry', async () => {
        await open(); option('Lines').click(); await settle();
        expect(callbacks().onNetworkError(new Error('Offline'))).toBe(false); await settle();
        expect(panel().querySelector('[role="alert"]').textContent).toBe('Unable to open Lines.');
        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        expect(option('Schedule').hasAttribute('aria-disabled')).toBe(false);
    });

    it('handles HTTP exceptions inside the sheet rather than replacing the page', async () => {
        await open(); option('Schedule').click(); await settle();
        expect(callbacks().onHttpException({ status: 500 })).toBe(false); await settle();
        expect(panel().querySelector('[role="alert"]').textContent).toContain('Schedule');
    });

    it('clears loading after validation failure or an unsuccessful finished visit', async () => {
        await open(); option('Lines').click(); await settle();
        callbacks().onError({ page: 'Unavailable' }); callbacks().onFinish(); await settle();
        expect(option('Lines').textContent).not.toContain('Opening');
        expect(panel().querySelector('[role="alert"]')).not.toBeNull();
    });

    it('retries the failed destination and restores its immediate loading state', async () => {
        await open(); option('Lines').click(); await settle();
        callbacks().onNetworkError(); await settle();
        [...panel().querySelectorAll('button')].find(button => button.textContent === 'Retry').click(); await settle();
        expect(visit).toHaveBeenCalledTimes(2);
        expect(visit.mock.calls[1][0]).toBe('/tools/lines');
        expect(option('Lines').textContent).toContain('Opening Lines…');
        expect(panel().querySelector('[role="alert"]')).toBeNull();
    });

    it('catches a synchronous navigation failure without leaving the sheet stuck', async () => {
        visit.mockImplementationOnce(() => { throw new Error('Navigation unavailable'); });
        await open(); option('Schedule').click(); await settle();
        expect(panel().querySelector('[role="alert"]').textContent).toContain('Schedule');
        expect(option('Schedule').textContent).not.toContain('Opening');
    });

    it('cancels only its own request when the user closes the sheet', async () => {
        await open(); option('Schedule').click(); await settle();
        const cancel = vi.fn(); callbacks().onCancelToken({ cancel });
        panel().querySelector('[aria-label="Close menu"]').click(); await settle();
        expect(cancel).toHaveBeenCalledOnce();
        expect(trigger().getAttribute('aria-expanded')).toBe('false');
    });

    it('ignores late callbacks from a dismissed attempt after reopening', async () => {
        await open(); option('Schedule').click(); await settle(); const old = callbacks();
        panel().querySelector('[aria-label="Close menu"]').click(); await settle();
        await vi.advanceTimersByTimeAsync(500); trigger().click(); await settle(); option('Lines').click(); await settle();
        old.onNetworkError(); old.onSuccess(); old.onFinish(); await settle();
        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        expect(option('Lines').textContent).toContain('Opening Lines…');
        expect(panel().querySelector('[role="alert"]')).toBeNull();
    });

    it('cleans up an active navigation request when the layout unmounts', async () => {
        await open(); option('Lines').click(); await settle();
        const cancel = vi.fn(); callbacks().onCancelToken({ cancel });
        app.unmount(); app = null;
        expect(cancel).toHaveBeenCalledOnce();
    });

    it('retains native modified-link behavior instead of starting an Inertia visit', async () => {
        await open();
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
        // Prevent jsdom attempting a real navigation after checking the component's handler.
        option('Schedule').addEventListener('click', e => e.preventDefault(), { once: true });
        option('Schedule').dispatchEvent(event); await settle();
        expect(visit).not.toHaveBeenCalled();
        expect(option('Schedule').textContent).not.toContain('Opening');
    });

    it('respects reduced motion on loading and interactive controls', async () => {
        await open(); option('Lines').click(); await settle();
        expect(option('Lines').querySelector('.animate-spin').classList.contains('motion-reduce:animate-none')).toBe(true);
        expect(option('Lines').classList.contains('motion-reduce:transition-none')).toBe(true);
    });

    it('keeps member navigation as the existing full-height side drawer', async () => {
        page.props.auth.user = { id: 1, name: 'Rob' }; await mount();
        [...document.querySelectorAll('nav[aria-label="Mobile navigation"] button')].find(button => button.textContent.trim() === 'Menu').click(); await settle();
        const dialog = document.querySelector('[role="dialog"]');
        expect(dialog.classList.contains('h-full')).toBe(true);
        expect(dialog.classList.contains('top-0')).toBe(true);
        expect(dialog.classList.contains('h-[50dvh]')).toBe(false);
        expect(document.querySelector('[aria-label="All navigation"] a[href="/tools/lines"]')).not.toBeNull();
    });

    it('keeps the desktop Tools dropdown independent of the mobile sheet', async () => {
        await mount(); const desktop = document.querySelector('[aria-label="Open tools menu"]');
        desktop.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })); await settle();
        expect(desktop.getAttribute('aria-expanded')).toBe('true');
        expect(trigger().getAttribute('aria-expanded')).toBe('false');
        expect(document.querySelector('[role="menu"] a').getAttribute('href')).toBe('/tools/schedule');
        expect(visit).not.toHaveBeenCalled();
    });
    it('dismisses with Escape and restores focus to the Tools trigger', async () => {
        await open();
        panel().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        await settle(); await vi.advanceTimersByTimeAsync(500); await settle();
        expect(trigger().getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(trigger());
    });

    it('retains the current page content while an explicit tool visit is pending', async () => {
        await open(); const content = document.querySelector('#page-content');
        option('Schedule').click(); await settle();
        expect(document.querySelector('#page-content')).toBe(content);
        expect(panel().classList.contains('absolute')).toBe(true);
        expect(panel().classList.contains('h-[50dvh]')).toBe(true);
    });

});
