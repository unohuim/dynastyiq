const TYPE_BUTTONS = new Set(['F', 'D', 'G']);

const normalizePositionValue = (value) => {
  const normalized = String(value ?? '').trim().toUpperCase();

  if (normalized === 'LW') return 'L';
  if (normalized === 'RW') return 'R';
  if (['LD', 'RD', 'DEF', 'DEFENSE', 'DEFENCEMAN', 'DEFENSEMAN'].includes(normalized)) return 'D';

  return normalized;
};

const positionTokens = (...values) => {
  const tokens = new Set();

  values.forEach((value) => {
    String(value ?? '')
      .split(/[,\s/|;]+/)
      .map(normalizePositionValue)
      .filter(Boolean)
      .forEach((token) => tokens.add(token));
  });

  return tokens;
};

export const isStatsTypeButton = (value) => TYPE_BUTTONS.has(String(value));

export class StatsFilterState {
  constructor(state) {
    this.state = state;
  }

  syncNumericFiltersFromPayload(payload, schemaAdapter, force = false) {
    const applied = payload.meta?.appliedFilters || {};
    const next = { ...(force ? {} : this.state.numericFilters) };

    schemaAdapter.numericFilterSpecs().forEach((spec) => {
      const key = String(spec.key);
      const bounds = spec.bounds || {};
      const appliedValue = applied[key] && typeof applied[key] === 'object' ? applied[key] : {};
      const current = force ? {} : (next[key] || {});

      next[key] = {
        min: current.min ?? appliedValue.min ?? bounds.min ?? 0,
        max: current.max ?? appliedValue.max ?? bounds.max ?? 0,
      };
    });

    this.state.numericFilters = next;

    if (force) {
      this.state.dirtyNumericFilters = {};
    }
  }

  setNumericFilterBound(key, bound, value) {
    const current = this.state.numericFilters[key] || {};
    const next = Number(value);
    const min = bound === 'min' ? next : Number(current.min ?? next);
    const max = bound === 'max' ? next : Number(current.max ?? next);

    this.state.numericFilters[key] = {
      min: Math.min(min, max),
      max: Math.max(min, max),
    };
    this.state.dirtyNumericFilters[key] = true;
  }

  reset(payload, schemaAdapter) {
    this.state.selectedPos = [];
    this.state.selectedPosTypes = [];
    this.state.selectedLeagues = [];
    this.state.dirtyNumericFilters = {};
    this.syncNumericFiltersFromPayload(payload, schemaAdapter, true);
  }

  togglePosition(value) {
    const normalized = String(value);

    if (isStatsTypeButton(normalized)) {
      const current = new Set(this.state.selectedPosTypes);
      if (current.has(normalized)) current.delete(normalized);
      else current.add(normalized);

      if (normalized === 'G' && current.has('G')) {
        this.state.selectedPosTypes = ['G'];
        this.state.selectedPos = ['G'];
      } else {
        current.delete('G');
        this.state.selectedPosTypes = [...current];
        this.state.selectedPos = this.state.selectedPos.filter((item) => item !== 'G');
        if (current.has('D')) this.state.selectedPos = [];
      }

      return;
    }

    const current = new Set(this.state.selectedPos);
    if (current.has(normalized)) current.delete(normalized);
    else current.add(normalized);

    this.state.selectedPos = [...current].filter((item) => item !== 'G');
    this.state.selectedPosTypes = this.state.selectedPosTypes.filter((item) => item !== 'G' && item !== 'D');
  }

  isPositionActive(value) {
    const normalized = String(value);
    return isStatsTypeButton(normalized)
      ? this.state.selectedPosTypes.includes(normalized)
      : this.state.selectedPos.includes(normalized);
  }

  filterRows(rows) {
    const selectedTypes = new Set(this.state.selectedPosTypes.map(normalizePositionValue));
    const selectedPositions = new Set(this.state.selectedPos.map(normalizePositionValue));

    if (selectedTypes.size === 0 && selectedPositions.size === 0) {
      return rows;
    }

    return rows.filter((row) => {
      const rowType = normalizePositionValue(row?.pos_type ?? row?.type);
      const rowPosition = normalizePositionValue(row?.pos ?? row?.position ?? rowType);
      const rowPositions = positionTokens(row?.pos, row?.position, row?.pos_type, row?.type);
      const isGoalie = row?.is_goalie === true || row?.is_goalie === 1 || row?.is_goalie === '1';
      const typeMatch = [...selectedTypes].some((type) => rowPositions.has(type));
      const positionMatch = [...selectedPositions].some((position) => rowPositions.has(position));

      return selectedTypes.has(rowType)
        || selectedTypes.has(rowPosition)
        || selectedPositions.has(rowPosition)
        || typeMatch
        || positionMatch
        || (isGoalie && (selectedTypes.has('G') || selectedPositions.has('G')));
    });
  }
}
