import { teamBg, formatStatValue, sortData } from './player-stats-utils.js';

export function PlayerStatsMobile({ container, data, headings, settings }) {
    console.log('💻 Mobile render fired with sortKey:', settings.sortKey, 'and data length:', data.length);
    container.innerHTML = '';

    const listWrapper = document.createElement('div');
    listWrapper.className = 'relative space-y-px';

    const sortedData = sortData(data, settings.sortKey, settings.sortDirection);

    sortedData.forEach((player) => {
        const card = document.createElement('div');
        card.className = 'player-stats-card-mobile';

        // Team strip
        const teamDivWrapper = document.createElement('div');
        teamDivWrapper.className = 'player-stats-team-strip-mobile';
        const teamDiv = document.createElement('div');
        teamDiv.className = 'player-stats-team-text-mobile';
        teamDiv.textContent = player?.team ?? '—';
        teamDivWrapper.style.background = teamBg(player?.team);
        teamDivWrapper.appendChild(teamDiv);
        // console.log(player);

        // Content area
        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'player-stats-content-mobile';

        // Top row: Name + POS, Sorted Stat
        const topRow = document.createElement('div');
        topRow.className = 'player-stats-top-row-mobile';

        // Left: POS + Name
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
        leftSide.appendChild(leftInner);
        leftInner.appendChild(posTag);
        leftInner.appendChild(name);

        // Right: Sorted Stat
        const rightSide = document.createElement('div');
        rightSide.className = 'player-stats-right-side-mobile';
        const rightInner = document.createElement('div');
        rightInner.className = 'flex items-center gap-1';
        const statLabel = document.createElement('span');
        statLabel.className = 'player-stats-sorted-label-mobile';
        statLabel.textContent = headings.find(h => h.key === settings.sortKey)?.label || settings.sortKey;
        const statValue = document.createElement('span');
        statValue.className = 'player-stats-sorted-value-mobile';
        statValue.textContent = formatStatValue(
            settings.sortKey,
            player[settings.sortKey] ?? player.stats?.[settings.sortKey]
        );

        rightSide.appendChild(rightInner);
        rightInner.appendChild(statLabel);
        rightInner.appendChild(statValue);

        topRow.appendChild(leftSide);
        topRow.appendChild(rightSide);


        // Bottom row: Other stats
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
        listWrapper.appendChild(card);
    });

    container.appendChild(listWrapper);
}
