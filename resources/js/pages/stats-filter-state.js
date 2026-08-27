const TYPE_BUTTONS = new Set(['F', 'D', 'G', 'SKT']);

const normalizePositionValue = (value) => {
  const normalized = String(value ?? '').trim().toUpperCase();

  if (normalized === 'LW') return 'L';
  if (normalized === 'RW') return 'R';
  if (normalized === 'W') return 'W';
  if (normalized === 'SKT') return 'SKT';
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
    this.state.selectedDraftYears = [];
    this.state.isDraftYearDropdownOpen = false;
    this.state.dirtyNumericFilters = {};
    this.syncNumericFiltersFromPayload(payload, schemaAdapter, true);
  }

  togglePosition(value) {
    const normalized = String(value);

    if (this.isPositionActive(normalized)) {
      this.state.selectedPos = [];
      this.state.selectedPosTypes = [];

      return;
    }

    if (normalized === 'W') {
      this.state.selectedPos = ['W'];
      this.state.selectedPosTypes = [];
      return;
    }

    if (isStatsTypeButton(normalized)) {
      if (normalized === 'G') {
        this.state.selectedPosTypes = ['G'];
        this.state.selectedPos = ['G'];
      } else if (normalized === 'SKT') {
        this.state.selectedPosTypes = ['SKT'];
        this.state.selectedPos = [];
      } else {
        this.state.selectedPosTypes = [normalized];
        this.state.selectedPos = [];
      }

      return;
    }

    this.state.selectedPos = [normalized];
    this.state.selectedPosTypes = [];
  }

  isPositionActive(value) {
    const normalized = String(value);
    if (normalized === 'SKT') {
      return this.state.selectedPosTypes.includes('SKT')
        || (this.state.selectedPosTypes.includes('F') && this.state.selectedPosTypes.includes('D'));
    }

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
      const positionMatch = [...selectedPositions].some((position) => {
        if (position === 'W') {
          return rowPositions.has('W') || rowPositions.has('L') || rowPositions.has('R') || rowPositions.has('LW') || rowPositions.has('RW');
        }

        return rowPositions.has(position);
      });

      return selectedTypes.has(rowType)
        || selectedTypes.has(rowPosition)
        || (selectedTypes.has('SKT') && (rowType === 'F' || rowType === 'D' || rowPosition === 'F' || rowPosition === 'D'))
        || selectedPositions.has(rowPosition)
        || typeMatch
        || positionMatch
        || (isGoalie && (selectedTypes.has('G') || selectedPositions.has('G')));
    });
  }
}
