export function sortData(data, sortKey, sortDirection = 'desc') {
    if (!sortKey) return data;

    return [...data].sort((a, b) => {
        const aValue = a.stats?.[sortKey] ?? a[sortKey];
        const bValue = b.stats?.[sortKey] ?? b[sortKey];

        if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
        if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
        return 0;
    });
}

export function formatContractValue(value) {
    return value ?? 0;
}


export const statFormatters = {
    contract_value: val => formatContractValue(val),
    shooting_percentage: val => typeof val === 'number' ? (val * 100).toFixed(1) + '%' : val,
    avgPTSpGP: val => typeof val === 'number' ? Number(val).toFixed(2) : val,
};

export function formatStatValue(key, val) {
    const formatter = statFormatters[key];
    return formatter ? formatter(val) : val ?? '';
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
