/* @vitest-environment jsdom */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import GoaliePicker from './GoaliePicker.vue';
import GameCard from './GameCard.vue';

describe('game goalie picker', () => {
  let app;
  const settle = async () => { for (let i = 0; i < 12; i += 1) await Promise.resolve(); await nextTick(); };
  const mount = (selected = vi.fn()) => {
    document.body.innerHTML = '<div id="picker-test"></div>';
    app = createApp({ render: () => h(GoaliePicker, {
      gameId: 123, team: 'TOR', goalie: { name: 'Old Starter', nhl_player_id: 1, status: 'confirmed', avatar_url: '/old.png' }, onSelected: selected,
    }) });
    app.mount('#picker-test');
    return selected;
  };
  afterEach(() => { app?.unmount(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

  it('opens from the avatar and saves a searched prospect without navigation', async () => {
    const fetcher = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ goalies: [
      { player_id: 20, nhl_player_id: 2, name: 'Young Prospect', league: 'AHL' },
      { player_id: 30, nhl_player_id: 3, name: 'Other Goalie' },
    ] }) }).mockResolvedValueOnce({ ok: true, json: async () => ({ nhl_game_id: 123, team_abbrev: 'TOR', starting_goalie: { name: 'Young Prospect', nhl_player_id: 2 } }) });
    vi.stubGlobal('fetch', fetcher);
    const selected = mount();
    document.querySelector('img').click(); await settle();
    expect(fetcher.mock.calls[0][0]).toBe('/games/123/goalies?team_abbrev=TOR');
    const input = document.querySelector('input');
    input.value = 'young'; input.dispatchEvent(new Event('input', { bubbles: true })); await nextTick();
    expect(document.querySelectorAll('[role="option"]')).toHaveLength(1);
    document.querySelector('[role="option"]').click(); await settle();
    expect(JSON.parse(fetcher.mock.calls[1][1].body)).toEqual({ team_abbrev: 'TOR', player_id: 20 });
    expect(selected).toHaveBeenCalledWith(expect.objectContaining({ starting_goalie: { name: 'Young Prospect', nhl_player_id: 2 } }));
    expect(document.querySelector('button').getAttribute('aria-expanded')).toBe('false');
  });

  it('retains the current starter and displays failed save errors', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ goalies: [{ player_id: 2, name: 'Prospect' }] }) })
      .mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Player no longer belongs to TOR.' }) }));
    const selected = mount();
    document.querySelector('button').click(); await settle();
    document.querySelector('[role="option"]').click(); await settle();
    expect(document.querySelector('[role="alert"]').textContent).toContain('Player no longer belongs');
    expect(document.body.textContent).toContain('Old Starter');
    expect(selected).not.toHaveBeenCalled();
    expect(document.querySelector('button').getAttribute('aria-expanded')).toBe('true');
  });

  it('supports keyboard navigation and Escape dismissal', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ goalies: [{ player_id: 2, name: 'Prospect' }] }) }));
    mount(); document.querySelector('button').click(); await settle();
    document.querySelector('input').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement.getAttribute('role')).toBe('option');
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await nextTick();
    expect(document.querySelector('button').getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(document.querySelector('button'));
  });

  it('shows load errors and aborts outstanding work on unmount', async () => {
    const fetcher = vi.fn().mockResolvedValue({ ok: false, json: async () => ({ message: 'Unable to load goalies.' }) });
    vi.stubGlobal('fetch', fetcher);
    mount(); document.querySelector('button').click(); await settle();
    expect(document.querySelector('[role="alert"]').textContent).toBe('Unable to load goalies.');
    const signal = fetcher.mock.calls[0][1].signal;
    app.unmount(); app = null;
    expect(signal.aborted).toBe(true);
  });

  it.each([[false, false, false], [true, true, false], [true, false, true]])('limits editing to super admins on non-live cards (%s, %s)', async (admin, live, visible) => {
    document.body.innerHTML = '<div id="picker-test"></div>';
    const side = { team_abbrev: 'TOR', score: null, lineup: null, starting_goalie: { name: 'Starter', status: 'expected' }, goalies: [] };
    app = createApp({ render: () => h(GameCard, {
      canManageLineups: admin,
      game: { nhl_game_id: 123, live_mode: live, game_state: live ? 'LIVE' : 'PRE', away: side, home: { ...side, team_abbrev: 'MTL' } },
    }) });
    app.mount('#picker-test'); await nextTick();
    expect(Boolean(document.querySelector('[aria-label="Choose TOR starting goalie"]'))).toBe(visible);
  });
});
