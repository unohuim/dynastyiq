<script setup>
import { computed } from 'vue';
import { windows as skaterWindows, metrics as skaterMetrics, historical as historicalValue, prediction, actual, previous, errorRate, quarterDisplay, projectedQuarter, quarterDifference, gameAdjusted, gameAdjustedError } from './game-aggregates';

const props = defineProps({ name: String, side: String, rows: Array, goalies: Boolean, league: [Number, String], period: { type: String, default: 'q1' } });
const windows = computed(() => props.goalies ? skaterWindows.filter(([key]) => !['venue_train', 'venue_last_season'].includes(key)) : skaterWindows);
const number = value => value === null || value === undefined ? '—' : Number(value).toFixed(2);
const percent = value => value === null || value === undefined ? '—' : `${Number(value) >= 0 ? '+' : ''}${number(value)}%`;
const records = computed(() => props.rows.find(row => row.inputs.context?.team_records)?.inputs.context.team_records);
const metrics = computed(() => props.goalies ? [['sv_pct', 'SV%'], ['gsax_per_100_xga', 'GSAx/100 xGA']] : skaterMetrics);
function historical(row, window, metric) {
    const raw = historicalValue(row, window, metric);
    if (window === 'current_quarter') return quarterDisplay(raw, row.inputs.context?.windows?.[window]?.games);
    return number(raw) + (metric === 'sv_pct' && raw !== null && raw !== undefined ? '%' : '');
}


// Shares use the matching column's selected-strength ice time, never all-strength fallback.
function toiValue(row, column) {
    if (column === 'previous') return previous(row, 'toi');
    if (column === 'quarter') return projectedQuarter(row, 'toi');
    if (column === 'current') return historicalValue(row, 'current_quarter', 'toi');
    if (column === 'adjusted') return gameAdjusted(toiValue(row, 'quarter'), toiValue(row, 'current'));
    if (column === 'predicted') return prediction(row, 'toi');
    if (column === 'actual') return actual(row, 'toi');
    return historicalValue(row, column, 'toi');
}
function toiShare(row, column) {
    const value = toiValue(row, column);
    const total = props.rows.map(player => toiValue(player, column))
        .filter(value => value != null).reduce((sum, value) => sum + value, 0);
    return value == null || total <= 0 ? null : value / total * 100;
}
function shareCells(row) {
    const quarter = toiShare(row, 'quarter');
    const current = toiShare(row, 'current');
    const adjusted = toiShare(row, 'adjusted');
    const predicted = toiShare(row, 'predicted');
    const measured = toiShare(row, 'actual');
    const share = value => value == null ? '—' : `${number(value)}%`;
    return [
        ...windows.value.map(([key]) => share(toiShare(row, key))),
        share(toiShare(row, 'previous')), share(quarter), share(current),
        quarter == null || current == null ? '—' : `${number(quarter - current)} pp`,
        share(adjusted), share(predicted), share(measured),
        percent(errorRate(predicted, measured)), percent(errorRate(quarter, measured)),
        percent(errorRate(adjusted, measured)),
    ];
}
</script>

