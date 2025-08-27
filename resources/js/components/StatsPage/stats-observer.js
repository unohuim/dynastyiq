/**
 * Sets up a MutationObserver to detect when #player-stats-page is swapped in the DOM.
 * Calls the provided callback when it detects a new container with data.
 *
 * @param {Function} onSwapCallback - function(newContainer, newData)
 */
export function playerStatsObserver(onSwapCallback) {
    let currentContainer = document.getElementById('player-stats-page');

    const observer = new MutationObserver(() => {
        const newContainer = document.getElementById('player-stats-page');
        const newData = window.__playerStats;

        if (newContainer && newData && newContainer !== currentContainer) {
            currentContainer = newContainer;
            onSwapCallback(newContainer, newData);
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
}