export class StatsSchemaAdapter {
  constructor(payload = {}) {
    this.payload = payload || {};
  }

  availableSeasons() {
    return Array.isArray(this.payload.meta?.availableSeasons)
      ? this.payload.meta.availableSeasons.map(String)
      : [];
  }

  availableGameTypes() {
    return Array.isArray(this.payload.meta?.availableGameTypes)
      ? this.payload.meta.availableGameTypes.map(String)
      : ['2'];
  }

  availableLeagues() {
    return Array.isArray(this.payload.meta?.availableLeagues)
      ? this.payload.meta.availableLeagues.map(String).filter(Boolean)
      : [];
  }

  draftYearOptions() {
    const definition = this.filterSchema().find((spec) => String(spec?.key || '') === 'entry_draft_year');

    return Array.isArray(definition?.options)
      ? [...new Set(definition.options.map(String).filter(Boolean))]
      : [];
  }

  canSlice() {
    return Boolean(this.payload.meta?.canSlice ?? true);
  }

  supportsDateRange() {
    return Boolean(this.payload.meta?.supportsDateRange ?? true);
  }

  filterSchema() {
    return Array.isArray(this.payload.meta?.filterSchema) ? this.payload.meta.filterSchema : [];
  }

  positionButtonsFromPayload(settings = {}) {
    const buttons = Array.isArray(this.payload.meta?.positionButtons)
      ? this.payload.meta.positionButtons
      : (Array.isArray(settings?.ui?.positionButtons) ? settings.ui.positionButtons : []);

    return buttons.map(String);
  }

  numericFilterSpecs() {
    return this.filterSchema().filter((spec) => {
      const type = String(spec?.type || '').toLowerCase();
      return ['number', 'int', 'float'].includes(type) && spec?.bounds && spec?.key;
    });
  }
}
