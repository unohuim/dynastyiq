export class UI {



  static SearchBar(parentContainer) {
    const searchBar = document.createElement('div');
    searchBar.id = 'searchbar-mobile';
    searchBar.className = 'searchbar-mobile'; // fixed + above nav

    const perspectivesBar = document.querySelector('#perspectivesBar');
    const barHeight = perspectivesBar ? perspectivesBar.offsetHeight : 0;

    searchBar.style.top = `${barHeight}px`;
    parentContainer.appendChild(searchBar);
    const searchBarHeight = searchBar.offsetHeight;
  

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
    input.placeholder = 'search players..';
    input.className = 'searchbar-input-mobile';
    input.setAttribute('inputmode', 'search');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('autocomplete', 'off');


    const svgButton = document.createElement('button');
    svgButton.type = "button";
    svgButton.className = "searchbar-button-mobile";

    // const caption = document.createTextNode('Sort');
    // svgButton.appendChild(caption);
    

    const svgIconFilter = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIconFilter.setAttribute('viewBox', '0 0 16 16');
    svgIconFilter.setAttribute('fill', 'none');
    svgIconFilter.setAttribute('stroke', 'currentColor');
    svgIconFilter.setAttribute('data-slot', 'icon');
    svgIconFilter.setAttribute('aria-hidden', true);
    svgIconFilter.setAttribute('class', 'searchbar-svg-mobile');
    

    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75");
    path.setAttribute("clip-rule", "evenodd");
    path.setAttribute("fill-rule", "evenodd");
    

    const svgButtonSort = document.createElement('button');
    svgButtonSort.type = "button";
    svgButtonSort.className = "searchbar-button-mobile";


    const svgIconSort = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIconSort.setAttribute('viewBox', '0 0 16 16');
    svgIconSort.setAttribute('fill', 'none');
    svgIconSort.setAttribute('stroke', 'currentColor');
    svgIconSort.setAttribute('data-slot', 'icon');
    svgIconSort.setAttribute('aria-hidden', true);
    svgIconSort.setAttribute('class', 'searchbar-svg-mobile');
    

    const pathSort = document.createElementNS("http://www.w3.org/2000/svg", "path");
    pathSort.setAttribute("d", "M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21 21 17.25");
    pathSort.setAttribute("clip-rule", "evenodd");
    pathSort.setAttribute("fill-rule", "evenodd");


    svgIconFilter.appendChild(path);
    svgIconSort.appendChild(pathSort);

    svgButton.appendChild(svgIconFilter);
    svgButtonSort.appendChild(svgIconSort);

    gridWrapper.appendChild(input);

    innerWrapper.appendChild(gridWrapper);
    innerWrapper.appendChild(svgButton);
    innerWrapper.appendChild(svgButtonSort);

    searchBar.appendChild(innerWrapper);
    

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

    return searchBar;
  }


}
