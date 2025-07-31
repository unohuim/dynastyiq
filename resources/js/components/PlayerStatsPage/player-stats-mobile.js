import { teamBg, formatStatValue, sortData } from './player-stats-utils.js';
import { SearchBar } from './ui/SearchBarMobile.js'; // adjust path as needed

export function PlayerStatsMobile({ container, data, headings, settings }) {
    console.log('💻 Mobile render fired with sortKey:', settings.sortKey, 'and data length:', data.length);

    let searchTerm = '';
    let isInitialRender = true;

    container.innerHTML = '';

    // Show loading placeholder before data renders
    const loadingPlaceholder = document.createElement('div');
    loadingPlaceholder.className = 'py-10 text-center text-gray-500';
    loadingPlaceholder.textContent = 'Loading player stats…';
    container.appendChild(loadingPlaceholder);

    // Wrap list + overlay container
    const relativeWrapper = document.createElement('div');
    relativeWrapper.className = 'relative hidden'; // initially hidden
    container.appendChild(relativeWrapper);

    // Render the search bar into relativeWrapper, passing placeholder param
    SearchBar.html(relativeWrapper, { placeholder: 'Search players…' });

    // Add overlay and listWrapper inside relativeWrapper for fade and content
    // const overlay = document.createElement('div');
    // overlay.className =
    //   'absolute inset-0 bg-white opacity-0 pointer-events-none transition-opacity duration-700 ease-in-out';
    // relativeWrapper.appendChild(overlay);


    const listContainer = document.createElement('div');
    listContainer.className = 'relative';

    // Append overlay and listWrapperapper inside listContainer
    const overlay = document.createElement('div');
    overlay.className =
        'absolute inset-0 bg-white opacity-0 pointer-events-none transition-opacity duration-500 ease-in-out';
    listContainer.appendChild(overlay);


    const listWrapper = document.createElement('div');
    listWrapper.className = 'relative space-y-px';
    relativeWrapper.appendChild(listWrapper);

    /* ── Render helper ──────────────────────────────────────────── */
    const renderList = () => {
      overlay.style.opacity = '1';
      overlay.style.pointerEvents = 'auto';

      setTimeout(() => {
        const sortedData = sortData(data, settings.sortKey, settings.sortDirection);
        const filteredData = sortedData.filter(p =>
          p.name.toLowerCase().includes(searchTerm)
        );

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
          leftInner.className = 'flex items-center gap-1';

          const posTag = document.createElement('span');
          posTag.className = 'player-stats-pos-tag-mobile';
          posTag.textContent = player?.pos ?? '—';

          const name = document.createElement('span');
          name.className = 'player-stats-name-mobile';
          name.textContent = player.name;

          leftInner.appendChild(posTag);
          leftInner.appendChild(name);
          leftSide.appendChild(leftInner);

          const rightSide = document.createElement('div');
          rightSide.className = 'player-stats-right-side-mobile';
          const rightInner = document.createElement('div');
          rightInner.className = 'flex items-center gap-1';

          const statLabel = document.createElement('span');
          statLabel.className = 'player-stats-sorted-label-mobile';
          statLabel.textContent =
            headings.find(h => h.key === settings.sortKey)?.label || settings.sortKey;

          const statValue = document.createElement('span');
          statValue.className = 'player-stats-sorted-value-mobile';
          statValue.textContent = formatStatValue(
            settings.sortKey,
            player[settings.sortKey] ?? player.stats?.[settings.sortKey]
          );

          rightInner.appendChild(statLabel);
          rightInner.appendChild(statValue);
          rightSide.appendChild(rightInner);

          topRow.appendChild(leftSide);
          topRow.appendChild(rightSide);

          const bottomRow = document.createElement('div');
          bottomRow.className = 'player-stats-bottom-row-mobile';

          const statGroup = document.createElement('div');
          statGroup.className = 'player-stats-stat-group-mobile';

          Object.entries(player.stats || {}).forEach(([key, value]) => {
            if (key !== settings.sortKey && value !== undefined) {
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
            }
          });

          bottomRow.appendChild(statGroup);

          contentWrapper.appendChild(topRow);
          contentWrapper.appendChild(bottomRow);

          card.appendChild(teamDivWrapper);
          card.appendChild(contentWrapper);
          fragment.appendChild(card);
        });

        listWrapper.replaceChildren(fragment);

        if (isInitialRender) {
          isInitialRender = false;
          loadingPlaceholder.remove();
          relativeWrapper.classList.remove('hidden');
        }

        requestAnimationFrame(() => {
          overlay.style.opacity = '0';
          overlay.style.pointerEvents = 'none';
        });
      }, 100);
    };


    // Listen to searchInput custom event dispatched by SearchBar component
    relativeWrapper.addEventListener('searchInput', (e) => {
      searchTerm = e.detail.searchTerm;
      renderList();
    });

    /* Initial render */
    renderList();
}
