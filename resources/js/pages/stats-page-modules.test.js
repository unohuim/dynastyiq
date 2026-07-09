import { describe, expect, it, vi } from 'vitest';

import { StatsColumnGroupAdapter } from './stats-column-group-adapter.js';
import { StatsFilterState } from './stats-filter-state.js';
import { StatsPayloadClient, normalizeStatsPayload, statsIdentityKeys } from './stats-payload-client.js';
import { StatsSchemaAdapter } from './stats-schema-adapter.js';

const payload = (overrides = {}) => ({
  headings: [
    { key: 'name', label: 'Player' },
    { key: 'team', label: 'Team' },
    { key: 'gp', label: 'GP' },
    { key: 'g', label: 'G' },
  ],
  data: [
    { name: 'One Player', team: 'TOR', gp: 10, g: 4 },
  ],
  settings: {},
  meta: {},
  ...overrides,
});

const state = (overrides = {}) => ({
  perspective: 'fantrax-league-7',
  period: 'season',
  slice: 'total',
  seasonId: '20252026',
  gameType: '2',
  selectedPos: [],
  selectedPosTypes: [],
  selectedLeagues: [],
  numericFilters: {},
  dirtyNumericFilters: {},
  ...overrides,
});

describe('stats page payload modules', () => {
  it('normalizes missing headings and data to arrays', () => {
    const normalized = normalizeStatsPayload({});

    expect(normalized.headings).toEqual([]);
    expect(normalized.data).toEqual([]);
  });

  it('builds row stats from non identity heading keys', () => {
    const normalized = normalizeStatsPayload(payload());

    expect(normalized.data[0].stats).toEqual({ g: 4 });
  });

  it('preserves existing row stats objects', () => {
    const normalized = normalizeStatsPayload(payload({
      data: [{ name: 'One Player', g: 4, stats: { custom: 9 } }],
    }));

    expect(normalized.data[0].stats).toEqual({ custom: 9 });
  });

  it('builds default season request params', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state());

    expect(params.get('perspective')).toBe('fantrax-league-7');
    expect(params.get('resource')).toBe('players');
    expect(params.get('period')).toBe('season');
    expect(params.get('season_id')).toBe('20252026');
    expect(params.get('availability')).toBe('0');
  });

  it('forces season params when date ranges are not supported', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      period: 'range',
    }), { supportsDateRange: false });

    expect(params.get('period')).toBe('season');
  });

  it('forces total slice when slicing is disabled', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      slice: 'p60',
    }), { canSlice: false });

    expect(params.get('slice')).toBe('total');
  });

  it('adds goalie column group when goalie type is selected', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      selectedPosTypes: ['G'],
    }));

    expect(params.get('column_group')).toBe('goalie');
  });

  it('adds goalie column group when goalie position is selected', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      selectedPos: ['G'],
    }));

    expect(params.get('column_group')).toBe('goalie');
  });

  it('adds selected league params', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      selectedLeagues: ['OHL', 'AHL'],
    }));

    expect(params.getAll('league[]')).toEqual(['OHL', 'AHL']);
  });

  it('sends only dirty numeric filters', () => {
    const params = new StatsPayloadClient({ apiUrl: '/stats' }).buildParams(state({
      numericFilters: {
        gp: { min: 10, max: 80 },
        g: { min: 1, max: 50 },
      },
      dirtyNumericFilters: {
        gp: true,
      },
    }));

    expect(params.get('gp_min')).toBe('10');
    expect(params.get('gp_max')).toBe('80');
    expect(params.has('g_min')).toBe(false);
  });

  it('returns cached payloads without fetching', async () => {
    const fetcher = vi.fn();
    const client = new StatsPayloadClient({ apiUrl: '/stats', fetcher });
    const params = new URLSearchParams('a=1');
    client.cachePayload(params, { data: [{ name: 'Cached' }] });

    const result = await client.fetchPayload(params);

    expect(result.fromCache).toBe(true);
    expect(result.payload.data[0].name).toBe('Cached');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('fetches and caches normalized payloads', async () => {
    const fetcher = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => payload(),
    });
    const client = new StatsPayloadClient({ apiUrl: '/stats', fetcher });
    const params = new URLSearchParams('a=1');

    const result = await client.fetchPayload(params, { force: true });

    expect(result.fromCache).toBe(false);
    expect(result.payload.data[0].stats).toEqual({ g: 4 });
    expect(client.cachedPayload(params).data[0].stats).toEqual({ g: 4 });
  });

  it('throws on failed stats payload responses', async () => {
    const fetcher = vi.fn().mockResolvedValue({ ok: false, status: 500 });
    const client = new StatsPayloadClient({ apiUrl: '/stats', fetcher });

    await expect(client.fetchPayload(new URLSearchParams('a=1'), { force: true }))
      .rejects
      .toThrow('Stats request failed (500)');
  });

  it('marks older fetch responses stale', async () => {
    let resolveFirst;
    const first = new Promise((resolve) => { resolveFirst = resolve; });
    const second = Promise.resolve({
      ok: true,
      json: async () => payload({ data: [{ name: 'Second', g: 2 }] }),
    });
    const fetcher = vi.fn()
      .mockReturnValueOnce(first)
      .mockReturnValueOnce(second);
    const client = new StatsPayloadClient({ apiUrl: '/stats', fetcher });

    const firstResult = client.fetchPayload(new URLSearchParams('a=1'), { force: true });
    const secondResult = await client.fetchPayload(new URLSearchParams('a=2'), { force: true });
    resolveFirst({
      ok: true,
      json: async () => payload({ data: [{ name: 'First', g: 1 }] }),
    });

    expect(secondResult.stale).toBe(false);
    expect((await firstResult).stale).toBe(true);
  });

  it('syncs numeric filters from payload schema bounds', () => {
    const current = state();
    const filterState = new StatsFilterState(current);
    const schema = new StatsSchemaAdapter(payload({
      meta: {
        filterSchema: [{ key: 'gp', type: 'number', bounds: { min: 0, max: 82 } }],
      },
    }));

    filterState.syncNumericFiltersFromPayload(schema.payload, schema);

    expect(current.numericFilters.gp).toEqual({ min: 0, max: 82 });
  });

  it('resets filter state and clears dirty numeric flags', () => {
    const current = state({
      selectedPos: ['C'],
      selectedPosTypes: ['F'],
      selectedLeagues: ['OHL'],
      dirtyNumericFilters: { gp: true },
      numericFilters: { gp: { min: 10, max: 82 } },
    });
    const filterState = new StatsFilterState(current);
    const schema = new StatsSchemaAdapter(payload({
      meta: {
        filterSchema: [{ key: 'gp', type: 'number', bounds: { min: 0, max: 82 } }],
      },
    }));

    filterState.reset(schema.payload, schema);

    expect(current.selectedPos).toEqual([]);
    expect(current.selectedPosTypes).toEqual([]);
    expect(current.selectedLeagues).toEqual([]);
    expect(current.dirtyNumericFilters).toEqual({});
    expect(current.numericFilters.gp).toEqual({ min: 0, max: 82 });
  });

  it('normalizes numeric filter bounds when min is moved above max', () => {
    const current = state({
      numericFilters: { gp: { min: 0, max: 20 } },
      dirtyNumericFilters: {},
    });

    new StatsFilterState(current).setNumericFilterBound('gp', 'min', 30);

    expect(current.numericFilters.gp).toEqual({ min: 20, max: 30 });
    expect(current.dirtyNumericFilters.gp).toBe(true);
  });

  it('selects goalie position exclusively when goalie type is toggled on', () => {
    const current = state({ selectedPos: ['C'], selectedPosTypes: ['F'] });

    new StatsFilterState(current).togglePosition('G');

    expect(current.selectedPos).toEqual(['G']);
    expect(current.selectedPosTypes).toEqual(['G']);
  });

  it('clears goalie state when a skater type is toggled', () => {
    const current = state({ selectedPos: ['G'], selectedPosTypes: ['G'] });

    new StatsFilterState(current).togglePosition('F');

    expect(current.selectedPos).toEqual([]);
    expect(current.selectedPosTypes).toEqual(['F']);
  });

  it('matches defense rows from defenseman position variants', () => {
    const current = state({ selectedPosTypes: ['D'] });
    const rows = [
      { name: 'Left Defense', pos: 'LD', pos_type: 'D' },
      { name: 'Center', pos: 'C', pos_type: 'F' },
    ];

    expect(new StatsFilterState(current).filterRows(rows).map((row) => row.name)).toEqual(['Left Defense']);
  });

  it('matches goalie rows from goalie flags', () => {
    const current = state({ selectedPosTypes: ['G'] });
    const rows = [
      { name: 'Goalie', pos: null, pos_type: null, is_goalie: true },
      { name: 'Skater', pos: 'C', pos_type: 'F' },
    ];

    expect(new StatsFilterState(current).filterRows(rows).map((row) => row.name)).toEqual(['Goalie']);
  });

  it('reads schema adapter defaults from missing metadata', () => {
    const adapter = new StatsSchemaAdapter(payload());

    expect(adapter.availableSeasons()).toEqual([]);
    expect(adapter.availableGameTypes()).toEqual(['2']);
    expect(adapter.availableLeagues()).toEqual([]);
    expect(adapter.canSlice()).toBe(true);
    expect(adapter.supportsDateRange()).toBe(true);
  });

  it('returns numeric filter specs only for bounded numeric schema entries', () => {
    const adapter = new StatsSchemaAdapter(payload({
      meta: {
        filterSchema: [
          { key: 'gp', type: 'number', bounds: { min: 0, max: 82 } },
          { key: 'team', type: 'enum', options: ['TOR'] },
          { key: 'bad', type: 'number' },
        ],
      },
    }));

    expect(adapter.numericFilterSpecs().map((spec) => spec.key)).toEqual(['gp']);
  });

  it('prefers payload position buttons over settings buttons', () => {
    const adapter = new StatsSchemaAdapter(payload({
      meta: { positionButtons: ['G'] },
    }));

    expect(adapter.positionButtonsFromPayload({ ui: { positionButtons: ['F'] } })).toEqual(['G']);
  });

  it('detects active goalie column group from selected goalie filters', () => {
    const adapter = new StatsColumnGroupAdapter(statsIdentityKeys);
    const settings = { columnGroups: { skater: [], goalie: [] }, activeColumnGroup: 'skater' };

    expect(adapter.activeColumnGroup(settings, state({ selectedPosTypes: ['G'] }))).toBe('goalie');
  });

  it('combines identity headings with active group headings without duplicates', () => {
    const adapter = new StatsColumnGroupAdapter(statsIdentityKeys);
    const result = adapter.activeHeadings(payload(), {
      activeColumnGroup: 'skater',
      columnGroups: {
        skater: [{ key: 'gp', label: 'GP Override' }, { key: 'g', label: 'G' }],
      },
    }, state());

    expect(result.map((heading) => heading.key)).toEqual(['name', 'team', 'gp', 'g']);
  });

  it('syncs sort to the active column group fallback', () => {
    const adapter = new StatsColumnGroupAdapter(statsIdentityKeys);
    const settings = {
      sortKey: 'g',
      defaultSortDirection: 'asc',
      activeColumnGroup: 'skater',
      columnGroups: {
        skater: [{ key: 'pts', label: 'PTS' }],
      },
      columnGroupSort: {
        skater: { sortKey: 'pts', sortDirection: 'desc' },
      },
    };

    adapter.syncSort(settings, payload(), state());

    expect(settings.sortKey).toBe('pts');
    expect(settings.sortDirection).toBe('desc');
    expect(settings.displayKey).toBe('pts');
  });
});
