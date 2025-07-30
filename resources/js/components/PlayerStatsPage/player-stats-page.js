import { sortData } from './player-stats-utils.js';
import { renderPlayerStatsDesktop } from './player-stats-desktop.js';
import { PlayerStatsMobile } from './player-stats-mobile.js';

let playerStatsComponent = null;


function createSearchBar(onSearchChange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'p-3';

    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'Filter by player name';
    input.className =
        'w-full px-4 py-2 text-sm border rounded-full shadow-sm focus:outline-none focus:ring';

    input.addEventListener('input', (e) => {
        onSearchChange(e.target.value);
    });

    wrapper.appendChild(input);
    return wrapper;
}

export class PlayerStatsPage {
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

        console.log(this.settings);

        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const nowMobile = window.innerWidth <= 768;
                if (nowMobile !== this.isMobile) {
                    this.isMobile = nowMobile;
                    this.render();
                }
            }, 200);
        });

    }


    updatePayload(newPayload) {
        console.log('🔁 updatePayload called with:', newPayload);    
        this.payload = newPayload;
        this.originalData = newPayload.data || [];
        this.headings = newPayload.headings || [];

        const settings = newPayload.settings || {};
        this.settings = {
            ...settings,
            sortKey: settings.defaultSort ?? null,
            sortDirection: settings.defaultSortDirection ?? 'desc',
        };

        this.render();
    }
    

    handleSortChange = ({ sortKey, sortDirection }) => {
        console.log(`↕️ Sort changed: ${sortKey} (${sortDirection})`);
        this.settings.sortKey = sortKey;
        this.settings.sortDirection = sortDirection;

        const sorted = sortData(this.originalData, sortKey, sortDirection);
        renderPlayerStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
    };

    render() {
        console.log('🖼 Rendering PlayerStatsPage with settings:', this.settings);
        const sorted = sortData(this.originalData, this.settings.sortKey, this.settings.sortDirection);
        const isMobile = window.innerWidth <= 768;


        if (isMobile) {
            const searchBar = createSearchBar((query) => {
                const filtered = this.originalData.filter((player) =>
                    player.player_name.toLowerCase().includes(query.toLowerCase())
                );
                PlayerStatsMobile({
                    container: this.container,
                    data: filtered,
                    headings: this.headings,
                    settings: this.settings,
                });
            });

            this.container.appendChild(searchBar);

            PlayerStatsMobile({
                container: this.container,
                data: this.originalData,
                headings: this.headings,
                settings: this.settings,
            });
        } else {
            renderPlayerStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
        }
    }
}



document.addEventListener('DOMContentLoaded', () => {
    console.log('dom loaded event received');
    const container = document.getElementById('player-stats-page');
    const data = window.__playerStats;

    if (container && data) {
        playerStatsComponent = new PlayerStatsPage({ container, data });
        playerStatsComponent.render();
    }
});


window.addEventListener('playerStatsUpdated', (event) => {
    console.log('update event received');
    const updatedData = event.detail?.json ?? {};
    if (!updatedData) {
        console.warn('⛔ No data in event.detail');
        return;
    }

    if (playerStatsComponent) {
        console.log('🔁 Updating PlayerStatsPage with new payload');
        playerStatsComponent.updatePayload(updatedData);
    } else {
        console.warn('⚠️ playerStatsComponent not initialized');
    }
});




