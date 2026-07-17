const ROOT_SELECTOR = "[data-draft-round-scroll-root]";
const SCROLLER_SELECTOR = "[data-draft-round-scroll]";
const BAR_SELECTOR = "[data-draft-round-scrollbar]";
const THUMB_SELECTOR = "[data-draft-round-scroll-thumb]";

const states = new WeakMap();

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function updateRoundScrollbar(root) {
    const scroller = root.querySelector(SCROLLER_SELECTOR);
    const bar = root.querySelector(BAR_SELECTOR);
    const thumb = root.querySelector(THUMB_SELECTOR);

    if (!(scroller instanceof HTMLElement)
        || !(bar instanceof HTMLElement)
        || !(thumb instanceof HTMLElement)) {
        return;
    }

    const overflow = scroller.scrollWidth > scroller.clientWidth + 1;
    root.classList.toggle("draft-round-scroll-root--overflowing", overflow);

    if (!overflow) {
        thumb.style.width = "0px";
        thumb.style.transform = "translateX(0px)";
        return;
    }

    const trackWidth = bar.clientWidth;
    const thumbWidth = Math.max(32, Math.round((scroller.clientWidth / scroller.scrollWidth) * trackWidth));
    const maxThumbOffset = Math.max(0, trackWidth - thumbWidth);
    const maxScroll = Math.max(1, scroller.scrollWidth - scroller.clientWidth);
    const thumbOffset = Math.round((scroller.scrollLeft / maxScroll) * maxThumbOffset);

    thumb.style.width = `${thumbWidth}px`;
    thumb.style.transform = `translateX(${thumbOffset}px)`;
}

function dragThumb(root, event) {
    const scroller = root.querySelector(SCROLLER_SELECTOR);
    const bar = root.querySelector(BAR_SELECTOR);
    const thumb = root.querySelector(THUMB_SELECTOR);

    if (!(scroller instanceof HTMLElement)
        || !(bar instanceof HTMLElement)
        || !(thumb instanceof HTMLElement)
        || event.button !== 0) {
        return;
    }

    event.preventDefault();
    const state = states.get(root);

    if (state) {
        state.dragging = true;
    }

    const startX = event.clientX;
    const startScroll = scroller.scrollLeft;
    const trackWidth = bar.clientWidth;
    const thumbWidth = thumb.offsetWidth;
    const maxThumbOffset = Math.max(1, trackWidth - thumbWidth);
    const maxScroll = Math.max(1, scroller.scrollWidth - scroller.clientWidth);

    const onMove = (moveEvent) => {
        const delta = moveEvent.clientX - startX;
        scroller.scrollLeft = clamp(startScroll + ((delta / maxThumbOffset) * maxScroll), 0, maxScroll);
        updateRoundScrollbar(root);
    };

    const onUp = () => {
        document.removeEventListener("pointermove", onMove);
        document.removeEventListener("pointerup", onUp);

        if (state) {
            state.dragging = false;
        }

        updateRoundScrollbar(root);
    };

    document.addEventListener("pointermove", onMove);
    document.addEventListener("pointerup", onUp, { once: true });
}

function setupRoundScrollbar(root) {
    if (!(root instanceof HTMLElement) || states.has(root)) {
        return;
    }

    const scroller = root.querySelector(SCROLLER_SELECTOR);
    const thumb = root.querySelector(THUMB_SELECTOR);

    if (!(scroller instanceof HTMLElement) || !(thumb instanceof HTMLElement)) {
        return;
    }

    const update = () => updateRoundScrollbar(root);
    const resizeObserver = typeof ResizeObserver === "undefined"
        ? null
        : new ResizeObserver(update);
    const mutationObserver = new MutationObserver(update);

    resizeObserver?.observe(root);
    resizeObserver?.observe(scroller);
    mutationObserver.observe(scroller, {
        childList: true,
        subtree: true,
    });

    scroller.addEventListener("scroll", update, { passive: true });
    scroller.addEventListener("wheel", update, { passive: true });
    scroller.addEventListener("touchstart", update, { passive: true });
    thumb.addEventListener("pointerdown", (event) => dragThumb(root, event));

    states.set(root, {
        dragging: false,
        resizeObserver,
        mutationObserver,
    });

    update();
}

export function registerDraftRoundScrollbars(root = document) {
    root.querySelectorAll(ROOT_SELECTOR).forEach(setupRoundScrollbar);
}

if (typeof document !== "undefined") {
    document.addEventListener("DOMContentLoaded", () => registerDraftRoundScrollbars());
    document.addEventListener("alpine:initialized", () => registerDraftRoundScrollbars());
}
