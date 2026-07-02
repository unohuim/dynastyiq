const DEFAULT_DEBOUNCE_MS = 300;

const classNames = {
    control: 'mt-0.5 grid grid-cols-1',
    icon: 'pointer-events-none col-start-1 row-start-1 ml-2.5 size-4 self-center text-gray-400',
    input: 'col-start-1 row-start-1 block min-h-8 w-full min-w-0 rounded-md bg-white py-1 pl-8 pr-8 text-sm text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:border-gray-300 focus:outline focus:outline-1 focus:-outline-offset-1 focus:outline-gray-300 focus:ring-0',
    actions: 'col-start-1 row-start-1 mr-1.5 self-center justify-self-end',
    button: 'inline-flex size-6 items-center justify-center rounded-md text-gray-400 hover:bg-gray-50 hover:text-gray-600 focus:outline focus:outline-1 focus:-outline-offset-1 focus:outline-gray-300 focus:ring-0 disabled:text-gray-300',
    buttonIcon: 'size-3.5',
    error: 'mt-1 text-xs text-red-600',
};

const attr = (element, name, fallback = '') => element.getAttribute(name) ?? fallback;

const boolAttr = (element, name) => ['1', 'true', 'yes'].includes(attr(element, name).toLowerCase());

const iconSvg = (path, label = null) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.5');
    svg.setAttribute('aria-hidden', label === null ? 'true' : 'false');
    svg.classList.add(...classNames.buttonIcon.split(' '));

    if (label !== null) {
        svg.setAttribute('role', 'img');
        const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        title.textContent = label;
        svg.append(title);
    }

    path.forEach((attributes) => {
        const element = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
        svg.append(element);
    });

    return svg;
};

const magnifyingGlassIcon = () => {
    const wrapper = document.createElement('div');
    wrapper.className = classNames.icon;
    const svg = iconSvg([
        {
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            d: 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
        },
    ]);
    wrapper.append(svg);

    return wrapper;
};

const xMarkIcon = () => iconSvg([
    {
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        d: 'M6 18 18 6M6 6l12 12',
    },
]);

/**
 * Reusable Tailwind-styled search input behavior.
 */
export class SearchField {
    constructor(root, options = {}) {
        this.root = root;
        this.input = root.matches('input, textarea') ? root : root.querySelector('input, textarea');
        this.name = options.name ?? (attr(root, 'data-search-field-name') || this.input?.name || '');
        this.scope = options.scope ?? (attr(root, 'data-search-field-scope') || null);
        this.id = options.id ?? (attr(root, 'data-search-field-id') || this.input?.id || null);
        this.debounceMs = Number(options.debounceMs ?? attr(root, 'data-search-field-debounce', DEFAULT_DEBOUNCE_MS));
        this.timer = null;
        this.loading = false;
        this.disabled = false;
        this.error = '';
        this.control = null;
        this.clearButton = null;
        this.errorLabel = null;

        if (!this.input) {
            throw new Error('SearchField requires an input or textarea');
        }

        this.input.__searchField = this;
    }

    mount() {
        if (this.root.dataset.searchFieldMounted === '1') {
            return this;
        }

        this.root.dataset.searchFieldMounted = '1';
        this.control = document.createElement('div');
        this.control.className = classNames.control;
        this.input.before(this.control);
        this.control.append(this.input);

        this.input.classList.add(...classNames.input.split(' '));
        this.input.setAttribute('autocomplete', attr(this.input, 'autocomplete', 'off'));

        this.control.append(magnifyingGlassIcon());

        const actions = document.createElement('div');
        actions.className = classNames.actions;

        this.clearButton = document.createElement('button');
        this.clearButton.type = 'button';
        this.clearButton.className = classNames.button;
        this.clearButton.setAttribute(
            'aria-label',
            `Clear ${this.input.getAttribute('aria-label') || this.name || 'search'}`
        );
        this.clearButton.append(xMarkIcon());
        this.clearButton.addEventListener('click', () => this.clear());
        actions.append(this.clearButton);

        this.errorLabel = document.createElement('div');
        this.errorLabel.className = classNames.error;
        this.errorLabel.hidden = true;

        this.control.append(actions);
        this.root.append(this.errorLabel);
        this.input.addEventListener('input', () => {
            this.syncState();
            this.scheduleEmit();
        });
        this.input.addEventListener('change', () => this.emit(true));

        if (boolAttr(this.root, 'data-search-field-autofocus')) {
            this.input.focus();
        }

        this.syncState();

        return this;
    }

    scheduleEmit() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.emit(false), this.debounceMs);
    }

    emit(immediate = false) {
        window.clearTimeout(this.timer);
        const restore = this.focusSnapshot();

        const event = new CustomEvent('search-field:change', {
            bubbles: true,
            detail: {
                name: this.name,
                value: this.input.value,
                scope: this.scope,
                id: this.id,
                immediate,
                focus: restore,
            },
        });

        this.root.dispatchEvent(event);
        this.syncState();
        this.restoreFocus(restore);
    }

    clear() {
        if (this.input.disabled) return;

        this.input.value = '';
        this.input.focus();
        this.emit(true);
    }

    setLoading(loading) {
        this.loading = loading;
    }

    setDisabled(disabled) {
        this.disabled = disabled;
        this.input.disabled = disabled;
        this.syncState();
    }

    setError(message = '') {
        this.error = message;
        this.syncState();
    }

    syncState() {
        if (this.clearButton) {
            this.clearButton.classList.toggle('hidden', this.input.value.length < 1 || this.input.disabled);
            this.clearButton.disabled = this.input.disabled;
        }

        if (this.errorLabel) {
            this.errorLabel.textContent = this.error;
            this.errorLabel.hidden = this.error === '';
        }
    }

    focusSnapshot() {
        if (document.activeElement !== this.input) {
            return null;
        }

        return {
            start: this.input.selectionStart,
            end: this.input.selectionEnd,
            direction: this.input.selectionDirection,
        };
    }

    restoreFocus(snapshot) {
        if (snapshot === null || !this.input.isConnected || document.activeElement === this.input) {
            return;
        }

        this.input.focus({ preventScroll: true });

        if (
            snapshot.start !== null
            && snapshot.end !== null
            && typeof this.input.setSelectionRange === 'function'
        ) {
            this.input.setSelectionRange(snapshot.start, snapshot.end, snapshot.direction ?? 'none');
        }
    }
}

export const mountSearchFields = (root = document) =>
    Array.from(root.querySelectorAll('[data-search-field]')).map((element) => new SearchField(element).mount());

export default SearchField;
