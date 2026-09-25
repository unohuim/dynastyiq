/* @vitest-environment jsdom */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import Index from './Index.vue';

describe('live games refresh', () => {
  let app;
  const settle = async () => { for (let i = 0; i < 12; i += 1) await Promise.resolve(); await nextTick(); };
  afterEach(() => { app?.unmount(); vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

  it('replaces pregame data with live NHL players and stops polling on unmount', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-24T23:00:00Z'));
    const side = { team_abbrev: 'TOR', score: null, lineup: null, starting_goalie: { name: 'Old Expected', status: 'expected' } };
    const game = { nhl_game_id: 123, game_state: 'PRE', start_time_utc: '2026-09-24T23:00:00Z', away: side, home: { ...side, team_abbrev: 'MTL' } };
    const liveSide = { ...side, score: 0, sog: 1, starting_goalie: null,
      goalies: [{ nhl_player_id: 2, name: 'NHL Starter', is_starter: true, saves: 1, goals_against: 0 }],
      lineup: { evidence_status: 'official', players: [{ nhl_player_id: 3, player_name: 'NHL Forward', lineup_role: 'forward', sweater_number: 10 }] },
    };
    const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({
      meta: { date: '2026-09-24' }, games: [{ ...game, game_state: 'LIVE', live_mode: true, away: liveSide, home: { ...liveSide, team_abbrev: 'MTL' } }],
    }) });
    vi.stubGlobal('fetch', fetcher);
    document.body.innerHTML = '<div id="games-test"></div>';
    app = createApp({ render: () => h(Index, { initialPayload: { games: [game], meta: { date: '2026-09-24' } }, payloadUrl: '/games/payload' }) });
    app.mount('#games-test');
    expect(document.body.textContent).toContain('Old Expected');
    await vi.advanceTimersByTimeAsync(30000); await settle();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(document.body.textContent).not.toContain('Old Expected');
    expect(document.body.textContent).toContain('NHL Starter');
    expect(document.body.textContent).toContain('NHL Forward');
    app.unmount(); app = null;
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
