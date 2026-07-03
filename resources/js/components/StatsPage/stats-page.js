// stats-page.js
import { sortData } from './stats-utils.js';
import { renderStatsDesktop } from './stats-desktop.js';
import { StatsMobile } from './stats-mobile.js';

let statsComponent = null;

const mobileBreakpoint = () => Number(window.__statsMobileBreakpoint ?? 1024);
const isMobileViewport = () => window.innerWidth < mobileBreakpoint();

// keys that should NOT change the "top-right display" when selected
const STATIC_DISPLAY_KEYS = new Set([
  'player', 'name',
  'contract', 'contract_value', 'contract_last_year', 'contract_term', 'contract_length', 'contract_type'
]);

export class StatsPage {
  constructor({ container, data }) {
    this.container = container;
    this.payload = data;
    this.originalData = data.data || [];
    this.headings = data.headings || [];

    const settings = data.settings || {};
    this.settings = {
      ...settings,
      sortKey: settings.sortKey ?? settings.defaultSort ?? null,
      sortDirection: settings.sortDirection ?? settings.defaultSortDirection ?? 'desc',
      leagueUserSortActive: false,
    };
    // displayKey controls the top-right label/value; defaults to the initial sortKey
    this.settings.displayKey = settings.displayKey ?? this.settings.sortKey;

    this.isMobile = isMobileViewport();

    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        const nowMobile = isMobileViewport();
        if (nowMobile !== this.isMobile) {
          this.isMobile = nowMobile;
          this.render();
        }
      }, 150);
    });
  }

  updatePayload(newPayload) {
    this.payload = newPayload;
    this.originalData = newPayload.data || [];
    this.headings = newPayload.headings || [];

    const settings = newPayload.settings || {};
    this.settings = {
      ...settings,
      sortKey: settings.sortKey ?? settings.defaultSort ?? null,
      sortDirection: settings.sortDirection ?? settings.defaultSortDirection ?? 'desc',
      leagueUserSortActive: false,
    };
    this.settings.displayKey = settings.displayKey ?? this.settings.sortKey;

    this.render();
  }

  handleSortChange = ({ sortKey, sortDirection, leagueUserSortActive = true }) => {
    // keep current displayKey if sorting by a static-display key; otherwise follow sortKey
    const nextDisplayKey = STATIC_DISPLAY_KEYS.has(String(sortKey)) ? this.settings.displayKey : sortKey;

    this.settings.sortKey = sortKey;
    this.settings.sortDirection = sortDirection;
    this.settings.leagueUserSortActive = leagueUserSortActive;
    this.settings.displayKey = nextDisplayKey;

    const sorted = sortData(this.originalData, sortKey, sortDirection);
    const isMobile = isMobileViewport();

    if (isMobile) {
      StatsMobile({
        container: this.container,
        data: this.originalData,
        headings: this.headings,
        settings: this.settings,
        onSortChange: this.handleSortChange,
      });
    } else {
      renderStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
    }
  };

  render() {
    const sorted = sortData(this.originalData, this.settings.sortKey, this.settings.sortDirection);
    const isMobile = isMobileViewport();

    if (isMobile) {
      StatsMobile({
        container: this.container,
        data: this.originalData,
        headings: this.headings,
        settings: this.settings,
        onSortChange: this.handleSortChange,
      });
    } else {
      renderStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
    }
  }
}

function bootStatsPage(initialData = null) {
  const container = document.getElementById('stats-page');
  const data = initialData ?? window.__stats;

  if (container && data && !statsComponent) {
    statsComponent = new StatsPage({ container, data });
    statsComponent.render();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => bootStatsPage(), { once: true });
} else {
  bootStatsPage();
}

window.addEventListener('statsUpdated', (event) => {
  const updatedData = event.detail?.json ?? {};
  if (!updatedData) return;

  bootStatsPage(updatedData);
  statsComponent?.updatePayload(updatedData);
});
