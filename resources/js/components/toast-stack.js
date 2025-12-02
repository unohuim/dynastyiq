const TYPE_MAP = {
    success: { background: 'bg-emerald-600', accent: 'bg-emerald-400', icon: '✓' },
    error: { background: 'bg-red-600', accent: 'bg-red-400', icon: '⚠' },
    info: { background: 'bg-sky-600', accent: 'bg-sky-400', icon: 'ℹ' },
};

const normalizeFlashes = (flashes = [], defaultDuration = 5000) => {
    if (!Array.isArray(flashes)) return [];

    return flashes
        .map((toast, index) => {
            const message = typeof toast?.message === 'string' ? toast.message.trim() : '';
            if (!message) return null;

            return {
                id: index + 1,
                duration: defaultDuration,
                closing: false,
                type: TYPE_MAP[toast?.type] ? toast.type : 'info',
                message,
            };
        })
        .filter(Boolean);
};

export const registerToastStack = (Alpine) => {
    if (!Alpine?.data) return;

    Alpine.data('toastStack', ({ flashes = [], defaultDuration = 5000 } = {}) => ({
        toasts: [],
        nextId: 1,
        defaultDuration,

        boot() {
            const initialFlashes = normalizeFlashes(flashes, defaultDuration);
            this.toasts = initialFlashes;
            this.nextId = initialFlashes.length + 1;
            this.toasts.forEach((toast) => this.scheduleDismiss(toast));

            window.toast = {
                show: (message, options = {}) => this.addToast(message, options),
                success: (message, options = {}) => this.addToast(message, { ...options, type: 'success' }),
                error: (message, options = {}) => this.addToast(message, { ...options, type: 'error' }),
                info: (message, options = {}) => this.addToast(message, { ...options, type: 'info' }),
                clear: () => (this.toasts = []),
            };
        },

        handleEvent(event) {
            const detail = event.detail ?? {};
            const payload = typeof detail === 'string' ? { message: detail } : detail;
            this.addToast(payload.message, payload);
        },

        addToast(message, { type = 'info', duration } = {}) {
            const safeMessage = typeof message === 'string' ? message.trim() : '';
            if (!safeMessage) return null;

            const toast = {
                id: this.nextId++,
                type: TYPE_MAP[type] ? type : 'info',
                message: safeMessage,
                duration: duration ?? this.defaultDuration,
                closing: false,
            };

            this.toasts.push(toast);
            this.$nextTick(() => this.focusRegion());
            this.scheduleDismiss(toast);

            return toast.id;
        },

        scheduleDismiss(toast) {
            window.setTimeout(() => this.close(toast.id), toast.duration);
        },

        close(id) {
            const toast = this.toasts.find((item) => item.id === id);
            if (!toast || toast.closing) return;

            toast.closing = true;
            window.setTimeout(() => {
                this.toasts = this.toasts.filter((item) => item.id !== id);
            }, 180);
        },

        focusRegion() {
            this.$refs.liveRegion?.focus({ preventScroll: true });
        },

        toastClasses(type) {
            return TYPE_MAP[type]?.background ?? TYPE_MAP.info.background;
        },

        toastAccent(type) {
            return TYPE_MAP[type]?.accent ?? TYPE_MAP.info.accent;
        },

        toastIcon(type) {
            return TYPE_MAP[type]?.icon ?? TYPE_MAP.info.icon;
        },
    }));
};

export default registerToastStack;
