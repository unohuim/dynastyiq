// stats-mobile.js
import { createApp } from 'vue';
import { UI } from './ui/UIComponent.js';
import PlayerCardList from '../../pages/Stats/Mobile/PlayerCardList.vue';

const MOBILE_IDENTITY_KEYS = new Set([
  'player',
  'name',
  'age',
  'team',
  'league',
  'pos',
  'position',
  'pos_type',
  'contract',
  'contract_value',
  'contract_value_num',
  'contract_last_year',
  'contract_last_year_num',
]);

const NEVER_DISPLAY_KEYS = new Set([
  'player',
  'name',
  'age',
  'team',
  'league',
  'pos',
  'position',
  'pos_type',
  'contract',
  'contract_value',
  'contract_value_num',
  'contract_last_year',
  'contract_last_year_num',
  'contract_term',
  'contract_length',
  'contract_type',
]);

let mobileEscapeHandler = null;
const containerListeners = new WeakMap();
const containerApps = new WeakMap();

const mobileMetricKeys = (headings) => (Array.isArray(headings) ? headings : [])
  .map((heading) => String(heading?.key ?? ''))
  .filter((key) => key && !MOBILE_IDENTITY_KEYS.has(key));

const firstMobileMetricKey = (headings, fallback = 'gp') => mobileMetricKeys(headings)[0] || fallback;

const headingLabel = (headings, key) => (
  (Array.isArray(headings) ? headings : []).find((heading) => heading?.key === key)?.label || key
);

function ensureDisplayKey(settings, headings) {
  if (
    !settings.displayKey ||
    NEVER_DISPLAY_KEYS.has(String(settings.displayKey)) ||
    NEVER_DISPLAY_KEYS.has(String(settings.sortKey))
  ) {
    settings.displayKey = firstMobileMetricKey(headings, 'gp');
  }
}

function emptyState(message) {
  const node = document.createElement('div');
  node.className = 'px-4 py-6 text-center text-sm text-gray-500';
  node.textContent = message;

  return node;
}

function getOrCreateElement(id) {
  const found = document.getElementById(id);

  if (found) {
    return found;
  }

  const node = document.createElement('div');
  node.id = id;

  return node;
}

