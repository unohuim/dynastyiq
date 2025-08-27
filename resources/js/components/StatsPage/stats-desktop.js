import { formatStatValue } from './stats-utils.js';

/**
 * Render the desktop player stats view using Tailwind div grid layout.
 *
 * @param {HTMLElement} container
 * @param {Array} data
 * @param {Array} headings - Array of { key, label }
 * @param {Object} settings - { sortKey, sortDirection }
 * @param {Function} onSortChange - Callback to update sort state
 */
export function renderStatsDesktop(container, data, headings, settings, onSortChange) {
    
    console.log('💻 Desktop render fired with sortKey:', settings.sortKey, 'and data length:', data.length);
    console.log('headings: ', headings);

    container.innerHTML = '';

    const wrapper = document.createElement('div');
    wrapper.className = 'min-w-full bg-white shadow rounded-lg overflow-hidden border border-gray-200';

    // Header Row
    const headerRow = document.createElement('div');
    headerRow.className = 'transition-all duration-300 ease-in-out will-change-transform grid text-xs font-semibold bg-gray-100 text-gray-700 px-4 py-2';
    headerRow.style.gridTemplateColumns = `repeat(${headings.length}, minmax(0, 1fr))`;

    headings.forEach(({ key, label }) => {
        const headerCell = document.createElement('div');
        headerCell.className = 'cursor-pointer flex items-center gap-1';
        headerCell.textContent = label;

        if (settings.sortKey === key) {
            const arrow = document.createElement('span');
            arrow.textContent = settings.sortDirection === 'asc' ? '↑' : '↓';
            headerCell.appendChild(arrow);
        }

        headerCell.addEventListener('click', () => {            
            const isSameKey = settings.sortKey === key;
            const newDirection = isSameKey && settings.sortDirection === 'desc' ? 'asc' : 'desc';
            onSortChange({ sortKey: key, sortDirection: newDirection });
        });

        headerRow.appendChild(headerCell);
    });

    wrapper.appendChild(headerRow);

    // Player Rows
    data.forEach(row => {
        const playerRow = document.createElement('div');
        playerRow.className = 'grid border-t px-4 py-2 text-sm hover:bg-gray-50';
        playerRow.style.gridTemplateColumns = `repeat(${headings.length}, minmax(0, 1fr))`;

        headings.forEach(({ key }) => {
            const cell = document.createElement('div');
            const rawVal = row.stats?.[key] ?? row[key];
            const val = formatStatValue(key, rawVal);

            if (settings.sortKey === key) {
                cell.className = 'font-semibold text-gray-900';
            } else {
                cell.className = 'text-sm text-gray-700';
            }

            cell.textContent = val ?? '';
            playerRow.appendChild(cell);
        });

        wrapper.appendChild(playerRow);
    });

    

    container.appendChild(wrapper);

}
