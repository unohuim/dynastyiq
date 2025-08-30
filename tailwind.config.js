import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    safelist: [
        'ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100',
        'border-indigo-100',
        'hover:border-indigo-200',
        'hover:bg-indigo-100',
        'bg-indigo-900',
        'bg-indigo-100',
        'bg-gradient-to-b',
        'from-orange-600', 'to-black',
        'from-red-700', 'to-yellow-500',
        'from-yellow-400', 'to-blue-800',
        'from-blue-700', 'to-orange-500',
        'from-blue-800', 'to-red-700',
        'from-blue-900', 'to-red-600',
        'from-red-600', 'to-gray-800',
        'from-green-700', 'to-black',
        'from-red-700', 'to-gray-100',
        'from-orange-500', 'to-blue-900',
        'from-red-700', 'to-blue-700',
        'from-yellow-400', 'to-blue-800',
        'from-blue-700', 'to-blue-500',
        'from-cyan-700', 'to-navy-800',
        'from-teal-700',
        'from-green-800', 'to-red-800',
        'from-gray-600',
        'from-gray-700', 'to-yellow-400',
        'from-red-700', 'to-black',
        'from-blue-800', 'to-yellow-400',
        'from-blue-800', 'to-gray-400',
        'text-xxs',

        // Mobile player card custom classes
        'perspectivesbar-mobile',
        'players-list-mobile',
        'player-stats-view',
        'player-stats-page',
        'player-stats-card-mobile',
        'player-stats-team-strip-mobile',
        'player-stats-team-text-mobile',
        'player-stats-content-mobile',
        'player-stats-top-row-mobile',
        'player-stats-left-mobile',
        'player-stats-pos-tag-mobile',
        'player-stats-name-mobile',
        'player-stats-aav-mobile',
        'player-stats-right-mobile',
        'player-stats-sorted-label-mobile',
        'player-stats-sorted-value-mobile',
        'player-stats-bottom-row-mobile',
        'player-stats-stat-group-mobile',
        'player-stats-stat-mobile',
        'player-stats-stat-key-mobile',
        'player-stats-stat-val-mobile',
        'searchbar-mobile',
        'searchbar-innerWrapper-mobile',
        'searchbar-gridWrapper-mobile',
        'searchbar-input-mobile',
        'searchbar-button-mobile',
        'searchbar-svg-mobile',
        'player-stats-page',
        'mb-1',
        'sticky',
        'top-0',
        'z-50',
        'z-40',
        'z-10',
        '-gap-y-8',

        // overlay
        'fixed','inset-0','bg-black/40','opacity-0','opacity-100',
        'pointer-events-none','pointer-events-auto','transition-opacity','duration-200','z-[60]',
        // sheet
        'inset-x-0','bottom-0','transform','translate-y-full','translate-y-0',
        'transition-transform','duration-300','ease-out','bg-white',
        'rounded-t-2xl','shadow-2xl','z-[70]','backdrop-blur','will-change-[transform]'
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: {
                    800: '#002868',
                },
                gold: {
                    400: '#FFD700',
                },
            },
            fontSize: {
                xxs: '0.60rem', // 👈 Add this here
            },
        },
    },

    plugins: [forms, typography],
};
