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
  let searchTerm = '';
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
      container.insertBefore(searchBar, listWrapper);
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
