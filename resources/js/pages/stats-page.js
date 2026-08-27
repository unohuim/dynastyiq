import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';
import { registerToastStack } from '../components/toast-stack.js';
import { leagueRosterHeadings, sortData, statValueForKey } from '../components/StatsPage/stats-utils.js';
import { renderStatsDesktop } from '../components/StatsPage/stats-desktop.js';
import { StatsMobile } from '../components/StatsPage/stats-mobile.js';
import { StatsColumnGroupAdapter } from './stats-column-group-adapter.js';
import { StatsFilterState } from './stats-filter-state.js';
import { StatsPayloadClient, normalizeStatsPayload, statsIdentityKeys } from './stats-payload-client.js';
import { StatsSchemaAdapter } from './stats-schema-adapter.js';
import { mountStatsSelect } from './Stats/Controls/mountStatsSelect.js';
import '../analytics-tracker.js';

const Alpine = window.Alpine ?? AlpineImport;

if (!Alpine.__hasFocusPlugin) {
  Alpine.plugin(focus);
  Alpine.__hasFocusPlugin = true;
}

window.Alpine = Alpine;
registerToastStack(Alpine);

if (!window.__alpineStarted) {
  Alpine.start();
  window.__alpineStarted = true;
}

const IDENTITY_KEYS = statsIdentityKeys;
const PROSPECT_HIDDEN_HEADING_KEYS = new Set([
  'aav',
  'cap_hit',
  'salary',
  'contract_value',
  'contract_value_num',
  'contract_last_year',
  'contract_last_year_num',
]);

const createElement = (tag, className = '', text = '') => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;

  return node;
};

const formatDateInputValue = (date) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
};

const defaultRangeDates = () => {
  const to = new Date();
  const from = new Date(to);
  from.setDate(from.getDate() - 30);

  return {
    fromDate: formatDateInputValue(from),
    toDate: formatDateInputValue(to),
  };
};

