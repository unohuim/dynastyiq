const classNames = {
    control: 'mt-0.5 grid grid-cols-1',
    select: 'col-start-1 row-start-1 block min-h-8 w-full min-w-0 appearance-none rounded-md bg-white py-1 pl-3 pr-8 text-sm text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 focus:border-gray-300 focus:outline focus:outline-1 focus:-outline-offset-1 focus:outline-gray-300 focus:ring-0',
    icon: 'pointer-events-none col-start-1 row-start-1 mr-2.5 size-4 self-center justify-self-end text-gray-400',
};

const attr = (element, name, fallback = '') => element.getAttribute(name) ?? fallback;

const chevronIcon = () => {
    const wrapper = document.createElement('div');
    wrapper.className = classNames.icon;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 20 20');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('size-4');

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('fill-rule', 'evenodd');
    path.setAttribute('clip-rule', 'evenodd');
    path.setAttribute('d', 'M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z');
    svg.append(path);
    wrapper.append(svg);

    return wrapper;
};

/**
 * Reusable Tailwind-styled native select behavior.
 */
export class SelectField {
    constructor(root, options = {}) {
        this.root = root;
        this.select = root.matches('select') ? root : root.querySelector('select');
        this.name = options.name ?? (attr(root, 'data-select-field-name') || this.select?.name || '');
        this.scope = options.scope ?? (attr(root, 'data-select-field-scope') || null);
        this.id = options.id ?? (attr(root, 'data-select-field-id') || this.select?.id || null);
        this.control = null;

        if (!this.select) {
            throw new Error('SelectField requires a select');
        }

        this.select.__selectField = this;
    }

    mount() {
        if (this.root.dataset.selectFieldMounted === '1') {
            return this;
        }

        this.root.dataset.selectFieldMounted = '1';
        this.control = document.createElement('div');
        this.control.className = classNames.control;
        this.select.before(this.control);
        this.control.append(this.select, chevronIcon());

        this.select.classList.remove('mt-0.5', 'mt-1', 'mt-2');
        this.select.classList.add(...classNames.select.split(' '));
        this.select.addEventListener('change', () => this.emit());

        return this;
    }

    emit() {
        const option = this.select.selectedOptions[0] ?? null;

        this.root.dispatchEvent(new CustomEvent('select-field:change', {
            bubbles: true,
            detail: {
                name: this.name,
                value: this.select.value,
                label: option?.textContent?.trim() ?? '',
                scope: this.scope,
                id: this.id,
            },
        }));
    }
}

export const mountSelectFields = (root = document) =>
    Array.from(root.querySelectorAll('[data-select-field]')).map((element) => new SelectField(element).mount());

export default SelectField;
