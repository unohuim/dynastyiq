const endpoint = '/analytics/events';
const heartbeatSeconds = 30;
const maxBatchSize = 10;

let pending = [];
let lastHeartbeatAt = Date.now();
let flushTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function currentPath() {
    return `${window.location.pathname}${window.location.search}`;
}

function enqueue(eventName, properties = {}) {
    pending.push({
        event_name: eventName,
        path: currentPath(),
        referrer: document.referrer || null,
        occurred_at: new Date().toISOString(),
        properties,
    });

    if (pending.length >= maxBatchSize) {
        flush();
        return;
    }

    window.clearTimeout(flushTimer);
    flushTimer = window.setTimeout(flush, 1000);
}

function flush() {
    window.clearTimeout(flushTimer);
    flushTimer = null;

    if (pending.length === 0) {
        return;
    }

    const events = pending.splice(0, maxBatchSize);

    window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ events }),
    }).catch(() => {
        pending = events.concat(pending).slice(0, maxBatchSize);
    });
}

function trackHeartbeat() {
    if (document.visibilityState !== 'visible') {
        lastHeartbeatAt = Date.now();
        return;
    }

    const now = Date.now();
    const elapsedSeconds = Math.round((now - lastHeartbeatAt) / 1000);
    lastHeartbeatAt = now;

    enqueue('heartbeat', {
        engaged_seconds: Math.min(Math.max(elapsedSeconds, 1), heartbeatSeconds),
    });
}

function trackDeclarativeClick(event) {
    const target = event.target instanceof Element
        ? event.target.closest('[data-track]')
        : null;

    if (!target) {
        return;
    }

    enqueue('ui.click', {
        key: target.getAttribute('data-track'),
    });
}

function bootAnalyticsTracker() {
    if (!document.querySelector('meta[name="csrf-token"]')) {
        return;
    }

    enqueue('page_view', {
        title: document.title,
    });

    window.setInterval(trackHeartbeat, heartbeatSeconds * 1000);
    document.addEventListener('click', trackDeclarativeClick);
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAnalyticsTracker, { once: true });
} else {
    bootAnalyticsTracker();
}