export function StatsMobile({ container, data, headings, settings, onSortChange }) {
  let searchTerm = settings.dashboardTable ? String(settings.mobileSearchTerm ?? '') : '';
  const rows = Array.isArray(data) ? data : [];

  ensureDisplayKey(settings, headings);

  const previous = containerListeners.get(container);
  if (previous) {
    container.removeEventListener('searchInputEvent', previous.search);
    container.removeEventListener('ui:open-sort-sheet', previous.openSort);
  }

  containerApps.get(container)?.unmount();
  containerApps.delete(container);
  container.innerHTML = '';

  const listWrapper = document.createElement('div');
  listWrapper.className = 'players-list-mobile';
  container.appendChild(listWrapper);

  const renderList = () => {
    try {
      containerApps.get(container)?.unmount();
      listWrapper.innerHTML = '';

      const app = createApp(PlayerCardList, {
        rows,
        headings,
        settings,
        searchTerm,
        onSortChange,
      });

      app.mount(listWrapper);
      containerApps.set(container, app);
    } catch (error) {
      console.error('[stats-mobile] render failed', error);
      listWrapper.replaceChildren(emptyState('Unable to render this stats view.'));
    }
  };

  renderList();

  try {
    const searchBar = UI.SearchBar(container);
    if (searchBar) {
      if (settings.dashboardTable) {
        searchBar.className = 'border-b border-[#143144] bg-[#03131f] px-2 py-2';
        const inner = searchBar.querySelector('.searchbar-innerWrapper-mobile');
        inner.className = 'flex min-w-0 items-center gap-2';
        const field = searchBar.querySelector('.searchbar-gridWrapper-mobile');
        field.className = 'relative min-w-0 flex-1';
        const input = searchBar.querySelector('input');
        input.className = 'h-8 w-full rounded-md border border-[#244258] bg-[#071d2c] py-0 pl-7 pr-2 text-[11px] text-slate-100 placeholder:text-slate-400 focus:border-sky-400 focus:ring-1 focus:ring-sky-400';
        input.placeholder = settings.dashboardProspects ? 'Search prospects…' : settings.dashboardGoalies ? 'Search goalies…' : 'Search skaters…';
        input.setAttribute('aria-label', input.placeholder.replace('…', ''));
        input.value = searchTerm;
        field.insertAdjacentHTML('afterbegin', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="pointer-events-none absolute left-2 top-2 h-4 w-4 text-slate-400"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>');
        const sort = inner.querySelector('button');
        sort.className = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-[#244258] bg-[#071d2c] text-slate-300 focus-visible:ring-2 focus-visible:ring-sky-400';
        sort.querySelector('svg').setAttribute('class', 'h-4 w-4');
        const filters = document.createElement('button');
        filters.type = 'button';
        filters.className = sort.className;
        filters.setAttribute('aria-label', 'Stats filters');
        filters.setAttribute('aria-controls', 'mobile-stats-filter-drawer');
        filters.setAttribute('aria-expanded', String(settings.mobileFiltersOpen === true));
        filters.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="h-4 w-4"><path d="M3 6h18M3 12h18M3 18h18M8 3v6M16 9v6M10 15v6"/></svg>';
        filters.addEventListener('click', () => settings.onOpenMobileFilters?.());
        inner.prepend(filters);
      }
      container.insertBefore(searchBar, listWrapper);
      if (settings.dashboardTable && !settings.dashboardGoalies) {
        const positions = document.createElement('div');
        positions.className = 'flex gap-1.5 overflow-x-auto border-b border-[#143144] bg-[#03131f] px-2 pb-2';
        positions.setAttribute('role', 'group');
        positions.setAttribute('aria-label', 'Player positions');
        const addPosition = (value, label, active) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'h-7 min-w-11 shrink-0 rounded-full border px-3 text-[10px] font-medium transition-colors duration-150 motion-reduce:transition-none focus-visible:ring-2 focus-visible:ring-sky-400 '
            + (active ? 'border-blue-500 bg-blue-600 text-white' : 'border-[#143144] bg-[#071d2c] text-slate-300');
          button.textContent = label;
          button.setAttribute('aria-pressed', String(active));
          button.addEventListener('click', () => value ? settings.onPositionToggle?.(value) : settings.onClearMobilePositions?.());
          positions.appendChild(button);
        };
        const selected = [...(settings.selectedPos ?? []), ...(settings.selectedPosTypes ?? [])];
        addPosition('', 'All', selected.length === 0);
        for (const position of settings.positionButtons ?? []) {
          if (position === 'All' || (!settings.dashboardProspects && ['F', 'G'].includes(position))) continue;
          if (settings.dashboardProspects && !['F', 'D', 'G'].includes(position)) continue;
          const labels = settings.dashboardProspects ? { F: 'Forwards', D: 'Defense', G: 'Goalies' } : { LW: 'L', RW: 'R' };
          addPosition(position, labels[position] || position, selected.includes(position));
        }
        container.insertBefore(positions, listWrapper);
      }
    }
  } catch (error) {
    console.error('[stats-mobile] search bar setup failed', error);
  }

  const closeSheet = () => {
    const overlay = document.getElementById('mobile-sort-overlay');
    const sheet = document.getElementById('mobile-sort-sheet');

    sheet?.classList.remove('translate-y-0');
    sheet?.classList.add('translate-y-full');
    overlay?.classList.remove('opacity-100');
    overlay?.classList.add('opacity-0', 'pointer-events-none');
    document.body.style.overflow = '';
  };

  const setupSortSheet = () => {
    const overlay = getOrCreateElement('mobile-sort-overlay');
    overlay.className = 'fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity duration-200 z-[60]';
    overlay.onclick = closeSheet;

    const sheet = getOrCreateElement('mobile-sort-sheet');
    sheet.className = [
      'fixed inset-x-0 bottom-0 transform translate-y-full transition-transform duration-300 ease-out',
      'bg-white rounded-t-2xl shadow-2xl z-[70] pointer-events-auto will-change-[transform]',
    ].join(' ');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML = `
      <div class="flex h-full flex-col">
        <div class="sticky top-0 z-10 bg-white/95 backdrop-blur border-b">
          <div class="p-4">
            <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-gray-300"></div>
            <div class="flex items-center justify-between">
              <h3 class="text-base font-semibold text-gray-900">Sort by</h3>
              <button id="mobile-sort-sheet-close" class="p-2 rounded-lg hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" aria-label="Close">
                <span class="block text-xl leading-none">&times;</span>
              </button>
            </div>
          </div>
        </div>
        <div class="p-4">
          <div id="sort-badges" class="grid grid-cols-2 gap-2"></div>
        </div>
      </div>
    `;

    if (!overlay.isConnected) document.body.appendChild(overlay);
    if (!sheet.isConnected) document.body.appendChild(sheet);

    const badgesWrap = sheet.querySelector('#sort-badges');
    const closeButton = sheet.querySelector('#mobile-sort-sheet-close');
    if (closeButton) {
      closeButton.onclick = closeSheet;
    }

    headings.forEach((heading) => {
      const key = String(heading?.key ?? '');
      const label = String(heading?.label ?? heading?.key ?? '');
      if (!key || ['team', 'league', 'pos', 'position', 'pos_type'].includes(key.toLowerCase())) return;

      const active = key === settings.sortKey;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = [
        'px-3 py-1.5 rounded-full border text-sm',
        active ? 'bg-indigo-600 text-white border-gray-900' : 'bg-white text-gray-800 border-indigo-100',
        'hover:bg-indigo-100 hover:border-indigo-200 transition-colors',
      ].join(' ');
      button.textContent = `${label}${active ? (settings.sortDirection === 'asc' ? ' ▲' : ' ▼') : ''}`;
      button.onclick = () => {
        if (settings.sortKey === key) {
          settings.sortDirection = settings.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
          settings.sortKey = key;
          settings.displayKey = NEVER_DISPLAY_KEYS.has(key) ? settings.displayKey : key;
        }
        onSortChange?.({ sortKey: settings.sortKey, sortDirection: settings.sortDirection });
        closeSheet();
      };
      badgesWrap?.appendChild(button);
    });

    return () => {
      document.body.style.overflow = 'hidden';
      overlay.classList.remove('pointer-events-none', 'opacity-0');
      overlay.classList.add('opacity-100');
      sheet.classList.remove('translate-y-full');
      sheet.classList.add('translate-y-0');
    };
  };

  let openSortSheet = () => {};
  try {
    openSortSheet = setupSortSheet();
  } catch (error) {
    console.error('[stats-mobile] sort sheet setup failed', error);
  }

  const searchHandler = (event) => {
    searchTerm = String(event.detail?.searchTerm ?? '');
    if (settings.dashboardTable) settings.onMobileSearchChange?.(searchTerm);
    renderList();
  };
  const openSortHandler = () => openSortSheet();

  container.addEventListener('searchInputEvent', searchHandler);
  container.addEventListener('ui:open-sort-sheet', openSortHandler);
  containerListeners.set(container, {
    search: searchHandler,
    openSort: openSortHandler,
  });

  if (mobileEscapeHandler) {
    document.removeEventListener('keydown', mobileEscapeHandler);
  }
  mobileEscapeHandler = (event) => {
    if (event.key === 'Escape') closeSheet();
  };
  document.addEventListener('keydown', mobileEscapeHandler);
}
