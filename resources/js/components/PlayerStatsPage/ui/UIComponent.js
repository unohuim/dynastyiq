export class UIComponent {
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
        placeholder.innerHTML = template;

        const searchInput = placeholder.querySelector('#searchInput');
        if (searchInput) {
          let debounceTimer;
          searchInput.addEventListener('input', e => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
              const searchTerm = e.target.value.trim().toLowerCase();
              placeholder.dispatchEvent(new CustomEvent('searchInput', {
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

    return placeholder;
  }
}
