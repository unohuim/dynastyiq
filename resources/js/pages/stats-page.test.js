/* @vitest-environment jsdom */

import { beforeEach, describe, expect, it, vi } from 'vitest';

const loadStatsPage = async () => {
  vi.resetModules();
  document.body.innerHTML = '';
  delete window.__statsPageConfig;

  return import('./stats-page.js');
};

const setViewport = (width) => {
  Object.defineProperty(window, 'innerWidth', {
    configurable: true,
    value: width,
  });
};

const payload = (meta = {}, settings = {}) => ({
  headings: [
    { key: 'name', label: 'Player' },
    { key: 'team', label: 'Team' },
    { key: 'league', label: 'League' },
    { key: 'pos_type', label: 'Type' },
    { key: 'gp', label: 'GP' },
  ],
  data: [
    {
      name: 'Test Prospect',
      avatar_url: 'https://example.test/test-prospect.png',
      team: 'CHI',
      league: 'WHL',
      pos: 'L',
      pos_type: 'F',
      gp: 22,
      stats: { gp: 22 },
    },
  ],
  settings: {
    slice: 'total',
    ...settings,
  },
  meta: {
    availableSeasons: [20252026, 20242025],
    availableGameTypes: [2],
    season: 20252026,
    game_type: 2,
    canSlice: false,
    supportsDateRange: false,
    positionButtons: ['F', 'C', 'LW', 'RW', 'D'],
    availableLeagues: [],
    ...meta,
  },
});

const createShell = async ({ meta = {}, settings = {}, mobile = false, config = {} } = {}) => {
  setViewport(mobile ? 390 : 1280);
  const { StatsPageShell } = await loadStatsPage();
  const container = document.createElement('div');
  document.body.appendChild(container);

  return new StatsPageShell(container, {
    apiUrl: '/api/stats',
    selectedPerspective: 'prospects',
    perspectives: [
      { slug: 'prospects', name: 'Prospects' },
      { slug: 'prospects-goalies', name: 'Prospects - Goalies' },
    ],
    initialPayload: payload(meta, settings),
    ...config,
  });
};

