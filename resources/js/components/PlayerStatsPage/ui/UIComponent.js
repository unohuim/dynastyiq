export class UI {



  static SearchBar(parentContainer) {
    const container = document.createElement('div');
    container.id = 'searchbar-mobile';
    container.className = 'searchbar-mobile'; // fixed + above nav

    const nav = document.querySelector('nav.sm\\:hidden'); // select mobile nav
    const navHeight = nav ? nav.offsetHeight : 0;
    // console.log('nav height: ', navHeight)

    container.style.bottom = `${navHeight}px`;
    // container.style.left = '0';        // fix left to screen start
    // container.style.right = '0';       // fix right to screen end
    // container.style.width = '100%';    // full width

    //contains row
    const innerWrapper = document.createElement('div');
    innerWrapper.className = 'searchbar-innerWrapper-mobile'; // full width with padding

    //contains input and svg
    const gridWrapper = document.createElement('div');
    gridWrapper.className = 'searchbar-gridWrapper-mobile';

    const input = document.createElement('input');
    input.id = 'searchInput';
    input.type = 'search';
    input.name = 'searchInput';
    input.placeholder = 'search..';
    input.className = 'searchbar-input-mobile';


    const svgButton = document.createElement('button');
    svgButton.type = "button";
    svgButton.className = "searchbar-button-mobile";

    // const caption = document.createTextNode('Sort');
    // svgButton.appendChild(caption);
    

    const svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIcon.setAttribute('viewBox', '0 0 16 16');
    svgIcon.setAttribute('fill', 'none');
    svgIcon.setAttribute('stroke', 'currentColor');
    svgIcon.setAttribute('data-slot', 'icon');
    svgIcon.setAttribute('aria-hidden', true);
    svgIcon.setAttribute('class', 'searchbar-svg-mobile');
    

    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75");
    // path.setAttribute('d', "M18.75 12.75h1.5a.75.75 0 0 0 0-1.5h-1.5a.75.75 0 0 0 0 1.5ZM12 6a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 12 6ZM12 18a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 12 18ZM3.75 6.75h1.5a.75.75 0 1 0 0-1.5h-1.5a.75.75 0 0 0 0 1.5ZM5.25 18.75h-1.5a.75.75 0 0 1 0-1.5h1.5a.75.75 0 0 1 0 1.5ZM3 12a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 3 12ZM9 3.75a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5ZM12.75 12a2.25 2.25 0 1 1 4.5 0 2.25 2.25 0 0 1-4.5 0ZM9 15.75a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Z");
    // path.setAttribute('d', "M2 2.75A.75.75 0 0 1 2.75 2h9.5a.75.75 0 0 1 0 1.5h-9.5A.75.75 0 0 1 2 2.75ZM2 6.25a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5h-5.5A.75.75 0 0 1 2 6.25Zm0 3.5A.75.75 0 0 1 2.75 9h3.5a.75.75 0 0 1 0 1.5h-3.5A.75.75 0 0 1 2 9.75ZM9.22 9.53a.75.75 0 0 1 0-1.06l2.25-2.25a.75.75 0 0 1 1.06 0l2.25 2.25a.75.75 0 0 1-1.06 1.06l-.97-.97v5.69a.75.75 0 0 1-1.5 0V8.56l-.97.97a.75.75 0 0 1-1.06 0Z");
    path.setAttribute("clip-rule", "evenodd");
    path.setAttribute("fill-rule", "evenodd");
    

    svgIcon.appendChild(path);
    svgButton.appendChild(svgIcon);

    gridWrapper.appendChild(input);

    innerWrapper.appendChild(gridWrapper);
    innerWrapper.appendChild(svgButton);

    container.appendChild(innerWrapper);
    parentContainer.appendChild(container);

    let debounceTimer;
    input.addEventListener('input', e => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const searchTerm = e.target.value.trim().toLowerCase();
        parentContainer.dispatchEvent(new CustomEvent('searchInputEvent', {
          detail: { searchTerm },
          bubbles: true,
        }));
      }, 150);
    });
  }
}
