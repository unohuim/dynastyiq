import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';
import { registerToastStack } from '../components/toast-stack.js';
import { leagueRosterHeadings, sortData } from '../components/StatsPage/stats-utils.js';
import { renderStatsDesktop } from '../components/StatsPage/stats-desktop.js';
import { StatsMobile } from '../components/StatsPage/stats-mobile.js';
import { StatsColumnGroupAdapter } from './stats-column-group-adapter.js';
import { StatsFilterState } from './stats-filter-state.js';
import { StatsPayloadClient, normalizeStatsPayload, statsIdentityKeys } from './stats-payload-client.js';
import { StatsSchemaAdapter } from './stats-schema-adapter.js';
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
      slice: settings.slice || 'total',
      seasonId: String(meta.season ?? ''),
      gameType: String(meta.game_type ?? '2'),
      positionButtons: this.positionButtonsFromPayload(meta, settings),
      selectedPos: [],
      selectedPosTypes: [],
      selectedLeagues: [],
      numericFilters: {},
      dirtyNumericFilters: {},
      leagueAutoSkaterFilter: false,
      teamAggregateStartersOnly: Boolean(settings.teamAggregateStartersOnly ?? meta.teamAggregateStartersOnly ?? false),
      loading: Boolean(this.config.initialLoading),
      error: '',
      isFilterDrawerOpen: false,
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
    if (!this.supportsDateRange()) {
      this.state.period = 'season';
    }
    if (this.resource === 'teams' || settings.teamAggregate === true) {
      this.state.teamAggregateStartersOnly = Boolean(
        settings.teamAggregateStartersOnly ?? meta.teamAggregateStartersOnly ?? this.state.teamAggregateStartersOnly,
      );
    }

    this.state.slice = settings.slice || this.state.slice;
    this.settings = {
      ...settings,
      sortKey: settings.sortKey ?? settings.defaultSort ?? this.settings.sortKey,
      sortDirection: settings.sortDirection ?? settings.defaultSortDirection ?? this.settings.sortDirection ?? 'desc',
      displayKey: settings.displayKey ?? settings.sortKey ?? settings.defaultSort ?? this.settings.displayKey,
      leagueUserSortActive: false,
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

  setPerspective(value) {
    this.state.perspective = value;
    this.state.selectedPos = [];
    this.state.selectedPosTypes = [];
    this.state.leagueAutoSkaterFilter = false;
    this.state.selectedLeagues = [];
    this.state.numericFilters = {};
    this.state.dirtyNumericFilters = {};
    this.fetchPayload();
  }

  setLeague(value) {
    this.state.selectedLeagues = value ? [value] : [];
    this.fetchPayload();
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
    this.fetchPayload();
  }

  setSlice(value) {
    this.state.slice = value;
    this.fetchPayload();
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
    }
    selects.appendChild(this.renderSelect(this.availableSeasons().map((season) => ({ label: season, value: season })), this.state.seasonId, (value) => this.setSeason(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
    if (this.availableLeagues().length > 0) {
      selects.appendChild(this.renderSelect([
        { label: 'All Leagues', value: '' },
        ...this.availableLeagues().map((league) => ({ label: league, value: league })),
      ], this.state.selectedLeagues[0] || '', (value) => this.setLeague(value), 'h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500'));
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
    const outer = createElement('div', 'px-4');
    const panel = createElement('div', 'relative z-50 overflow-visible rounded-lg bg-white/80 backdrop-blur ring-1 ring-gray-200 shadow-md mb-3 mt-2');
    const row = createElement('div', 'flex flex-wrap justify-between items-center gap-3 p-3');

    row.appendChild(this.renderSelect(this.perspectiveOptions(), this.state.perspective, (value) => this.setPerspective(value), 'h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500'));

    if (this.supportsDateRange()) {
      row.appendChild(this.renderSegmented([
        { label: 'Season', value: 'season' },
        { label: 'Range', value: 'range' },
      ], this.state.period, (value) => this.setPeriod(value)));
    }
    row.appendChild(this.renderSelect(this.availableSeasons().map((season) => ({ label: season, value: season })), this.state.seasonId, (value) => this.setSeason(value), 'h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500'));
    if (this.availableLeagues().length > 0) {
      row.appendChild(this.renderSelect([
        { label: 'All Leagues', value: '' },
        ...this.availableLeagues().map((league) => ({ label: league, value: league })),
      ], this.state.selectedLeagues[0] || '', (value) => this.setLeague(value), 'h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500'));
    }

    if (this.canSlice()) {
      row.appendChild(this.renderSegmented([
        { label: 'Total', value: 'total' },
        { label: 'P/GP', value: 'pgp' },
        { label: 'Per 60', value: 'p60' },
      ], this.state.slice, (value) => this.setSlice(value)));
    }

    row.appendChild(this.renderSelect(this.availableGameTypes().map((type) => ({ label: this.gameTypeLabel(type), value: type })), this.state.gameType, (value) => this.setGameType(value), 'h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500'));

    const positionRow = createElement('div', 'w-full flex items-center gap-2 pb-3');
    this.state.positionButtons.forEach((button) => {
      positionRow.appendChild(this.renderPositionButton(button, 'h-9 w-9 rounded-full text-[11px] font-semibold ring-1 ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100 transition-colors'));
    });
    row.appendChild(positionRow);

    panel.appendChild(row);
    outer.appendChild(panel);

    return outer;
  }

  renderSelect(options, selectedValue, onChange, className) {
    const wrapper = createElement('div', 'relative z-50 -mr-px grid grow grid-cols-1');
    const select = createElement('select', `${className} appearance-none`);
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

  renderSegmented(options, selectedValue, onChange) {
    const wrapper = createElement('div', 'inline-flex rounded-full ring-1 ring-gray-200 overflow-hidden');

    options.forEach((option) => {
      const button = createElement('button', option.value === selectedValue ? 'bg-indigo-600 text-white px-4 h-10 text-sm' : 'bg-white text-gray-700 hover:bg-gray-50 px-4 h-10 text-sm', option.label);
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
      selectedPos: [...this.state.selectedPos],
      selectedPosTypes: [...this.state.selectedPosTypes],
      leagueAutoSkaterFilter: this.state.leagueAutoSkaterFilter,
      teamAggregateStartersOnly: this.state.teamAggregateStartersOnly,
      onTeamAggregateStartersChange: this.onTeamAggregateStartersChange,
      onLeagueFantasyTeamFilterChange: this.onLeagueFantasyTeamFilterChange,
    };
    const activeHeadings = leagueRosterHeadings(
      this.prospectHeadings(this.activeHeadings(), renderSettings),
      renderSettings,
    );
    const rows = this.locallyFilteredRows();
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