describe('stats page prospect controls', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/stats');
  });

  it('treats prospect payloads as unsupported for date ranges', async () => {
    const shell = await createShell();

    expect(shell.supportsDateRange()).toBe(false);
  });

  it('falls back to skaters when the selected perspective is missing from server config', async () => {
    const shell = await createShell({
      config: {
        selectedPerspective: undefined,
        perspectives: [
          { slug: 'prospects', name: 'Prospects' },
          { slug: 'skaters', name: 'Skaters' },
        ],
      },
    });

    expect(shell.state.perspective).toBe('skaters');
  });

  it('defaults to supporting date ranges when metadata is absent', async () => {
    const shell = await createShell({ meta: { supportsDateRange: undefined } });

    expect(shell.supportsDateRange()).toBe(true);
  });

  it('forces params to season mode when date ranges are unsupported', async () => {
    const shell = await createShell();
    shell.state.period = 'range';

    expect(shell.buildParams().get('period')).toBe('season');
  });

  it('keeps the selected season in params when season mode is forced', async () => {
    const shell = await createShell();
    shell.state.period = 'range';
    shell.state.seasonId = '20242025';

    expect(shell.buildParams().get('season_id')).toBe('20242025');
  });

  it('forces total slice in params when slicing is disabled', async () => {
    const shell = await createShell();
    shell.state.slice = 'p60';

    expect(shell.buildParams().get('slice')).toBe('total');
  });

  it('keeps requested slice in params when slicing is enabled', async () => {
    const shell = await createShell({ meta: { canSlice: true }, settings: { slice: 'p60' } });
    shell.state.slice = 'p60';

    expect(shell.buildParams().get('slice')).toBe('p60');
  });

  it('resets range state to season when syncing an unsupported payload', async () => {
    const shell = await createShell();
    shell.state.period = 'range';

    shell.syncStateFromPayload();

    expect(shell.state.period).toBe('season');
  });

  it('preserves range state when syncing a supported payload', async () => {
    const shell = await createShell({ meta: { supportsDateRange: true } });
    shell.state.period = 'range';

    shell.syncStateFromPayload();

    expect(shell.state.period).toBe('range');
  });

  it('renders no mobile range option for prospect payloads', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderControls();

    expect(document.body.textContent).not.toContain('Range');
  });

  it('renders the top mobile controls filter button', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderControls();

    expect(document.body.querySelector('button[aria-label="Stats filters"]')).not.toBeNull();
  });

  it('opens the mobile filter drawer from the top filters button', async () => {
    const shell = await createShell({
      mobile: true,
      meta: {
        filterSchema: [
          { key: 'gp', label: 'GP', type: 'number', bounds: { min: 0, max: 82 }, step: 1 },
        ],
      },
    });

    shell.render();

    const button = document.body.querySelector('button[aria-label="Stats filters"]');
    button?.click();

    const drawer = document.body.querySelector('#mobile-stats-filter-drawer');

    expect(drawer).not.toBeNull();
    expect(drawer?.className).toContain('translate-x-0');
    expect(drawer?.querySelector('.dual-slider')).not.toBeNull();
    expect(document.body.style.overflow).toBe('hidden');
  });

  it('does not render a searchbar-adjacent filter button', async () => {
    const shell = await createShell({ mobile: true });

    shell.render();

    expect(document.querySelector('#searchbar-mobile button[aria-label="Stats filters"]')).toBeNull();
  });

  it('opens the sort drawer from the visible mobile sort button', async () => {
    const shell = await createShell({ mobile: true });

    shell.render();

    document.querySelector('#searchbar-mobile button[aria-label="Sort stats"]')?.click();

    const filterDrawer = document.body.querySelector('#mobile-stats-filter-drawer');
    const sortSheet = document.body.querySelector('#mobile-sort-sheet');

    expect(sortSheet?.className).toContain('translate-y-0');
    expect(filterDrawer?.className).not.toContain('translate-x-0');
  });

  it('renders the mobile range option when supported', async () => {
    const shell = await createShell({ meta: { supportsDateRange: true }, mobile: true });

    shell.renderControls();

    expect(document.body.textContent).toContain('Range');
  });

  it('renders no desktop range option for prospect payloads', async () => {
    const shell = await createShell();

    shell.renderControls();

    expect(document.body.textContent).not.toContain('Range');
  });

  it('renders the desktop range option when supported', async () => {
    const shell = await createShell({ meta: { supportsDateRange: true } });

    shell.renderControls();

    expect(document.body.textContent).toContain('Range');
  });

  it('renders no mobile per-game slice option when slicing is disabled', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderControls();

    expect(document.body.textContent).not.toContain('P/GP');
  });

  it('renders no desktop per-game slice option when slicing is disabled', async () => {
    const shell = await createShell();

    shell.renderControls();

    expect(document.body.textContent).not.toContain('P/GP');
  });

  it('renders mobile slice options when slicing is enabled', async () => {
    const shell = await createShell({ meta: { canSlice: true }, mobile: true });

    shell.renderControls();

    expect(document.body.textContent).toContain('P/GP');
  });

  it('renders desktop slice options when slicing is enabled', async () => {
    const shell = await createShell({ meta: { canSlice: true } });

    shell.renderControls();

    expect(document.body.textContent).toContain('Per 60');
  });

  it('normalizes available seasons to strings', async () => {
    const shell = await createShell();

    expect(shell.availableSeasons()).toEqual(['20252026', '20242025']);
  });

  it('uses prospect game types from the payload', async () => {
    const shell = await createShell();

    expect(shell.availableGameTypes()).toEqual(['2']);
  });

  it('renders skater prospect position buttons from payload metadata', async () => {
    const shell = await createShell();

    shell.renderControls();

    expect(document.body.textContent).toContain('F');
    expect(document.body.textContent).toContain('D');
    expect(document.body.textContent).not.toContain('G');
  });

  it('renders no goalie prospect position buttons when metadata is empty', async () => {
    const shell = await createShell({ meta: { positionButtons: [] } });

    shell.renderControls();

    expect(document.body.querySelectorAll('button')).toHaveLength(0);
  });

  it('clears selected position filters when perspectives change', async () => {
    const shell = await createShell();
    shell.fetchPayload = vi.fn();
    shell.state.selectedPos = ['C'];
    shell.state.selectedPosTypes = ['F'];
    shell.state.selectedLeagues = ['WHL'];

    shell.setPerspective('prospects-goalies');

    expect(shell.state.selectedPos).toEqual([]);
    expect(shell.state.selectedPosTypes).toEqual([]);
    expect(shell.state.selectedLeagues).toEqual([]);
  });

  it('hydrates selected position filters from payload metadata', async () => {
    const shell = await createShell({
      meta: {
        pos: ['C'],
        pos_type: ['F'],
      },
    });

    shell.syncStateFromPayload();

    expect(shell.state.selectedPos).toEqual(['C']);
    expect(shell.state.selectedPosTypes).toEqual(['F']);
  });

  it('tracks center and wing buttons locally without adding request params', async () => {
    const shell = await createShell();
    shell.fetchPayload = vi.fn();

    shell.togglePosition('C');
    shell.togglePosition('LW');
    shell.togglePosition('RW');

    expect(shell.state.selectedPos).toEqual(['C', 'LW', 'RW']);
    expect(shell.fetchPayload).not.toHaveBeenCalled();
    expect(shell.buildParams().getAll('pos[]')).toEqual([]);
    expect(shell.buildParams().getAll('pos_type[]')).toEqual([]);
  });

  it('tracks forward button locally without adding request params', async () => {
    const shell = await createShell();
    shell.fetchPayload = vi.fn();

    shell.togglePosition('F');

    expect(shell.state.selectedPosTypes).toEqual(['F']);
    expect(shell.fetchPayload).not.toHaveBeenCalled();
    expect(shell.buildParams().getAll('pos[]')).toEqual([]);
    expect(shell.buildParams().getAll('pos_type[]')).toEqual([]);
  });

  it('applies a center filter while leaving goalie mode', async () => {
    const shell = await createShell({
      meta: { positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] },
      config: { syncUrl: false },
    });
    shell.payloadClient.fetchPayload = vi.fn().mockResolvedValue({
      stale: false,
      payload: payload({ positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] }),
    });

    shell.togglePosition('G');
    await Promise.resolve();
    await Promise.resolve();
    shell.togglePosition('C');
    await Promise.resolve();
    await Promise.resolve();

    expect(shell.state.selectedPos).toEqual(['C']);
    expect(shell.state.selectedPosTypes).toEqual([]);
    expect(shell.payloadClient.fetchPayload).toHaveBeenCalledTimes(2);
  });

  it('uses cached skater headings immediately when leaving goalie mode', async () => {
    const shell = await createShell({
      meta: { positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] },
      settings: {
        activeColumnGroup: 'skater',
        columnGroups: {
          skater: [{ key: 'g', label: 'G' }, { key: 'a', label: 'A' }],
          goalie: [{ key: 'sv', label: 'SV' }, { key: 'gaa', label: 'GAA' }],
        },
      },
      config: { syncUrl: false },
    });

    shell.rememberSkaterHeadings();
    shell.state.selectedPosTypes = ['G'];
    shell.state.selectedPos = ['G'];
    shell.settings.activeColumnGroup = 'goalie';
    shell.payload.headings = [
      { key: 'name', label: 'Player' },
      { key: 'team', label: 'Team' },
      { key: 'pos_type', label: 'Type' },
      { key: 'sv', label: 'SV' },
      { key: 'gaa', label: 'GAA' },
    ];
    shell.payloadClient.fetchPayload = vi.fn().mockResolvedValue({
      stale: false,
      payload: payload({ positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] }),
    });

    shell.togglePosition('C');

    expect(shell.activeHeadings().map((heading) => heading.key)).toEqual(['name', 'team', 'league', 'pos_type', 'gp', 'g', 'a']);
  });

  it('applies a forward type filter while leaving goalie mode', async () => {
    const shell = await createShell({
      meta: { positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] },
      config: { syncUrl: false },
    });
    shell.payloadClient.fetchPayload = vi.fn().mockResolvedValue({
      stale: false,
      payload: payload({ positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] }),
    });

    shell.togglePosition('G');
    await Promise.resolve();
    await Promise.resolve();
    shell.togglePosition('F');
    await Promise.resolve();
    await Promise.resolve();

    expect(shell.state.selectedPos).toEqual([]);
    expect(shell.state.selectedPosTypes).toEqual(['F']);
    expect(shell.payloadClient.fetchPayload).toHaveBeenCalledTimes(2);
  });

  it('applies a defense type filter while leaving goalie mode', async () => {
    const shell = await createShell({
      meta: { positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] },
      config: { syncUrl: false },
    });
    shell.payloadClient.fetchPayload = vi.fn().mockResolvedValue({
      stale: false,
      payload: payload({ positionButtons: ['F', 'C', 'LW', 'RW', 'D', 'G'] }),
    });

    shell.togglePosition('G');
    await Promise.resolve();
    await Promise.resolve();
    shell.togglePosition('D');
    await Promise.resolve();
    await Promise.resolve();

    expect(shell.state.selectedPos).toEqual([]);
    expect(shell.state.selectedPosTypes).toEqual(['D']);
    expect(shell.payloadClient.fetchPayload).toHaveBeenCalledTimes(2);
  });

  it('filters the already-loaded rows by selected position buttons', async () => {
    const shell = await createShell();
    shell.fetchPayload = vi.fn();
    shell.payload.data = [
      { name: 'Center Prospect', team: 'CHI', league: 'WHL', pos: 'C', pos_type: 'F', gp: 22, stats: { gp: 22 } },
      { name: 'Left Prospect', team: 'CHI', league: 'WHL', pos: 'L', pos_type: 'F', gp: 20, stats: { gp: 20 } },
      { name: 'Defense Prospect', team: 'CHI', league: 'WHL', pos: 'D', pos_type: 'D', gp: 18, stats: { gp: 18 } },
    ];

    shell.togglePosition('C');

    expect(document.body.textContent).toContain('Center Prospect');
    expect(document.body.textContent).not.toContain('Left Prospect');
    expect(document.body.textContent).not.toContain('Defense Prospect');
    expect(shell.fetchPayload).not.toHaveBeenCalled();

    shell.togglePosition('C');
    shell.togglePosition('F');

    expect(document.body.textContent).toContain('Center Prospect');
    expect(document.body.textContent).toContain('Left Prospect');
    expect(document.body.textContent).not.toContain('Defense Prospect');
    expect(shell.fetchPayload).not.toHaveBeenCalled();
  });

  it('normalizes available leagues to strings', async () => {
    const shell = await createShell({ meta: { availableLeagues: ['WHL', 123] } });

    expect(shell.availableLeagues()).toEqual(['WHL', '123']);
  });

  it('includes selected league filters in params', async () => {
    const shell = await createShell({ meta: { availableLeagues: ['WHL', 'AHL'] } });
    shell.state.selectedLeagues = ['WHL'];

    expect(shell.buildParams().getAll('league[]')).toEqual(['WHL']);
  });

  it('does not include untouched numeric slider defaults in params', async () => {
    const shell = await createShell({
      meta: {
        filterSchema: [
          { key: 'gp', label: 'GP', type: 'number', bounds: { min: 0, max: 80 }, step: 1 },
        ],
      },
    });

    expect(shell.state.numericFilters.gp).toEqual({ min: 0, max: 80 });
    expect(shell.buildParams().has('gp_min')).toBe(false);
    expect(shell.buildParams().has('gp_max')).toBe(false);
  });

  it('includes numeric slider params after the user changes them', async () => {
    const shell = await createShell({
      meta: {
        filterSchema: [
          { key: 'gp', label: 'GP', type: 'number', bounds: { min: 0, max: 80 }, step: 1 },
        ],
      },
    });

    shell.setNumericFilterBound('gp', 'min', 12);

    expect(shell.buildParams().get('gp_min')).toBe('12');
    expect(shell.buildParams().get('gp_max')).toBe('80');
  });

  it('clears all filters when switching perspectives', async () => {
    const shell = await createShell({
      meta: {
        availableLeagues: ['WHL'],
        filterSchema: [
          { key: 'gp', label: 'GP', type: 'number', bounds: { min: 0, max: 80 }, step: 1 },
        ],
      },
    });
    shell.fetchPayload = vi.fn();
    shell.state.selectedPos = ['C'];
    shell.state.selectedPosTypes = ['F'];
    shell.state.selectedLeagues = ['WHL'];
    shell.setNumericFilterBound('gp', 'min', 12);

    shell.setPerspective('prospects-goalies');

    expect(shell.state.selectedPos).toEqual([]);
    expect(shell.state.selectedPosTypes).toEqual([]);
    expect(shell.state.selectedLeagues).toEqual([]);
    expect(shell.state.numericFilters).toEqual({});
    expect(shell.state.dirtyNumericFilters).toEqual({});
    expect(shell.buildParams().has('league[]')).toBe(false);
    expect(shell.buildParams().has('gp_min')).toBe(false);
    expect(shell.fetchPayload).toHaveBeenCalledTimes(1);
  });

  it('renders desktop league selector only when league options exist', async () => {
    const shell = await createShell({ meta: { availableLeagues: ['WHL', 'AHL'] } });

    shell.renderControls();

    expect(document.body.textContent).toContain('All Leagues');
  });

  it('does not render desktop league selector when league options are absent', async () => {
    const shell = await createShell();

    shell.renderControls();

    expect(document.body.textContent).not.toContain('All Leagues');
  });

  it('renders mobile league selector when league options exist', async () => {
    const shell = await createShell({ meta: { availableLeagues: ['WHL'] }, mobile: true });

    shell.renderControls();

    expect(document.body.textContent).toContain('All Leagues');
  });

  it('sets selected league and fetches a new payload', async () => {
    const shell = await createShell({ meta: { availableLeagues: ['WHL'] } });
    shell.fetchPayload = vi.fn();

    shell.setLeague('WHL');

    expect(shell.state.selectedLeagues).toEqual(['WHL']);
    expect(shell.fetchPayload).toHaveBeenCalledTimes(1);
  });

  it('renders mobile position as a compact square marker instead of the old text tag', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    expect(document.body.querySelector('.player-stats-pos-tag-mobile')).toBeNull();
    expect(document.body.querySelector('span.inline-flex span.border')).not.toBeNull();
    expect(document.body.querySelector('span.inline-flex svg')).toBeNull();
  });

  it('renders mobile position from canonical player position instead of broad position type', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    expect(document.body.textContent).toContain('L');
    expect(document.body.textContent).not.toContain('FTest Prospect');
  });

  it('renders the player avatar in mobile player identity', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    expect(document.body.querySelector('img[src="https://example.test/test-prospect.png"]')).not.toBeNull();
  });

  it('uses an aligned mobile player identity layout', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    expect(document.body.querySelector('.player-stats-identity-mobile')).not.toBeNull();
    expect(document.body.querySelector('.player-stats-icon-rail-mobile')).not.toBeNull();
    expect(document.body.querySelector('.player-stats-name-line-mobile')).not.toBeNull();
    expect(document.body.querySelector('.player-stats-detail-line-mobile')).not.toBeNull();
    expect(document.body.querySelector('.player-stats-name-stack-mobile')).not.toBeNull();
    expect(document.body.querySelector('.player-stats-meta-mobile')).not.toBeNull();
  });

  it('renders the league under the player name in mobile player identity', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    const league = document.body.querySelector('.player-stats-league-mobile');

    expect(league?.textContent).toBe('WHL');
    expect(league?.className).toContain('text-gray-400');
  });

  it('uses the phone-only mobile viewport boundary', async () => {
    const shell = await createShell({
      mobile: true,
      config: { mobileBreakpoint: 640 },
    });

    setViewport(639);
    expect(shell.isMobile()).toBe(true);

    setViewport(640);
    expect(shell.isMobile()).toBe(false);
  });

  it('renders mobile team strip as NHL owner team without appending league', async () => {
    const shell = await createShell({ mobile: true });

    shell.renderContent();

    expect(document.body.querySelector('.player-stats-team-text-mobile')?.textContent).toBe('CHI');
  });

  it('renders desktop forward position marker with the square dimensions', async () => {
    const shell = await createShell();

    shell.renderContent();

    const hasForwardShape = Array.from(document.body.querySelectorAll('div'))
      .some((node) => node.className.includes('h-5') && node.className.includes('w-5'));

    expect(hasForwardShape).toBe(true);
  });

  it('uses only the first canonical position for desktop position display', async () => {
    const shell = await createShell();
    shell.payload.data[0].pos = 'R/LW';

    shell.renderContent();

    expect(document.body.textContent).toContain('R');
    expect(document.body.textContent).not.toContain('LW');
    expect(document.body.querySelector('svg polygon')).toBeNull();
  });

  it('renders the player avatar in the desktop player column', async () => {
    const shell = await createShell();

    shell.renderContent();

    expect(document.body.querySelector('img[src="https://example.test/test-prospect.png"]')).not.toBeNull();
  });

  it('sorts displayed toi by numeric toi seconds instead of the formatted label', async () => {
    const shell = await createShell({
      settings: {
        defaultSort: 'toi',
        sortKey: 'toi',
      },
    });
    shell.payload.headings = [
      { key: 'name', label: 'Player' },
      { key: 'team', label: 'Team' },
      { key: 'pos_type', label: 'Type' },
      { key: 'toi', label: 'TOI' },
    ];
    shell.payload.data = [
      {
        name: 'Nine Minute Player',
        team: 'CHI',
        pos_type: 'F',
        toi: '9:00',
        toi_seconds: 540,
      },
      {
        name: 'Twenty Minute Player',
        team: 'CHI',
        pos_type: 'F',
        toi: '20:00',
        toi_seconds: 1200,
      },
    ];

    shell.renderContent();

    expect(document.body.textContent.indexOf('Twenty Minute Player'))
      .toBeLessThan(document.body.textContent.indexOf('Nine Minute Player'));
  });

  it('formats desktop stat numbers with thousands separators and preserves decimals', async () => {
    const shell = await createShell({
      settings: {
        defaultSort: 'sog',
        sortKey: 'sog',
      },
    });
    shell.payload.headings = [
      { key: 'name', label: 'Player' },
      { key: 'team', label: 'Team' },
      { key: 'league', label: 'League' },
      { key: 'pos_type', label: 'Type' },
      { key: 'sog', label: 'SOG' },
      { key: 'g_per_gp', label: 'G/gp' },
      { key: 'ipp', label: 'IPP' },
      { key: 'sv_pct', label: 'SV%' },
    ];
    shell.payload.data = [
      {
        name: 'Formatted Player',
        team: 'CHI',
        league: 'ABCDEFGHIJK',
        pos_type: 'F',
        sog: 12345,
        g_per_gp: 1.5555,
        ipp: 0.75678,
        sv_pct: 0.917,
      },
    ];

    shell.renderContent();

    expect(document.body.textContent).toContain('12,345');
    expect(document.body.textContent).toContain('ABCDEFGH');
    expect(document.body.textContent).not.toContain('ABCDEFGHI');
    expect(document.body.textContent).toContain('1.5');
    expect(document.body.textContent).not.toContain('1.6');
    expect(document.body.textContent).toContain('75.68%');
    expect(document.body.textContent).toContain('0.917');
  });

  it('lets league column sorting override yahoo slot order until slot is selected again', async () => {
    const shell = await createShell({
      settings: {
        ownerColumn: true,
        leaguePlatform: 'yahoo',
        defaultSort: 'gp',
        sortKey: 'gp',
        sortDirection: 'desc',
      },
    });
    shell.payload.data = [
      {
        name: 'High GP Player',
        team: 'NYR',
        league: 'NHL',
        pos_type: 'F',
        gp: 80,
        stats: { gp: 80 },
        fantasy_team_name: 'Alpha Team',
        fantasy_team_avatar_url: 'https://example.test/alpha.png',
        roster_slot: 'LW',
        roster_sort_order: 1,
        roster_group_sort_order: 0,
        roster_status_sort_order: 0,
      },
      {
        name: 'Slot First Player',
        team: 'NYR',
        league: 'NHL',
        pos_type: 'F',
        gp: 10,
        stats: { gp: 10 },
        fantasy_team_name: 'Alpha Team',
        fantasy_team_avatar_url: 'https://example.test/alpha.png',
        roster_slot: 'C',
        roster_sort_order: 2,
        roster_group_sort_order: 0,
        roster_status_sort_order: 0,
      },
    ];

    shell.renderContent();
    Array.from(document.body.querySelectorAll('button'))
      .find((button) => button.textContent.includes('All Players'))
      ?.click();
    Array.from(document.body.querySelectorAll('button'))
      .find((button) => button.textContent.includes('Alpha Team'))
      ?.click();

    expect(document.body.textContent.indexOf('High GP Player'))
      .toBeLessThan(document.body.textContent.indexOf('Slot First Player'));

    Array.from(document.body.querySelectorAll('div'))
      .find((node) => node.textContent.trim() === 'Player')
      ?.click();

    expect(document.body.textContent.indexOf('Slot First Player'))
      .toBeLessThan(document.body.textContent.indexOf('High GP Player'));

    Array.from(document.body.querySelectorAll('div'))
      .find((node) => node.textContent.trim() === 'Slot')
      ?.click();

    expect(document.body.textContent.indexOf('High GP Player'))
      .toBeLessThan(document.body.textContent.indexOf('Slot First Player'));
  });

  it('defaults league owner stats to the current user fantasy team and labels the global option all players', async () => {
    const shell = await createShell({
      settings: {
        ownerColumn: true,
        leaguePlatform: 'fantrax',
        defaultSort: 'gp',
        sortKey: 'gp',
        columnGroups: {
          skater: [{ key: 'gp', label: 'GP' }],
          goalie: [{ key: 'wins', label: 'W' }],
        },
      },
    });
    shell.payload.data = [
      {
        name: 'Other Team Player',
        team: 'NYR',
        pos_type: 'F',
        gp: 20,
        stats: { gp: 20 },
        fantasy_team_name: 'Other Team',
        fantasy_team_avatar_url: 'https://example.test/other.png',
      },
      {
        name: 'My Team Player',
        team: 'TOR',
        pos_type: 'F',
        gp: 10,
        stats: { gp: 10 },
        fantasy_team_name: 'My Team',
        fantasy_team_avatar_url: 'https://example.test/my.png',
        fantasy_team_is_user_team: true,
      },
    ];

    shell.render();

    expect(document.body.textContent).toContain('My Team');
    expect(document.body.textContent).toContain('All Players');
    expect(document.body.textContent).not.toContain('All Teams');
    expect(document.body.textContent).toContain('My Team Player');
    expect(document.body.textContent).not.toContain('Other Team Player');
  });

  it('auto-selects forward and defense filters for non-team league selections', async () => {
    const shell = await createShell({
      meta: {
        positionButtons: ['F', 'D', 'G'],
      },
      settings: {
        ownerColumn: true,
        leaguePlatform: 'fantrax',
        defaultSort: 'gp',
        sortKey: 'gp',
        columnGroups: {
          skater: [{ key: 'gp', label: 'GP' }],
          goalie: [{ key: 'wins', label: 'W' }],
        },
      },
    });
    shell.payload.data = [
      {
        name: 'Visible Skater',
        team: 'TOR',
        pos_type: 'F',
        gp: 10,
        stats: { gp: 10 },
      },
      {
        name: 'Hidden Goalie',
        team: 'TOR',
        pos_type: 'G',
        is_goalie: true,
        wins: 5,
        stats: { wins: 5 },
      },
    ];

    shell.render();

    expect(shell.state.selectedPosTypes).toEqual(['F', 'D']);
    expect(document.body.textContent).toContain('Visible Skater');
    expect(document.body.textContent).not.toContain('Hidden Goalie');
  });

  it('orders selected fantasy team rosters with a visible goalie stat header', async () => {
    const shell = await createShell({
      settings: {
        ownerColumn: true,
        leaguePlatform: 'fantrax',
        defaultSort: 'gp',
        sortKey: 'gp',
        columnGroups: {
          skater: [{ key: 'gp', label: 'GP' }],
          goalie: [{ key: 'fantasy_pts_pg', label: 'FP/G' }, { key: 'wins', label: 'W' }],
        },
      },
    });
    shell.payload.headings = [
      { key: 'name', label: 'Player' },
      { key: 'team', label: 'Team' },
      { key: 'league', label: 'League' },
      { key: 'pos_type', label: 'Type' },
      { key: 'age', label: 'Age' },
      { key: 'contract_value_num', label: 'AAV' },
      { key: 'contract_last_year', label: 'Term End' },
      { key: 'contract_type', label: 'Contract Type' },
      { key: 'gp', label: 'GP' },
    ];
    shell.payload.data = [
      {
        name: 'Minor Skater',
        team: 'TOR',
        pos_type: 'F',
        age: 21,
        contract_value_num: 1.2,
        contract_last_year: '2027-28',
        contract_type: 'ELC',
        gp: 1,
        stats: { gp: 1 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'MIN',
        roster_group: 'minor',
        roster_sort_order: 40,
        roster_group_sort_order: 1,
        roster_status_sort_order: 10,
      },
      {
        name: 'Active Skater',
        team: 'TOR',
        pos_type: 'F',
        age: 25,
        contract_value_num: 4.5,
        contract_last_year: '2028-29',
        contract_type: 'Standard',
        gp: 20,
        stats: { gp: 20 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'LW',
        roster_group: 'active',
        roster_sort_order: 10,
        roster_group_sort_order: 0,
        roster_status_sort_order: 10,
      },
      {
        name: 'Reserve Goalie',
        team: 'TOR',
        pos_type: 'G',
        is_goalie: true,
        age: 27,
        contract_value_num: 2.1,
        contract_last_year: '2026-27',
        contract_type: 'Bridge',
        games_played: 15,
        fantasy_pts_pg: 3.4,
        wins: 2,
        stats: { gp: 0, fantasy_pts_pg: 0, wins: 2 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'BN',
        roster_status: 'bench',
        roster_group: 'active',
        roster_sort_order: 20,
        roster_group_sort_order: 0,
        roster_status_sort_order: 20,
      },
      {
        name: 'Active Goalie',
        team: 'TOR',
        pos_type: 'G',
        is_goalie: true,
        age: 29,
        contract_value_num: 6.3,
        contract_last_year: '2029-30',
        contract_type: 'Standard',
        games_played: 30,
        fantasy_pts_pg: 7.7,
        wins: 10,
        stats: { gp: 0, fantasy_pts_pg: 0, wins: 10 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'G',
        roster_status: 'active',
        roster_group: 'active',
        roster_sort_order: 10,
        roster_group_sort_order: 0,
        roster_status_sort_order: 10,
      },
      {
        name: 'IR Goalie',
        team: 'TOR',
        pos_type: 'G',
        is_goalie: true,
        age: 31,
        contract_value_num: 3.4,
        contract_last_year: '2025-26',
        contract_type: 'Veteran',
        games_played: 5,
        fantasy_pts_pg: 1.2,
        wins: 1,
        stats: { gp: 0, fantasy_pts_pg: 0, wins: 1 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'IR',
        roster_status: 'ir',
        roster_group: 'active',
        roster_sort_order: 30,
        roster_group_sort_order: 0,
        roster_status_sort_order: 30,
      },
      {
        name: 'Minor Goalie',
        team: 'TOR',
        pos_type: 'G',
        is_goalie: true,
        age: 22,
        contract_value_num: 0.9,
        contract_last_year: '2026-27',
        contract_type: 'ELC',
        games_played: 3,
        fantasy_pts_pg: 0.8,
        wins: 0,
        stats: { gp: 0, fantasy_pts_pg: 0, wins: 0 },
        fantasy_team_name: 'My Team',
        fantasy_team_is_user_team: true,
        roster_slot: 'MIN',
        roster_group: 'minor',
        roster_sort_order: 40,
        roster_group_sort_order: 1,
        roster_status_sort_order: 10,
      },
    ];

    shell.render();

    expect(document.body.textContent).not.toContain('Skaters');
    expect(document.body.textContent).toContain('Goalies');
    expect(document.body.textContent).not.toContain('Minors');
    expect(document.body.textContent).toContain('Age');
    expect(document.body.textContent).toContain('AAV');
    expect(document.body.textContent).toContain('Type');
    expect(document.body.textContent).toContain('Term');
    expect(document.body.textContent).toContain('GP');
    expect(document.body.textContent).toContain('FP/G');
    expect(document.body.textContent).not.toContain('Term End');
    expect(document.body.textContent).not.toContain('Contract Type');
    expect(document.body.textContent).toContain('4.5');
    expect(document.body.textContent).toContain('2028-29');
    expect(document.body.textContent).toContain('Standard');
    expect(document.body.textContent).toContain('6.3');
    expect(document.body.textContent).toContain('2029-30');
    expect(document.body.textContent).toContain('7.7');
    expect(document.body.textContent.indexOf('AAV'))
      .toBeLessThan(document.body.textContent.indexOf('Type'));
    expect(document.body.textContent.indexOf('Type'))
      .toBeLessThan(document.body.textContent.indexOf('Term'));
    expect(document.body.textContent.indexOf('Term'))
      .toBeLessThan(document.body.textContent.indexOf('GP'));
    Array.from(document.body.querySelectorAll('button'))
      .find((button) => button.textContent.trim() === 'Ranks')
      ?.click();
    expect(document.body.querySelector('button[aria-pressed="true"]')?.textContent).toBe('Ranks');
    const exactCellValues = Array.from(document.body.querySelectorAll('div'))
      .map((node) => node.textContent.trim());
    expect(exactCellValues).toContain('20');
    expect(exactCellValues).toContain('30');
    expect(exactCellValues).toContain('7.7');
    const goalieHeader = Array.from(document.body.querySelectorAll('div'))
      .find((node) => node.textContent.trim() === 'Goalies');
    expect(goalieHeader?.className).toContain('bg-gray-100');
    expect(goalieHeader?.className).not.toContain('sticky');
    const ageHeader = Array.from(document.body.querySelectorAll('div'))
      .find((node) => node.textContent.trim() === 'Age');
    expect(ageHeader?.className).not.toContain('select-none');
    expect(document.body.textContent.indexOf('Active Skater'))
      .toBeLessThan(document.body.textContent.indexOf('Minor Skater'));
    expect(document.body.textContent.indexOf('Minor Skater'))
      .toBeLessThan(document.body.textContent.indexOf('Goalies'));
    expect(document.body.textContent.indexOf('Goalies'))
      .toBeLessThan(document.body.textContent.indexOf('Active Goalie'));
    expect(document.body.textContent.indexOf('Active Goalie'))
      .toBeLessThan(document.body.textContent.indexOf('Reserve Goalie'));
    expect(document.body.textContent.indexOf('Reserve Goalie'))
      .toBeLessThan(document.body.textContent.indexOf('IR Goalie'));
    expect(document.body.textContent.indexOf('IR Goalie'))
      .toBeLessThan(document.body.textContent.indexOf('Minor Goalie'));
  });
});
