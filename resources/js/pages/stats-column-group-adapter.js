export class StatsColumnGroupAdapter {
  constructor(identityKeys) {
    this.identityKeys = identityKeys;
  }

  hasColumnGroups(settings) {
    return settings.columnGroups
      && typeof settings.columnGroups === 'object'
      && !Array.isArray(settings.columnGroups);
  }

  activeColumnGroup(settings, state) {
    if (!this.hasColumnGroups(settings)) {
      return null;
    }

    const selectedPosTypes = Array.isArray(state.selectedPosTypes) ? state.selectedPosTypes : [];
    const selectedPos = Array.isArray(state.selectedPos) ? state.selectedPos : [];
    const hasGoalieFilter = selectedPosTypes.includes('G') || selectedPos.includes('G');
    const hasSkaterFilter = [...selectedPosTypes, ...selectedPos].some((value) => String(value) !== 'G');

    if (hasGoalieFilter) {
      return 'goalie';
    }

    if (hasSkaterFilter) {
      return 'skater';
    }

    if (Array.isArray(settings.columnGroups?.all)) {
      return 'all';
    }

    return settings.activeColumnGroup || 'skater';
  }

  activeHeadings(payload, settings, state) {
    if (!this.hasColumnGroups(settings)) {
      return payload.headings;
    }

    const group = this.activeColumnGroup(settings, state);
    const groupColumns = Array.isArray(settings.columnGroups?.[group])
      ? settings.columnGroups[group]
      : [];

    const identityHeadings = payload.headings.filter((heading) => {
      const key = String(heading?.key ?? '');
      return this.identityKeys.has(key);
    });
    const seen = new Set();

    return [...identityHeadings, ...groupColumns]
      .filter((heading) => {
        const key = String(heading?.key ?? '');
        if (!key || seen.has(key)) return false;

        seen.add(key);
        return true;
      });
  }

  syncSort(settings, payload, state) {
    if (!this.hasColumnGroups(settings)) {
      return;
    }

    const headings = this.activeHeadings(payload, settings, state);
    const activeKeys = new Set(headings.map((heading) => String(heading?.key ?? '')).filter(Boolean));

    if (activeKeys.has(String(settings.sortKey ?? ''))) {
      return;
    }

    const group = this.activeColumnGroup(settings, state);
    const groupSort = settings.columnGroupSort?.[group] || {};
    const fallbackKey = groupSort.sortKey
      || settings.columnGroups?.[group]?.[0]?.key
      || headings.find((heading) => !this.identityKeys.has(String(heading?.key ?? '')))?.key
      || headings[0]?.key
      || null;

    settings.sortKey = fallbackKey;
    settings.sortDirection = groupSort.sortDirection || settings.defaultSortDirection || 'desc';
    settings.displayKey = fallbackKey;
  }
}
