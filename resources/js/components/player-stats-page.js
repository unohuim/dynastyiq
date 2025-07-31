

export class PlayerStatsPage {
  constructor({ container, data }) {
    this.container = container;
    this.players = data.data || [];
    this.columns = data.headings || [];

    this.sort = {
      key: data.settings?.defaultSort ?? null,
      direction: data.settings?.defaultSortDirection ?? 'desc',
    };
  }

  getValue(player, key) {
    if (key in player) return player[key];
    if (player.stats && key in player.stats) return player.stats[key];
    return null;
  }

  sortPlayers() {
    const { key, direction } = this.sort;
    if (!key) return this.players;

    return [...this.players].sort((a, b) => {
      const aVal = this.getValue(a, key);
      const bVal = this.getValue(b, key);

      if (aVal === undefined || aVal === null) return 1;
      if (bVal === undefined || bVal === null) return -1;

      if (typeof aVal === 'string') {
        return aVal.localeCompare(bVal) * (direction === 'asc' ? 1 : -1);
      }

      return (aVal - bVal) * (direction === 'asc' ? 1 : -1);
    });
  }

  render() {
    const sortedPlayers = this.sortPlayers();

    const table = document.createElement('div');
    table.className = 'h-full min-w-full bg-white shadow rounded-lg overflow-hidden border border-gray-200';

    const headerRow = document.createElement('div');
    headerRow.className = 'grid text-xs font-semibold bg-gray-100 text-gray-700 px-2 py-2';
    headerRow.style.gridTemplateColumns = `repeat(${this.columns.length}, minmax(0, 1fr))`;

    this.columns.forEach(col => {
      const th = document.createElement('div');
      th.className = 'cursor-pointer px-2 flex items-center gap-1';
      th.textContent = col.label;

      th.addEventListener('click', () => {
        if (this.sort.key === col.key) {
          this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
        } else {
          this.sort.key = col.key;
          this.sort.direction = 'desc';
        }
        this.render();
      });

      if (this.sort.key === col.key) {
        const arrow = document.createElement('span');
        arrow.textContent = this.sort.direction === 'asc' ? '↑' : '↓';
        th.appendChild(arrow);
      }

      headerRow.appendChild(th);
    });

    table.appendChild(headerRow);

    sortedPlayers.forEach(player => {
      const row = document.createElement('div');
      row.className = 'grid px-2 py-2 text-sm border-t hover:bg-gray-50';
      row.style.gridTemplateColumns = `repeat(${this.columns.length}, minmax(0, 1fr))`;

      this.columns.forEach(col => {
        const td = document.createElement('div');
        td.className = 'px-2';

        const val = this.getValue(player, col.key);

        if (col.key === 'shooting_percentage') {
          td.textContent = typeof val === 'number' ? (val * 100).toFixed(1) + '%' : '—';
        } else if (col.key === 'avgPTSpGP' || col.key === 'ppg') {
          td.textContent = typeof val === 'number' ? val.toFixed(2) : '—';
        } else {
          td.textContent = val ?? '—';
        }

        row.appendChild(td);
      });

      table.appendChild(row);
    });

    this.container.innerHTML = '';
    this.container.appendChild(table);
  }
}
