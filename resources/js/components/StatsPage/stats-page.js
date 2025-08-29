// stats-page.js
import { sortData } from './stats-utils.js';
import { renderStatsDesktop } from './stats-desktop.js';
import { StatsMobile } from './stats-mobile.js';

let statsComponent = null;

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
    };
    // displayKey controls the top-right label/value; defaults to the initial sortKey
    this.settings.displayKey = settings.displayKey ?? this.settings.sortKey;

    this.isMobile = window.innerWidth <= 639;

    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        const nowMobile = window.innerWidth <= 639;
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
    };
    this.settings.displayKey = settings.displayKey ?? this.settings.sortKey;

    this.render();
  }

  handleSortChange = ({ sortKey, sortDirection }) => {
    // keep current displayKey if sorting by a static-display key; otherwise follow sortKey
    const nextDisplayKey = STATIC_DISPLAY_KEYS.has(String(sortKey)) ? this.settings.displayKey : sortKey;

    this.settings.sortKey = sortKey;
    this.settings.sortDirection = sortDirection;
    this.settings.displayKey = nextDisplayKey;

    const sorted = sortData(this.originalData, sortKey, sortDirection);
    const isMobile = window.innerWidth <= 639;

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
    const isMobile = window.innerWidth <= 639;

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

document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('stats-page');
  const data = window.__stats;

  if (container && data) {
    statsComponent = new StatsPage({ container, data });
    statsComponent.render();
  }
});

window.addEventListener('statsUpdated', (event) => {
  const updatedData = event.detail?.json ?? {};
  if (!updatedData) return;

  if (statsComponent) {
    statsComponent.updatePayload(updatedData);
  }
});
