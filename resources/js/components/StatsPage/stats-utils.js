const HYDRATED_ROW_FALLBACK_KEYS = new Set([
    'gp',
    'fantasy_pts_pg',
    'fantasy_pts_per_game',
]);

const hasMeaningfulStatValue = (value) => value !== undefined
    && value !== null
    && value !== ''
    && value !== 0
    && value !== '0';

const statValueAliases = (key) => {
    const normalized = String(key ?? '').toLowerCase();

    if (normalized === 'gp') return ['gp', 'games_played', 'gamesPlayed', 'games'];

    return [key];
};

const firstStatValueForKeys = (source, keys, meaningfulOnly = false) => {
    if (!source || typeof source !== 'object') return undefined;

    return keys.map((key) => source?.[key]).find((value) => {
        if (meaningfulOnly) return hasMeaningfulStatValue(value);

        return value !== undefined && value !== null && value !== '';
    });
};

export function statValueForKey(row, key) {
    const normalized = String(key ?? '').toLowerCase();
    const keys = statValueAliases(key);
    const nestedValue = firstStatValueForKeys(row?.stats, keys);
    const rowValue = firstStatValueForKeys(row, keys);

    if (HYDRATED_ROW_FALLBACK_KEYS.has(normalized)) {
        return firstStatValueForKeys(row, keys, true)
            ?? firstStatValueForKeys(row?.stats, keys, true)
            ?? rowValue
            ?? nestedValue;
    }

    return nestedValue ?? rowValue;
}

export function sortData(data, sortKey, sortDirection = 'desc') {
    if (!sortKey) return data;

    return [...data].sort((a, b) => {
        const key = sortKey === 'toi' ? 'toi_seconds' : sortKey;
        const aValue = statValueForKey(a, key);
        const bValue = statValueForKey(b, key);

        if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
        if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
        return 0;
    });
}

const PROSPECT_POSITION_ORDER = ['C', 'L', 'R', 'D', 'G'];

const prospectPositionRank = (label) => {
    const index = PROSPECT_POSITION_ORDER.indexOf(label);

    return index === -1 ? PROSPECT_POSITION_ORDER.length : index;
};

const positionTokens = (value) => String(value ?? '')
    .toUpperCase()
    .split(/[^A-Z]+/)
    .filter(Boolean);

export function isLeagueProspectMode(settings = {}) {
    return ['skaters', 'goalies'].includes(String(settings?.leagueProspectMode ?? ''));
}

export function prospectPositionGroup(row = {}) {
    const tokens = [
        ...positionTokens(row?.pos),
        ...positionTokens(row?.position),
        ...positionTokens(row?.eligible_positions),
        ...positionTokens(row?.position_eligibility),
        ...positionTokens(row?.fantrax_position),
        ...positionTokens(row?.roster_slot),
        ...positionTokens(row?.pos_type),
        ...positionTokens(row?.type),
    ];

    if (tokens.some((token) => token === 'C')) return 'C';
    if (tokens.some((token) => ['LW', 'L'].includes(token))) return 'L';
    if (tokens.some((token) => ['RW', 'R'].includes(token))) return 'R';
    if (tokens.some((token) => token === 'D')) return 'D';
    if (tokens.some((token) => token === 'G')) return 'G';
    if (tokens.some((token) => token === 'F')) return 'F';

    return 'Other';
}

export function groupRowsByProspectPosition(rows = []) {
    const groups = new Map();

    rows.forEach((row) => {
        const label = prospectPositionGroup(row);

        if (!groups.has(label)) {
            groups.set(label, []);
        }

        groups.get(label).push(row);
    });

    return [...groups.entries()]
        .sort(([a], [b]) => {
            const rank = prospectPositionRank(a) - prospectPositionRank(b);

            return rank !== 0 ? rank : String(a).localeCompare(String(b));
        })
        .map(([label, groupedRows]) => ({ label, rows: groupedRows }));
}

export function formatContractValue(value) {
    return value ?? 0;
}