export class StatsPageShell {
  constructor(container, config) {
    this.container = container;
    this.config = config || {};
    this.apiUrl = this.config.apiUrl;
    this.mobileBreakpoint = Number(this.config.mobileBreakpoint ?? this.config.nonMobileBreakpoint ?? 640);
    this.payload = normalizeStatsPayload(this.config.initialPayload || {});
    this.schemaAdapter = new StatsSchemaAdapter(this.payload);
    this.columnGroupAdapter = new StatsColumnGroupAdapter(IDENTITY_KEYS);
    this.resource = this.config.resource || this.payload?.settings?.resource || 'players';
    this.payloadClient = new StatsPayloadClient({ apiUrl: this.apiUrl, resource: this.resource });
    this.perspectives = Array.isArray(this.config.perspectives) ? this.config.perspectives : [];
    this.connectedLeagues = Array.isArray(this.config.connectedLeagues) ? this.config.connectedLeagues : [];
    this.defaultPerspective = this.perspectives.find((perspective) => perspective?.slug === 'skaters')?.slug
      || this.perspectives.find((perspective) => perspective?.name === 'Skaters')?.slug
      || this.perspectives[0]?.slug
      || '';

    const meta = this.payload.meta || {};
    const settings = this.payload.settings || {};

    this.state = {
      perspective: this.config.selectedPerspective || this.defaultPerspective,
      period: 'season',
      fromDate: '',
      toDate: '',
      slice: settings.slice || 'total',
      seasonId: String(meta.season ?? ''),
      gameType: String(meta.game_type ?? '2'),
      positionButtons: this.positionButtonsFromPayload(meta, settings),
      selectedPos: [],
      selectedPosTypes: [],
      selectedLeagues: [],
      selectedDraftYears: [],
      rangeError: '',
      numericFilters: {},
      dirtyNumericFilters: {},
      leagueAutoSkaterFilter: false,
      teamAggregateStartersOnly: Boolean(settings.teamAggregateStartersOnly ?? meta.teamAggregateStartersOnly ?? false),
      nhleLens: Boolean(settings.nhleLens ?? meta.nhle?.active ?? false),
      loading: Boolean(this.config.initialLoading),
      error: '',
      isFilterDrawerOpen: false,
      isDraftYearDropdownOpen: false,
      draftYearDropdownRect: null,
      isMobile: this.isMobile(),
    };
    this.filterState = new StatsFilterState(this.state);
    this.pendingLocalPositionFilters = null;
    this.cachedSkaterHeadings = null;

    this.settings = {
      ...settings,
      sortKey: settings.sortKey ?? settings.defaultSort ?? null,
      sortDirection: settings.sortDirection ?? settings.defaultSortDirection ?? 'desc',
      displayKey: settings.displayKey ?? settings.sortKey ?? settings.defaultSort ?? null,
      leagueUserSortActive: false,
    };
    this.syncNumericFiltersFromPayload();

    this.controlsEl = createElement('div');
    this.contentEl = createElement('div');
    this.container.replaceChildren(this.controlsEl, this.contentEl);

    let resizeTimer = null;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        const next = this.isMobile();
        if (next !== this.state.isMobile) {
          this.state.isMobile = next;
          if (!next) {
            this.state.isFilterDrawerOpen = false;
            document.body.style.overflow = '';
          }
          this.render();
        }
      }, 120);
    });
    document.addEventListener('click', (event) => {
      if (!this.state.isDraftYearDropdownOpen) return;
      if (event.target instanceof Element && event.target.closest('[data-draft-year-dropdown]')) return;

      this.state.isDraftYearDropdownOpen = false;
      this.state.draftYearDropdownRect = null;
      this.renderControls();
    });
  }

  isMobile() {
    return window.innerWidth < this.mobileBreakpoint;
  }

  availableSeasons() {
    return this.schemaAdapter.availableSeasons();
  }

  availableGameTypes() {
    return this.schemaAdapter.availableGameTypes();
  }

  availableLeagues() {
    return this.schemaAdapter.availableLeagues();
  }

  draftYearOptions() {
    return this.schemaAdapter.draftYearOptions();
  }

  canSlice() {
    return this.schemaAdapter.canSlice();
  }

  supportsDateRange() {
    return this.schemaAdapter.supportsDateRange();
  }

  perspectiveOptions() {
    return this.perspectives.map((perspective) => ({
      label: perspective.name || perspective.slug,
      value: perspective.slug || perspective.name,
    }));
  }

  hasColumnGroups() {
    return this.columnGroupAdapter.hasColumnGroups(this.settings);
  }

  activeColumnGroup() {
    return this.columnGroupAdapter.activeColumnGroup(this.settings, this.state);
  }

  activeHeadings() {
    if (this.resource === 'teams' || this.settings.teamAggregate === true) {
      return this.payload.headings;
    }

    return this.columnGroupAdapter.activeHeadings(this.payload, this.settings, this.state);
  }

  syncColumnGroupSort() {
    if (this.resource === 'teams' || this.settings.teamAggregate === true) return;

    this.columnGroupAdapter.syncSort(this.settings, this.payload, this.state);
  }

  buildParams() {
    return this.payloadClient.buildParams(this.state, {
      canSlice: this.canSlice(),
      supportsDateRange: this.supportsDateRange(),
    });
  }

  cacheKeyFromParams(params) {
    return this.payloadClient.cacheKeyFromParams(params);
  }

  cachePayload(params, payload) {
    this.payloadClient.cachePayload(params, payload);
  }

  applyPayload(payload) {
    this.payload = payload;
    this.schemaAdapter = new StatsSchemaAdapter(this.payload);
    if (Array.isArray(this.payload.perspectives) && this.payload.perspectives.length > 0) {
      this.perspectives = this.payload.perspectives;
    }
    if (
      this.payload.selectedPerspective
      && this.perspectiveOptions().some((option) => option.value === this.payload.selectedPerspective)
    ) {
      this.state.perspective = this.payload.selectedPerspective;
    }
    this.connectedLeagues = Array.isArray(this.payload.connectedLeagues)
      ? this.payload.connectedLeagues
      : this.connectedLeagues;

    this.syncStateFromPayload();
    this.rememberSkaterHeadings();
  }

  updateUrl(params) {
    if (this.config.syncUrl === false) return;

    window.history.replaceState(null, '', `/stats?${params.toString()}`);
  }

  async fetchPayload(options = {}) {
    const force = options?.force === true;
    if (!this.apiUrl || (this.state.loading && !force)) return;

    const params = this.buildParams();
    if (!force && this.payloadClient.hasCachedPayload(params)) {
      this.applyPayload(this.payloadClient.cachedPayload(params));
      this.updateUrl(this.buildParams());
      this.render();
      return;
    }

    this.state.loading = true;
    this.state.error = '';
    this.render();

    this.updateUrl(params);

    let result = null;
    try {
      result = await this.payloadClient.fetchPayload(params, { force });
      if (result.stale) return;

      this.applyPayload(result.payload);
      this.cachePayload(this.buildParams(), result.payload);
      this.updateUrl(this.buildParams());
    } catch (error) {
      console.error('[stats-page] fetch failed', error);
      this.state.error = error?.message || 'Unable to load stats.';
    } finally {
      if (result?.stale) return;
      this.state.loading = false;
      this.render();
    }
  }

  syncStateFromPayload() {
    const meta = this.payload.meta || {};
    const settings = this.payload.settings || {};
    const pendingLocalPositionFilters = this.pendingLocalPositionFilters;
    const preserveGoalieMode = !pendingLocalPositionFilters
      && (this.state.selectedPosTypes.includes('G') || this.state.selectedPos.includes('G'));

    if (meta.season != null) this.state.seasonId = String(meta.season);
    if (meta.game_type != null) this.state.gameType = String(meta.game_type);
    if (Array.isArray(meta.positionButtons) || Array.isArray(settings?.ui?.positionButtons)) {
      this.state.positionButtons = this.positionButtonsFromPayload(meta, settings);
    }
    if (Array.isArray(meta.pos)) {
      this.state.selectedPos = meta.pos.map(String);
    }
    if (Array.isArray(meta.pos_type)) {
      this.state.selectedPosTypes = meta.pos_type.map(String);
    }
    if (pendingLocalPositionFilters) {
      this.state.selectedPos = pendingLocalPositionFilters.selectedPos;
      this.state.selectedPosTypes = pendingLocalPositionFilters.selectedPosTypes;
      this.pendingLocalPositionFilters = null;
    } else if (preserveGoalieMode) {
      this.state.selectedPosTypes = ['G'];
      this.state.selectedPos = ['G'];
    }
    if (Array.isArray(meta.appliedFilters?.league)) {
      this.state.selectedLeagues = meta.appliedFilters.league.map(String);
    }
    if (meta.appliedFilters?.entry_draft_year != null) {
      this.state.selectedDraftYears = this.draftYearsFromAppliedFilter(meta.appliedFilters.entry_draft_year);
    }
    if (!this.supportsDateRange()) {
      this.state.period = 'season';
    }
    if (this.resource === 'teams' || settings.teamAggregate === true) {
      this.state.teamAggregateStartersOnly = Boolean(
        settings.teamAggregateStartersOnly ?? meta.teamAggregateStartersOnly ?? this.state.teamAggregateStartersOnly,
      );
    }
    this.state.nhleLens = Boolean(settings.nhleLens ?? meta.nhle?.active ?? this.state.nhleLens);

    this.state.slice = this.canSlice() ? this.state.slice : 'total';
    const preserveUserSort = this.settings?.leagueUserSortActive === true;
    const preservedSort = preserveUserSort
      ? {
          sortKey: this.settings.sortKey,
          sortDirection: this.settings.sortDirection,
          displayKey: this.settings.displayKey,
        }
      : null;
    this.settings = {
      ...settings,
      sortKey: preservedSort?.sortKey ?? settings.sortKey ?? settings.defaultSort ?? this.settings.sortKey,
      sortDirection: preservedSort?.sortDirection ?? settings.sortDirection ?? settings.defaultSortDirection ?? this.settings.sortDirection ?? 'desc',
      displayKey: preservedSort?.displayKey ?? settings.displayKey ?? settings.sortKey ?? settings.defaultSort ?? this.settings.displayKey,
      leagueUserSortActive: preserveUserSort,
    };
    this.syncNumericFiltersFromPayload();
  }

  filterSchema() {
    return this.schemaAdapter.filterSchema();
  }

  positionButtonsFromPayload(meta = {}, settings = {}) {
    return new StatsSchemaAdapter({ meta }).positionButtonsFromPayload(settings);
  }

  numericFilterSpecs() {
    return this.schemaAdapter.numericFilterSpecs();
  }

  syncNumericFiltersFromPayload(force = false) {
    this.filterState.syncNumericFiltersFromPayload(this.payload, this.schemaAdapter, force);
  }

  setNumericFilterBound(key, bound, value) {
    this.filterState.setNumericFilterBound(key, bound, value);
  }

  resetFilters() {
    this.filterState.reset(this.payload, this.schemaAdapter);
    this.fetchPayload();
  }

  applyFilters() {
    this.closeFilterDrawer();
    this.fetchPayload();
  }

  onSortChange = ({ sortKey, sortDirection, leagueUserSortActive = true }) => {
    this.settings.sortKey = sortKey;
    this.settings.sortDirection = sortDirection;
    this.settings.displayKey = sortKey;
    this.settings.leagueUserSortActive = leagueUserSortActive;
    this.renderContent();
  };

  onTeamAggregateStartersChange = (enabled) => {
    this.state.teamAggregateStartersOnly = enabled === true;
    this.fetchPayload({ force: true });
  };

  onNhleLensChange = (enabled) => {
    this.state.nhleLens = enabled === true;
    this.fetchPayload({ force: true });
  };

  setPerspective(value) {
    this.state.perspective = value;
    this.state.selectedPos = [];
    this.state.selectedPosTypes = [];
    this.state.leagueAutoSkaterFilter = false;
    this.state.selectedLeagues = [];
    this.state.selectedDraftYears = [];
    this.state.isDraftYearDropdownOpen = false;
    this.state.numericFilters = {};
    this.state.dirtyNumericFilters = {};
    this.state.nhleLens = value === 'prospects' ? this.state.nhleLens : false;
    this.fetchPayload();
  }

  setLeague(value) {
    this.state.selectedLeagues = value ? [value] : [];
    this.fetchPayload();
  }

  setDraftYears(values) {
    this.state.selectedDraftYears = this.normalizeDraftYears(values);
    this.fetchPayload();
  }

  normalizeDraftYears(values) {
    return [...new Set((values || [])
      .map((value) => Number(value))
      .filter((value) => Number.isFinite(value) && value > 0))]
      .sort((a, b) => a - b)
      .map(String);
  }

  draftYearsFromAppliedFilter(value) {
    if (Array.isArray(value)) {
      return this.normalizeDraftYears(value);
    }

    if (value && typeof value === 'object') {
      const min = Number(value.min);
      const max = Number(value.max);
      if (Number.isFinite(min) && Number.isFinite(max) && min > 0 && max > 0) {
        const start = Math.min(min, max);
        const end = Math.max(min, max);
        const years = [];
        for (let year = start; year <= end; year += 1) {
          years.push(String(year));
        }

        return years;
      }

      return this.normalizeDraftYears([value.min, value.max]);
    }

    return this.normalizeDraftYears([value]);
  }

  draftYearButtonLabel() {
    const years = this.normalizeDraftYears(this.state.selectedDraftYears);
    if (years.length === 0) return 'Drafted';
    if (years.length === 1) return years[0];

    return `${years[0]}-${years[years.length - 1]}`;
  }

  toggleDraftYearDropdown(event = null) {
    event?.stopPropagation?.();
    const nextOpen = !this.state.isDraftYearDropdownOpen;
    this.state.isDraftYearDropdownOpen = nextOpen;
    this.state.draftYearDropdownRect = nextOpen && event?.currentTarget instanceof Element
      ? event.currentTarget.getBoundingClientRect()
      : null;
    this.renderControls();
  }

  onDraftYearClick(year, event = null) {
    event?.stopPropagation?.();
    const normalizedYear = String(year);
    if (this.state.selectedDraftYears.includes(normalizedYear)) {
      this.setDraftYears([]);
      return;
    }

    if (this.state.selectedDraftYears.length === 0) {
      this.setDraftYears([normalizedYear]);
      return;
    }

    const selected = this.normalizeDraftYears([...this.state.selectedDraftYears, normalizedYear])
      .map((value) => Number(value));
    const min = Math.min(...selected);
    const max = Math.max(...selected);
    const range = [];

    for (let value = min; value <= max; value += 1) {
      range.push(String(value));
    }

    this.setDraftYears(range);
  }

  setSeason(value) {
    this.state.seasonId = value;
    this.fetchPayload();
  }

  setGameType(value) {
    this.state.gameType = value;
    this.fetchPayload();
  }

  setPeriod(value) {
    this.state.period = value;
    if (value === 'range') {
      this.ensureRangeDates();
      this.state.rangeError = '';
      this.renderControls();
      return;
    }
    this.state.rangeError = '';
    this.fetchPayload();
  }

  setRangeDate(bound, value) {
    if (bound !== 'fromDate' && bound !== 'toDate') return;

    this.state[bound] = value;
    this.state.rangeError = '';
    this.renderControls();
  }

  rangeValidationMessage() {
    if (this.state.period !== 'range') return '';
    if (!this.state.fromDate || !this.state.toDate) return 'Choose start and end dates.';
    if (this.state.fromDate > this.state.toDate) return 'Start date must be before end date.';

    return '';
  }

  applyRangeDates() {
    const message = this.rangeValidationMessage();
    if (message) {
      this.state.rangeError = message;
      this.renderControls();
      return;
    }

    this.state.rangeError = '';
    this.fetchPayload();
  }

  ensureRangeDates() {
    if (this.state.fromDate && this.state.toDate) return;

    const defaults = defaultRangeDates();
    this.state.fromDate = this.state.fromDate || defaults.fromDate;
    this.state.toDate = this.state.toDate || defaults.toDate;
  }

  setSlice(value) {
    this.state.slice = value;
    this.renderContent();
  }

  togglePosition(value) {
    this.state.leagueAutoSkaterFilter = false;
    const wasGoalieMode = this.state.selectedPosTypes.includes('G') || this.state.selectedPos.includes('G');
    if (!wasGoalieMode) {
      this.rememberSkaterHeadings();
    }

    this.filterState.togglePosition(value);
    const isGoalieMode = this.state.selectedPosTypes.includes('G') || this.state.selectedPos.includes('G');
    this.renderControls();

    if (this.config.syncUrl === false && this.apiUrl && wasGoalieMode !== isGoalieMode) {
      if (wasGoalieMode && !isGoalieMode) {
        this.pendingLocalPositionFilters = {
          selectedPos: [...this.state.selectedPos],
          selectedPosTypes: [...this.state.selectedPosTypes],
        };
      }

      this.fetchPayload();
    } else {
      this.renderContent();
    }
  }

  isPositionActive(value) {
    return this.filterState.isPositionActive(value);
  }

  onLeagueFantasyTeamFilterChange = ({ teamSpecific = false } = {}) => {
    if (this.resource === 'teams' || this.settings.teamAggregate === true) return;

    const shouldAutoSkaters = !teamSpecific;
    const hasAutoSkaters = this.state.leagueAutoSkaterFilter === true
      && this.state.selectedPosTypes.length === 2
      && this.state.selectedPosTypes.includes('F')
      && this.state.selectedPosTypes.includes('D')
      && this.state.selectedPos.length === 0;

    if (shouldAutoSkaters && hasAutoSkaters) return;
    if (!shouldAutoSkaters && !this.state.leagueAutoSkaterFilter) return;

    if (shouldAutoSkaters) {
      this.state.selectedPos = [];
      this.state.selectedPosTypes = ['F', 'D'];
      this.state.leagueAutoSkaterFilter = true;
    } else {
      this.state.selectedPos = [];
      this.state.selectedPosTypes = [];
      this.state.leagueAutoSkaterFilter = false;
    }

    this.renderControls();
  };

  locallyFilteredRows() {
    return this.filterState.filterRows(this.payload.data);
  }

  render() {
    if (this.state.loading && this.payload.data.length === 0) {
      this.controlsEl.innerHTML = '';
      this.renderContent();
      return;
    }

    this.renderControls();
    this.renderContent();
  }

  renderControls() {
    this.controlsEl.innerHTML = '';
    this.removeDraftYearDropdownPortal();

    if (this.resource === 'teams' || this.settings.teamAggregate === true) {
      return;
    }

    if (this.state.isMobile) {
      this.controlsEl.appendChild(this.renderMobileControls());
      return;
    }

    this.controlsEl.appendChild(this.renderDesktopControls());
  }

  renderMobileControls() {
    const wrapper = createElement('div', 'top-0 z-40');
    const row = createElement('div', 'flex');

    const filterButton = createElement('button', 'searchbar-button-mobile', '');
    filterButton.type = 'button';
    filterButton.setAttribute('aria-label', 'Stats filters');
    filterButton.addEventListener('click', () => this.openFilterDrawer());
    filterButton.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="searchbar-svg-mobile" aria-hidden="true">
        <path d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>
      </svg>
    `;

    row.appendChild(filterButton);
    row.appendChild(this.renderSelect(this.perspectiveOptions(), this.state.perspective, (value) => this.setPerspective(value), 'col-start-1 row-start-1 block w-full bg-white py-1.5 pl-10 pr-3 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 focus:outline-2 focus:outline-indigo-600'));

    wrapper.appendChild(row);
    wrapper.appendChild(this.renderMobileFilterDrawer());

    return wrapper;
  }

  renderMobileFilterStrip() {
    const wrapper = createElement('div', 'bg-white border-b border-gray-200 px-3 py-2 space-y-2');
    const positionRow = createElement('div', 'flex flex-wrap gap-2');

    this.state.positionButtons.forEach((button) => {
      positionRow.appendChild(this.renderPositionButton(button, 'h-8 w-8 rounded-full text-[11px] font-semibold ring-1 ring-gray-200 transition-colors'));
    });

    const selects = createElement('div', 'grid grid-cols-2 gap-2');
    if (this.supportsDateRange()) {
      selects.appendChild(this.renderSelect([
        { label: 'Season', value: 'season' },
        { label: 'Range', value: 'range' },
      ], this.state.period, (value) => this.setPeriod(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
      if (this.state.period === 'range') {
        this.ensureRangeDates();
        selects.appendChild(this.renderDateInput('fromDate', 'Start', 'h-9 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-900 focus:ring-2 focus:ring-indigo-500'));
        selects.appendChild(this.renderDateInput('toDate', 'End', 'h-9 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-900 focus:ring-2 focus:ring-indigo-500'));
        selects.appendChild(this.renderRangeApplyButton('h-9 w-full rounded-md px-3 text-sm font-semibold'));
        if (this.state.rangeError) {
          const error = createElement('div', 'col-span-2 text-xs font-medium text-red-600', this.state.rangeError);
          selects.appendChild(error);
        }
      }
    }
    selects.appendChild(this.renderSelect(this.availableSeasons().map((season) => ({ label: season, value: season })), this.state.seasonId, (value) => this.setSeason(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
    if (this.availableLeagues().length > 0) {
      selects.appendChild(this.renderSelect([
        { label: 'All Leagues', value: '' },
        ...this.availableLeagues().map((league) => ({ label: league, value: league })),
      ], this.state.selectedLeagues[0] || '', (value) => this.setLeague(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
    }
    if (this.draftYearOptions().length > 0) {
      selects.appendChild(this.renderDraftYearDropdown('h-9 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-900 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500'));
    }
    selects.appendChild(this.renderSelect(this.availableGameTypes().map((type) => ({ label: this.gameTypeLabel(type), value: type })), this.state.gameType, (value) => this.setGameType(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));

    if (this.canSlice()) {
      selects.appendChild(this.renderSelect([
        { label: 'Total', value: 'total' },
        { label: 'P/GP', value: 'pgp' },
        { label: 'Per 60', value: 'p60' },
      ], this.state.slice, (value) => this.setSlice(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
    }

    wrapper.appendChild(positionRow);
    wrapper.appendChild(selects);

    return wrapper;
  }

  openFilterDrawer() {
    this.state.isFilterDrawerOpen = true;
    document.body.style.overflow = 'hidden';
    this.renderControls();
  }

  closeFilterDrawer() {
    this.state.isFilterDrawerOpen = false;
    document.body.style.overflow = '';
    this.renderControls();
  }

  renderMobileFilterDrawer() {
    const overlay = createElement(
      'button',
      this.state.isFilterDrawerOpen
        ? 'filters-backdrop z-[60] opacity-100 transition-opacity duration-200'
        : 'filters-backdrop z-[60] opacity-0 pointer-events-none transition-opacity duration-200',
    );
    overlay.type = 'button';
    overlay.setAttribute('aria-label', 'Close stats filters');
    overlay.addEventListener('click', () => this.closeFilterDrawer());

    const drawer = createElement(
      'div',
      [
        'filters-drawer z-[70] transform transition-transform duration-300 ease-out will-change-transform',
        this.state.isFilterDrawerOpen ? 'translate-x-0' : 'translate-x-full',
      ].join(' '),
    );
    drawer.id = 'mobile-stats-filter-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-label', 'Stats filters');
    drawer.setAttribute('aria-hidden', this.state.isFilterDrawerOpen ? 'false' : 'true');

    const header = createElement('header', 'px-4 py-3 border-b flex items-center justify-between');
    const headerRow = createElement('div', 'flex items-center justify-between');
    const title = createElement('h2', 'text-base font-semibold text-gray-900', 'Filters');
    const actions = createElement('div', 'flex items-center gap-2');
    const reset = createElement('button', 'px-3 py-1.5 text-sm rounded border', 'Reset');
    reset.type = 'button';
    reset.addEventListener('click', () => this.resetFilters());
    const apply = createElement('button', 'px-3 py-1.5 text-sm rounded bg-indigo-600 text-white', 'Apply');
    apply.type = 'button';
    apply.disabled = this.state.loading;
    apply.addEventListener('click', () => this.applyFilters());
    const close = createElement('button', 'p-2 rounded-full hover:bg-gray-100', '');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close stats filters');
    close.innerHTML = '<span class="block text-xl leading-none">&times;</span>';
    close.addEventListener('click', () => this.closeFilterDrawer());

    actions.appendChild(reset);
    actions.appendChild(apply);
    actions.appendChild(close);
    headerRow.className = 'contents';
    headerRow.appendChild(title);
    headerRow.appendChild(actions);
    header.appendChild(headerRow);

    const body = createElement('div', 'flex-1 overflow-y-auto px-4 py-4 space-y-6');
    body.appendChild(this.renderMobileFilterStrip());
    this.numericFilterSpecs().forEach((spec) => {
      body.appendChild(this.renderDualSliderFilter(spec));
    });

    drawer.appendChild(header);
    drawer.appendChild(body);

    const footer = createElement('footer', 'px-4 py-3 border-t flex items-center gap-2');
    const footerReset = createElement('button', 'px-3 py-1.5 text-sm rounded border', 'Reset');
    footerReset.type = 'button';
    footerReset.addEventListener('click', () => this.resetFilters());
    const footerApply = createElement('button', 'px-3 py-1.5 text-sm rounded bg-indigo-600 text-white', 'Apply');
    footerApply.type = 'button';
    footerApply.disabled = this.state.loading;
    footerApply.addEventListener('click', () => this.applyFilters());
    footer.appendChild(footerReset);
    footer.appendChild(footerApply);
    if (this.state.loading) footer.appendChild(createElement('span', 'ml-auto text-xs text-gray-500', 'Updating...'));
    drawer.appendChild(footer);

    const fragment = document.createDocumentFragment();
    fragment.appendChild(overlay);
    fragment.appendChild(drawer);

    return fragment;
  }

  renderDualSliderFilter(spec) {
    const key = String(spec.key);
    const bounds = spec.bounds || {};
    const minBound = Number(bounds.min ?? 0);
    const maxBound = Number(bounds.max ?? minBound);
    const step = Number(spec.step ?? 1);
    const current = this.state.numericFilters[key] || { min: minBound, max: maxBound };

    const wrapper = createElement('div');
    const header = createElement('div', 'flex items-center justify-between mb-1.5');
    const label = createElement('span', 'text-sm font-medium', spec.label || key);
    const value = createElement('span', 'text-xs text-gray-500');
    header.appendChild(label);
    header.appendChild(value);

    const slider = createElement('div', 'dual-slider');
    const rail = createElement('div', 'rail');
    const active = createElement('div', 'active');
    const maxInput = document.createElement('input');
    const minInput = document.createElement('input');

    const clamp = (number) => Math.min(maxBound, Math.max(minBound, Number(number)));
    const pct = (number) => {
      if (maxBound <= minBound) return 0;
      return ((clamp(number) - minBound) / (maxBound - minBound)) * 100;
    };
    const sync = () => {
      const selected = this.state.numericFilters[key] || { min: minBound, max: maxBound };
      const min = clamp(selected.min);
      const max = clamp(selected.max);
      minInput.value = String(min);
      maxInput.value = String(max);
      value.textContent = `${min} - ${max}`;
      const left = pct(min);
      const right = pct(max);
      active.style.left = `${left}%`;
      active.style.width = `${Math.max(0, right - left)}%`;
    };

    [maxInput, minInput].forEach((input) => {
      input.type = 'range';
      input.min = String(minBound);
      input.max = String(maxBound);
      input.step = String(step);
      input.className = 'absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none';
    });
    maxInput.classList.add('max');
    minInput.classList.add('min');

    maxInput.addEventListener('input', (event) => {
      this.setNumericFilterBound(key, 'max', event.target.value);
      sync();
    });
    minInput.addEventListener('input', (event) => {
      this.setNumericFilterBound(key, 'min', event.target.value);
      sync();
    });

    slider.appendChild(rail);
    slider.appendChild(active);
    slider.appendChild(maxInput);
    slider.appendChild(minInput);
    wrapper.appendChild(header);
    wrapper.appendChild(slider);
    sync();

    return wrapper;
  }

  renderDesktopControls() {
    const outer = createElement('div', 'px-4 pt-3 sm:px-6');
    const panel = createElement('div', 'relative z-50 mb-3 overflow-visible border border-gray-200 bg-white shadow-sm');
    const row = createElement('div', 'flex min-h-[86px] items-stretch');
    const iconBlock = createElement('div', 'flex w-24 shrink-0 items-center justify-center bg-indigo-600 text-white');
    iconBlock.innerHTML = `
      <svg viewBox="0 0 64 64" aria-hidden="true" class="h-12 w-12" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="29" cy="13" r="5"/>
        <path d="m26 19-9 9 9 8 9-10"/>
        <path d="m17 28-9 3"/>
        <path d="m27 36-7 12-11 3"/>
        <path d="m34 27 8 11 10 4"/>
        <path d="m10 51 14 1"/>
        <path d="m40 49 14 3"/>
        <path d="m40 27 13 25"/>
        <path d="m50 52 7 2"/>
      </svg>
    `;
    row.appendChild(iconBlock);

    const controls = createElement(
      'div',
      this.state.period === 'range'
        ? 'grid flex-1 grid-cols-[minmax(180px,0.95fr)_minmax(210px,1.05fr)_minmax(390px,1.8fr)_minmax(170px,0.85fr)_minmax(190px,0.9fr)] items-stretch'
        : 'grid flex-1 grid-cols-[minmax(190px,1fr)_minmax(220px,1.12fr)_minmax(180px,0.9fr)_minmax(170px,0.85fr)_minmax(190px,0.9fr)] items-stretch',
    );
    const controlSelectClass = 'h-8 w-full border-0 border-b border-gray-300 bg-transparent px-0 pb-1 pt-0 text-[15px] text-gray-950 focus:border-indigo-600 focus:outline-none focus:ring-0';

    controls.appendChild(this.renderDesktopControlGroup(
      'Report',
      this.renderDesktopSelect(this.perspectiveOptions(), this.state.perspective, (value) => this.setPerspective(value), 'Report', controlSelectClass),
    ));

    if (this.supportsDateRange()) {
      const periodGroup = this.renderDesktopControlGroup('Season Mode', this.renderSegmented([
        { label: 'Season', value: 'season' },
        { label: 'Range', value: 'range' },
      ], this.state.period, (value) => this.setPeriod(value), 'underline'));
      controls.appendChild(periodGroup);
    }
    if (this.state.period === 'range') {
      this.ensureRangeDates();
      controls.appendChild(this.renderDesktopControlGroup('Dates', this.renderDateRangeControls()));
    } else {
      controls.appendChild(this.renderDesktopControlGroup(
        'Season',
        this.renderDesktopSelect(this.availableSeasons().map((season) => ({ label: season, value: season })), this.state.seasonId, (value) => this.setSeason(value), 'Season', controlSelectClass),
      ));
    }
    if (this.draftYearOptions().length > 0) {
      controls.appendChild(this.renderDesktopControlGroup(
        'Drafted',
        this.renderDraftYearDropdown('h-9 w-full border-0 border-b border-gray-300 bg-transparent px-0 pb-1 pt-0 text-base text-gray-950 hover:border-indigo-500 focus:outline-none focus:ring-0'),
      ));
    }

    controls.appendChild(this.renderDesktopControlGroup(
      'Game Type',
      this.renderDesktopSelect(this.availableGameTypes().map((type) => ({ label: this.gameTypeLabel(type), value: type })), this.state.gameType, (value) => this.setGameType(value), 'Game Type', controlSelectClass),
    ));

    row.appendChild(controls);
    panel.appendChild(row);
    outer.appendChild(panel);

    return outer;
  }

  renderDesktopControlGroup(label, control) {
    const group = createElement('div', 'flex min-w-0 flex-col justify-center border-r border-gray-200 px-7 py-4 last:border-r-0');
    group.appendChild(createElement('div', 'mb-2 text-[11px] font-semibold uppercase tracking-[0.20em] text-gray-500', label));
    group.appendChild(control);

    return group;
  }

  renderDateRangeControls() {
    const wrapper = createElement('div', 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2');
    wrapper.appendChild(this.renderDateInput('fromDate', 'Start', 'h-8 min-w-0 border-0 border-b border-gray-300 bg-transparent px-0 text-[13px] text-gray-900 focus:border-indigo-600 focus:outline-none focus:ring-0'));
    wrapper.appendChild(this.renderDateInput('toDate', 'End', 'h-8 min-w-0 border-0 border-b border-gray-300 bg-transparent px-0 text-[13px] text-gray-900 focus:border-indigo-600 focus:outline-none focus:ring-0'));
    wrapper.appendChild(this.renderRangeApplyButton('h-8 border-b border-indigo-600 px-2 text-[11px] font-semibold uppercase tracking-[0.12em]'));
    if (this.state.rangeError) {
      wrapper.appendChild(createElement('div', 'col-span-3 text-xs font-medium text-red-600', this.state.rangeError));
    }

    return wrapper;
  }

  renderDesktopSelect(options, selectedValue, onChange, label, triggerClass) {
    return mountStatsSelect({
      options,
      modelValue: selectedValue,
      onChange,
      placeholder: label,
      ariaLabel: label,
      triggerClass: `${triggerClass} inline-flex items-center justify-between gap-3 text-left`,
    });
  }

  renderSelect(options, selectedValue, onChange, className, wrapperClass = 'relative z-50 -mr-px grid grow grid-cols-1') {
    const wrapper = createElement('div', wrapperClass);
    const select = createElement('select', `${className} stats-select-native`);
    select.value = selectedValue;
    select.addEventListener('change', (event) => onChange(event.target.value));

    options.forEach((option) => {
      const node = document.createElement('option');
      node.value = option.value;
      node.textContent = option.label;
      select.appendChild(node);
    });

    select.value = selectedValue;
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 20 20');
    icon.setAttribute('fill', 'currentColor');
    icon.setAttribute('aria-hidden', 'true');
    icon.classList.add('pointer-events-none', 'col-start-1', 'row-start-1', 'mr-3', 'size-4', 'self-center', 'justify-self-end', 'text-gray-400');

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('fill-rule', 'evenodd');
    path.setAttribute('clip-rule', 'evenodd');
    path.setAttribute('d', 'M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z');
    icon.appendChild(path);

    wrapper.appendChild(select);
    wrapper.appendChild(icon);

    return wrapper;
  }

  renderDateInput(bound, label, className) {
    const input = createElement('input', className);
    input.type = 'date';
    input.value = this.state[bound] || '';
    input.setAttribute('aria-label', label);
    input.addEventListener('change', (event) => this.setRangeDate(bound, event.target.value));

    return input;
  }

  renderRangeApplyButton(className) {
    const invalid = Boolean(this.rangeValidationMessage());
    const button = createElement('button', `${className} ${invalid ? 'cursor-not-allowed bg-gray-100 text-gray-400' : 'bg-gray-900 text-white hover:bg-gray-800'}`, 'Apply');
    button.type = 'button';
    button.disabled = invalid;
    button.addEventListener('click', () => this.applyRangeDates());

    return button;
  }

  renderDraftYearDropdown(buttonClassName) {
    const wrapper = createElement('div', 'relative');
    wrapper.dataset.draftYearDropdown = 'true';

    const button = createElement('button', `${buttonClassName} inline-flex items-center justify-between gap-2`, '');
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'menu');
    button.setAttribute('aria-expanded', this.state.isDraftYearDropdownOpen ? 'true' : 'false');
    button.addEventListener('click', (event) => this.toggleDraftYearDropdown(event));

    const label = createElement('span', 'truncate', this.draftYearButtonLabel());
    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chevron.setAttribute('viewBox', '0 0 20 20');
    chevron.setAttribute('fill', 'currentColor');
    chevron.setAttribute('aria-hidden', 'true');
    chevron.classList.add('size-4', 'shrink-0', 'text-gray-400');
    const chevronPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    chevronPath.setAttribute('fill-rule', 'evenodd');
    chevronPath.setAttribute('clip-rule', 'evenodd');
    chevronPath.setAttribute('d', 'M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z');
    chevron.appendChild(chevronPath);
    button.appendChild(label);
    button.appendChild(chevron);
    wrapper.appendChild(button);

    if (!this.state.isDraftYearDropdownOpen) {
      return wrapper;
    }

    const menu = createElement('div', 'fixed z-[1000] max-h-72 w-44 origin-top-right overflow-y-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 focus:outline-none');
    menu.dataset.draftYearDropdown = 'true';
    menu.dataset.draftYearDropdownPortal = 'true';
    menu.setAttribute('role', 'menu');
    const rect = this.state.draftYearDropdownRect;
    if (rect) {
      menu.style.top = `${rect.bottom + 8}px`;
      menu.style.left = `${Math.max(8, rect.right - 176)}px`;
    }

    this.draftYearOptions().forEach((year) => {
      const selected = this.state.selectedDraftYears.includes(String(year));
      const item = createElement(
        'button',
        [
          'group flex w-full items-center gap-3 px-4 py-2 text-left text-sm',
          selected ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-50',
        ].join(' '),
        '',
      );
      item.type = 'button';
      item.setAttribute('role', 'menuitemcheckbox');
      item.setAttribute('aria-checked', selected ? 'true' : 'false');
      item.addEventListener('click', (event) => this.onDraftYearClick(year, event));

      const check = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      check.setAttribute('viewBox', '0 0 20 20');
      check.setAttribute('fill', 'currentColor');
      check.setAttribute('aria-hidden', 'true');
      check.classList.add('size-4', 'shrink-0', selected ? 'text-indigo-600' : 'text-transparent');
      const checkPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      checkPath.setAttribute('fill-rule', 'evenodd');
      checkPath.setAttribute('clip-rule', 'evenodd');
      checkPath.setAttribute('d', 'M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z');
      check.appendChild(checkPath);

      item.appendChild(check);
      item.appendChild(createElement('span', 'truncate', String(year)));
      menu.appendChild(item);
    });

    document.body.appendChild(menu);

    return wrapper;
  }

  removeDraftYearDropdownPortal() {
    document.querySelectorAll('[data-draft-year-dropdown-portal="true"]').forEach((node) => node.remove());
  }

  renderSegmented(options, selectedValue, onChange, variant = 'pill') {
    const isUnderline = variant === 'underline';
    const wrapper = createElement('div', isUnderline
      ? 'grid h-9 grid-flow-col items-end border-b border-gray-300'
      : 'inline-flex overflow-hidden rounded-md border border-gray-200 bg-gray-50 p-0.5');

    options.forEach((option) => {
      const active = option.value === selectedValue;
      const button = createElement('button', isUnderline
        ? [
          'h-9 min-w-20 border-b-2 px-4 text-sm transition-colors',
          active
            ? 'border-indigo-600 font-semibold text-indigo-600'
            : 'border-transparent font-medium text-gray-600 hover:text-gray-950',
        ].join(' ')
        : active
          ? 'h-8 rounded px-3 text-sm font-semibold bg-gray-900 text-white'
          : 'h-8 rounded px-3 text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900',
      option.label);
      button.type = 'button';
      button.addEventListener('click', () => onChange(option.value));
      wrapper.appendChild(button);
    });

    return wrapper;
  }

  renderPositionButton(label, baseClass) {
    const button = createElement('button', baseClass, label);
    button.type = 'button';
    button.classList.add(...(this.isPositionActive(label) ? ['bg-indigo-600', 'text-white', 'ring-indigo-600/30'] : ['bg-white', 'text-gray-700', 'hover:bg-gray-50']));
    button.addEventListener('click', () => this.togglePosition(label));

    return button;
  }

  renderContent() {
    if (this.state.loading) {
      this.contentEl.replaceChildren(this.state.isMobile ? this.renderMobileSkeleton() : this.renderDesktopSkeleton());
      return;
    }

    if (this.state.error) {
      this.contentEl.replaceChildren(this.renderError());
      return;
    }

    this.contentEl.innerHTML = '';
    this.syncColumnGroupSort();
    const renderSettings = {
      ...this.settings,
      leagueProspectMode: this.payload?.meta?.leagueProspectMode || '',
      resource: this.settings.resource || this.resource,
      teamAggregate: this.settings.teamAggregate === true || this.resource === 'teams',
      canSlice: this.canSlice(),
      positionButtons: [...this.state.positionButtons],
      selectedPos: [...this.state.selectedPos],
      selectedPosTypes: [...this.state.selectedPosTypes],
      leagueAutoSkaterFilter: this.state.leagueAutoSkaterFilter,
      teamAggregateStartersOnly: this.state.teamAggregateStartersOnly,
      nhleLens: this.state.nhleLens,
      slice: this.canSlice() ? this.state.slice : 'total',
      onSliceChange: (value) => this.setSlice(value),
      onPositionToggle: (value) => this.togglePosition(value),
      onTeamAggregateStartersChange: this.onTeamAggregateStartersChange,
      onNhleLensChange: this.onNhleLensChange,
      onLeagueFantasyTeamFilterChange: this.onLeagueFantasyTeamFilterChange,
    };
    const activeHeadings = leagueRosterHeadings(
      this.prospectHeadings(this.activeHeadings(), renderSettings),
      renderSettings,
    );
    const rows = this.locallySlicedRows(this.locallyFilteredRows(), activeHeadings);
    const sorted = renderSettings.teamAggregate === true
      ? rows
      : sortData(rows, this.settings.sortKey, this.settings.sortDirection);

    if (this.state.isMobile) {
      StatsMobile({
        container: this.contentEl,
        data: sorted,
        headings: activeHeadings,
        settings: renderSettings,
        onSortChange: this.onSortChange,
      });
      return;
    }

    renderStatsDesktop(this.contentEl, sorted, activeHeadings, {
      ...renderSettings,
      activeRenderedColumnGroup: this.activeColumnGroup(),
      goalieFilterActive: this.state.selectedPosTypes.includes('G') || this.state.selectedPos.includes('G'),
    }, this.onSortChange);
  }

  locallySlicedRows(rows, headings) {
    if (!this.canSlice() || this.state.slice === 'total') return rows;

    const slice = this.state.slice;
    const keys = new Set((Array.isArray(headings) ? headings : [])
      .map((heading) => String(heading?.key ?? ''))
      .filter((key) => key !== '' && this.shouldSliceStatKey(key)));

    return (Array.isArray(rows) ? rows : []).map((row) => {
      const totals = row?.__slice_totals && typeof row.__slice_totals === 'object' ? row.__slice_totals : {};
      const gamesPlayed = Number(totals.gp ?? row?.gp ?? 0);
      const toiSeconds = Number(totals.toi_total_seconds ?? row?.toi_total_seconds ?? (Number(row?.toi_seconds ?? 0) * gamesPlayed));
      const next = {
        ...row,
        stats: row?.stats && typeof row.stats === 'object' ? { ...row.stats } : undefined,
      };

      keys.forEach((key) => {
        const total = Number(totals[key] ?? statValueForKey(row, key));
        if (!Number.isFinite(total)) return;

        if (slice === 'pgp') {
          next[key] = gamesPlayed > 0 ? Math.round((total / gamesPlayed) * 100) / 100 : 0;
        } else if (slice === 'p60') {
          next[key] = toiSeconds > 0 ? Math.round((total / (toiSeconds / 3600)) * 100) / 100 : 0;
        }

        if (next.stats) {
          next.stats[key] = next[key];
        }
      });

      return next;
    });
  }

  shouldSliceStatKey(key) {
    const normalized = String(key).toLowerCase();
    if (
      normalized.endsWith('_pg')
      || normalized.endsWith('_p60')
      || normalized.endsWith('_per_gp')
      || normalized.endsWith('_per_60')
      || normalized.endsWith('_percentage')
      || normalized.endsWith('_pct')
      || normalized.endsWith('_p')
      || normalized === 'gaa'
      || normalized === 'sv_pct'
      || normalized === 'shooting_percentage'
    ) {
      return false;
    }

    return !new Set([
      '__rk',
      '__owner',
      'age',
      'team',
      'league',
      'pos',
      'position',
      'pos_type',
      'type',
      'player',
      'name',
      'gp',
      'toi',
      'toi_seconds',
      'toi_total_seconds',
      'contract',
      'contract_value',
      'contract_value_num',
      'contract_last_year',
      'contract_last_year_num',
      'contract_term',
      'contract_length',
      'contract_type',
      'drafted_overall_pick',
      'drafted_year',
      'drafted_label',
    ]).has(normalized);
  }

  prospectHeadings(headings, settings) {
    if (!['skaters', 'goalies'].includes(String(settings?.leagueProspectMode ?? ''))) {
      return headings;
    }

    return (Array.isArray(headings) ? headings : []).filter((heading) => {
      const key = String(heading?.key ?? '').toLowerCase();

      return !PROSPECT_HIDDEN_HEADING_KEYS.has(key);
    });
  }

  rememberSkaterHeadings() {
    if (!this.hasColumnGroups()) {
      this.cachedSkaterHeadings = Array.isArray(this.payload.headings) ? [...this.payload.headings] : null;
      return;
    }

    const skaterHeadings = this.columnGroupAdapter.activeHeadings(this.payload, this.settings, {
      ...this.state,
      selectedPos: [],
      selectedPosTypes: [],
    });

    if (Array.isArray(skaterHeadings) && skaterHeadings.length > 0) {
      this.cachedSkaterHeadings = [...skaterHeadings];
    }
  }

  renderMobileSkeleton() {
    const wrapper = createElement('div', 'players-list-mobile');
    for (let i = 0; i < 8; i += 1) {
      const card = createElement('div', 'player-stats-card-mobile animate-pulse');
      card.innerHTML = `
        <div class="player-stats-team-strip-mobile bg-gray-200"></div>
        <div class="player-stats-content-mobile py-3">
          <div class="flex items-center justify-between gap-3">
            <div class="h-3 w-36 rounded bg-gray-200"></div>
            <div class="h-3 w-12 rounded bg-gray-200"></div>
          </div>
          <div class="mt-3 flex justify-end gap-3">
            <div class="h-2 w-10 rounded bg-gray-200"></div>
            <div class="h-2 w-10 rounded bg-gray-200"></div>
            <div class="h-2 w-10 rounded bg-gray-200"></div>
          </div>
        </div>
      `;
      wrapper.appendChild(card);
    }

    return wrapper;
  }

  renderDesktopSkeleton() {
    const wrapper = createElement('div', 'px-4');
    const panel = createElement('div', 'bg-white shadow rounded-lg border border-gray-200 overflow-hidden animate-pulse');

    for (let i = 0; i < 12; i += 1) {
      const row = createElement('div', 'grid grid-cols-8 gap-3 border-b border-gray-100 px-4 py-3');
      for (let j = 0; j < 8; j += 1) {
        row.appendChild(createElement('div', j === 1 ? 'h-3 w-28 rounded bg-gray-200' : 'h-3 w-12 rounded bg-gray-200'));
      }
      panel.appendChild(row);
    }

    wrapper.appendChild(panel);

    return wrapper;
  }

  renderError() {
    const wrapper = createElement('div', 'px-4 py-6');
    const panel = createElement('div', 'rounded-md bg-white p-4 text-sm text-gray-700 shadow');
    const message = createElement('p', 'font-medium text-gray-900', 'Unable to load this stats view.');
    const detail = createElement('p', 'mt-1 text-gray-500', this.state.error);
    const button = createElement('button', 'mt-3 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500', 'Retry');
    button.type = 'button';
    button.addEventListener('click', () => this.fetchPayload());

    panel.appendChild(message);
    panel.appendChild(detail);
    panel.appendChild(button);
    wrapper.appendChild(panel);

    return wrapper;
  }

  gameTypeLabel(value) {
    return {
      1: 'Preseason',
      2: 'Regular',
      3: 'Playoffs',
    }[String(value)] || String(value);
  }
}

export const mountStatsPage = (container, config = {}) => {
  if (!container) return null;

  container.dataset.statsMounted = '1';
  const shell = new StatsPageShell(container, config);
  shell.render();

  return shell;
};

const bootStatsPage = () => {
  const container = document.getElementById('stats-page');
  if (!container || container.dataset.statsMounted === '1') return;

  mountStatsPage(container, window.__statsPageConfig || {});
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootStatsPage, { once: true });
} else {
  bootStatsPage();
}

window.DIQ = window.DIQ || {};
window.DIQ.mountStatsPage = mountStatsPage;
window.dispatchEvent(new CustomEvent('diq:stats-page-ready'));
