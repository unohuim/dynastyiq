/* @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import GamePredictions from './GamePredictions.vue';

describe('Operational Game Predictions', () => {
    let app;
    let list;
    let fetcher;
    const revision = { id: 4, source: 'api', prediction_available: true, is_canonical: true, away_goals: 2.2,
        home_goals: 3.1, winner: 'TOR', win_probability: .61, confidence: 67, qualified: true, predicted_at: '2026-10-22T15:00:00Z' };
    const settle = async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); await nextTick(); };
    const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
    const mount = async () => {
        app = createApp(GamePredictions, { url: '/admin/game-predictions', today: '2026-10-22' });
        app.mount('#predictions'); await settle();
    };
    const input = async (node, value) => {
        node.value = value; node.dispatchEvent(new Event('input', { bubbles: true })); await nextTick();
        node.dispatchEvent(new Event('change', { bubbles: true })); await settle();
    };
    beforeEach(() => {
        vi.useFakeTimers(); vi.setSystemTime(new Date('2026-10-22T15:00:00Z'));
        document.head.innerHTML = '<meta name="csrf-token" content="test-csrf">';
        document.body.innerHTML = '<div id="predictions"></div>';
        list = { games: [{ nhl_game_id: 1, date: '2026-10-22', away: 'BOS', home: 'TOR', actual_away: null, actual_home: null,
            revision: { ...revision }, work: null }], runs: [{ id: 7, start_date: '2026-10-21', end_date: '2026-10-22', status: 'completed' }],
        run: null, current_page: 1, last_page: 1, total: 1 };
        fetcher = vi.fn(async (url, options) => {
            const payload = options?.method === 'POST' ? { run_id: 8, total_games: 1 }
                : url.includes('/games/') ? { revisions: [{ ...revision }], current_page: 1, last_page: 1, total: 1 }
                : url.includes('/revisions/') ? { payload: { prediction: { score: 'saved' } }, evidence: { stack: 'Default' } } : list;
            return { ok: true, json: async () => structuredClone(payload) };
        });
        vi.stubGlobal('fetch', fetcher);
    });
    afterEach(() => {
        app?.unmount(); app = null; vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = ''; document.head.innerHTML = '';
    });

    it('starts on the backend supplied date', async () => {
        await mount(); expect(document.querySelector('input[type=date]').value).toBe('2026-10-22');
        expect(fetcher.mock.calls[0][0]).toContain('start_date=2026-10-22');
    });
    it('shows one date until range mode is enabled', async () => {
        await mount(); expect(document.querySelectorAll('input[type=date]')).toHaveLength(1);
        document.querySelector('input[type=checkbox]').click(); await nextTick();
        expect(document.querySelectorAll('input[type=date]')).toHaveLength(2);
    });
    it('constrains the end-date picker to the start date', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await nextTick();
        expect(document.querySelectorAll('input[type=date]')[1].min).toBe('2026-10-22');
    });
    it('submits a single date to the operational run endpoint', async () => {
        await mount(); button('Run Predictions').click(); await settle();
        const call = fetcher.mock.calls.find(([, options]) => options.method === 'POST');
        expect(call[0]).toBe('/admin/game-predictions/runs');
        expect(JSON.parse(call[1].body)).toEqual({ start_date: '2026-10-22', end_date: '2026-10-22' });
    });
    it('submits the inclusive selected range', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await nextTick();
        await input(document.querySelectorAll('input[type=date]')[1], '2026-10-24');
        button('Run Predictions').click(); await settle();
        expect(JSON.parse(fetcher.mock.calls.find(([, options]) => options.method === 'POST')[1].body).end_date).toBe('2026-10-24');
    });
    it('includes the existing CSRF token when starting work', async () => {
        await mount(); button('Run Predictions').click(); await settle();
        expect(fetcher.mock.calls.find(([, options]) => options.method === 'POST')[1].headers['X-CSRF-TOKEN']).toBe('test-csrf');
    });
    it('shows immediate run feedback and emits the existing toast event', async () => {
        const listener = vi.fn(); window.addEventListener('toast', listener);
        await mount(); button('Run Predictions').click(); await settle();
        expect(document.body.textContent).toContain('Prediction run #8 created for 1 games.');
        expect(listener).toHaveBeenCalledOnce(); window.removeEventListener('toast', listener);
    });
    it('shows away-home projected scores from the latest saved revision', async () => {
        await mount(); expect(document.querySelector('tbody').textContent).toContain('2.20–3.10');
    });
    it('keeps win probability distinct from confidence', async () => {
        await mount(); expect(document.querySelector('tbody').textContent).toContain('61.0%');
        expect(document.querySelector('tbody').textContent).toContain('67.00%');
    });
    it('shows qualification independently of the predicted winner', async () => {
        list.games[0].revision.qualified = false; await mount();
        expect(document.querySelector('tbody').textContent).toContain('TOR');
        expect(document.querySelector('tbody').textContent).toContain('No');
    });
    it('does not fabricate a score before any successful calculation', async () => {
        list.games[0].revision = null; await mount();
        expect(document.querySelector('tbody').textContent).not.toContain('2.20–3.10');
        expect(document.querySelector('tbody').textContent).toContain('—');
    });
    it('shows actual final scores when supplied', async () => {
        list.games[0].actual_away = 3; list.games[0].actual_home = 4; await mount();
        expect(document.querySelector('tbody').textContent).toContain('3–4');
    });
    it('retains the latest score while a newer work item fails', async () => {
        list.games[0].work = { status: 'failed', last_error: 'Missing lineup' }; await mount();
        expect(document.querySelector('tbody').textContent).toContain('2.20–3.10');
        expect(document.querySelector('tbody').textContent).toContain('Missing lineup');
    });
    it('counts completed unavailable and failed items toward visible progress', async () => {
        list.run = { id: 8, status: 'running', total_games: 5, counts: { completed: 2, unavailable: 1, failed: 1 } }; await mount();
        expect(document.querySelector('progress').value).toBe(4);
        expect(document.querySelector('progress').max).toBe(5);
    });
    it('opens saved history without running predictions', async () => {
        await mount(); button('Revisions').click(); await settle();
        expect(fetcher.mock.calls.some(([url]) => url.includes('/games/1/revisions'))).toBe(true);
        expect(fetcher.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        expect(button('Revisions').getAttribute('aria-expanded')).toBe('true');
    });
    it('closes expanded history on a second click', async () => {
        await mount(); button('Revisions').click(); await settle(); button('Revisions').click(); await settle();
        expect(button('Revisions').getAttribute('aria-expanded')).toBe('false');
    });
    it('loads complete frozen evidence only on request', async () => {
        await mount(); button('Revisions').click(); await settle(); button('Saved inputs & result').click(); await settle();
        expect(fetcher.mock.calls.some(([url]) => url.endsWith('/revisions/4'))).toBe(true);
        expect(document.querySelector('pre').textContent).toContain('Default');
    });
    it('hides evidence on a second click', async () => {
        await mount(); button('Revisions').click(); await settle(); button('Saved inputs & result').click(); await settle();
        button('Saved inputs & result').click(); await settle(); expect(document.querySelector('pre')).toBeNull();
    });
    it('shows a calm empty state when no stored games match', async () => {
        list.games = []; list.total = 0; await mount();
        expect(document.body.textContent).toContain('No stored games for these dates.');
    });
    it('shows server validation errors from a rejected run', async () => {
        await mount(); fetcher.mockImplementationOnce(async () => ({ ok: false, json: async () => ({ errors: { stack: ['Select a default stack.'] } }) }));
        button('Run Predictions').click(); await settle();
        expect(document.querySelector('[role=alert]').textContent).toBe('Select a default stack.');
    });
    it('refreshes the list immediately when a date is selected without recalculating', async () => {
        await mount(); await input(document.querySelector('input[type=date]'), '2026-10-25');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-25');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-25');
        expect(fetcher.mock.calls.every(([, options]) => !options.method)).toBe(true);
        expect(button('View Results')).toBeUndefined();
    });
    it('switches to a selected previous runs dates and id', async () => {
        await mount(); const select = document.querySelector('select'); select.value = '7';
        select.dispatchEvent(new Event('change', { bubbles: true })); await settle();
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-21');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('run_id=7');
    });
    it('paginates game results without recalculating', async () => {
        list.last_page = 2; await mount(); button('Next').click(); await settle();
        expect(fetcher.mock.calls.at(-1)[0]).toContain('page=2');
        expect(fetcher.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
    });
    it('polls only reads while the tab is mounted', async () => {
        await mount(); const before = fetcher.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5000); await settle(); expect(fetcher.mock.calls.length).toBeGreaterThan(before);
        expect(fetcher.mock.calls.every(([, options]) => !options.method)).toBe(true);
    });
    it('stops polling when the tab unmounts', async () => {
        await mount(); app.unmount(); app = null; const before = fetcher.mock.calls.length;
        await vi.advanceTimersByTimeAsync(15000); expect(fetcher.mock.calls.length).toBe(before);
    });
    it('aborts in-flight reads on unmount', async () => {
        let signal;
        fetcher.mockImplementation((_url, options) => { signal = options.signal; return new Promise(() => {}); });
        await mount(); app.unmount(); app = null; expect(signal.aborted).toBe(true);
    });
    it('escapes saved reasons instead of interpreting them as HTML', async () => {
        await mount(); list.games[0].work = { status: 'failed', last_error: '<img src=x onerror=alert(1)>' };
        await vi.advanceTimersByTimeAsync(5000); await settle();
        expect(document.querySelector('tbody img')).toBeNull();
        expect(document.querySelector('tbody').textContent).toContain('<img src=x onerror=alert(1)>');
    });
    it('moves to the previous day and fetches its games', async () => {
        await mount(); document.querySelector('[aria-label="Previous day"]').click(); await settle();
        expect(document.querySelector('input[type=date]').value).toBe('2026-10-21');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-21');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-21');
    });
    it('moves to the next day and fetches its games', async () => {
        await mount(); document.querySelector('[aria-label="Next day"]').click(); await settle();
        expect(document.querySelector('input[type=date]').value).toBe('2026-10-23');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-23');
    });
    it('fetches the inclusive range as soon as the second date is selected', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        await input(document.querySelector('#prediction-end-date'), '2026-10-25');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-22');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-25');
    });
    it('moves the range end independently with its arrows', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        document.querySelector('[aria-label="Next end date"]').click(); await settle();
        expect(document.querySelector('#prediction-end-date').value).toBe('2026-10-23');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-22');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-23');
    });
    it('moves the range start independently with its arrows', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        document.querySelector('[aria-label="Previous start date"]').click(); await settle();
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-21');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-22');
    });
    it('disables arrows that would reverse a same-day range', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        expect(document.querySelector('[aria-label="Next start date"]').disabled).toBe(true);
        expect(document.querySelector('[aria-label="Previous end date"]').disabled).toBe(true);
    });
    it('does not fetch incomplete selections or retain stale game rows', async () => {
        await mount(); const before = fetcher.mock.calls.length;
        await input(document.querySelector('#prediction-start-date'), '');
        expect(fetcher.mock.calls.length).toBe(before);
        expect(document.querySelector('tbody').textContent).not.toContain('BOS');
        expect(document.querySelector('[role=alert]').textContent).toContain('Choose valid dates');
        expect(button('Run Predictions').disabled).toBe(true);
    });
    it('does not fetch a reversed range until the second date becomes valid', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        const before = fetcher.mock.calls.length;
        await input(document.querySelector('#prediction-start-date'), '2026-10-24');
        expect(fetcher.mock.calls.length).toBe(before);
        expect(document.querySelector('[role=alert]').textContent).toContain('End date');
        await input(document.querySelector('#prediction-end-date'), '2026-10-25');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('start_date=2026-10-24');
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-25');
    });
    it('handles month year and leap-day boundaries as calendar days', async () => {
        await mount(); await input(document.querySelector('#prediction-start-date'), '2027-01-01');
        document.querySelector('[aria-label="Previous day"]').click(); await settle();
        expect(document.querySelector('#prediction-start-date').value).toBe('2026-12-31');
        await input(document.querySelector('#prediction-start-date'), '2028-03-01');
        document.querySelector('[aria-label="Previous day"]').click(); await settle();
        expect(document.querySelector('#prediction-start-date').value).toBe('2028-02-29');
    });
    it('returns to single-date results when range mode is disabled', async () => {
        await mount(); document.querySelector('input[type=checkbox]').click(); await settle();
        await input(document.querySelector('#prediction-end-date'), '2026-10-25');
        document.querySelector('input[type=checkbox]').click(); await settle();
        expect(fetcher.mock.calls.at(-1)[0]).toContain('end_date=2026-10-22');
        expect(document.querySelector('#prediction-end-date')).toBeNull();
    });

    it('does not start predictions when a date field submits the form', async () => {
        await mount(); document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await settle(); expect(fetcher.mock.calls.every(([, options]) => !options.method)).toBe(true);
    });

});
