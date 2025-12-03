import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { registerToastStack } from './toast-stack';

const createComponent = (options = {}) => {
    let factory;
    const Alpine = {
        data: vi.fn((name, dataFactory) => {
            if (name === 'toastStack') {
                factory = dataFactory;
            }
        }),
    };

    registerToastStack(Alpine);

    const component = factory?.(options);
    if (!component) throw new Error('toastStack factory was not registered');

    component.$nextTick = (cb) => cb();
    component.$refs = { liveRegion: { focus: vi.fn() } };

    return component;
};

describe('toast-stack Alpine registration', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        delete window.toast;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('registers the toastStack data component when Alpine provides data()', () => {
        const Alpine = { data: vi.fn() };

        registerToastStack(Alpine);

        expect(Alpine.data).toHaveBeenCalledWith('toastStack', expect.any(Function));
    });

    it('normalizes initial flashes and schedules dismissals during boot', () => {
        const component = createComponent({
            flashes: [
                { message: 'Success', type: 'success' },
                { message: '   ', type: 'success' },
                { message: 'Needs type normalization', type: 'unknown' },
            ],
            defaultDuration: 1000,
        });

        component.boot();

        expect(component.toasts).toEqual([
            expect.objectContaining({ id: 1, message: 'Success', type: 'success', duration: 1000, closing: false }),
            expect.objectContaining({ id: 3, message: 'Needs type normalization', type: 'info', duration: 1000, closing: false }),
        ]);
        expect(component.nextId).toBe(3);
        expect(window.toast).toBeDefined();

        vi.advanceTimersByTime(1000);
        expect(component.toasts.every((toast) => toast.closing)).toBe(true);

        vi.advanceTimersByTime(180);
        expect(component.toasts).toHaveLength(0);
    });

    it('adds and removes runtime toasts via handleEvent and global toast helpers', () => {
        const component = createComponent();
        component.boot();

        component.handleEvent({ detail: { message: 'Runtime toast', duration: 200 } });
        expect(component.toasts).toHaveLength(1);
        expect(component.toasts[0]).toMatchObject({ message: 'Runtime toast', type: 'info' });
        expect(component.$refs.liveRegion.focus).toHaveBeenCalled();

        const id = window.toast.success('Queued', { duration: 10000 });
        expect(component.toasts).toHaveLength(2);
        expect(component.toasts[1]).toMatchObject({ id, message: 'Queued', type: 'success' });

        vi.advanceTimersByTime(component.toasts[0].duration);
        vi.advanceTimersByTime(180);
        expect(component.toasts).toEqual([expect.objectContaining({ id, message: 'Queued', type: 'success' })]);

        vi.advanceTimersByTime(component.toasts[0].duration);
        vi.advanceTimersByTime(180);
        expect(component.toasts).toHaveLength(0);
    });
});
