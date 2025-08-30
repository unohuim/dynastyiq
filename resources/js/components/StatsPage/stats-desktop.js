// stats-desktop.js
import { formatStatValue, teamBg } from './stats-utils.js';

// === keep your colours exactly as set before ===
export const BORDER_COLOUR_F = '#7CCCF2';
export const BORDER_COLOUR_D = '#FAE919';
export const BORDER_COLOUR_G = '#fecaca';

export const TXT_COLOUR_POS = '#606971';
export const TXT_COLOUR_F   = null;
export const TXT_COLOUR_D   = null;
export const TXT_COLOUR_G   = null;

const posTextColor = (p) => {
  const c = String(p || '').toUpperCase();
  if (c === 'F' && TXT_COLOUR_F) return TXT_COLOUR_F;
  if (c === 'D' && TXT_COLOUR_D) return TXT_COLOUR_D;
  if (c === 'G' && TXT_COLOUR_G) return TXT_COLOUR_G;
  return TXT_COLOUR_POS;
};

// AAV helpers
const isAAVKey = (k='') =>
  ['aav', 'contract_value', 'contract_value_num'].includes(String(k).toLowerCase());

const formatAAV = (val) => {
  let n = null;
  if (typeof val === 'number') n = val;
  else if (typeof val === 'string') {
    const s = val.replace(/[$,mM]/g, '');
    const parsed = parseFloat(s);
    if (Number.isFinite(parsed)) n = parsed;
  }
  if (n == null) return '';
  if (n > 1000) n = n / 1e6;
  return `$${n.toFixed(3)}`;
};

export function renderStatsDesktop(container, data, headings, settings, onSortChange) {
  container.innerHTML = '';

  // --- Move the Type column to be the first column (display only) ---
  const srcHeadings = Array.isArray(headings) ? [...headings] : [];
  const typeOrigIdx = srcHeadings.findIndex(h =>
    ['type','pos_type'].includes(String(h?.key || '').toLowerCase())
  );
  const displayHeadings = [...srcHeadings];
  if (typeOrigIdx > -1) {
    const [typeCol] = displayHeadings.splice(typeOrigIdx, 1);
    displayHeadings.unshift(typeCol);
  }
  // -------------------------------------------------------------------

  const wrapper = document.createElement('div');
  wrapper.className =
    'min-w-full bg-white shadow rounded-lg overflow-hidden border border-gray-200';

  // column sizing (computed on displayHeadings)
  const teamIdx   = displayHeadings.findIndex(h => String(h.key).toLowerCase() === 'team');
  const typeIdx   = displayHeadings.findIndex(h => ['type','pos_type'].includes(String(h.key).toLowerCase()));
  const playerIdx = displayHeadings.findIndex(h => /^(player|name)$/i.test(String(h.key)));

  const gridCols = displayHeadings.map((_, i) => {
    if (i === typeIdx)   return '56px';                 // now first column
    if (i === teamIdx)   return '92px';
    if (i === playerIdx) return 'minmax(220px,2fr)';
    return 'minmax(0,1fr)';
  }).join(' ');

  // header
  const headerRow = document.createElement('div');
  headerRow.className = 'grid text-xs font-semibold bg-gray-100 text-gray-700 px-4 py-2';
  headerRow.style.gridTemplateColumns = gridCols;

  displayHeadings.forEach(({ key, label }) => {
    const th = document.createElement('div');
    th.className = 'cursor-pointer select-none flex items-center justify-center gap-1';
    th.textContent = label;

    if (settings.sortKey === key) {
      const arrow = document.createElement('span');
      arrow.textContent = settings.sortDirection === 'asc' ? '↑' : '↓';
      th.appendChild(arrow);
      th.classList.add('text-gray-900');
    }

    th.addEventListener('click', () => {
      const same = settings.sortKey === key;
      onSortChange?.({
        sortKey: key,
        sortDirection: same && settings.sortDirection === 'desc' ? 'asc' : 'desc',
      });
    });

    headerRow.appendChild(th);
  });

  wrapper.appendChild(headerRow);

  // POS shape
  const buildPosShape = (raw) => {
    const v = String(raw ?? '').trim().toUpperCase();
    const wrap = document.createElement('div');
    wrap.className = 'h-10 w-full flex items-center justify-center';
    const box = document.createElement('div');
    box.className = 'h-8 w-8 flex items-center justify-center';
    wrap.appendChild(box);

    if (v === 'F') {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 100 100');
      svg.setAttribute('width', '100%');
      svg.setAttribute('height', '100%');

      const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
      poly.setAttribute('points', '50,3 3,97 97,97');
      poly.setAttribute('fill', 'none');
      poly.setAttribute('stroke', BORDER_COLOUR_F);
      poly.setAttribute('stroke-width', '2');
      poly.setAttribute('stroke-linejoin', 'round');

      const txt = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      txt.setAttribute('x', '50');
      txt.setAttribute('y', '66');
      txt.setAttribute('text-anchor', 'middle');
      txt.setAttribute('dominant-baseline', 'middle');
      txt.setAttribute('fill', posTextColor('F'));
      txt.setAttribute('font-size', '32');
      txt.setAttribute('font-weight', '700');
      txt.textContent = 'F';

      svg.appendChild(poly);
      svg.appendChild(txt);
      box.appendChild(svg);
      return wrap;
    }

    const inner = document.createElement('div');
    inner.className =
      'h-full w-full flex items-center justify-center border-2 font-semibold text-[12px]';
    inner.style.color = posTextColor(v);

    if (v === 'D') {
      inner.className += ' rounded-[6px] transform scale-110';
      inner.style.borderColor = BORDER_COLOUR_D;
      inner.textContent = 'D';
    } else if (v === 'G') {
      inner.className += ' rounded-full';
      inner.style.borderColor = BORDER_COLOUR_G;
      inner.textContent = 'G';
    } else {
      inner.className += ' rounded';
      inner.style.borderColor = '#e5e7eb';
      inner.textContent = v || '—';
    }

    box.appendChild(inner);
    return wrap;
  };

  // rows
  data.forEach(row => {
    const tr = document.createElement('div');
    tr.className = 'grid border-t px-4 py-3 text-sm hover:bg-gray-50 transition-colors';
    tr.style.gridTemplateColumns = gridCols;

    displayHeadings.forEach(({ key }, i) => {
      const cell = document.createElement('div');

      if (i === teamIdx) {
        const badge = document.createElement('div');
        badge.className =
          'inline-flex h-8 px-3 rounded-md items-center justify-center ' +
          'text-white font-semibold text-xs tracking-wide shadow-sm';
        badge.style.background = teamBg(row?.team);
        badge.textContent = row?.team ?? '—';
        cell.className = 'flex items-center justify-center text-gray-500';
        cell.appendChild(badge);

      } else if (i === typeIdx) {
        const val = row[key] ?? row.pos_type ?? row.type;
        cell.className = 'flex items-center justify-center text-gray-500';
        cell.appendChild(buildPosShape(val));

      } else if (isAAVKey(key)) {
        const raw = row.stats?.[key] ?? row[key];
        cell.className = 'flex items-center justify-center text-sm text-gray-500';
        cell.textContent = formatAAV(raw);

      } else {
        const rawVal = row.stats?.[key] ?? row[key];
        const val = formatStatValue(key, rawVal);
        const common = 'flex items-center justify-center text-gray-500';
        cell.className = (settings.sortKey === key)
          ? `${common} font-semibold`
          : `${common} text-[13px]`;
        cell.textContent = val ?? '';
      }

      tr.appendChild(cell);
    });

    wrapper.appendChild(tr);
  });

  container.appendChild(wrapper);
}