export const statFormatters = {
    contract_value: val => formatContractValue(val),
    league: val => formatLeagueName(val),
    shooting_percentage: val => typeof val === 'number' ? (val * 100).toFixed(1) + '%' : val,
    ipp: val => formatPercentageTwoDecimals(val),
    avgPTSpGP: val => typeof val === 'number' ? Number(val).toFixed(2) : val,
    g_per_gp: val => formatOneDecimalTruncated(val),
    pts_per_gp: val => formatOneDecimalTruncated(val),
};

export function formatStatValue(key, val) {
    const formatter = statFormatters[key];
    return formatter ? formatter(val) : val ?? '';
}

function formatOneDecimalTruncated(value) {
    const number = typeof value === 'number' ? value : Number(value);

    if (!Number.isFinite(number)) {
        return value ?? '';
    }

    return (Math.trunc(number * 10) / 10).toFixed(1);
}

function formatPercentageTwoDecimals(value) {
    const number = typeof value === 'number' ? value : Number(value);

    if (!Number.isFinite(number)) {
        return value ?? '';
    }

    return `${(number * 100).toFixed(2)}%`;
}

function formatLeagueName(value) {
    return String(value ?? '').slice(0, 8);
}


const teamGradients = {
    ANA: 'linear-gradient(to bottom, #FF6F00, #000000)',
    ARI: 'linear-gradient(to bottom, #8C2633, #000000)',
    BOS: 'linear-gradient(to bottom, #FFB81C, #000000)',
    BUF: 'linear-gradient(to bottom, #002654, #FDBB2F)',
    CGY: 'linear-gradient(to bottom, #C8102E, #F1BE48)',
    CAR: 'linear-gradient(to bottom, #CC0000, #000000)',
    CHI: 'linear-gradient(to bottom, #CF0A2C, #000000)',
    COL: 'linear-gradient(to bottom, #6F263D, #236192)',
    CBJ: 'linear-gradient(to bottom, #002654, #A6A6A6)',
    DAL: 'linear-gradient(to bottom, #006847, #000000)',
    DET: 'linear-gradient(to bottom, #CE1126, #FFFFFF)',
    EDM: 'linear-gradient(to bottom, #FF4C00, #041E42)',
    FLA: 'linear-gradient(to bottom, #041E42, #C8102E)',
    LAK: 'linear-gradient(to bottom, #A2AAAD, #000000)',
    MIN: 'linear-gradient(to bottom, #154734, #A6192E)',
    MTL: 'linear-gradient(to bottom, #AF1E2D, #192168)',
    NSH: 'linear-gradient(to bottom, #FFB81C, #041E42)',
    NJD: 'linear-gradient(to bottom, #CE1126, #000000)',
    NYI: 'linear-gradient(to bottom, #00539B, #F47D30)',
    NYR: 'linear-gradient(to bottom, #0038A8, #CE1126)',
    OTT: 'linear-gradient(to bottom, #E31837, #000000)',
    PHI: 'linear-gradient(to bottom, #FA4616, #000000)',
    PIT: 'linear-gradient(to bottom, #FFB81C, #000000)',
    SEA: 'linear-gradient(to bottom, #001628, #99D9D9)',
    SJS: 'linear-gradient(to bottom, #006D75, #000000)',
    STL: 'linear-gradient(to bottom, #002F87, #FDB827)',
    TBL: 'linear-gradient(to bottom, #002868, #00529B)',
    TOR: 'linear-gradient(to bottom, #00205B, #003E7E)',
    VAN: 'linear-gradient(to bottom, #00205B, #00843D)',
    VGK: 'linear-gradient(to bottom, #B4975A, #333F48)',
    WSH: 'linear-gradient(to bottom, #C8102E, #041E42)',
    WPG: 'linear-gradient(to bottom, #041E42, #7B303D)',
};

export function teamBg(teamAbbrev) {
    return teamGradients[teamAbbrev] || 'linear-gradient(to bottom, #e5e7eb, #9ca3af)';
}