<template>
    <article class="min-w-0 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
        <header class="flex items-baseline justify-between gap-3"><h3 class="text-lg font-semibold">{{ name }} <span class="text-sm font-normal text-gray-500">{{ goalies ? 'Goalies' : 'Skaters' }}</span></h3><span class="text-xs uppercase tracking-wide text-gray-500">{{ side }}</span></header>
        <section v-if="!goalies" aria-label="Pregame team records" class="rounded-lg bg-gray-50 p-3">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">Team record before this game</h4>
            <dl class="grid grid-cols-3 gap-3 text-sm sm:grid-cols-5"><div v-for="[key, label] in [['last_5', 'L5'], ['last_10', 'L10'], ['last_20', 'L20'], ['season', 'Season'], ['opponent_last_10', 'Vs opp. L10']]" :key="key"><dt class="text-xs text-gray-500">{{ label }}</dt><dd class="mt-1 font-semibold">{{ records?.[key] ? `${records[key].wins}–${records[key].losses}` : '—' }}</dd><dd class="text-xs text-gray-500">{{ records?.[key]?.win_pct != null ? `${number(records[key].win_pct)}%` : '—' }}</dd></div></dl>
        </section>
        <div class="max-h-[70dvh] overflow-auto rounded-lg border border-gray-200" tabindex="0" role="region" :aria-label="`${name} ${goalies ? 'goalie' : 'skater'} context`">
            <table class="w-full whitespace-nowrap text-right text-xs tabular-nums">
                <caption class="sr-only">{{ name }} · {{ goalies ? 'Save percentage and goals saved above expected per 100 expected goals against' : 'Shot attempts per 60, ice time in minutes per game, and shot attempts per game' }}</caption>
                <thead class="sticky top-0 z-20 bg-gray-50 text-gray-600"><tr><th scope="col" class="sticky left-0 z-10 bg-gray-50 px-3 py-3 text-left">Player</th><th scope="col" class="px-3 py-3">Metric</th><th v-for="[key, label] in windows" :key="key" scope="col" class="px-3 py-3">{{ label }}</th><template v-if="!goalies"><th scope="col" class="px-3 py-3">Previous</th><th scope="col" class="px-3 py-3">Proj {{ period.toUpperCase() }}</th><th scope="col" class="px-3 py-3">Current {{ period.toUpperCase() }}</th><th scope="col" class="px-3 py-3">{{ period.toUpperCase() }} Diff</th><th scope="col" class="px-3 py-3">Game Adj</th><th scope="col" class="bg-indigo-50 px-3 py-3">Proj</th><th scope="col" class="px-3 py-3">Actual</th><th scope="col" class="px-3 py-3">err% Proj</th><th scope="col" class="px-3 py-3">err% Proj{{ period.toUpperCase() }}</th><th scope="col" class="px-3 py-3">Game Adj %</th></template></tr></thead>
                <tbody>
                    <template v-for="row in rows" :key="row.nhl_player_id">
                        <tr v-for="([metric, label], index) in metrics" :key="metric" :class="index === 0 ? 'border-t border-gray-200' : 'text-gray-500'">
                            <th v-if="index === 0" scope="rowgroup" :rowspan="metrics.length + (goalies ? 0 : 1)" class="sticky left-0 z-10 max-w-32 whitespace-normal bg-white px-3 py-4 text-left font-medium text-gray-950">{{ row.player_name }}<span class="mt-1 block text-xs text-gray-500">{{ row.inputs.context?.position ?? '—' }}</span></th>
                            <th scope="row" class="px-3 py-3 font-normal">{{ label }}</th>
                            <td v-for="[key] in windows" :key="key" class="px-3 py-3" :title="`${row.inputs.context?.windows?.[key]?.games ?? 0} prior appearances`">{{ historical(row, key, metric) }}</td>
                            <template v-if="!goalies"><td class="px-3 py-3">{{ number(previous(row, metric)) }}</td><td class="px-3 py-3" data-quarter-projection>{{ number(projectedQuarter(row, metric)) }}</td><td class="px-3 py-3" data-quarter-test>{{ historical(row, 'current_quarter', metric) }}</td><td class="px-3 py-3" data-quarter-difference>{{ number(quarterDifference(projectedQuarter(row, metric), historicalValue(row, 'current_quarter', metric))) }}</td><td class="px-3 py-3" data-game-adjusted>{{ number(gameAdjusted(projectedQuarter(row, metric), historicalValue(row, 'current_quarter', metric))) }}</td><td class="bg-indigo-50/50 px-3 py-3 font-medium">{{ number(prediction(row, metric)) }}</td><td class="px-3 py-3">{{ number(actual(row, metric)) }}</td><td class="px-3 py-3">{{ percent(errorRate(prediction(row, metric), actual(row, metric))) }}</td><td class="px-3 py-3" data-quarter-error>{{ percent(errorRate(projectedQuarter(row, metric), actual(row, metric))) }}</td><td class="px-3 py-3" data-game-adjusted-error>{{ percent(gameAdjustedError(projectedQuarter(row, metric), historicalValue(row, 'current_quarter', metric), actual(row, metric))) }}</td></template>
                        </tr>
                        <tr v-if="!goalies" class="text-gray-500" data-toi-share>
                            <th scope="row" class="px-3 py-3 font-normal">TOI Share</th>
                            <td v-for="(value, index) in shareCells(row)" :key="index" class="px-3 py-3">{{ value }}</td>
                        </tr>
                    </template>
                    <tr class="border-t border-gray-200 bg-gray-50 font-semibold"><th scope="row" class="sticky left-0 z-10 bg-gray-50 px-3 py-4 text-left">League Average</th><td :colspan="goalies ? 7 : 10" class="px-3 py-4">—</td><template v-if="!goalies"><td class="bg-indigo-50 px-3 py-4">{{ number(league) }}</td><td colspan="8" class="px-3 py-4">—</td></template></tr>
                    <tr v-if="!rows.length"><td :colspan="goalies ? 8 : 20" class="px-3 py-6 text-left text-gray-500">No saved {{ goalies ? 'goalie' : 'skater' }} context.</td></tr>
                </tbody>
            </table>
        </div>
    </article>
</template>
