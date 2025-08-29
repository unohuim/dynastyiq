// stats-mobile.js
import { teamBg, formatStatValue, sortData } from './stats-utils.js';
import { UI } from './ui/UIComponent.js';

// Keys that should be considered “the same stat” for hiding in the bottom row
const SORT_ALIASES = {
  contract_value_num: ['contract_value_num', 'contract_value'],         // AAV
  contract_last_year_num: ['contract_last_year_num', 'contract_last_year'],
  gp: ['gp', 'games_played'],
};

const aliasSet = (k) => new Set(SORT_ALIASES[String(k)] || [String(k)]);




export function StatsMobile({ container, data, headings, settings, onSortChange }) {
  let searchTerm = '';
  let isInitialRender = true;

  container.innerHTML = '';
  UI.SearchBar(container);

  const listWrapper = document.createElement('div');
  listWrapper.className = 'players-list-mobile';
  container.appendChild(listWrapper);

  // Bottom drawer (closed by default)
  const overlay = getOverlay();
  overlay.className = [
    'fixed inset-0 bg-black/40',
    'opacity-0',
    'pointer-events-none',                 // <— start non-interactive
    'transition-opacity duration-200 z-[60]'
  ].join(' ');
  overlay.addEventListener('click', closeSheet);

  
  const sheet = getSheet();
  sheet.className = [
    'fixed inset-x-0 bottom-0',
    'transform',
    'translate-y-full',                    // <— start closed
    'transition-transform duration-300 ease-out',
    'bg-white rounded-t-2xl shadow-2xl z-[70] pointer-events-auto will-change-[transform]'
  ].join(' ');
  sheet.setAttribute('role', 'dialog');
  sheet.setAttribute('aria-modal', 'true');

  // Mount order matters: overlay first, then sheet
  document.body.appendChild(overlay);
  document.body.appendChild(sheet);



  sheet.innerHTML = `
  <div class="flex h-full flex-col">
    <!-- Sticky header -->
    <div class="sticky top-0 z-10 bg-white/95 backdrop-blur border-b">
      <div class="p-4">
        <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-gray-300"></div>
        <div class="flex items-center justify-between">
          <h3 class="text-base font-semibold text-gray-900">Sort by</h3>
          <button id="sheet-close"
                  class="p-2 rounded-lg hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                  aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd"/>
            </svg>
          </button>
        </div>
        <p class="mt-1 text-xs text-gray-500">Tap a field to sort • tap again to flip direction</p>
      </div>
    </div>

    <!-- Chips grid -->
    <div class="p-4">
      <div id="sort-badges" class="grid grid-cols-2 gap-2"></div>
    </div>
  </div>
`;


  

  const closeBtn   = sheet.querySelector('#sheet-close');
  const badgesWrap = sheet.querySelector('#sort-badges');

  //const btnClose  = sheet.querySelector('#sheet-close');
  const badgesEl  = sheet.querySelector('#sort-badges');


  // Returns the singleton overlay element.
  // If it doesn't exist, creates a detached <div id="mobile-sort-overlay"> and returns it.
  // No side effects beyond setting the id on a new element.
  function getOverlay() {
    const id = 'mobile-sort-overlay';
    const found = document.getElementById(id);
    if (found) return found;
    const el = document.createElement('div');
    el.id = id;
    return el;
  }


  // Returns the singleton sheet element.
  // If it doesn't exist, creates a detached <div id="mobile-sort-sheet"> and returns it.
  // No side effects beyond setting the id on a new element.
  function getSheet() {
    const id = 'mobile-sort-sheet';
    const found = document.getElementById(id);
    if (found) return found;
    const el = document.createElement('div');
    el.id = id;
    return el;
  }


  // Open / close helpers
  function openSheet() {
    document.body.style.overflow = 'hidden';
    overlay.classList.remove('pointer-events-none', 'opacity-0');
    overlay.classList.add('opacity-100');
    sheet.classList.remove('translate-y-full');
    sheet.classList.add('translate-y-0');
  }

  function closeSheet() {
    sheet.classList.remove('translate-y-0');
    sheet.classList.add('translate-y-full');
    overlay.classList.remove('opacity-100');
    overlay.classList.add('opacity-0', 'pointer-events-none');
    sheet.addEventListener('transitionend', () => {
      document.body.style.overflow = '';
    }, { once: true });
  }

  closeSheet();


  // Bind to your sort button (second .searchbar-button-mobile)
  const searchbarRoot = document.getElementById('searchbar-mobile');
  let externalSortBtn = null;
  if (searchbarRoot) {
    const btns = searchbarRoot.querySelectorAll('.searchbar-button-mobile');
    externalSortBtn = btns[1] || btns[btns.length - 1] || null;
  }
  if (externalSortBtn) externalSortBtn.addEventListener('click', openSheet);


  closeBtn.addEventListener('click', closeSheet);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSheet(); });


  function syncDirectionButtons() {
    const d = (settings.sortDirection || 'desc').toLowerCase();
    const active = ['bg-gray-900','text-white','border-gray-900'];
    const idle   = ['bg-white','text-gray-800','border-gray-300'];
  }


  function renderBadges() {
    badgesWrap.innerHTML = '';
    const frag = document.createDocumentFragment();
    const BLOCKED_KEYS   = new Set(['team','pos','position','pos_type']);
    const BLOCKED_LABELS = new Set(['team','pos','position','type']);

    headings.forEach(h => {
      const key   = String(h?.key ?? '').toLowerCase();
      const label = String(h?.label ?? h?.key ?? '').trim().toLowerCase();
      if (BLOCKED_KEYS.has(key) || BLOCKED_LABELS.has(label)) return;

      const active = h.key === settings.sortKey;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = [
        'px-3 py-1.5 rounded-full border text-sm',
        active ? 'bg-indigo-600 text-white border-gray-900' : 'bg-white text-gray-800 border-indigo-100',
        'hover:bg-indigo-100 hover:border-indigo-200 transition-colors'
      ].join(' ');
      const arrow = active ? (settings.sortDirection === 'asc' ? ' ▲' : ' ▼') : '';
      btn.textContent = `${h.label || h.key}${arrow}`;
      btn.addEventListener('click', () => {
        if (settings.sortKey === h.key) {
          settings.sortDirection = settings.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
          const STATIC_DISPLAY_KEYS = new Set([
            'player','name','contract','contract_value','contract_last_year','contract_term','contract_length','contract_type', 'age'
          ]);
          const nextDisplayKey = STATIC_DISPLAY_KEYS.has(String(h.key)) ? settings.displayKey : h.key;
          settings.sortKey = h.key;
          settings.displayKey = nextDisplayKey;
        }
        onSortChange?.({ sortKey: settings.sortKey, sortDirection: settings.sortDirection });
        renderBadges();
        renderList();
      });
      frag.appendChild(btn);
    });

    badgesWrap.appendChild(frag);
  }



  // List rendering (uses settings.displayKey for the top-right label/value)
  const renderList = () => {
    const sortedData = sortData(data, settings.sortKey, settings.sortDirection);
    const filteredData = sortedData.filter(p => p.name.toLowerCase().includes(searchTerm));

    const fragment = document.createDocumentFragment();

    filteredData.forEach(player => {
      const card = document.createElement('div');
      card.className = 'player-stats-card-mobile';

      const teamDivWrapper = document.createElement('div');
      teamDivWrapper.className = 'player-stats-team-strip-mobile';
      const teamDiv = document.createElement('div');
      teamDiv.className = 'player-stats-team-text-mobile';
      teamDiv.textContent = player?.team ?? '—';
      teamDivWrapper.style.background = teamBg(player?.team);
      teamDivWrapper.appendChild(teamDiv);

      const contentWrapper = document.createElement('div');
      contentWrapper.className = 'player-stats-content-mobile';

      const topRow = document.createElement('div');
      topRow.className = 'player-stats-top-row-mobile';

      const leftSide = document.createElement('div');
      leftSide.className = 'player-stats-left-side-mobile';

      const leftInner = document.createElement('div');
      leftInner.className = 'flex w-full justify-between items-center gap-1';

      const posTag = document.createElement('span');
      posTag.className = 'player-stats-pos-tag-mobile';
      posTag.textContent = (player?.pos ?? player?.pos_type ?? '').toString() || '—';

      const name = document.createElement('span');
      name.className = 'player-stats-name-mobile';
      name.textContent = player.name;

      const middleInner = document.createElement('div');
      middleInner.className = 'flex w-20';


      //age
      const ageStat = document.createElement('div');
      ageStat.className = 'player-stats-stat-key-mobile';

      const ageKey = document.createElement('span');
      ageKey.className = 'ml-1 text-xxs player-stats-stat-key-mobile';
      ageKey.textContent = 'Age ';

      const ageVal = document.createElement('span');
      ageVal.className = 'text-xxs player-stats-stat-val-mobile';
      ageVal.textContent = player?.age;

      ageStat.appendChild(ageKey);
      ageStat.appendChild(ageVal);


      const aav = document.createElement('span');
      aav.className = 'player-stats-aav-mobile';
      const raw = player?.contract_value;
      let millions = null;
      if (typeof raw === 'number') {
        millions = raw / 1e6;
      } else if (typeof raw === 'string') {
        const n = parseFloat(raw.replace(/[^0-9.]/g, ''));
        millions = isFinite(n) ? (n <= 100 ? n : n / 1e6) : null;
      }
      const millionsStr = (millions ?? 0).toFixed(1);
      const lastYr = (player?.contract_last_year ?? '').toString().trim();
      aav.textContent = `$${millionsStr}M${lastYr ? ` | ${lastYr}` : ''}`;

      leftInner.appendChild(posTag);
      leftInner.appendChild(name);
      leftInner.appendChild(ageStat);
      leftInner.appendChild(aav);
      leftSide.appendChild(leftInner);

      const rightSide = document.createElement('div');
      rightSide.className = 'player-stats-right-side-mobile';
      const rightInner = document.createElement('div');
      rightInner.className = 'flex items-center gap-1';

      // Use displayKey here (NOT sortKey)
      const displayKey = settings.displayKey || settings.sortKey;
      const statLabel = document.createElement('span');
      statLabel.className = 'player-stats-sorted-label-mobile';
      statLabel.textContent =
        headings.find(h => h.key === displayKey)?.label || displayKey;

      const statValue = document.createElement('span');
      statValue.className = 'player-stats-sorted-value-mobile';
      statValue.textContent = formatStatValue(
        displayKey,
        player[displayKey] ?? player.stats?.[displayKey]
      );

      rightInner.appendChild(statLabel);
      rightInner.appendChild(statValue);
      rightSide.appendChild(rightInner);

      topRow.appendChild(leftSide);
      topRow.appendChild(middleInner);
      topRow.appendChild(rightSide);

      const bottomRow = document.createElement('div');
      bottomRow.className = 'player-stats-bottom-row-mobile';

      const statGroup = document.createElement('div');
      statGroup.className = 'player-stats-stat-group-mobile';

      

      // --- Bottom row: GP pinned first unless sorting by GP ---
      const statsObj = player.stats || {};
      let statKeys = Object.keys(statsObj);

      // If gp isn't in stats{}, we still want to show it (from identity: player.gp)
      const hasGpInStats = statKeys.includes('gp');
      const hasGpIdentity = !hasGpInStats && (player.gp !== undefined && player.gp !== null);

      // Build the keys list with gp control
      if (hasGpInStats || hasGpIdentity) {
        // remove any gp already in stats list; we'll control its placement
        statKeys = statKeys.filter(k => k !== 'gp');
        // put gp first when not the active sort
        if (String(settings.sortKey) !== 'gp') {
          statKeys.unshift('gp');
        }
      }

      // Value getter with identity fallback for gp
      const getStatValueForKey = (key) => {
        if (key === 'gp') return (statsObj.gp ?? player.gp);
        return statsObj[key];
      };


      const hideIfAliases = aliasSet(settings.sortKey);

      statKeys.forEach((key) => {
        const value = getStatValueForKey(key);
        if (value === undefined) return;
        if (hideIfAliases.has(String(key))) return;
        if (String(key) === String(settings.sortKey)) return; // hide active sort stat

        const stat = document.createElement('div');
        stat.className = 'player-stats-stat-mobile';

        const statKey = document.createElement('span');
        statKey.className = 'player-stats-stat-key-mobile';
        statKey.textContent = headings.find(h => h.key === key)?.label || key;

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
    });




    listWrapper.replaceChildren(fragment);

    if (isInitialRender) isInitialRender = false;
  };



  container.addEventListener('searchInputEvent', (e) => {
    searchTerm = e.detail.searchTerm;
    renderList();
  });


  container.addEventListener('ui:open-sort-sheet', openSheet);


  const NEVER_DISPLAY_KEYS = new Set([
    'player','name',
    'age', 'team', 'pos', 'pos_type',                                 // ⬅️ add this
    'contract','contract_value','contract_value_num',
    'contract_last_year','contract_last_year_num',
    'contract_term','contract_length','contract_type'
  ]);

  //make sure sortable id fields don't show up top-right
  if (
    !settings.displayKey ||
    NEVER_DISPLAY_KEYS.has(String(settings.displayKey)) ||
    NEVER_DISPLAY_KEYS.has(String(settings.sortKey))
  ) {
    //const firstMetric = (headings.find(h => !NEVER_DISPLAY_KEYS.has(String(h.key))) || {}).key;
    //settings.displayKey = firstMetric || 'pts';
    settings.displayKey = ' ';
  }


  renderBadges();
  syncDirectionButtons();
  renderList();
}
