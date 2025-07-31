export class UI {
  static containerSelector = '';
  static templateUrl = '';

  static async loadTemplate(url, params = {}) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`Failed to load template: ${url}`);
      let template = await res.text();

      Object.entries(params).forEach(([key, val]) => {
        const re = new RegExp(`{{\\s*${key}\\s*}}`, 'g');
        template = template.replace(re, val);
      });

      return template.trim();
    } catch (err) {
      console.error(err);
      return null;
    }
  }

  static html(container, params = {}) {
    if (!(container instanceof Element)) {
      console.error('Invalid container element passed.');
      return null;
    }

    const placeholder = document.createElement('div');
    placeholder.className = `${this.name.toLowerCase()}-placeholder`;
    placeholder.textContent = `Loading ${this.name}…`;

    container.appendChild(placeholder);

    this.loadTemplate(this.templateUrl, params).then(template => {
      if (template) {
        // Create a temporary container to parse the HTML string
        const tempContainer = document.createElement('div');
        tempContainer.innerHTML = template;

        // Replace the placeholder div with the loaded template's root element
        container.replaceChild(tempContainer.firstElementChild, placeholder);

        const searchInput = container.querySelector('#searchInput');

        if (searchInput) {
          let debounceTimer;
          searchInput.addEventListener('input', e => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
              const searchTerm = e.target.value.trim().toLowerCase();
              container.dispatchEvent(new CustomEvent('searchInput', {
                detail: { searchTerm },
                bubbles: true,
              }));
            }, 50);
          });
        }
      } else {
        placeholder.textContent = `Failed to load ${this.name}.`;
      }
    });

    return container;
  }

  static SearchBar(relativeWrapper, placeholder) {
    this.containerSelector = '#searchbar-mobile';
    this.templateUrl = '/ui-htm/searchbar-mobile.htm';

    this.html(relativeWrapper, { placeholder: 'search…' });
  }
}
