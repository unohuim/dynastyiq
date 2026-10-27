/* @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, ref, shallowRef } from 'vue';
import { useLiveGameFeed } from '../../layouts/live-game-feed';

describe('shared live game subscriptions', () => {
  let app;
  let incoming;
  let connected;
  let state;
  let update;
  const settle = async () => { for (let i = 0; i < 12; i += 1) await Promise.resolve(); await nextTick(); };
  const snapshot = (sequence = 1, extra = {}) => ({ sequence, observed_at: new Date().toISOString(), changed: true,
    game: { nhl_game_id: 123, game_state: 'LIVE', mode: 'live', away: { score: 0 }, home: { score: 1 } }, ...extra });
  const mount = async (options = {}) => {
    incoming = shallowRef(null);
    connected = ref(true);
    update = vi.fn();
    app = createApp({ setup() {
      state = useLiveGameFeed({ update, ...options });
      return () => h('p', state.warning.value);
    } });
    app.provide('nhlLiveGame', incoming);
    app.provide('nhlLiveConnected', connected);
    app.mount('#live-test');
    await settle();
  };
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-27T23:00:00Z'));
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ snapshots: [] }) }));
    document.body.innerHTML = '<div id="live-test"></div>';
  });
  afterEach(() => {
    app?.unmount(); app = null;
    vi.useRealTimers(); vi.unstubAllGlobals(); vi.restoreAllMocks(); document.body.innerHTML = '';
  });

  it('reads one initial snapshot to close the subscription hydration gap', async () => {
    await mount(); expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('reads only our shared endpoint and never an NHL endpoint', async () => {
    await mount(); expect(fetch.mock.calls[0][0].pathname).toBe('/games/live');
  });
  it('requests a specific game for a detail subscriber', async () => {
    await mount({ gameId: 123 }); expect(fetch.mock.calls[0][0].searchParams.get('game_id')).toBe('123');
  });
  it('bypasses browser caches when resynchronizing live snapshots', async () => {
    await mount(); expect(fetch.mock.calls[0][1].cache).toBe('no-store');
  });
  it('applies pushed scores without making an HTTP request for compact consumers', async () => {
    await mount(); incoming.value = snapshot(); await settle();
    expect(update).toHaveBeenCalledWith(expect.objectContaining({ sequence: 1 }), false);
    expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('ignores a pushed snapshot with an older sequence', async () => {
    await mount(); incoming.value = snapshot(5); incoming.value = snapshot(4); await settle();
    expect(update).toHaveBeenCalledTimes(1);
  });
  it('accepts a score correction when its sequence is newer', async () => {
    await mount(); incoming.value = snapshot(1); incoming.value = snapshot(2, { game: { nhl_game_id: 123, mode: 'live', home: { score: 0 } } });
    await settle(); expect(update.mock.calls.at(-1)[0].game.home.score).toBe(0);
  });
  it('keeps different games sequence fences independent', async () => {
    await mount(); incoming.value = snapshot(100); incoming.value = snapshot(1, { game: { nhl_game_id: 124 } });
    await settle(); expect(update).toHaveBeenCalledTimes(2);
  });
  it('rejects updates for another detail game', async () => {
    await mount({ gameId: 456 }); incoming.value = snapshot(); await settle(); expect(update).not.toHaveBeenCalled();
  });
  it('honors the visible games predicate', async () => {
    await mount({ accept: game => game.nhl_game_id === 456 }); incoming.value = snapshot(); await settle();
    expect(update).not.toHaveBeenCalled();
  });
  it('does not refetch details for unchanged heartbeat messages', async () => {
    await mount({ full: true }); incoming.value = snapshot(); await settle();
    const count = fetch.mock.calls.length;
    incoming.value = snapshot(2, { changed: false }); await settle(); expect(fetch).toHaveBeenCalledTimes(count);
  });
  it('applies scores immediately while updated details are requested', async () => {
    await mount({ full: true }); incoming.value = snapshot(); await settle();
    expect(update.mock.calls[0][1]).toBe(false); expect(fetch.mock.calls.at(-1)[0].searchParams.get('game_id')).toBe('123');
  });
  it('applies full detail from the shared snapshot response', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => ({ snapshots: [snapshot()] }) });
    await mount({ gameId: 123, full: true }); expect(update).toHaveBeenCalledWith(expect.any(Object), true);
  });
  it('does not replace lineups with pregame clock snapshots', async () => {
    await mount({ full: true }); incoming.value = snapshot(1, { game: { nhl_game_id: 123, mode: 'preview' } });
    await settle(); expect(fetch).toHaveBeenCalledTimes(1); expect(update.mock.calls[0][1]).toBe(false);
  });
  it('resynchronizes after Reverb reconnects', async () => {
    await mount(); connected.value = false; await settle(); connected.value = true; await settle();
    expect(fetch).toHaveBeenCalledTimes(2);
  });
  it('resynchronizes when the browser returns to the foreground', async () => {
    await mount(); document.dispatchEvent(new Event('visibilitychange')); await settle(); expect(fetch).toHaveBeenCalledTimes(2);
  });
  it('does not resynchronize a hidden document', async () => {
    await mount(); vi.spyOn(document, 'hidden', 'get').mockReturnValue(true);
    document.dispatchEvent(new Event('visibilitychange')); await settle(); expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('marks an expired live stream as delayed and attempts recovery', async () => {
    await mount(); incoming.value = snapshot(); await settle();
    await vi.advanceTimersByTimeAsync(25000); await settle(); expect(state.warning.value).toContain('delayed');
    expect(fetch.mock.calls.length).toBeGreaterThan(1);
  });
  it('does not apply explicitly stale server snapshots', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => ({ snapshots: [snapshot(1, { stale: true })] }) });
    await mount(); expect(update).not.toHaveBeenCalled(); expect(state.warning.value).toContain('delayed');
  });
  it('shows connection interruptions instead of silently claiming current data', async () => {
    await mount(); connected.value = false; await settle(); expect(state.warning.value).toContain('interrupted');
  });
  it('shows snapshot errors and keeps the mounted consumer alive', async () => {
    fetch.mockRejectedValue(new Error('offline')); await mount(); expect(state.warning.value).toContain('unavailable');
  });
  it('never periodically fetches snapshots while a healthy stream is active', async () => {
    await mount(); incoming.value = snapshot(); await settle(); await vi.advanceTimersByTimeAsync(15000);
    expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('stops reconnect polling after the component unmounts', async () => {
    await mount(); connected.value = false; await settle(); app.unmount(); app = null;
    const count = fetch.mock.calls.length; await vi.advanceTimersByTimeAsync(30000); expect(fetch).toHaveBeenCalledTimes(count);
  });
  it('aborts a pending snapshot request on unmount', async () => {
    fetch.mockReturnValue(new Promise(() => {})); await mount(); const signal = fetch.mock.calls[0][1].signal;
    app.unmount(); app = null; expect(signal.aborted).toBe(true);
  });
});
