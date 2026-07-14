// stats-mobile.js
import { teamBg, formatStatValue, groupRowsByProspectPosition, isLeagueProspectMode, sortData, statValueForKey } from './stats-utils.js';
import { UI } from './ui/UIComponent.js';

const SORT_ALIASES = {
  contract_value_num: ['contract_value_num', 'contract_value'],
  contract_last_year_num: ['contract_last_year_num', 'contract_last_year'],
  gp: ['gp', 'games_played'],
};

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

const aliasSet = (key) => new Set(SORT_ALIASES[String(key)] || [String(key)]);

const mobileMetricKeys = (headings) => (Array.isArray(headings) ? headings : [])
  .map((heading) => String(heading?.key ?? ''))
  .filter((key) => key && !MOBILE_IDENTITY_KEYS.has(key));

const firstMobileMetricKey = (headings, fallback = 'gp') => mobileMetricKeys(headings)[0] || fallback;

const displayValue = (row, key) => statValueForKey(row, key);

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

const playerInitials = (name = '') => String(name)
  .trim()
  .split(/\s+/)
  .slice(0, 2)
  .map((part) => part.charAt(0).toUpperCase())
  .join('') || '?';

function buildMobileAvatar(player) {
  const name = player?.name ?? 'Unknown';
  const avatarUrl = player?.avatar_url || player?.head_shot_url;
  const wrap = document.createElement('span');
  wrap.className = 'inline-flex h-7 w-7 shrink-0 items-center justify-center self-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-1 ring-gray-200';

  if (!avatarUrl) {
    wrap.textContent = playerInitials(name);
    return wrap;
  }

  const img = document.createElement('img');
  img.src = avatarUrl;
  img.alt = '';
  img.loading = 'lazy';
  img.className = 'h-7 w-7 rounded-full object-cover';
  img.addEventListener('error', () => {
    img.remove();
    wrap.textContent = playerInitials(name);
  });
  wrap.appendChild(img);

  return wrap;
}

function buildMobilePosShape(raw, rawType) {
  const value = displayPosition(raw);
  const shapeType = displayPosition(rawType);
  const wrap = document.createElement('span');
  wrap.className = 'inline-flex h-6 w-6 shrink-0 items-center justify-center self-center';

  const marker = document.createElement('span');
  marker.className = 'inline-flex h-6 w-6 items-center justify-center text-[9px] font-bold leading-none text-gray-600';

  if (shapeType === 'F') {
    marker.classList.add('rounded-[3px]', 'border');
    marker.style.borderColor = '#7CCCF2';
    marker.textContent = value || 'F';
  } else if (shapeType === 'D') {
    marker.classList.add('rounded-[3px]', 'border');
    marker.style.borderColor = '#FAE919';
    marker.textContent = value || 'D';
  } else if (shapeType === 'G') {
    marker.classList.add('rounded-full', 'border-2');
    marker.style.borderColor = '#fecaca';
    marker.textContent = value || 'G';
  } else {
    marker.classList.add('rounded-[3px]', 'border-2', 'border-gray-200');
    marker.textContent = value || '-';
  }

  wrap.appendChild(marker);
  return wrap;
}

