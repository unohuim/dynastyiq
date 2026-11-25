/* @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import Webhooks from './Webhooks.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), patch: vi.fn(), get: vi.fn(), delete: vi.fn(), notify: vi.fn(), copy: vi.fn() }));
vi.mock('axios', () => ({ default: { post: mocks.post, put: mocks.put, patch: mocks.patch, get: mocks.get, delete: mocks.delete } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));
vi.mock('reka-ui', async () => {
    const { h } = await import('vue');
    const wrap = { setup: (_, { slots, attrs }) => () => h('div', attrs, slots.default?.()) };
    return { DialogRoot: { props: ['open'], setup: (props, { slots }) => () => props.open ? h('div', slots.default?.()) : null },
        DialogPortal: wrap, DialogOverlay: wrap, DialogContent: wrap, DialogTitle: wrap, DialogDescription: wrap };
});
const row = (extra = {}) => ({ id: 1, name: 'Gner8 predictions', client_name: 'Gner8', api_client_id: 10, client_revoked: false,
    receiving_url: 'https://gner8.example/webhooks/diq', events: ['prediction.available'], is_enabled: true,
    update_url: '/admin/webhooks/1', status_url: '/admin/webhooks/1/status', secret_url: '/admin/webhooks/1/secret', delete_url: '/admin/webhooks/1', ...extra });
let app;
let props;
let host;
let clipboardDescriptor;
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await nextTick(); };
const button = text => [...host.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const mount = (extra = {}) => {
    props = reactive({ subscriptions: [row()], apiClients: [{ id: 10, name: 'Gner8', revoked: false }],
        eventOptions: [{ value: 'prediction.available', label: 'New Prediction Available' }], storeUrl: '/admin/webhooks', ...extra });
    host = document.createElement('div'); document.body.append(host);
    app = createApp({ render: () => h(Webhooks, props) }); app.provide('notify', mocks.notify); app.mount(host);
};
const fill = async (selector, value, type = 'input') => {
    const field = host.querySelector(selector); field.value = value; field.dispatchEvent(new Event(type, { bubbles: true })); await nextTick();
};
const openEdit = async () => { host.querySelector('[aria-label="Edit Gner8 predictions"]').click(); await nextTick(); };
const submit = () => host.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

beforeEach(() => {
    vi.clearAllMocks();
    mocks.post.mockResolvedValue({ data: { subscription: row({ id: 2, name: 'New webhook' }) } });
    mocks.put.mockResolvedValue({ data: { subscription: row({ name: 'Renamed' }) } });
    mocks.patch.mockResolvedValue({ data: { subscription: row({ is_enabled: false }) } });
    mocks.get.mockResolvedValue({ data: { secret: 'whsec_test' } });
    mocks.delete.mockResolvedValue({ data: { deleted: true } });
    clipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: mocks.copy } });
    mocks.copy.mockResolvedValue(undefined);
});
afterEach(() => {
    app?.unmount(); app = null; document.body.innerHTML = ''; vi.restoreAllMocks();
    if (clipboardDescriptor) Object.defineProperty(navigator, 'clipboard', clipboardDescriptor);
    else delete navigator.clipboard;
});

it('renders desktop rows and mobile cards from the same inventory', () => {
    mount(); expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(1);
    expect(host.querySelectorAll('[data-webhook-card]')).toHaveLength(1);
    expect(host.textContent).toContain('Gner8 predictions'); expect(host.textContent).toContain('Notification delivery is not connected yet.');
});
it('provides an honest empty inventory', () => {
    mount({ subscriptions: [] }); expect(host.textContent).toContain('No webhooks yet');
    expect(host.textContent).toContain('0 of 0 subscriptions');
});
it('disables creation without any API clients', () => {
    mount({ apiClients: [] }); expect(button('+ Add Webhook').disabled).toBe(true);
    expect(host.textContent).toContain('Create an API client');
});
it('filters names without requesting data', async () => {
    mount(); await fill('[aria-label="Search webhooks"]', 'unmatched');
    expect(host.textContent).toContain('No matching webhooks'); expect(mocks.get).not.toHaveBeenCalled();
});
it('searches endpoint URLs case insensitively', async () => {
    mount(); await fill('[aria-label="Search webhooks"]', 'GNER8.EXAMPLE');
    expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(1);
});
it('filters by subscriber independently of status', async () => {
    mount({ subscriptions: [row(), row({ id: 2, api_client_id: 20, client_name: 'Other' })], apiClients: [{ id: 10, name: 'Gner8' }, { id: 20, name: 'Other' }] });
    await fill('[aria-label="Filter by subscriber"]', '20', 'change');
    expect(host.querySelector('[data-webhook-id]').dataset.webhookId).toBe('2');
});
it('filters enabled and disabled subscriptions locally', async () => {
    mount({ subscriptions: [row(), row({ id: 2, name: 'Disabled', is_enabled: false })] });
    await fill('[aria-label="Filter by status"]', 'disabled', 'change');
    expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(1);
    expect(host.querySelector('[data-webhook-id]').textContent).toContain('Disabled');
});
it('sorts inventory without mutating supplied props', () => {
    mount({ subscriptions: [row({ name: 'Zulu' }), row({ id: 2, name: 'Alpha' })] });
    expect(host.querySelector('[data-webhook-id]').textContent).toContain('Alpha');
    expect(props.subscriptions[0].name).toBe('Zulu');
});
it('opens a new subscription with enabled off and the supported event selected', async () => {
    mount(); button('+ Add Webhook').click(); await nextTick();
    const checks = [...host.querySelector('form').querySelectorAll('input[type="checkbox"]')];
    expect(checks[0].checked).toBe(true); expect(checks[1].checked).toBe(false);
    expect(button('Reveal secret')).toBeUndefined();
});
it('creates through the provided endpoint and appends the returned record', async () => {
    mount(); button('+ Add Webhook').click(); await nextTick();
    await fill('form input[maxlength="120"]', 'New webhook');
    await fill('form select', '10', 'change');
    await fill('form input[type="url"]', 'https://new.example/events');
    submit(); await settle();
    expect(mocks.post).toHaveBeenCalledWith('/admin/webhooks', expect.objectContaining({ name: 'New webhook', is_enabled: false }));
    expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(2); expect(host.querySelector('form')).toBeNull();
    expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'success' }));
});
it('edits using the rows own endpoint and preserves immutable props', async () => {
    mount(); await openEdit(); await fill('form input[maxlength="120"]', 'Renamed'); submit(); await settle();
    expect(mocks.put).toHaveBeenCalledWith('/admin/webhooks/1', expect.objectContaining({ name: 'Renamed' }));
    expect(host.textContent).toContain('Renamed'); expect(props.subscriptions[0].name).toBe('Gner8 predictions');
});
it('preserves editor values and shows field validation failures', async () => {
    mocks.put.mockRejectedValue({ response: { data: { message: 'Validation failed', errors: { receiving_url: ['Use HTTPS.'] } } } });
    mount(); await openEdit(); submit(); await settle();
    expect(host.querySelector('form')).not.toBeNull(); expect(host.textContent).toContain('Use HTTPS.');
    expect(host.querySelector('form input[type="url"]').value).toBe(row().receiving_url);
});
it('prevents duplicate saves while the request is pending', async () => {
    mocks.put.mockReturnValue(new Promise(() => {})); mount(); await openEdit(); submit(); submit(); await nextTick();
    expect(mocks.put).toHaveBeenCalledTimes(1); expect(button('Saving…').disabled).toBe(true);
});
it('cancels edits without persistence', async () => {
    mount(); await openEdit(); await fill('form input[maxlength="120"]', 'Unsaved'); button('Cancel').click(); await nextTick();
    expect(host.querySelector('form')).toBeNull(); expect(mocks.put).not.toHaveBeenCalled();
    expect(props.subscriptions[0].name).toBe('Gner8 predictions');
});
it('persists enabled switches and updates both responsive presentations', async () => {
    mount(); host.querySelector('[role="switch"]').click(); await settle();
    expect(mocks.patch).toHaveBeenCalledWith('/admin/webhooks/1/status', { is_enabled: false });
    expect([...host.querySelectorAll('[role="switch"]')].every(node => node.getAttribute('aria-checked') === 'false')).toBe(true);
    expect(mocks.notify).toHaveBeenCalledWith({ type: 'success', message: 'Webhook disabled.' });
});
it('retains previous enabled state on a failed toggle', async () => {
    mocks.patch.mockRejectedValue(new Error('offline')); mount(); host.querySelector('[role="switch"]').click(); await settle();
    expect(host.querySelector('[role="switch"]').getAttribute('aria-checked')).toBe('true');
    expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
});
it('fetches signing secrets only after an explicit reveal', async () => {
    mount(); await openEdit(); expect(mocks.get).not.toHaveBeenCalled();
    button('Reveal secret').click(); await settle(); expect(mocks.get).toHaveBeenCalledWith('/admin/webhooks/1/secret');
    expect(host.textContent).toContain('whsec_test');
});
it('hides a revealed secret without making another request', async () => {
    mount(); await openEdit(); button('Reveal secret').click(); await settle(); button('Hide secret').click(); await nextTick();
    expect(host.textContent).not.toContain('whsec_test'); expect(mocks.get).toHaveBeenCalledTimes(1);
});
it('copies an explicitly revealed secret', async () => {
    mount(); await openEdit(); button('Reveal secret').click(); await settle(); button('Copy secret').click(); await settle();
    expect(mocks.copy).toHaveBeenCalledWith('whsec_test');
});
it('does not show a secret when reveal fails', async () => {
    mocks.get.mockRejectedValue(new Error('offline')); mount(); await openEdit(); button('Reveal secret').click(); await settle();
    expect(button('Copy secret')).toBeUndefined(); expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
});
it('requires confirmation before deleting a subscription', async () => {
    mount(); await openEdit(); button('Delete').click(); await nextTick();
    expect(host.textContent).toContain('Delete webhook?'); expect(mocks.delete).not.toHaveBeenCalled();
    button('Delete Webhook').click(); await settle();
    expect(mocks.delete).toHaveBeenCalledWith('/admin/webhooks/1'); expect(host.textContent).toContain('No webhooks yet');
});
it('cancels deletion without removing the row', async () => {
    mount(); await openEdit(); button('Delete').click(); await nextTick(); button('Keep Webhook').click(); await nextTick();
    expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(1); expect(mocks.delete).not.toHaveBeenCalled();
});
it('retains the row and confirmation after a failed delete', async () => {
    mocks.delete.mockRejectedValue(new Error('offline')); mount(); await openEdit(); button('Delete').click(); await nextTick();
    button('Delete Webhook').click(); await settle(); expect(host.querySelectorAll('[data-webhook-id]')).toHaveLength(1);
    expect(button('Delete Webhook')).toBeDefined(); expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
});
it('has no notification delivery history test or retry controls', () => {
    mount(); expect(button('Send Test')).toBeUndefined(); expect(button('Retry')).toBeUndefined();
    expect(host.textContent).not.toContain('Delivery history'); expect(mocks.get).not.toHaveBeenCalled();
});
it('retains search when refreshed inventory props arrive', async () => {
    mount(); await fill('[aria-label="Search webhooks"]', 'next');
    props.subscriptions = [row({ name: 'Next subscription' })]; await nextTick();
    expect(host.querySelector('[data-webhook-id]').textContent).toContain('Next subscription');
    expect(host.querySelector('[aria-label="Search webhooks"]').value).toBe('next');
});

it('reports clipboard failure without discarding the revealed secret', async () => {
    mocks.copy.mockRejectedValue(new Error('clipboard unavailable'));
    mount(); await openEdit(); button('Reveal secret').click(); await settle(); button('Copy secret').click(); await settle();
    expect(host.textContent).toContain('whsec_test');
    expect(mocks.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', message: 'Unable to copy. Select and copy the secret below.' }));
});

it('clears revealed secrets when the editor closes', async () => {
    mount(); await openEdit(); button('Reveal secret').click(); await settle(); button('Cancel').click(); await nextTick();
    await openEdit(); expect(host.textContent).not.toContain('whsec_test');
    expect(button('Reveal secret')).toBeDefined();
});
