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

    it('shows pause and stop controls while a build is queued', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0"><span>Building</span><form data-pregame-run-control-form><button>Pause</button></form><form data-pregame-run-control-form><button>Stop</button></form></div>');
        expect(row.textContent).toContain('Pause');
        expect(row.textContent).toContain('Stop');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(true);
    });

    it('keeps pause and stop controls available while a build is running', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0"><span>Building</span><form data-pregame-run-control-form><button>Pause</button></form><form data-pregame-run-control-form><button>Stop</button></form></div>');
        expect(row.textContent).toContain('Building');
        expect(row.querySelectorAll('[data-pregame-run-control-form]')).toHaveLength(2);
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(true);
    });

    it('shows pausing status while claimed work drains', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0"><span>Pausing after claimed games</span><form data-pregame-run-control-form><button>Stop</button></form></div>');
        expect(row.textContent).toContain('Pausing after claimed games');
        expect(row.textContent).not.toContain('Pause');
        expect(row.textContent).toContain('Stop');
    });

    it('offers resume and stop for a paused build', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0"><span>Pregame paused</span><form data-pregame-run-control-form><button>Resume</button></form><form data-pregame-run-control-form><button>Stop</button></form></div>');
        expect(row.textContent).toContain('Resume');
        expect(row.textContent).toContain('Stop');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(true);
    });

    it('unlocks a fresh build and hides controls after stop', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="0" data-pregame-viewable="0"><span>Pregame stopped</span></div>');
        expect(row.textContent).toContain('Pregame stopped');
        expect(row.querySelectorAll('[data-pregame-run-control-form]')).toHaveLength(0);
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(false);
    });

    it('unlocks a fresh build after completed status', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="0" data-pregame-viewable="1"><span>Pregame completed</span></div>');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(false);
        expect(row.querySelector('[data-pregame-view-link]').classList.contains('hidden')).toBe(false);
    });

    it('unlocks a fresh build after failed status', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="0" data-pregame-viewable="0"><span>Pregame failed</span></div>');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(false);
        expect(row.textContent).toContain('Pregame failed');
    });

    it('keeps the build action locked through pausing and paused transitions', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0">Pausing</div>');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(true);
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0">Pregame paused</div>');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(true);
    });

    it('unlocks the build action after a stopped state follows a pause', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0">Pregame paused</div>');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="0" data-pregame-viewable="0">Pregame stopped</div>');
        expect(row.querySelector('[data-sat-model-pregame-build-form] button').disabled).toBe(false);
        expect(row.querySelectorAll('[data-pregame-run-control-form]')).toHaveLength(0);
    });

    it('restores pause and stop actions after resuming', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0"><form data-pregame-run-control-form><button>Resume</button></form></div>');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0"><form data-pregame-run-control-form><button>Pause</button></form><form data-pregame-run-control-form><button>Stop</button></form></div>');
        expect(row.textContent).toContain('Pause');
        expect(row.textContent).toContain('Stop');
        expect(row.textContent).not.toContain('Resume');
    });

    it('keeps run-control forms inside the live progress region', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0" role="status" aria-live="polite"><form data-pregame-run-control-form><button>Resume</button></form></div>');
        expect(row.querySelector('[data-pregame-progress] [role="status"]').getAttribute('aria-live')).toBe('polite');
        expect(row.querySelector('[data-pregame-progress] [data-pregame-run-control-form]')).not.toBeNull();
    });

    it('keeps completed game counts in the updated control state', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0">Pregame paused · 8 / 20 games</div>');
        expect(row.textContent).toContain('8 / 20 games');
        expect(row.textContent).toContain('Pregame paused');
    });

    it('replaces stale action forms when the server reports a terminal state', () => {
        const row = document.querySelector('tr');
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0"><form data-pregame-run-control-form><button>Stop</button></form></div>');
        updatePregameProgress(row, '<div data-pregame-active="0" data-pregame-locked="0" data-pregame-viewable="0">Pregame stopped</div>');
        expect(row.querySelector('[data-pregame-run-control-form]')).toBeNull();
        expect(row.textContent).toContain('Pregame stopped');
    });

    it('keeps polling while pausing but stops polling after the pause settles', async () => {
        const row = document.querySelector('tr');
        row.querySelector('[data-pregame-progress]').dataset.progressUrl = '/progress';
        updatePregameProgress(row, '<div data-pregame-active="1" data-pregame-locked="1" data-pregame-viewable="0">Pausing</div>');
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ progress_html: '<div data-pregame-active="0" data-pregame-locked="1" data-pregame-viewable="0">Pregame paused</div>' }) });
        vi.stubGlobal('fetch', fetch);
        stop = mountPregameProgress(document.body);
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetch).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
