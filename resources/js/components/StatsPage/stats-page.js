import { sortData } from './stats-utils.js';
import { renderStatsDesktop } from './stats-desktop.js';
import { StatsMobile } from './stats-mobile.js';

let statsComponent = null;



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
        renderStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
    };

    render() {
        console.log('🖼 Rendering StatsPage with settings:', this.settings);
        const sorted = sortData(this.originalData, this.settings.sortKey, this.settings.sortDirection);
        const isMobile = window.innerWidth <= 639;


        if (isMobile) {
            StatsMobile({
                container: this.container,
                data: this.originalData,
                headings: this.headings,
                settings: this.settings,
            });
        } else {
            renderStatsDesktop(this.container, sorted, this.headings, this.settings, this.handleSortChange);
        }
    }
}



document.addEventListener('DOMContentLoaded', () => {
    console.log('dom loaded event received');
    const container = document.getElementById('stats-page');
    const data = window.__stats;

    if (container && data) {
        statsComponent = new StatsPage({ container, data });
        statsComponent.render();
    }
});


window.addEventListener('statsUpdated', (event) => {
    console.log('update event received');
    const updatedData = event.detail?.json ?? {};
    if (!updatedData) {
        console.warn('⛔ No data in event.detail');
        return;
    }

    if (statsComponent) {
        console.log('🔁 Updating statsPage with new payload');
        statsComponent.updatePayload(updatedData);
    } else {
        console.warn('⚠️ statsComponent not initialized');
    }
});