function displayPosition(raw) {
  const first = String(raw ?? '').split(/[,\s/]+/).find(Boolean)?.trim().toUpperCase() || '';

  return first;
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
  const isDefaultProspectSort = () => isLeagueProspectMode(settings) && settings.leagueUserSortActive !== true;

  ensureDisplayKey(settings, headings);

  const previous = containerListeners.get(container);
  if (previous) {
    container.removeEventListener('searchInputEvent', previous.search);
    container.removeEventListener('ui:open-sort-sheet', previous.openSort);
  }

  container.innerHTML = '';

  const listWrapper = document.createElement('div');
  listWrapper.className = 'players-list-mobile';
  container.appendChild(listWrapper);

  const renderList = () => {
    try {
      const sortedData = sortData(rows, settings.sortKey, settings.sortDirection);
      const filteredData = sortedData.filter((row) => String(row?.name ?? '').toLowerCase().includes(searchTerm));
      const fragment = document.createDocumentFragment();

      if (filteredData.length === 0) {
        fragment.appendChild(emptyState('No players match the current view.'));
      }

      const appendPlayerCard = (player) => {
        const card = document.createElement('div');
        card.className = 'player-stats-card-mobile';

        const teamDivWrapper = document.createElement('div');
        teamDivWrapper.className = 'player-stats-team-strip-mobile';
        teamDivWrapper.style.background = teamBg(player?.team);

        const teamDiv = document.createElement('div');
        teamDiv.className = 'player-stats-team-text-mobile';
        teamDiv.textContent = player?.team ?? '-';
        teamDivWrapper.appendChild(teamDiv);

        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'player-stats-content-mobile';

        const iconRail = document.createElement('span');
        iconRail.className = 'player-stats-icon-rail-mobile';
        iconRail.appendChild(buildMobilePosShape(player?.pos ?? player?.position ?? player?.pos_type, player?.pos_type));
        iconRail.appendChild(buildMobileAvatar(player));
        contentWrapper.appendChild(iconRail);

        const topRow = document.createElement('div');
        topRow.className = 'player-stats-top-row-mobile';

        const leftSide = document.createElement('div');
        leftSide.className = 'min-w-0 flex flex-1 self-stretch';

        const leftInner = document.createElement('div');
        leftInner.className = 'player-stats-identity-mobile';

        const name = document.createElement('span');
        name.className = 'player-stats-name-mobile';
        name.textContent = player?.name ?? 'Unknown';

        const nameLine = document.createElement('span');
        nameLine.className = 'player-stats-name-line-mobile';
        nameLine.appendChild(name);

        const ageStat = document.createElement('div');
        ageStat.className = 'player-stats-age-mobile';
        ageStat.textContent = player?.age ? `Age ${player.age}` : '';

        const cap = document.createElement('span');
        cap.className = 'player-stats-aav-mobile';
        const rawCap = player?.contract_value;
        let millions = null;
        if (typeof rawCap === 'number') {
          millions = rawCap / 1e6;
        } else if (typeof rawCap === 'string') {
          const parsed = parseFloat(rawCap.replace(/[^0-9.]/g, ''));
          millions = Number.isFinite(parsed) ? (parsed <= 100 ? parsed : parsed / 1e6) : null;
        }
        const lastYear = String(player?.contract_last_year ?? '').trim();
        cap.textContent = `$${(millions ?? 0).toFixed(2)}M${lastYear ? ` | ${lastYear}` : ''}`;

        const meta = document.createElement('span');
        meta.className = 'player-stats-meta-mobile';
        meta.appendChild(ageStat);
        meta.appendChild(cap);

        const nameBlock = document.createElement('span');
        nameBlock.className = 'player-stats-name-stack-mobile';
        nameBlock.appendChild(nameLine);

        const detailLine = document.createElement('span');
        detailLine.className = 'player-stats-detail-line-mobile';

        const leagueName = formatStatValue('league', player?.league);
        if (leagueName) {
          const league = document.createElement('span');
          league.className = 'player-stats-league-mobile';
          league.textContent = leagueName;
          detailLine.appendChild(league);
        }
        detailLine.appendChild(meta);
        nameBlock.appendChild(detailLine);

        leftInner.appendChild(nameBlock);
        leftSide.appendChild(leftInner);

        const rightSide = document.createElement('div');
        rightSide.className = 'shrink-0 max-w-[5.5rem] overflow-hidden';

        const rightInner = document.createElement('div');
        rightInner.className = 'flex min-w-0 shrink-0 items-center gap-1';

        const displayKey = settings.displayKey || settings.sortKey || firstMobileMetricKey(headings, 'gp');

        const statLabel = document.createElement('span');
        statLabel.className = 'player-stats-sorted-label-mobile truncate';
        statLabel.textContent = headingLabel(headings, displayKey);

        const statValue = document.createElement('span');
        statValue.className = 'player-stats-sorted-value-mobile shrink-0';
        statValue.textContent = formatStatValue(displayKey, displayValue(player, displayKey));

        rightInner.appendChild(statLabel);
        rightInner.appendChild(statValue);
        rightSide.appendChild(rightInner);

        topRow.appendChild(leftSide);
        topRow.appendChild(rightSide);

        const bottomRow = document.createElement('div');
        bottomRow.className = 'player-stats-bottom-row-mobile';

        const statGroup = document.createElement('div');
        statGroup.className = 'player-stats-stat-group-mobile';

        let statKeys = mobileMetricKeys(headings).filter((key) => statValueForKey(player, key) !== undefined);

        if (statValueForKey(player, 'gp') !== undefined && String(settings.sortKey) !== 'gp') {
          statKeys = ['gp', ...statKeys.filter((key) => key !== 'gp')];
        }

        const hiddenAliases = aliasSet(settings.sortKey);
        const selectedKeys = [];

        statKeys.forEach((key) => {
          if (!key || selectedKeys.includes(key)) return;
          if (hiddenAliases.has(String(key))) return;
          if (String(key) === String(settings.sortKey)) return;
          selectedKeys.push(key);
        });

        selectedKeys.slice(0, 6).forEach((key) => {
          const value = statValueForKey(player, key);
          if (value === undefined) return;

          const stat = document.createElement('div');
          stat.className = 'player-stats-stat-mobile';

          const statKey = document.createElement('span');
          statKey.className = 'player-stats-stat-key-mobile';
          statKey.textContent = headingLabel(headings, key);

          const statVal = document.createElement('span');
          statVal.className = 'player-stats-stat-val-mobile';
          statVal.textContent = formatStatValue(key, value);

          stat.appendChild(statKey);
          stat.appendChild(statVal);
          statGroup.appendChild(stat);
        });

        bottomRow.appendChild(statGroup);
        contentWrapper.appendChild(topRow);
        contentWrapper.appendChild(bottomRow);
        card.appendChild(teamDivWrapper);
        card.appendChild(contentWrapper);
        fragment.appendChild(card);
      };

      if (isDefaultProspectSort()) {
        groupRowsByProspectPosition(filteredData).forEach((group) => {
          group.rows.forEach(appendPlayerCard);
        });
      } else {
        filteredData.forEach(appendPlayerCard);
      }

      listWrapper.replaceChildren(fragment);
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
