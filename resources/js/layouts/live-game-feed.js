import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/** Subscribe through the layout-owned connection; resync only shared snapshots, never NHL. */
export function useLiveGameFeed({ gameId = null, full = false, accept = () => true, active = () => true, update }) {
    const incoming = inject('nhlLiveGame', null);
    const connected = inject('nhlLiveConnected', ref(false));
    const warning = ref('');
    const sequences = new Map();
    const pending = new Map();
    const timeouts = new Set();
    let disposed = false;
    let timer;
    let lastObserved = null;
    let mountedAt = null;
    const id = () => typeof gameId === 'function' ? gameId() : gameId?.value ?? gameId;
    const fresh = computed(() => !warning.value);

    function apply(snapshot, detail = false) {
        const key = Number(snapshot?.game?.nhl_game_id);
        if (!key || !Number.isFinite(Number(snapshot.sequence)) || !accept(snapshot.game)
            || (id() != null && key !== Number(id()))) return;
        if (Number(snapshot.sequence) < (sequences.get(key) ?? -1)) return;
        if (snapshot.stale) { warning.value = 'Live updates delayed. Reconnecting…'; return; }
        lastObserved = snapshot.observed_at;
        warning.value = connected.value ? '' : 'Live connection interrupted. Reconnecting…';
        if (!detail && full) {
            // Clock/score changes apply immediately; details follow from our shared snapshot endpoint.
            update(snapshot, false);
            if (snapshot.game.mode !== 'preview' && (snapshot.changed !== false || !sequences.has(key))) void resync(key);
        } else update(snapshot, detail);
        sequences.set(key, Number(snapshot.sequence));
    }
    async function resync(target = id()) {
        if (disposed || !incoming || document.hidden) return;
        const key = target ?? 'all';
        pending.get(key)?.abort();
        const controller = new AbortController();
        pending.set(key, controller);
        const timeout = setTimeout(() => controller.abort(), 10000);
        timeouts.add(timeout);
        try {
            const url = new URL('/games/live', window.location.origin);
            if (target != null) url.searchParams.set('game_id', target);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw new Error('Live snapshot unavailable');
            const payload = await response.json();
            if (disposed || pending.get(key) !== controller) return;
            for (const snapshot of payload.snapshots ?? []) apply(snapshot, target != null && snapshot.game.mode !== 'preview');
        } catch (error) {
            if (!disposed && error?.name !== 'AbortError') warning.value = 'Live updates unavailable. Reconnecting…';
        } finally {
            clearTimeout(timeout);
            timeouts.delete(timeout);
            if (pending.get(key) === controller) pending.delete(key);
        }
    }
    const foreground = () => { if (!document.hidden) void resync(); };
    watch(() => incoming?.value, snapshot => { if (snapshot) apply(snapshot); }, { flush: 'sync' });
    watch(connected, value => { if (value) void resync(); else warning.value = 'Live connection interrupted. Reconnecting…'; });
    onMounted(() => {
        if (!incoming || !active()) return;
        mountedAt = Date.now();
        void resync();
        document.addEventListener('visibilitychange', foreground);
        timer = setInterval(() => {
            if (!active()) return;
            const stale = Date.now() - (lastObserved ? Date.parse(lastObserved) : mountedAt) > 20000;
            if (stale) warning.value = 'Live updates delayed. Reconnecting…';
            if (!connected.value || stale) void resync();
        }, 5000);
    });
    onBeforeUnmount(() => {
        disposed = true;
        clearInterval(timer);
        pending.forEach(controller => controller.abort());
        timeouts.forEach(clearTimeout);
        document.removeEventListener('visibilitychange', foreground);
    });
    return { warning, fresh, resync };
}
