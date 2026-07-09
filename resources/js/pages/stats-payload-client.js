const IDENTITY_KEYS = new Set([
  'name',
  'player',
  'team',
  'league',
  'pos',
  'pos_type',
  'age',
  'contract_value',
  'contract_value_num',
  'contract_last_year',
  'contract_last_year_num',
  'avatar_url',
  'head_shot_url',
  'id',
  'nhl_player_id',
  'gp',
]);

export const statsIdentityKeys = IDENTITY_KEYS;

export const normalizeStatsPayload = (payload = {}) => {
  const headings = Array.isArray(payload.headings) ? payload.headings : [];
  const data = Array.isArray(payload.data) ? payload.data : [];
  const statKeys = headings
    .map((heading) => heading?.key)
    .filter((key) => key && !IDENTITY_KEYS.has(String(key)));

  return {
    ...payload,
    headings,
    data: data.map((row) => {
      if (row?.stats && typeof row.stats === 'object') return row;

      const stats = {};
      statKeys.forEach((key) => {
        if (row?.[key] !== undefined) stats[key] = row[key];
      });

      return { ...row, stats };
    }),
    settings: payload.settings || {},
    meta: payload.meta || {},
  };
};

export class StatsPayloadClient {
  constructor({ apiUrl, fetcher = (window.fetch ? window.fetch.bind(window) : null) } = {}) {
    this.apiUrl = apiUrl;
    this.fetcher = fetcher;
    this.requestSeq = 0;
    this.payloadCache = new Map();
  }

  buildParams(state, { canSlice = true, supportsDateRange = true } = {}) {
    const params = new URLSearchParams();
    const period = supportsDateRange ? state.period : 'season';

    params.set('perspective', state.perspective);
    params.set('resource', 'players');
    params.set('period', period);
    params.set('slice', canSlice ? state.slice : 'total');

    if (period === 'season' && state.seasonId) {
      params.set('season_id', state.seasonId);
    }

    params.set('game_type', state.gameType);
    params.set('availability', '0');
    if (state.selectedPosTypes.includes('G') || state.selectedPos.includes('G')) {
      params.set('column_group', 'goalie');
    }

    state.selectedLeagues.forEach((value) => params.append('league[]', value));
    Object.entries(state.numericFilters || {}).forEach(([key, value]) => {
      if (!state.dirtyNumericFilters?.[key]) return;

      if (value?.min !== undefined && value.min !== null && value.min !== '') {
        params.set(`${key}_min`, String(value.min));
      }
      if (value?.max !== undefined && value.max !== null && value.max !== '') {
        params.set(`${key}_max`, String(value.max));
      }
    });

    return params;
  }

  cacheKeyFromParams(params) {
    return params.toString();
  }

  cachedPayload(params) {
    return this.payloadCache.get(this.cacheKeyFromParams(params));
  }

  hasCachedPayload(params) {
    return this.payloadCache.has(this.cacheKeyFromParams(params));
  }

  cachePayload(params, payload) {
    this.payloadCache.set(this.cacheKeyFromParams(params), payload);
  }

  async fetchPayload(params, { force = false } = {}) {
    if (!this.apiUrl || !this.fetcher) {
      return { stale: false, fromCache: false, payload: null, params };
    }

    if (!force && this.hasCachedPayload(params)) {
      return {
        stale: false,
        fromCache: true,
        payload: this.cachedPayload(params),
        params,
      };
    }

    const requestId = this.requestSeq + 1;
    this.requestSeq = requestId;

    const response = await this.fetcher(`${this.apiUrl}?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (requestId !== this.requestSeq) {
      return { stale: true, fromCache: false, payload: null, params };
    }

    if (!response.ok) {
      throw new Error(`Stats request failed (${response.status})`);
    }

    const payload = normalizeStatsPayload(await response.json());
    this.cachePayload(params, payload);

    return {
      stale: false,
      fromCache: false,
      payload,
      params,
    };
  }
}
