// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountPregameProgress, updatePregameProgress } from './nhl-sat-models';

describe('model pregame progress', () => {
    let stop;
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        document.body.innerHTML = `<table><tbody><tr data-sat-model-row="1"><td>
            <div data-pregame-progress></div>
            <span data-model-training-status>Complete</span>
            <form data-sat-model-pregame-build-form><button type="submit">Build Pregame</button></form>
            <a data-pregame-view-link class="hidden" href="/effects">View Pregame Impacts</a>
            <span data-pregame-view-disabled aria-disabled="true">View Pregame Impacts</span>
        </td></tr></tbody></table>`;
    });

    afterEach(() => {
        stop?.();
        stop = undefined;
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('shows live progress and disables duplicate starts while building', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1">Building · 12 / 75 games</div>');
        expect(row.textContent).toContain('Building · 12 / 75 games');
        expect(row.querySelector('button').disabled).toBe(true);
        expect(row.querySelector('[data-model-training-status]').classList.contains('hidden')).toBe(true);
    });

    it.each(['Pregame completed', 'Pregame failed'])('shows %s and enables a rebuild', (label) => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1">Building</div>');
        updatePregameProgress(row, `<div data-pregame-active="0">${label}</div>`);
        expect(row.textContent).toContain(label);
        expect(row.querySelector('button').disabled).toBe(false);
        expect(row.querySelector('[data-model-training-status]').classList.contains('hidden')).toBe(false);
    });

    it('preserves the model row and does not require one after navigation', () => {
        const row = document.querySelector('tr');
        const form = row.querySelector('form');
        updatePregameProgress(row, '<div data-pregame-active="1">Building</div>');
        expect(row.querySelector('form')).toBe(form);
        expect(() => updatePregameProgress(null, '')).not.toThrow();
    });

    it('enables inspection when saved data becomes available and disables it during a rebuild', () => {
        const row = document.querySelector('tr');
        const link = row.querySelector('[data-pregame-view-link]');
        const disabled = row.querySelector('[data-pregame-view-disabled]');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-viewable="1">Pregame completed</div>');
        expect(link.classList.contains('hidden')).toBe(false);
        expect(disabled.classList.contains('hidden')).toBe(true);
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-viewable="0">Building</div>');
        expect(link.classList.contains('hidden')).toBe(true);
        expect(disabled.classList.contains('hidden')).toBe(false);
    });

    it('keeps inspection disabled when completion has no saved data', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-viewable="0">Pregame completed</div>');
        expect(row.querySelector('[data-pregame-view-link]').classList.contains('hidden')).toBe(true);
        expect(row.querySelector('[data-pregame-view-disabled]').classList.contains('hidden')).toBe(false);
    });

    it('polls active progress and stops requesting after completion', async () => {
        const row = document.querySelector('tr');
        row.querySelector('[data-pregame-progress]').dataset.progressUrl = '/progress';
        updatePregameProgress(row, '<div data-pregame-active="1">Building</div>');
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ progress_html: '<div data-pregame-active="0">Pregame completed</div>' }) });
        vi.stubGlobal('fetch', fetch);
        stop = mountPregameProgress(document.body);
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(row.textContent).toContain('Pregame completed');
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('retains progress during a failed request and retries without dispatching work', async () => {
        const row = document.querySelector('tr');
        row.querySelector('[data-pregame-progress]').dataset.progressUrl = '/progress';
        updatePregameProgress(row, '<div data-pregame-active="1">Building · 3 / 10</div>');
        const fetch = vi.fn().mockRejectedValue(new Error('Offline'));
        vi.stubGlobal('fetch', fetch);
        stop = mountPregameProgress(document.body);
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(row.textContent).toContain('Building · 3 / 10');
        expect(fetch.mock.calls[0][1].method).toBeUndefined();
        stop();
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
