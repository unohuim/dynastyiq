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
});
