<script setup>
import { Link } from '@inertiajs/vue3';
import LineupTeam from './LineupTeam.vue';
defineProps({ game: { type: Object, required: true } });
const localDateTime = (value) => value ? new Intl.DateTimeFormat(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(new Date(value)) : 'Start time unavailable';
</script>
<template><div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8"><Link :href="`/games?date=${game.game_date}`" class="text-sm font-semibold text-indigo-600">← All games</Link><div class="mb-6 mt-3 flex items-end justify-between"><div><h1 class="text-2xl font-semibold">{{ game.away.team_abbrev }} at {{ game.home.team_abbrev }}</h1><p class="mt-1 text-sm text-gray-600">{{ localDateTime(game.start_time_utc) }}</p></div><span class="text-xs text-gray-400">NHL game #{{ game.nhl_game_id }}</span></div><div class="grid gap-6 xl:grid-cols-2"><LineupTeam :team="game.away" side="Away"/><LineupTeam :team="game.home" side="Home"/></div></div></template>
